<?php

/**
 * Smoke test for the v0.1 storage layer.
 *
 * Not a unit-test framework — a script that exercises the real database
 * through the real repositories and asserts the outcomes. Run with:
 *
 *     kirby newsletter:test:smoke
 *
 * It writes to a throwaway database under the system temp dir, so it never
 * touches the project's own subscriber data.
 */

use E9li\Newsletter\Consent;
use E9li\Newsletter\Database;
use E9li\Newsletter\EmailAddress;
use E9li\Newsletter\Migrations;
use E9li\Newsletter\Model\Subscriber;
use E9li\Newsletter\Model\Subscription;
use E9li\Newsletter\Newsletter;
use E9li\Newsletter\Render\Document;
use E9li\Newsletter\Repository\State;
use E9li\Newsletter\Repository\Subscribers;
use E9li\Newsletter\Repository\Subscriptions;
use E9li\Newsletter\Repository\Suppressions;
use E9li\Newsletter\Routes;
use E9li\Newsletter\Secret;
use E9li\Newsletter\Send\LogTransport;
use E9li\Newsletter\Send\Runner;
use E9li\Newsletter\Send\Seeder;
use E9li\Newsletter\Send\Transport;
use E9li\Newsletter\Signup;
use E9li\Newsletter\Theme;
use E9li\Newsletter\Token;

return function (callable $assert, callable $section): void {

    // ── Email normalisation ─────────────────────────────────────────────────
    $section('EmailAddress');

    $assert(
        EmailAddress::normalize('  Anna@Example.COM ') === 'anna@example.com',
        'normalize lowercases and trims'
    );
    $assert(
        EmailAddress::hash('Anna@Example.com') === EmailAddress::hash('anna@example.com'),
        'hash is case-insensitive'
    );
    $assert(EmailAddress::isValid('anna@example.com') === true, 'accepts a valid address');
    $assert(EmailAddress::isValid('anna@example') === false, 'rejects a bare hostname');
    $assert(EmailAddress::isValid('anna@example.com.') === false, 'rejects a trailing dot');
    $assert(EmailAddress::isValid('not-an-email') === false, 'rejects a non-address');
    $assert(EmailAddress::isValid('') === false, 'rejects an empty string');
    $assert(EmailAddress::domain('a@b.example.com') === 'b.example.com', 'extracts the domain');
    $assert(EmailAddress::isDisposable('x@mailinator.com') === true, 'flags disposable domains');
    $assert(EmailAddress::isRole('info@example.com') === true, 'flags role addresses');
    $assert(EmailAddress::isRole('info+news@example.com') === true, 'flags tagged role addresses');
    $assert(EmailAddress::isRole('anna@example.com') === false, 'does not flag a person');

    // ── Tokens ──────────────────────────────────────────────────────────────
    $section('Token');

    $token = Token::create('unsub', 4217, 'de', 'all');
    $payload = Token::verify('unsub', $token);

    $assert($payload !== null, 'a freshly minted token verifies');
    $assert($payload['id'] === 4217, 'the subscriber id round-trips');
    $assert($payload['language'] === 'de', 'the language round-trips');
    $assert($payload['scope'] === 'all', 'the scope round-trips');

    $assert(
        Token::verify('prefs', $token) === null,
        'a token minted for one purpose does not verify for another'
    );
    $assert(
        Token::verify('unsub', substr($token, 0, -1) . 'x') === null,
        'a tampered signature does not verify'
    );
    $assert(
        Token::verify('unsub', 'v1.4217.de.all') === null,
        'a token without a signature does not verify'
    );
    $assert(
        Token::verify('unsub', 'v2.4217.de.all.' . str_repeat('a', 32)) === null,
        'an unknown version does not verify'
    );

    $nullLang = Token::verify('unsub', Token::create('unsub', 7, null, 'all'));
    $assert($nullLang['language'] === null, 'a null language round-trips as null');

    $assert(strlen(Secret::key()) === 64, 'the generated secret is 32 bytes of hex');

    // ── Subscribers ─────────────────────────────────────────────────────────
    $section('Subscribers');

    $a = Subscribers::upsert('Anna@Example.com', [
        'name'     => 'Anna Müller',
        'language' => 'de',
        'source'   => 'form',
        'ip'       => '192.168.10.55',
    ]);

    $assert($a->email === 'Anna@Example.com', 'the address is stored as entered');
    $assert($a->emailNorm === 'anna@example.com', 'the normalised address is the identity key');
    $assert($a->status === Subscriber::STATUS_PENDING, 'a new subscriber starts pending');
    $assert($a->firstName() === 'Anna', 'first name is split off a two-part name');
    $assert($a->lastName() === 'Müller', 'last name handles multibyte characters');
    $assert($a->signupIp === '192.168.10.0', 'the IP is anonymised by default');

    $again = Subscribers::upsert('  ANNA@example.com  ');
    $assert($again->id === $a->id, 'a differently-cased address updates the same row');
    $assert(Subscribers::count() === 1, 'no duplicate was created');
    $assert($again->name === 'Anna Müller', 'an omitted name does not erase the stored one');

    $single = Subscribers::upsert('solo@example.com', ['name' => 'Cher']);
    $assert($single->firstName() === null, 'a one-word name yields no first name');
    $assert($single->lastName() === 'Cher', 'a one-word name is treated as the last name');

    $assert(
        Subscribers::anonymizeIp('2001:db8:1234:5678::1') === '2001:db8:1234::',
        'IPv6 keeps only the /48 prefix'
    );
    $assert(Subscribers::anonymizeIp('not-an-ip') === null, 'a malformed IP is dropped entirely');

    // ── Subscriptions & double opt-in ───────────────────────────────────────
    $section('Subscriptions');

    $list = 'page://listmain00001';

    $optin = Subscriptions::requestOptin($a->id, $list, 'de', '192.168.10.55');
    $assert(is_string($optin) === true && strlen($optin) === 64, 'the opt-in token is 32 random bytes');

    $stored = Database::first(
        'SELECT optin_token_hash FROM nl_subscriptions WHERE subscriber_id = :id',
        ['id' => $a->id]
    );
    $assert(
        $stored['optin_token_hash'] === hash('sha256', $optin),
        'only the hash of the opt-in token is stored'
    );
    $assert($stored['optin_token_hash'] !== $optin, 'the plaintext token is never stored');

    $sub = Subscriptions::find($a->id, $list);
    $assert($sub->status === Subscription::STATUS_PENDING, 'the subscription starts pending');
    $assert(Subscriptions::countActive($list) === 0, 'a pending subscription does not count as active');

    $assert(Subscriptions::confirm('wrong-token') === null, 'an unknown token does not confirm');

    $confirmed = Subscriptions::confirm($optin, '192.168.10.55');
    $assert($confirmed !== null, 'the correct token confirms');
    $assert($confirmed['subscription']->isActive() === true, 'the subscription becomes active');
    $assert($confirmed['subscription']->confirmedAt !== null, 'the confirmation timestamp is recorded');

    $assert(
        Subscriptions::confirm($optin) === null,
        'the opt-in token is single-use and cannot be replayed'
    );

    Subscribers::confirm($a->id, '192.168.10.55');
    $assert(Subscribers::find($a->id)->isActive() === true, 'the subscriber becomes active');
    $assert(Subscriptions::countActive($list) === 1, 'the active count now sees them');

    // A second list, so unsubscribing one does not deactivate the person.
    $list2 = 'page://listevents0001';
    Subscriptions::activate($a->id, $list2, 'fr');

    $assert(count(Subscriptions::forSubscriber($a->id)) === 2, 'a person can hold two subscriptions');

    // Languages are normalised against the site's own set. On a single-language
    // site that means they are stored as null: keeping 'fr' would promise a
    // translation that cannot exist, and would then filter recipients out of
    // every send.
    if (kirby()->multilang() === true) {
        $assert(
            Subscriptions::find($a->id, $list2)->language === 'fr',
            'a per-list language overrides the person default'
        );
    } else {
        $assert(
            Subscriptions::find($a->id, $list2)->language === null,
            'a language is not stored on a single-language site'
        );
        $assert(
            Newsletter::language('fr') === null,
            'language normalisation yields null without languages configured'
        );
    }

    $affected = Subscriptions::unsubscribe($a->id, $list, Subscription::REASON_ONECLICK);
    $assert($affected === 1, 'unsubscribing one list reports one change');
    $assert(
        Subscribers::find($a->id)->isActive() === true,
        'the person stays active while another list remains'
    );

    $assert(
        Subscriptions::unsubscribe($a->id, $list) === 0,
        'unsubscribing an already-unsubscribed list reports no change'
    );

    Subscriptions::unsubscribe($a->id, null, Subscription::REASON_LINK);
    $assert(
        Subscribers::find($a->id)->status === Subscriber::STATUS_UNSUBSCRIBED,
        'the person is unsubscribed once the last list goes'
    );
    $assert(
        Subscribers::find($a->id)->unsubscribedAt !== null,
        'the unsubscribe timestamp is recorded'
    );

    // Re-subscribing must go through pending again, never straight to active.
    $again = Subscriptions::requestOptin($a->id, $list, 'de');
    $assert(is_string($again) === true, 're-subscribing issues a fresh token');
    $assert(
        Subscriptions::find($a->id, $list)->status === Subscription::STATUS_PENDING,
        're-subscribing returns to pending, not straight to active'
    );
    $assert(
        Subscriptions::find($a->id, $list)->optinResends === 1,
        'the resend counter increments'
    );

    Subscriptions::activate($a->id, $list);
    $assert(
        Subscriptions::requestOptin($a->id, $list) === null,
        'an already-active subscription issues no new token'
    );
    $assert(
        Subscribers::find($a->id)->isActive() === true,
        'activating a list revives an unsubscribed person'
    );

    // Terminal statuses must survive a subscription change: someone who
    // complained must not be reactivated by an import or an admin click.
    Subscribers::setStatus($a->id, Subscriber::STATUS_COMPLAINED);
    Subscriptions::activate($a->id, $list2);
    $assert(
        Subscribers::find($a->id)->status === Subscriber::STATUS_COMPLAINED,
        'a complained subscriber is not revived by activating a list'
    );
    Subscribers::setStatus($a->id, Subscriber::STATUS_ACTIVE);

    // ── Suppressions ────────────────────────────────────────────────────────
    $section('Suppressions');

    $assert(Suppressions::isSuppressed('bounced@example.com') === false, 'unknown addresses are not suppressed');

    Suppressions::add('bounced@example.com', Suppressions::REASON_HARD_BOUNCE, '550 unknown user');
    $assert(Suppressions::isSuppressed('bounced@example.com') === true, 'a hard bounce suppresses');
    $assert(Suppressions::isSuppressed('BOUNCED@Example.com') === true, 'suppression is case-insensitive');

    Suppressions::add('bounced@example.com', Suppressions::REASON_HARD_BOUNCE, 'again');
    $assert(Suppressions::count() === 1, 'suppressing twice does not duplicate the row');

    Suppressions::add('angry@example.com', Suppressions::REASON_COMPLAINT);
    $assert(
        Suppressions::remove('angry@example.com') === false,
        'a complaint-based suppression cannot be lifted by accident'
    );
    $assert(
        Suppressions::isSuppressed('angry@example.com') === true,
        'the complaint suppression is still in force'
    );
    $assert(
        Suppressions::remove('angry@example.com', true) === true,
        'a complaint suppression can be lifted deliberately'
    );

    Suppressions::add('temp@example.com', Suppressions::REASON_INVALID, null, null, null, time() - 60);
    $assert(
        Suppressions::isSuppressed('temp@example.com') === false,
        'an expired suppression no longer blocks'
    );
    $assert(Suppressions::prune() === 1, 'pruning removes the expired row');

    $hash = EmailAddress::hash('forgotten@example.com');
    Suppressions::addHash($hash, Suppressions::REASON_GDPR);
    $assert(
        Suppressions::isHashSuppressed($hash) === true,
        'a hash-only suppression still blocks after erasure'
    );
    $assert(
        Suppressions::find('forgotten@example.com')['email_norm'] === null,
        'the erased address is not retained'
    );

    // ── State ───────────────────────────────────────────────────────────────
    $section('State');

    $assert(State::get('nothing') === null, 'an unset key returns null');
    $assert(State::get('nothing', 'fallback') === 'fallback', 'an unset key returns the default');

    State::set('tick.last_at', 1700000000);
    $assert(State::int('tick.last_at') === 1700000000, 'an integer round-trips');

    State::set('tick.last_at', 1700000001);
    $assert(State::int('tick.last_at') === 1700000001, 'setting twice updates rather than duplicating');

    $assert(State::remember('warmup.started_at', 111) === 111, 'remember stores a new key');
    $assert(State::remember('warmup.started_at', 999) === '111', 'remember leaves an existing key alone');

    // ── Pagination ──────────────────────────────────────────────────────────
    $section('Pagination');

    for ($i = 0; $i < 30; $i++) {
        Subscribers::upsert('bulk' . $i . '@example.com', ['language' => $i % 2 === 0 ? 'de' : 'fr']);
    }

    $page1 = Subscribers::paginate([], 1, 10);
    $assert(count($page1['data']) === 10, 'a page holds the requested number of rows');
    $assert($page1['total'] === 32, 'the total counts every subscriber');
    $assert($page1['pages'] === 4, 'the page count is derived from the total');

    $expectedFr = kirby()->multilang() === true ? 15 : 0;
    $filtered = Subscribers::paginate(['language' => 'fr'], 1, 100);
    $assert($filtered['total'] === $expectedFr, 'filtering by language narrows the total');

    // The 30 bulk rows plus `$single`; `$a` is active by now.
    $pending = Subscribers::paginate(['status' => Subscriber::STATUS_PENDING], 1, 100);
    $assert($pending['total'] === 31, 'filtering by status narrows the total');
    $assert(
        Subscribers::count(Subscriber::STATUS_ACTIVE) === 1,
        'exactly one subscriber is active'
    );

    $search = Subscribers::paginate(['query' => 'bulk1@'], 1, 10);
    $assert($search['total'] === 1, 'search matches the normalised address');

    $injection = Subscribers::paginate(['sort' => 'id; DROP TABLE nl_subscribers'], 1, 5);
    $assert($injection['total'] === 32, 'a non-allowlisted sort column is ignored rather than executed');
    $assert(
        Database::value('SELECT COUNT(*) FROM nl_subscribers') === 32,
        'the table survived the injection attempt'
    );

    // ── Schema ──────────────────────────────────────────────────────────────
    $section('Schema');

    $tables = Database::column(
        'SELECT name FROM sqlite_master WHERE type = "table" AND name LIKE "nl_%" ORDER BY name',
        [],
        'name'
    );

    foreach ([
        'nl_campaign_lists', 'nl_campaigns', 'nl_domain_state', 'nl_events',
        'nl_migrations', 'nl_queue', 'nl_state', 'nl_subscribers',
        'nl_subscriptions', 'nl_suppressions', 'nl_throttle', 'nl_webhook_events',
    ] as $table) {
        $assert(in_array($table, $tables, true) === true, 'table ' . $table . ' exists');
    }

    $assert(Migrations::marker() === Migrations::SCHEMA, 'the schema marker matches the code');
    $assert(Database::journalMode() === 'wal', 'WAL journalling is active');

    // Foreign keys must actually be enforced, or a deleted campaign would
    // leave orphaned queue rows that the runner would happily try to send.
    $assert(
        (int)Database::value('PRAGMA foreign_keys') === 1,
        'foreign key enforcement is on'
    );

    Database::execute(
        'INSERT INTO nl_subscriptions (subscriber_id, list_uuid, status, created_at, updated_at)
         VALUES (:id, "page://cascade", "active", 1, 1)',
        ['id' => $single->id]
    );
    Database::delete('nl_subscribers', 'id = :id', ['id' => $single->id]);
    $assert(
        (int)Database::value(
            'SELECT COUNT(*) FROM nl_subscriptions WHERE subscriber_id = :id',
            ['id' => $single->id]
        ) === 0,
        'deleting a subscriber cascades to their subscriptions'
    );

    // ── Rendering ───────────────────────────────────────────────────────────
    $section('Rendering');

    $campaign = page('newsletter/campaigns/2026-08-sommer');
    $assert($campaign !== null, 'the sample campaign page exists');

    if ($campaign !== null) {
        $rendered = Document::render($campaign);
        $assert(str_contains($rendered['html'], '<table') === true, 'the fixture renders a table');
        $assert(str_contains($rendered['html'], 'style=') === true, 'the fixture uses inline styles');
        $assert(str_contains($rendered['html'], 'Musterstrasse 1') === true, 'the postal address is in the HTML');
        $assert(str_contains($rendered['html'], '{{unsubscribe_url}}') === true, 'the unsubscribe placeholder is left for send time');
        $assert(
            str_contains($rendered['html'], 'https://kirby.test/media/example.jpg') === true
            || str_contains($rendered['html'], 'https&#x3A;&#x2F;&#x2F;kirby.test&#x2F;media&#x2F;example.jpg') === true,
            'an image src is absolute'
        );
        $assert(str_contains($rendered['text'], 'Mehr erfahren') === true, 'plain text contains the heading and the button');
        $assert(str_contains($rendered['text'], 'https://kirby.test/') === true, 'plain text contains the button URL');
    }

    $templatePage = page('newsletter/templates/standard');
    $svg = $templatePage === null ? null : new \Kirby\Cms\File([
        'filename' => 'logo.svg',
        'parent'   => $templatePage,
    ]);
    $assert(Theme::fileUrl($svg) === null, 'an SVG logo is omitted');

    // ── Consent ─────────────────────────────────────────────────────────────
    $section('Consent');

    $refused = Signup::subscribe('noconsent-' . bin2hex(random_bytes(3)) . '@example.com', ['page://nllistmain000001'], [
        'consent' => false,
        'ip'      => '203.0.113.10',
    ]);
    $assert(
        $refused->ok === false && $refused->code === Signup::ERR_CONSENT,
        'a form subscribe without the checkbox is ERR_CONSENT'
    );

    $consentEmail = 'consent-' . bin2hex(random_bytes(3)) . '@example.com';
    Signup::subscribe($consentEmail, ['page://nllistmain000001'], [
        'consent' => '1',
        'name'    => 'Ada',
        'ip'      => '203.0.113.11',
    ]);
    $consented = Subscribers::findByEmail($consentEmail);
    $assert($consented !== null, 'a consented signup is stored');

    if ($consented !== null) {
        $stored = Subscriptions::find($consented->id, 'page://nllistmain000001');
        $expected = hash('sha256', 'I agree to receive the monthly news and can unsubscribe at any time.');
        $assert(
            $stored !== null && $stored->consentTextHash === $expected,
            'the stored hash is the sentence shown on the list'
        );
    }

    $prefsPerson = Subscribers::upsert('prefs-' . bin2hex(random_bytes(3)) . '@example.com', ['name' => 'Bea']);
    $prefsToken = Token::create('prefs', $prefsPerson->id, null, 'all');
    Signup::updatePreferences($prefsToken, ['page://nllistmain000001']);
    $prefsSub = Subscriptions::find($prefsPerson->id, 'page://nllistmain000001');
    $assert(
        $prefsSub !== null && $prefsSub->isPending() === true && $prefsSub->consentSource === 'preferences' && $prefsSub->consentTextHash === null,
        'a preference-centre tick on a new opt-in list stays pending without a hash'
    );

    // ── Queue ───────────────────────────────────────────────────────────────
    $section('Queue');

    $shared = Subscribers::upsert('shared-' . bin2hex(random_bytes(3)) . '@example.com', ['name' => 'Ada']);
    Subscriptions::activate($shared->id, 'page://lista');
    Subscriptions::activate($shared->id, 'page://listb');

    $artifact = Seeder::write('smoke-send', null, '<p>Hi {{name}}</p><a href="{{unsubscribe_url}}">x</a>', "Hi\n{{unsubscribe_url}}");
    $campaignId = Database::insert('nl_campaigns', [
        'send_uuid'    => 'smoke-send',
        'page_uuid'    => 'page://smoke',
        'page_id'      => 'smoke',
        'language'     => null,
        'subject'      => 'Hello',
        'from_email'   => 'news@kirby.test',
        'status'       => 'sending',
        'artifact_key' => $artifact,
        'total'        => 1,
        'sent_count'   => 0,
        'failed_count' => 0,
        'track_opens'  => 0,
        'created_at'   => time(),
        'updated_at'   => time(),
    ]);

    $added = Seeder::enqueue($campaignId, ['page://lista', 'page://listb'], $artifact, '');
    $assert($added === 1, 'two lists with one shared subscriber insert one queue row');

    $blocked = Subscribers::upsert('blocked-' . bin2hex(random_bytes(3)) . '@example.com', ['name' => 'Gone']);
    Subscriptions::activate($blocked->id, 'page://lista');
    Suppressions::add($blocked->emailNorm, Suppressions::REASON_MANUAL, 'smoke');
    Seeder::enqueue($campaignId, ['page://lista'], $artifact, '');
    $assert(
        (int)Database::value(
            'SELECT COUNT(*) FROM nl_queue WHERE campaign_id = :id AND subscriber_id = :sid',
            ['id' => $campaignId, 'sid' => $blocked->id]
        ) === 0,
        'a suppressed address inserts no queue row'
    );

    Transport::force(new LogTransport());
    $tick = Runner::tick();
    $assert($tick['sent'] === 1, 'a tick writes the outbox and marks the row sent');
    $again = Runner::tick();
    $assert($again['sent'] === 0, 'a second tick does not send the row again');

    $outbox = glob(Newsletter::root() . '/outbox/*.eml') ?: [];
    $assert($outbox !== [], 'the outbox contains a message');

    if ($outbox !== []) {
        $mime = (string)file_get_contents($outbox[0]);
        $assert(str_contains($mime, 'List-Unsubscribe:') === true, 'the MIME contains List-Unsubscribe');
        $assert(str_contains($mime, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click') === true, 'the MIME contains List-Unsubscribe-Post');
    }

    $keep = Subscribers::upsert('keep-' . bin2hex(random_bytes(3)) . '@example.com', ['name' => 'Cara']);
    Subscriptions::activate($keep->id, 'page://dropme');
    Subscriptions::activate($keep->id, 'page://keepme');
    Database::execute(
        'INSERT INTO nl_campaign_lists (campaign_id, list_uuid) VALUES (:id, :list)',
        ['id' => $campaignId, 'list' => 'page://dropme']
    );
    Signup::unsubscribe(Token::create('unsub', $keep->id, null, 'c:' . $campaignId));
    $dropped = Subscriptions::find($keep->id, 'page://dropme');
    $kept = Subscriptions::find($keep->id, 'page://keepme');
    $assert(
        $dropped !== null && $dropped->isActive() === false && $kept !== null && $kept->isActive() === true,
        'a c: token unsubscribes the campaign lists and leaves the others'
    );

    // ── Opens ───────────────────────────────────────────────────────────────
    $section('Opens');

    Database::execute('UPDATE nl_campaigns SET track_opens = 1 WHERE id = :id', ['id' => $campaignId]);
    $queueId = (int)Database::value(
        'SELECT id FROM nl_queue WHERE campaign_id = :id LIMIT 1',
        ['id' => $campaignId]
    );
    $openToken = Token::create('open', $queueId, null, 'q');
    $gif = Routes::open($openToken);
    Routes::open($openToken);
    $assert(str_starts_with($gif->body(), 'GIF') === true, 'the open route returns a GIF');
    $assert(
        (int)Database::value(
            'SELECT COUNT(*) FROM nl_events WHERE queue_id = :id AND type = "open"',
            ['id' => $queueId]
        ) === 1,
        'two requests with the same token produce one open event'
    );

    $quietId = Database::insert('nl_campaigns', [
        'send_uuid'   => 'smoke-quiet',
        'page_uuid'   => 'page://quiet',
        'page_id'     => 'quiet',
        'subject'     => 'Quiet',
        'from_email'  => 'news@kirby.test',
        'status'      => 'sending',
        'track_opens' => 0,
        'created_at'  => time(),
        'updated_at'  => time(),
    ]);
    $quietQueue = Database::insert('nl_queue', [
        'campaign_id'   => $quietId,
        'subscriber_id' => $shared->id,
        'email'         => $shared->emailNorm,
        'domain'        => 'example.com',
        'artifact_key'  => $artifact,
        'message_id'    => '<quiet-' . bin2hex(random_bytes(4)) . '@kirby.test>',
        'status'        => 'sent',
        'available_at'  => time(),
        'created_at'    => time(),
        'updated_at'    => time(),
    ]);
    $quietGif = Routes::open(Token::create('open', $quietQueue, null, 'q'));
    $assert(str_starts_with($quietGif->body(), 'GIF') === true, 'tracking off still returns a GIF');
    $assert(
        (int)Database::value(
            'SELECT COUNT(*) FROM nl_events WHERE queue_id = :id',
            ['id' => $quietQueue]
        ) === 0,
        'tracking off writes no event'
    );

    $erased = Subscribers::upsert('erase-' . bin2hex(random_bytes(3)) . '@example.com', ['name' => 'Eve']);
    $erasedHash = $erased->emailHash;
    Subscribers::erase($erased->id, true);
    $assert(Subscribers::find($erased->id) === null, 'erasure removes the subscriber');
    $hashRow = Database::first(
        'SELECT email_norm, reason FROM nl_suppressions WHERE email_hash = :hash',
        ['hash' => $erasedHash]
    );
    $assert(
        $hashRow !== null && ($hashRow['email_norm'] === null || $hashRow['email_norm'] === '') && $hashRow['reason'] === Suppressions::REASON_GDPR,
        'erasure leaves a hash row and no email'
    );

    Transport::force(null);
};

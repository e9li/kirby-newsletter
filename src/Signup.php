<?php

namespace E9li\Newsletter;

use E9li\Newsletter\Model\Subscriber;
use E9li\Newsletter\Model\Subscription;
use E9li\Newsletter\Repository\Subscribers;
use E9li\Newsletter\Repository\Subscriptions;
use E9li\Newsletter\Repository\Suppressions;
use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * The public subscribe / confirm / unsubscribe use-cases.
 *
 * Every method returns a `Result` rather than throwing, because all of these
 * are reached from an anonymous HTTP request where the only sensible outcome
 * is a message on a page.
 *
 * A deliberate rule runs through the whole class: responses never reveal
 * whether an address is already on a list. Otherwise the subscribe form becomes
 * an oracle for testing whether someone is a subscriber.
 */
final class Signup
{
    public const OK_PENDING      = 'pending';       // opt-in mail sent
    public const OK_SUBSCRIBED   = 'subscribed';    // active immediately
    public const OK_CONFIRMED    = 'confirmed';
    public const OK_UNSUBSCRIBED = 'unsubscribed';
    public const OK_UPDATED      = 'updated';

    public const ERR_EMAIL       = 'invalidEmail';
    public const ERR_MX          = 'undeliverable';
    public const ERR_DISPOSABLE  = 'disposable';
    public const ERR_LISTS       = 'noLists';
    public const ERR_SUPPRESSED  = 'suppressed';
    public const ERR_RATE        = 'rateLimited';
    public const ERR_BOT         = 'bot';
    public const ERR_TOKEN       = 'invalidToken';
    public const ERR_EXPIRED     = 'expiredToken';
    public const ERR_MAIL        = 'mailFailed';
    public const ERR_CONSENT     = 'consentRequired';

    /**
     * Subscribes an address to one or more lists.
     *
     * @param array<int, string> $listIdentifiers UUIDs or page ids
     * @param array{name?: string, language?: string, meta?: array, source?: string,
     *              sourceDetail?: string, consent?: bool, ip?: string, ua?: string,
     *              honeypot?: string, startedAt?: int|string} $data
     */
    public static function subscribe(string $email, array $listIdentifiers, array $data = []): Result
    {
        // 1. Bot checks first — they cost nothing and keep junk out of the DB.
        if (static::looksAutomated($data) === true) {
            // Report success. A bot that learns it was detected will adapt;
            // one that thinks it succeeded will not retry.
            Log::debug('Signup rejected as automated', ['email' => EmailAddress::mask($email)]);

            return Result::ok(static::OK_PENDING);
        }

        // 2. Address validity.
        $email = trim($email);

        if (EmailAddress::isValid($email) === false) {
            return Result::error(static::ERR_EMAIL);
        }

        if (
            Newsletter::option('optin.blockDisposable', true) === true
            && EmailAddress::isDisposable($email) === true
        ) {
            return Result::error(static::ERR_DISPOSABLE);
        }

        // An MX check here means a typo like @gmial.com never reaches the
        // database, so it can never become a bounce against our reputation.
        if (
            Newsletter::option('optin.validateMx', true) === true
            && EmailAddress::hasMx($email) === false
        ) {
            return Result::error(static::ERR_MX);
        }

        // 3. Lists.
        $lists = Lists::findMany($listIdentifiers);

        if ($lists === []) {
            return Result::error(static::ERR_LISTS);
        }

        // The browser `required` attribute is not the check. A form post
        // without the checkbox is refused before any row is written.
        $source = (string)($data['source'] ?? 'form');
        $consentGiven = in_array($data['consent'] ?? false, [true, 1, '1', 'on', 'true'], true);

        if ($source === 'form' && $consentGiven === false) {
            return Result::error(static::ERR_CONSENT);
        }

        // 4. Suppression gate. Reported as generic success for the same reason
        // as the bot branch: a suppressed address must not be discoverable.
        if (Suppressions::isSuppressed($email) === true) {
            Log::info('Signup blocked by suppression', ['email' => EmailAddress::mask($email)]);

            return Result::ok(static::OK_PENDING);
        }

        // 5. Rate limit per IP.
        if (static::isRateLimited($data['ip'] ?? null) === true) {
            return Result::error(static::ERR_RATE);
        }

        // 6. Persist.
        $subscriber = Subscribers::upsert($email, [
            'name'         => static::clean($data['name'] ?? null, 200),
            'language'     => $data['language'] ?? null,
            'source'       => $data['source'] ?? 'form',
            'sourceDetail' => static::clean($data['sourceDetail'] ?? null, 255),
            'ip'           => $data['ip'] ?? null,
            'ua'           => $data['ua'] ?? null,
            'meta'         => is_array($data['meta'] ?? null) ? $data['meta'] : [],
        ]);

        $pending = [];
        $active  = [];
        $multiple = count($lists) > 1;
        $consentUrl = Consent::url(is_string($data['consentUrl'] ?? null) ? $data['consentUrl'] : null);

        foreach ($lists as $list) {
            $uuid = Lists::uuid($list);
            $record = Consent::record($list, $source === '' ? Consent::SOURCE_FORM : $source, $multiple, $consentUrl);

            if (Lists::requiresOptin($list) === false) {
                Subscriptions::activate($subscriber->id, $uuid, $data['language'] ?? null);
                Subscriptions::stampConsent($subscriber->id, $uuid, $record);
                $active[] = $list;

                continue;
            }

            if (Subscriptions::canResend($subscriber->id, $uuid) === false) {
                // Silently skip. The cap exists so the form cannot be used to
                // bomb someone's inbox by submitting their address repeatedly.
                Log::info('Opt-in resend cap reached', [
                    'subscriber' => $subscriber->id,
                    'list'       => $uuid,
                ]);

                continue;
            }

            $token = Subscriptions::requestOptin(
                $subscriber->id,
                $uuid,
                $data['language'] ?? null,
                $data['ip'] ?? null
            );

            if ($token !== null) {
                Subscriptions::stampConsent($subscriber->id, $uuid, $record);
                $pending[] = ['list' => $list, 'token' => $token];
            }
        }

        Subscribers::syncStatus($subscriber->id);

        // 7. Send one confirmation per list awaiting it.
        $sent = 0;

        foreach ($pending as $entry) {
            if (static::sendOptin($subscriber, $entry['list'], $entry['token'], $data['language'] ?? null) === true) {
                $sent++;
            }
        }

        if ($pending !== [] && $sent === 0) {
            return Result::error(static::ERR_MAIL);
        }

        return Result::ok(
            $pending === [] && $active !== [] ? static::OK_SUBSCRIBED : static::OK_PENDING,
            ['subscriber' => $subscriber->id]
        );
    }

    /**
     * Confirms a double opt-in token.
     */
    public static function confirm(string $token, string|null $ip = null, string|null $ua = null): Result
    {
        $token = trim($token);

        if ($token === '' || ctype_xdigit($token) === false) {
            return Result::error(static::ERR_TOKEN);
        }

        $confirmed = Subscriptions::confirm($token, $ip);

        if ($confirmed === null) {
            // Cannot distinguish "never existed" from "expired" without a
            // second lookup, and saying "expired" for an unknown token would
            // confirm that the token format is right. Report expiry, which is
            // the overwhelmingly common real case, and offer a resubscribe link.
            return Result::error(static::ERR_EXPIRED);
        }

        $subscriberId = $confirmed['subscriberId'];
        $subscriber   = Subscribers::find($subscriberId);

        if ($subscriber === null) {
            return Result::error(static::ERR_TOKEN);
        }

        // Record the person-level consent evidence the first time they confirm
        // anything. This is the GDPR Art. 7(1) / UWG Art. 3 lit. o proof.
        if ($subscriber->confirmedAt === null) {
            Subscribers::confirm($subscriberId, $ip, $ua);
        } else {
            Subscribers::syncStatus($subscriberId);
        }

        $list = Lists::find($confirmed['subscription']->listUuid);
        $subscription = $confirmed['subscription'];

        // A hash written at signup is the sentence they saw then, so it stays.
        // Reconsent and a preference-centre opt-in leave the hash empty on
        // purpose; confirming is what records the wording now on the list.
        if ($subscription !== null && $subscription->consentTextHash === null && $list !== null) {
            $source = $subscription->consentSource;

            if ($source === null || $source === '') {
                $source = Consent::SOURCE_RECONSENT;
            }

            Subscriptions::stampConsent($subscriberId, $subscription->listUuid, [
                'hash'   => Consent::hash(Consent::sentence($list, false)),
                'url'    => Consent::url($subscription->consentUrl),
                'source' => $source,
            ]);
        }

        Log::info('Subscription confirmed', [
            'subscriber' => $subscriberId,
            'list'       => $confirmed['subscription']->listUuid,
        ]);

        return Result::ok(static::OK_CONFIRMED, [
            'subscriber' => $subscriberId,
            'list'       => $list,
            'language'   => $confirmed['subscription']->language,
        ]);
    }

    /**
     * Unsubscribes from an HMAC token.
     *
     * @param string $scope 'all', or a specific list UUID
     */
    public static function unsubscribe(
        string $token,
        string $reason = Subscription::REASON_LINK,
    ): Result {
        $payload = Token::verify('unsub', $token);

        if ($payload === null) {
            return Result::error(static::ERR_TOKEN);
        }

        $subscriber = Subscribers::find($payload['id']);

        if ($subscriber === null) {
            // The person was erased. Their unsubscribe link still "works" as
            // far as they are concerned — there is nothing left to mail.
            return Result::ok(static::OK_UNSUBSCRIBED);
        }

        $scope = $payload['scope'];
        $affected = 0;
        $listUuid = null;

        // `c:<campaignId>` leaves every list that send was frozen with, and
        // no list that was not on it.
        if (preg_match('/^c:(\d+)$/', $scope, $match) === 1 || preg_match('/^c(\d+)$/', $scope, $match) === 1) {
            $campaignId = (int)$match[1];
            $lists = Database::query(
                'SELECT list_uuid FROM nl_campaign_lists WHERE campaign_id = :id',
                ['id' => $campaignId]
            );

            foreach ($lists as $row) {
                $affected += Subscriptions::unsubscribe(
                    $subscriber->id,
                    (string)$row['list_uuid'],
                    $reason,
                    $campaignId
                );
            }
        } else {
            $listUuid = $scope === 'all' ? null : $scope;
            $affected = Subscriptions::unsubscribe($subscriber->id, $listUuid, $reason);
        }

        Log::info('Unsubscribed', [
            'subscriber' => $subscriber->id,
            'scope'      => $scope,
            'reason'     => $reason,
            'changed'    => $affected,
        ]);

        return Result::ok(static::OK_UNSUBSCRIBED, [
            'subscriber' => $subscriber->id,
            'list'       => $listUuid === null ? null : Lists::find($listUuid),
            'language'   => $payload['language'],
            'changed'    => $affected,
        ]);
    }

    /**
     * Loads the preference-centre state for a token.
     *
     * @return Result with data: subscriber, subscriptions, lists
     */
    public static function preferences(string $token): Result
    {
        $payload = Token::verify('prefs', $token);

        if ($payload === null) {
            return Result::error(static::ERR_TOKEN);
        }

        $subscriber = Subscribers::find($payload['id']);

        if ($subscriber === null) {
            return Result::error(static::ERR_TOKEN);
        }

        $subscriptions = [];

        foreach (Subscriptions::forSubscriber($subscriber->id) as $subscription) {
            $subscriptions[$subscription->listUuid] = $subscription;
        }

        return Result::ok('ok', [
            'subscriber'    => $subscriber,
            'subscriptions' => $subscriptions,
            'language'      => $payload['language'],
        ]);
    }

    /**
     * Applies a preference-centre update.
     *
     * @param array<int, string> $listIdentifiers the lists that should remain active
     */
    public static function updatePreferences(
        string $token,
        array $listIdentifiers,
        string|null $language = null,
    ): Result {
        $state = static::preferences($token);

        if ($state->ok === false) {
            return $state;
        }

        /** @var Subscriber $subscriber */
        $subscriber = $state->data['subscriber'];

        $wanted = [];

        foreach (Lists::findMany($listIdentifiers) as $list) {
            $wanted[] = Lists::uuid($list);
        }

        // Only ever touch lists the person could actually see, so a crafted
        // request cannot subscribe them to a private segment.
        foreach (Lists::public() as $list) {
            $uuid = Lists::uuid($list);
            $existing = Subscriptions::find($subscriber->id, $uuid);

            if (in_array($uuid, $wanted, true) === true) {
                if ($existing !== null && $existing->isActive() === true) {
                    continue;
                }

                // A list they are not already on does not become active just
                // because they ticked it. Opt-in lists wait for the
                // confirmation mail; lists that do not require it store the
                // preference-centre wording as the consent record.
                if (Lists::requiresOptin($list) === true) {
                    if (
                        $existing !== null
                        && $existing->isPending() === true
                        && $existing->isOptinExpired() === false
                    ) {
                        continue;
                    }

                    $token = Subscriptions::requestOptin($subscriber->id, $uuid, $language);

                    if ($token !== null) {
                        Subscriptions::stampConsent($subscriber->id, $uuid, [
                            'hash'   => null,
                            'url'    => Consent::url(),
                            'source' => Consent::SOURCE_PREFERENCES,
                        ]);
                        static::sendOptin($subscriber, $list, $token, $language);
                    }

                    continue;
                }

                Subscriptions::activate($subscriber->id, $uuid, $language);
                Subscriptions::stampConsent(
                    $subscriber->id,
                    $uuid,
                    Consent::record($list, Consent::SOURCE_PREFERENCES, false, Consent::url())
                );

                continue;
            }

            Subscriptions::unsubscribe($subscriber->id, $uuid, Subscription::REASON_LINK);
        }

        if ($language !== null) {
            Subscribers::upsert($subscriber->emailNorm, ['language' => $language]);
        }

        Subscribers::syncStatus($subscriber->id);

        return Result::ok(static::OK_UPDATED, ['subscriber' => $subscriber->id]);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Honeypot and time-trap.
     *
     * Neither is the real gate — the confirmation mail is — but together they
     * remove most naive form spam before it ever reaches the database.
     */
    protected static function looksAutomated(array $data): bool
    {
        $honeypot = (string)Newsletter::option('optin.honeypot', 'website');

        if ($honeypot !== '' && trim((string)($data[$honeypot] ?? $data['honeypot'] ?? '')) !== '') {
            return true;
        }

        $minimum = (int)Newsletter::option('optin.minFillSeconds', 3);

        if ($minimum > 0 && ($data['startedAt'] ?? null) !== null) {
            $started = static::decodeStartedAt((string)$data['startedAt']);

            // A missing or unreadable stamp is not treated as a bot: a visitor
            // with a cached page or a stripped field would be locked out.
            if ($started !== null && (time() - $started) < $minimum) {
                return true;
            }
        }

        return false;
    }

    /**
     * Signs the form-render timestamp so it cannot simply be back-dated.
     */
    public static function encodeStartedAt(int|null $time = null): string
    {
        $time = $time ?? time();

        return $time . '.' . substr(hash_hmac('sha256', 'form|' . $time, Secret::key()), 0, 16);
    }

    protected static function decodeStartedAt(string $value): int|null
    {
        $parts = explode('.', $value);

        if (count($parts) !== 2 || ctype_digit($parts[0]) === false) {
            return null;
        }

        $expected = substr(hash_hmac('sha256', 'form|' . $parts[0], Secret::key()), 0, 16);

        return hash_equals($expected, $parts[1]) === true ? (int)$parts[0] : null;
    }

    /**
     * Per-IP signup rate limit, counted from the signup timestamps already in
     * the database rather than a separate counter table.
     */
    protected static function isRateLimited(string|null $ip): bool
    {
        $limit = (int)Newsletter::option('optin.rateLimitPerHour', 10);

        if ($limit <= 0 || $ip === null) {
            return false;
        }

        $stored = Subscribers::ip($ip);

        if ($stored === null) {
            // IP storage is disabled, so there is nothing to count against.
            return false;
        }

        $count = (int)Database::value(
            'SELECT COUNT(*) FROM nl_subscribers WHERE signup_ip = :ip AND signup_at > :since',
            ['ip' => $stored, 'since' => time() - 3600]
        );

        return $count >= $limit;
    }

    public static function sendOptin(
        Subscriber $subscriber,
        Page $list,
        string $token,
        string|null $language,
    ): bool {
        $language = Newsletter::language($language ?? $subscriber->language);

        return Newsletter::withLanguage($language, static function () use ($subscriber, $list, $token, $language): bool {
            $url = Routes::url('confirm', $token, $language);

            return Mailer::send(
                to: $subscriber->email,
                subject: t(
                    'e9li.newsletter.optin.subject',
                    'Please confirm your subscription'
                ) . ' – ' . Lists::title($list, $language),
                template: 'optin',
                data: [
                    'subscriber' => $subscriber,
                    'list'       => $list,
                    'listTitle'  => Lists::title($list, $language),
                    'url'        => $url,
                    'expires'    => time() + (int)Newsletter::option('optin.ttl', 604800),
                ],
                language: $language,
            );
        });
    }

    protected static function clean(string|null $value, int $length): string|null
    {
        if ($value === null) {
            return null;
        }

        // Strip control characters — a newline in a name would end up in a
        // header later on, which is a classic injection route.
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $length, 'UTF-8');
    }
}

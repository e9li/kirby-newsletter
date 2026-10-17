<?php

namespace E9li\Newsletter\Send;

use E9li\Newsletter\Database;
use E9li\Newsletter\EmailAddress;
use E9li\Newsletter\Lists;
use E9li\Newsletter\Mailer;
use E9li\Newsletter\Newsletter;
use E9li\Newsletter\Render\Document;
use Kirby\Cms\Page;
use Kirby\Filesystem\F;
use Kirby\Toolkit\Str;

/**
 * Freezes a campaign and fills the queue. Does not send.
 *
 * One row per language, one queue row per subscriber even if they sit on
 * two of the campaign's lists — the unique index does that with INSERT OR IGNORE.
 * The message id is minted here so a later retry reuses it.
 */
final class Seeder
{
    /**
     * @return array{ok: bool, error: string|null, sendUuid: string|null, campaigns: array<int, int>, queued: int}
     */
    public static function seed(Page $page): array
    {
        $from = Mailer::from();

        if ($from === null) {
            return static::fail('No sender address is configured.');
        }

        $lists = [];

        foreach ($page->newsletterLists()->toPages() as $list) {
            if (Lists::isList($list) === true) {
                $lists[] = Lists::uuid($list);
            }
        }

        if ($lists === []) {
            return static::fail('The campaign has no lists.');
        }

        $languages = static::languages($page);
        $sendUuid  = Str::uuid();
        $track     = Newsletter::option('track.opens', false) === true;
        $campaigns = [];
        $queued    = 0;

        foreach ($languages as $language) {
            $rendered = Document::render($page, $language, $track);
            $key      = static::write($sendUuid, $language, $rendered['html'], $rendered['text']);
            $code     = $language ?? '';

            $subject = Newsletter::withLanguage($language, static fn (): string => trim($page->newsletterSubject()->value()));

            if ($subject === '') {
                return static::fail('The campaign has no subject.');
            }

            $id = Database::insert('nl_campaigns', [
                'send_uuid'     => $sendUuid,
                'page_uuid'     => $page->uuid()?->toString() ?? $page->id(),
                'page_id'       => $page->id(),
                'language'      => $language,
                'subject'       => $subject,
                'preheader'     => Newsletter::withLanguage($language, static fn (): string => $page->newsletterPreheader()->value()) ?: null,
                'from_email'    => $from,
                'from_name'     => Newsletter::option('fromName'),
                'reply_to'      => Newsletter::option('replyTo') ?? $from,
                'theme_uuid'    => $page->newsletterTemplate()->toPage()?->uuid()?->toString(),
                'artifact_key'  => $key,
                'status'        => 'sending',
                'started_at'    => time(),
                'total'         => 0,
                'sent_count'    => 0,
                'failed_count'  => 0,
                'transport'     => Transport::name(),
                'rate_per_hour' => (int)Newsletter::option('rate.perHour', 600),
                'track_opens'   => $track === true ? 1 : 0,
                'track_clicks'  => 0,
                'is_test'       => 0,
                'created_by'    => kirby()->user()?->id(),
                'created_at'    => time(),
                'updated_at'    => time(),
            ]);

            foreach ($lists as $uuid) {
                Database::execute(
                    'INSERT OR IGNORE INTO nl_campaign_lists (campaign_id, list_uuid) VALUES (:id, :list)',
                    ['id' => $id, 'list' => $uuid]
                );
            }

            $added = static::enqueue($id, $lists, $key, $code);
            Database::update('nl_campaigns', [
                'total' => $added,
            ], 'id = :id', ['id' => $id]);

            $campaigns[] = $id;
            $queued += $added;
        }

        if ($queued === 0) {
            return static::fail('No active subscribers left after the suppression list.');
        }

        return [
            'ok'        => true,
            'error'     => null,
            'sendUuid'  => $sendUuid,
            'campaigns' => $campaigns,
            'queued'    => $queued,
        ];
    }

    /**
     * Queues a reconsent run: pending rows on one list whose consent was cleared.
     * The artifact is the opt-in mail, and the same throttle sends it.
     *
     * @return array{ok: bool, error: string|null, sendUuid: string|null, campaigns: array<int, int>, queued: int}
     */
    public static function reconsent(Page $list): array
    {
        if (Lists::isList($list) === false) {
            return static::fail('That page is not a list.');
        }

        $from = Mailer::from();

        if ($from === null) {
            return static::fail('No sender address is configured.');
        }

        $uuid     = Lists::uuid($list);
        $sendUuid = Str::uuid();
        $language = Newsletter::defaultLanguage();
        $rendered = static::optinDocument($list, $language);
        $key      = static::write($sendUuid, $language, $rendered['html'], $rendered['text']);

        $id = Database::insert('nl_campaigns', [
            'send_uuid'    => $sendUuid,
            'page_uuid'    => $list->uuid()?->toString() ?? $list->id(),
            'page_id'      => $list->id(),
            'language'     => $language,
            'subject'      => $rendered['subject'],
            'preheader'    => null,
            'from_email'   => $from,
            'from_name'    => Newsletter::option('fromName'),
            'reply_to'     => Newsletter::option('replyTo') ?? $from,
            'artifact_key' => $key,
            'status'       => 'sending',
            'started_at'   => time(),
            'total'        => 0,
            'transport'    => Transport::name(),
            'rate_per_hour' => (int)Newsletter::option('rate.perHour', 600),
            'track_opens'  => 0,
            'track_clicks' => 0,
            'is_test'      => 0,
            'created_at'   => time(),
            'updated_at'   => time(),
        ]);

        Database::execute(
            'INSERT OR IGNORE INTO nl_campaign_lists (campaign_id, list_uuid) VALUES (:id, :list)',
            ['id' => $id, 'list' => $uuid]
        );

        $added = static::enqueue($id, [$uuid], $key, null, true);
        Database::update('nl_campaigns', ['total' => $added], 'id = :id', ['id' => $id]);

        if ($added === 0) {
            return static::fail('Nobody on this list is waiting for fresh consent.');
        }

        return [
            'ok'        => true,
            'error'     => null,
            'sendUuid'  => $sendUuid,
            'campaigns' => [$id],
            'queued'    => $added,
        ];
    }

    /**
     * Inserts queue rows for the active (or reconsent-pending) subscribers
     * on the given lists. One row per person.
     *
     * `$language` null means "every language" — used by the reconsent run,
     * which is a single opt-in artifact. An empty string means the
     * single-language site.
     */
    public static function enqueue(
        int $campaignId,
        array $listUuids,
        string $artifactKey,
        string|null $language,
        bool $reconsent = false,
    ): int {
        if ($listUuids === []) {
            return 0;
        }

        $bindings = ['now' => time()];
        $holders  = [];

        foreach (array_values($listUuids) as $index => $uuid) {
            $key = 'l' . $index;
            $holders[] = ':' . $key;
            $bindings[$key] = $uuid;
        }

        $status = $reconsent === true ? 'pending' : 'active';
        $sql = 'SELECT DISTINCT s.id, s.email_norm, s.name, s.email_hash,
                       COALESCE(sub.language, s.language, :fallback) AS lang
                  FROM nl_subscribers s
                  JOIN nl_subscriptions sub ON sub.subscriber_id = s.id
                 WHERE sub.list_uuid IN (' . implode(', ', $holders) . ')
                   AND sub.status = :status
                   AND NOT EXISTS (
                       SELECT 1 FROM nl_suppressions sup
                        WHERE sup.email_hash = s.email_hash
                          AND (sup.expires_at IS NULL OR sup.expires_at > :now)
                   )';

        $bindings['status']   = $status;
        $bindings['fallback'] = Newsletter::defaultLanguage() ?? '';

        if ($reconsent === true) {
            $sql .= ' AND sub.consent_source IS NULL';
        } elseif ($language !== null) {
            $sql .= ' AND COALESCE(sub.language, s.language, :fallback) = :lang';
            $bindings['lang'] = $language;
        }

        if ($reconsent === false) {
            $sql .= ' AND s.status = "active"';
        }

        $rows  = Database::query($sql, $bindings);
        $added = 0;
        $now   = time();
        $domain = Mailer::sendingDomain() ?? 'localhost';

        foreach ($rows as $row) {
            $email = (string)$row['email_norm'];
            $host  = EmailAddress::domain($email);

            if ($host === '') {
                continue;
            }

            $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';

            Database::execute(
                'INSERT OR IGNORE INTO nl_queue
                    (campaign_id, subscriber_id, email, name, language, domain, list_uuid,
                     artifact_key, message_id, status, attempts, available_at, priority, created_at, updated_at)
                 VALUES
                    (:campaign, :subscriber, :email, :name, :language, :domain, NULL,
                     :artifact, :message, "queued", 0, :available, 0, :created, :updated)',
                [
                    'campaign'   => $campaignId,
                    'subscriber' => (int)$row['id'],
                    'email'      => $email,
                    'name'       => $row['name'],
                    'language'   => $row['lang'] === '' ? null : $row['lang'],
                    'domain'     => $host,
                    'artifact'   => $artifactKey,
                    'message'    => $messageId,
                    'available'  => $now,
                    'created'    => $now,
                    'updated'    => $now,
                ]
            );

            if (Database::affected() === 1) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * Latest send for a campaign page, summed across its languages.
     *
     * @return array{sendUuid: string, total: int, sent: int, failed: int, opened: int, tracked: bool, ids: array<int, int>}|null
     */
    public static function latest(string $pageUuid): array|null
    {
        try {
            $row = Database::first(
                'SELECT send_uuid FROM nl_campaigns WHERE page_uuid = :uuid ORDER BY id DESC LIMIT 1',
                ['uuid' => $pageUuid]
            );
        } catch (\Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $rows = Database::query(
            'SELECT id, total, sent_count, failed_count, track_opens FROM nl_campaigns WHERE send_uuid = :send',
            ['send' => $row['send_uuid']]
        );

        if ($rows === []) {
            return null;
        }

        $ids     = [];
        $total   = 0;
        $sent    = 0;
        $failed  = 0;
        $tracked = false;

        foreach ($rows as $item) {
            $ids[] = (int)$item['id'];
            $total += (int)$item['total'];
            $sent += (int)$item['sent_count'];
            $failed += (int)$item['failed_count'];
            $tracked = $tracked || (int)$item['track_opens'] === 1;
        }

        $opened = 0;

        if ($tracked === true && $ids !== []) {
            $holders = [];
            $bindings = [];

            foreach ($ids as $index => $id) {
                $key = 'c' . $index;
                $holders[] = ':' . $key;
                $bindings[$key] = $id;
            }

            $opened = (int)Database::value(
                'SELECT COUNT(*) FROM nl_events WHERE type = "open" AND campaign_id IN (' . implode(', ', $holders) . ')',
                $bindings
            );
        }

        return [
            'sendUuid' => (string)$row['send_uuid'],
            'total'    => $total,
            'sent'     => $sent,
            'failed'   => $failed,
            'opened'   => $opened,
            'tracked'  => $tracked,
            'ids'      => $ids,
        ];
    }

    /**
     * @param array{html: string, text: string} $rendered
     */
    public static function write(string $sendUuid, string|null $language, string $html, string $text): string
    {
        $code = $language === null || $language === '' ? 'default' : $language;
        $dir  = Newsletter::root() . '/artifacts/' . $sendUuid;

        if (is_dir($dir) === false) {
            \Kirby\Filesystem\Dir::make($dir, true);
        }

        F::write($dir . '/' . $code . '.html', $html);
        F::write($dir . '/' . $code . '.txt', $text);

        return 'artifacts/' . $sendUuid . '/' . $code . '.html';
    }

    /**
     * @return array<int, string|null>
     */
    public static function languages(Page $page): array
    {
        $kirby = kirby();

        if ($kirby->multilang() === false) {
            return [null];
        }

        $codes = [];

        foreach ($kirby->languages() as $language) {
            if ($page->translation($language->code())->exists() === true) {
                $codes[] = $language->code();
            }
        }

        return $codes === [] ? [Newsletter::defaultLanguage()] : $codes;
    }

    /**
     * Opt-in mail with the confirm link left as a placeholder.
     * The runner mints the single-use token when the row is actually sent.
     *
     * @return array{html: string, text: string, subject: string}
     */
    protected static function optinDocument(Page $list, string|null $language): array
    {
        return Newsletter::withLanguage($language, static function () use ($list): array {
            $title   = Lists::title($list, Newsletter::defaultLanguage());
            $subject = t('e9li.newsletter.optin.subject', 'Please confirm your subscription') . ' – ' . $title;
            $font    = Theme::FONTS['system'];

            $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f4f5;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:32px 12px;">'
                . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;background:#ffffff;"><tr>'
                . '<td style="padding:32px;font-family:' . $font . ';font-size:16px;line-height:26px;color:#18181b;">'
                . '<p>' . esc(t('e9li.newsletter.optin.greeting', 'Hello')) . ' {{name}}</p>'
                . '<p>' . esc(tt('e9li.newsletter.optin.intro', 'Please confirm that you would like to receive “{list}”.', ['list' => $title])) . '</p>'
                . '<p><a href="{{confirm_url}}">' . esc(t('e9li.newsletter.optin.button', 'Confirm subscription')) . '</a></p>'
                . '<p>{{confirm_url}}</p>'
                . '</td></tr></table></td></tr></table></body></html>';

            $text = t('e9li.newsletter.optin.greeting', 'Hello') . ' {{name}}' . "\n\n"
                . tt('e9li.newsletter.optin.intro', 'Please confirm that you would like to receive “{list}”.', ['list' => $title]) . "\n\n"
                . '{{confirm_url}}' . "\n";

            return [
                'html'    => $html,
                'text'    => $text,
                'subject' => $subject,
            ];
        });
    }

    /**
     * @return array{ok: bool, error: string, sendUuid: null, campaigns: array<int, int>, queued: int}
     */
    protected static function fail(string $error): array
    {
        return [
            'ok'        => false,
            'error'     => $error,
            'sendUuid'  => null,
            'campaigns' => [],
            'queued'    => 0,
        ];
    }
}

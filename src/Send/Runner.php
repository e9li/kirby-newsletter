<?php

namespace E9li\Newsletter\Send;

use E9li\Newsletter\Database;
use E9li\Newsletter\EmailAddress;
use E9li\Newsletter\Mailer;
use E9li\Newsletter\Newsletter;
use E9li\Newsletter\Render\Document;
use E9li\Newsletter\Repository\Subscriptions;
use E9li\Newsletter\Routes;
use E9li\Newsletter\Token;
use Kirby\Cms\Page;
use Kirby\Filesystem\F;

/**
 * `kirby newsletter:tick`.
 *
 * Claims a small batch, sends it on one connection, and stops when the
 * wall-clock budget or the hourly rate says so. A second tick must not
 * send a row that the first tick already marked sent.
 */
final class Runner
{
    /**
     * @return array{sent: int, failed: int, claimed: int}
     */
    public static function tick(): array
    {
        Newsletter::bootstrap();

        $started = microtime(true);
        $budget  = max(1, (int)Newsletter::option('tick.maxSeconds', 50));
        $worker  = (string)getmypid() . '-' . bin2hex(random_bytes(3));
        $ids     = static::claim($worker, static::batchSize());
        $transport = Transport::resolve();
        $sent    = 0;
        $failed  = 0;
        $left    = $ids;

        try {
            foreach ($ids as $id) {
                array_shift($left);

                if ((microtime(true) - $started) >= $budget || static::allow() === false) {
                    static::release($id);

                    foreach ($left as $rest) {
                        static::release((int)$rest);
                    }

                    break;
                }

                if (static::deliver((int)$id, $transport) === true) {
                    $sent++;
                } else {
                    $failed++;
                }

                if ($left !== []) {
                    static::pause();

                    if ((microtime(true) - $started) >= $budget) {
                        foreach ($left as $rest) {
                            static::release((int)$rest);
                        }

                        break;
                    }
                }
            }
        } finally {
            $transport->close();
        }

        return [
            'sent'    => $sent,
            'failed'  => $failed,
            'claimed' => count($ids),
        ];
    }

    /**
     * Renders the campaign and hands it to the transport for the given
     * addresses. No queue row and no last_sent_at.
     *
     * @param array<int, string> $recipients
     * @return array{sent: int, failed: array<int, string>}
     */
    public static function test(Page $page, array $recipients): array
    {
        $rendered = Document::render($page);
        $from     = Mailer::from();
        $sent     = 0;
        $failed   = [];
        $transport = Transport::resolve();
        $domain   = Mailer::sendingDomain() ?? 'localhost';
        $site     = rtrim((string)kirby()->url(), '/');

        try {
            foreach ($recipients as $recipient) {
                $recipient = trim($recipient);

                if (EmailAddress::isValid($recipient) === false) {
                    $failed[] = $recipient;

                    continue;
                }

                $values = [
                    'name'            => 'Ada',
                    'email'           => $recipient,
                    'unsubscribe_url' => $site . '/newsletter/u/preview',
                    'preferences_url' => $site . '/newsletter/preferences/preview',
                    'web_url'         => $site,
                    'list_reason'     => Document::reasons($page),
                    'open_url'        => $site . '/newsletter/o/preview',
                    'confirm_url'     => $site . '/newsletter/confirm/preview',
                ];

                $message = new Message(
                    to: $recipient,
                    toName: null,
                    from: (string)$from,
                    fromName: is_string(Newsletter::option('fromName')) ? Newsletter::option('fromName') : null,
                    replyTo: is_string(Newsletter::option('replyTo')) ? Newsletter::option('replyTo') : $from,
                    subject: trim($page->newsletterSubject()->value()),
                    html: Document::fill($rendered['html'], $values, true),
                    text: Document::fill($rendered['text'], $values, false),
                    messageId: '<test-' . bin2hex(random_bytes(8)) . '@' . $domain . '>',
                    headers: [
                        'List-Unsubscribe'      => '<' . $values['unsubscribe_url'] . '>',
                        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                    ],
                );

                $result = $transport->send($message);

                if ($result->ok === true) {
                    $sent++;
                } else {
                    $failed[] = $recipient;
                }
            }
        } finally {
            $transport->close();
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * @return array<int, int>
     */
    public static function claim(string $worker, int $batch): array
    {
        $now      = time();
        $deadline = $now - max(30, (int)Newsletter::option('tick.claimTimeout', 900));
        $batch    = max(1, $batch);
        $claimed  = [];

        Database::transaction(function () use ($worker, $batch, $now, $deadline, &$claimed): void {
            Database::execute(
                'UPDATE nl_queue
                    SET status = "queued", worker = NULL, claimed_at = NULL, updated_at = :now
                  WHERE status = "claimed" AND claimed_at IS NOT NULL AND claimed_at < :deadline',
                ['now' => $now, 'deadline' => $deadline]
            );

            $rows = Database::query(
                'SELECT q.id
                   FROM nl_queue q
                  WHERE q.status = "queued"
                    AND q.available_at <= :now
                    AND NOT EXISTS (
                        SELECT 1 FROM nl_domain_state d
                         WHERE d.domain = q.domain
                           AND d.defer_until IS NOT NULL
                           AND d.defer_until > :now
                    )
                  ORDER BY q.priority DESC, q.id ASC
                  LIMIT ' . $batch,
                ['now' => $now]
            );

            foreach ($rows as $row) {
                $affected = Database::update('nl_queue', [
                    'status'     => 'claimed',
                    'worker'     => $worker,
                    'claimed_at' => $now,
                ], 'id = :id AND status = "queued"', ['id' => (int)$row['id']]);

                if ($affected === 1) {
                    $claimed[] = (int)$row['id'];
                }
            }
        });

        return $claimed;
    }

    public static function deliver(int $queueId, Transport $transport): bool
    {
        $row = Database::first(
            'SELECT q.*, c.subject, c.from_email, c.from_name, c.reply_to, c.track_opens
               FROM nl_queue q
               JOIN nl_campaigns c ON c.id = q.campaign_id
              WHERE q.id = :id',
            ['id' => $queueId]
        );

        if ($row === null) {
            return false;
        }

        $htmlPath = Newsletter::root() . '/' . ltrim((string)$row['artifact_key'], '/');
        $textPath = preg_replace('/\.html$/', '.txt', $htmlPath) ?: $htmlPath;
        $html     = is_file($htmlPath) ? (string)F::read($htmlPath) : '';
        $text     = is_file($textPath) ? (string)F::read($textPath) : '';

        if ($html === '' && $text === '') {
            static::fail($row, 'Missing rendered message', null);

            return false;
        }

        $subscriberId = (int)$row['subscriber_id'];
        $campaignId   = (int)$row['campaign_id'];
        $language     = $row['language'] !== null ? (string)$row['language'] : null;
        $site         = rtrim((string)kirby()->url(), '/');
        $unsub        = Routes::url('unsubscribe', Token::create('unsub', $subscriberId, $language, 'c:' . $campaignId), $language);
        $prefs        = Routes::url('preferences', Token::create('prefs', $subscriberId, $language, 'all'), $language);

        $values = [
            'name'            => static::name($row),
            'email'           => (string)$row['email'],
            'unsubscribe_url' => $unsub,
            'preferences_url' => $prefs,
            'web_url'         => $site,
            'list_reason'     => Document::reasonsFor(static::reasonsLists($campaignId, $subscriberId)),
            'open_url'        => '',
            'confirm_url'     => '',
        ];

        if ((int)$row['track_opens'] === 1) {
            $values['open_url'] = Routes::url(
                'open',
                Token::create('open', $queueId, $language, 'q'),
                $language
            );
        }

        if (str_contains($html, '{{confirm_url}}') === true || str_contains($text, '{{confirm_url}}') === true) {
            $values['confirm_url'] = static::confirmUrl($campaignId, $subscriberId, $language);
        }

        $listId = Newsletter::option('listId');

        if (is_string($listId) === false || $listId === '') {
            $listId = (Mailer::sendingDomain() ?? 'localhost') . '.newsletter';
        }

        $message = new Message(
            to: (string)$row['email'],
            toName: $row['name'] !== null ? (string)$row['name'] : null,
            from: (string)$row['from_email'],
            fromName: $row['from_name'] !== null ? (string)$row['from_name'] : null,
            replyTo: $row['reply_to'] !== null ? (string)$row['reply_to'] : null,
            subject: (string)$row['subject'],
            html: Document::fill($html, $values, true),
            text: Document::fill($text, $values, false),
            messageId: (string)$row['message_id'],
            headers: [
                'List-Unsubscribe'      => '<' . $unsub . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                'List-Id'               => '<' . Transport::header((string)$listId) . '>',
            ],
        );

        $result = $transport->send($message);

        if ($result->ok === true) {
            $now = time();

            Database::update('nl_queue', [
                'status'     => 'sent',
                'sent_at'    => $now,
                'worker'     => null,
                'claimed_at' => null,
                'error'      => null,
                'error_code' => null,
            ], 'id = :id', ['id' => $queueId]);

            Database::execute(
                'UPDATE nl_campaigns SET sent_count = sent_count + 1, updated_at = :now WHERE id = :id',
                ['now' => $now, 'id' => $campaignId]
            );

            Database::execute(
                'UPDATE nl_subscribers SET last_sent_at = :now, updated_at = :now WHERE id = :id',
                ['now' => $now, 'id' => $subscriberId]
            );

            static::event($campaignId, $queueId, $subscriberId, (string)$row['email'], 'delivered');
            static::finish($campaignId);

            return true;
        }

        if ($result->defersDomain() === true) {
            static::defer((string)$row['domain'], $result->code, $result->error);
        }

        static::fail($row, $result->error ?? 'Send failed', $result->code);

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected static function fail(array $row, string $error, int|null $code): void
    {
        $attempts = (int)$row['attempts'] + 1;
        $max      = max(1, (int)Newsletter::option('queue.maxAttempts', 3));
        $now      = time();
        $id       = (int)$row['id'];

        if ($attempts >= $max) {
            Database::update('nl_queue', [
                'status'     => 'failed',
                'attempts'   => $attempts,
                'worker'     => null,
                'claimed_at' => null,
                'error'      => mb_substr($error, 0, 1000, 'UTF-8'),
                'error_code' => $code === null ? null : (string)$code,
            ], 'id = :id', ['id' => $id]);

            Database::execute(
                'UPDATE nl_campaigns SET failed_count = failed_count + 1, updated_at = :now WHERE id = :id',
                ['now' => $now, 'id' => (int)$row['campaign_id']]
            );

            static::finish((int)$row['campaign_id']);

            return;
        }

        $backoff = Newsletter::option('queue.backoff', [300, 1800, 7200]);
        $backoff = is_array($backoff) === true && $backoff !== [] ? array_values($backoff) : [300];
        $delay   = (int)($backoff[min($attempts - 1, count($backoff) - 1)] ?? 300);

        Database::update('nl_queue', [
            'status'       => 'queued',
            'attempts'     => $attempts,
            'worker'       => null,
            'claimed_at'   => null,
            'available_at' => $now + max(1, $delay),
            'error'        => mb_substr($error, 0, 1000, 'UTF-8'),
            'error_code'   => $code === null ? null : (string)$code,
        ], 'id = :id', ['id' => $id]);
    }

    protected static function finish(int $campaignId): void
    {
        $left = (int)Database::value(
            'SELECT COUNT(*) FROM nl_queue WHERE campaign_id = :id AND status IN ("queued", "claimed")',
            ['id' => $campaignId]
        );

        if ($left > 0) {
            return;
        }

        Database::update('nl_campaigns', [
            'status'      => 'sent',
            'finished_at' => time(),
        ], 'id = :id AND status = "sending"', ['id' => $campaignId]);
    }

    protected static function defer(string $domain, int|null $code, string|null $error): void
    {
        $current = Database::first(
            'SELECT defer_count FROM nl_domain_state WHERE domain = :domain',
            ['domain' => $domain]
        );
        $count = (int)($current['defer_count'] ?? 0);
        $ramp  = Newsletter::option('domain.deferBackoff', [300, 900, 1800, 3600, 7200]);
        $ramp  = is_array($ramp) === true && $ramp !== [] ? array_values($ramp) : [300];
        $wait  = (int)($ramp[min($count, count($ramp) - 1)] ?? 300);
        $now   = time();

        Database::execute(
            'INSERT INTO nl_domain_state (domain, defer_until, defer_count, last_code, last_error, updated_at)
             VALUES (:domain, :until, 1, :code, :error, :now)
             ON CONFLICT(domain) DO UPDATE SET
                defer_count = defer_count + 1,
                defer_until = excluded.defer_until,
                last_code   = excluded.last_code,
                last_error  = excluded.last_error,
                updated_at  = excluded.updated_at',
            [
                'domain' => $domain,
                'until'  => $now + max(1, $wait),
                'code'   => $code === null ? null : (string)$code,
                'error'  => $error === null ? null : mb_substr($error, 0, 1000, 'UTF-8'),
                'now'    => $now,
            ]
        );
    }

    protected static function event(
        int $campaignId,
        int $queueId,
        int $subscriberId,
        string $email,
        string $type,
    ): void {
        Database::insert('nl_events', [
            'campaign_id'   => $campaignId,
            'queue_id'      => $queueId,
            'subscriber_id' => $subscriberId,
            'email_hash'    => EmailAddress::hash($email),
            'type'          => $type,
            'transport'     => Transport::name(),
            'created_at'    => time(),
        ]);
    }

    protected static function confirmUrl(int $campaignId, int $subscriberId, string|null $language): string
    {
        $list = Database::first(
            'SELECT list_uuid FROM nl_campaign_lists WHERE campaign_id = :id LIMIT 1',
            ['id' => $campaignId]
        );

        if ($list === null) {
            return '';
        }

        $token = Subscriptions::requestOptin($subscriberId, (string)$list['list_uuid'], $language);

        if ($token === null) {
            return '';
        }

        return Routes::url('confirm', $token, $language);
    }

    /**
     * @return array<int, string>
     */
    protected static function reasonsLists(int $campaignId, int $subscriberId): array
    {
        $rows = Database::query(
            'SELECT sub.list_uuid
               FROM nl_subscriptions sub
               JOIN nl_campaign_lists cl ON cl.list_uuid = sub.list_uuid
              WHERE cl.campaign_id = :campaign
                AND sub.subscriber_id = :subscriber
                AND sub.status IN ("active", "pending")',
            ['campaign' => $campaignId, 'subscriber' => $subscriberId]
        );

        return array_map(static fn (array $row): string => (string)$row['list_uuid'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected static function name(array $row): string
    {
        $name = trim((string)($row['name'] ?? ''));

        if ($name === '') {
            return explode('@', (string)$row['email'])[0];
        }

        $parts = preg_split('/\s+/u', $name) ?: [$name];

        return $parts[0] !== '' ? $parts[0] : $name;
    }

    protected static function allow(): bool
    {
        $limit = (int)Newsletter::option('rate.perHour', 600);

        if ($limit <= 0) {
            return true;
        }

        $bucket = 'global:' . date('YmdH');
        $count  = (int)Database::value(
            'SELECT count FROM nl_throttle WHERE bucket = :bucket',
            ['bucket' => $bucket]
        );

        if ($count >= $limit) {
            return false;
        }

        Database::execute(
            'INSERT INTO nl_throttle (bucket, count, updated_at) VALUES (:bucket, 1, :now)
             ON CONFLICT(bucket) DO UPDATE SET count = count + 1, updated_at = excluded.updated_at',
            ['bucket' => $bucket, 'now' => time()]
        );

        return true;
    }

    protected static function release(int $id): void
    {
        Database::update('nl_queue', [
            'status'     => 'queued',
            'worker'     => null,
            'claimed_at' => null,
        ], 'id = :id AND status = "claimed"', ['id' => $id]);
    }

    protected static function pause(): void
    {
        $min = (int)Newsletter::option('tick.delayMinMs', 250);
        $max = (int)Newsletter::option('tick.delayMaxMs', 1500);

        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        if ($max <= 0) {
            return;
        }

        usleep(random_int(max(0, $min), $max) * 1000);
    }

    protected static function batchSize(): int
    {
        $base = max(1, (int)Newsletter::option('rate.batchSize', 12));
        $span = max(0, (int)round($base * 0.2));

        if ($span === 0) {
            return $base;
        }

        return max(1, $base + random_int(-$span, $span));
    }
}

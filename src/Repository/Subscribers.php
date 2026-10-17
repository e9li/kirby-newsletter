<?php

namespace E9li\Newsletter\Repository;

use E9li\Newsletter\Database;
use E9li\Newsletter\EmailAddress;
use E9li\Newsletter\Log;
use E9li\Newsletter\Model\Subscriber;
use E9li\Newsletter\Newsletter;

/**
 * Reads and writes `nl_subscribers`.
 *
 * Identity is always `email_norm`, never the raw address and never the id
 * alone, so a second signup with different capitalisation updates the existing
 * person rather than creating a duplicate.
 */
final class Subscribers
{
    /**
     * Columns the Panel may sort by. An allowlist, because the value reaches
     * an ORDER BY clause where it cannot be bound as a parameter.
     */
    public const SORTABLE = [
        'id', 'email_norm', 'name', 'status', 'language',
        'created_at', 'confirmed_at', 'last_sent_at', 'last_open_at',
    ];

    public static function find(int $id): Subscriber|null
    {
        $row = Database::first('SELECT * FROM nl_subscribers WHERE id = :id', ['id' => $id]);

        return $row === null ? null : Subscriber::fromRow($row);
    }

    public static function findByEmail(string $email): Subscriber|null
    {
        $row = Database::first(
            'SELECT * FROM nl_subscribers WHERE email_norm = :norm',
            ['norm' => EmailAddress::normalize($email)]
        );

        return $row === null ? null : Subscriber::fromRow($row);
    }

    public static function findByHash(string $hash): Subscriber|null
    {
        $row = Database::first(
            'SELECT * FROM nl_subscribers WHERE email_hash = :hash',
            ['hash' => $hash]
        );

        return $row === null ? null : Subscriber::fromRow($row);
    }

    /**
     * Creates a subscriber, or updates the existing one with the same
     * normalised address.
     *
     * Returns the subscriber either way. Deliberately does NOT change `status`
     * on an existing row: someone who previously unsubscribed must not be
     * silently reactivated by filling in the form again — that decision belongs
     * to the confirmation step.
     *
     * @param array{name?: string|null, language?: string|null, source?: string|null,
     *              sourceDetail?: string|null, ip?: string|null, ua?: string|null,
     *              meta?: array} $data
     */
    public static function upsert(string $email, array $data = []): Subscriber
    {
        $now      = time();
        $norm     = EmailAddress::normalize($email);
        $existing = static::findByEmail($norm);

        if ($existing !== null) {
            $update = ['updated_at' => $now];

            // Only fill blanks. A form that omits the name must not erase a
            // name captured earlier.
            if (($data['name'] ?? null) !== null && $existing->name === null) {
                $update['name'] = $data['name'];
            }

            if (($data['language'] ?? null) !== null) {
                $update['language'] = Newsletter::language($data['language']);
            }

            if (($data['meta'] ?? null) !== null && $data['meta'] !== []) {
                $update['meta'] = json_encode(
                    [...$existing->meta, ...$data['meta']],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            Database::update('nl_subscribers', $update, 'id = :id', ['id' => $existing->id]);

            return static::find($existing->id);
        }

        $id = Database::insert('nl_subscribers', [
            'email'         => trim($email),
            'email_norm'    => $norm,
            'email_hash'    => EmailAddress::hash($norm),
            'name'          => $data['name'] ?? null,
            'language'      => Newsletter::language($data['language'] ?? null),
            'status'        => Subscriber::STATUS_PENDING,
            'source'        => $data['source'] ?? null,
            'source_detail' => $data['sourceDetail'] ?? null,
            'signup_at'     => $now,
            'signup_ip'     => static::ip($data['ip'] ?? null),
            'signup_ua'     => static::truncate($data['ua'] ?? null, 255),
            'meta'          => ($data['meta'] ?? []) === []
                ? null
                : json_encode($data['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return static::find($id);
    }

    /**
     * Marks a subscriber active and records the confirmation evidence.
     *
     * The IP and timestamp here are the GDPR Art. 7(1) proof that consent was
     * given, and the Swiss UWG Art. 3 lit. o proof that it was given by this
     * person rather than added by us.
     */
    public static function confirm(int $id, string|null $ip = null, string|null $ua = null): void
    {
        $now = time();

        Database::update('nl_subscribers', [
            'status'       => Subscriber::STATUS_ACTIVE,
            'confirmed_at' => $now,
            'confirm_ip'   => static::ip($ip),
            'confirm_ua'   => static::truncate($ua, 255),
            'updated_at'   => $now,
        ], 'id = :id', ['id' => $id]);
    }

    /**
     * Deletes a person, cascades their subscriptions, and detaches events.
     *
     * The hash suppression is what stops a later import from undoing the
     * erasure. Self-service always keeps it; the Panel dialog can decline.
     */
    public static function erase(int $id, bool $keepHash = true): bool
    {
        $subscriber = static::find($id);

        if ($subscriber === null) {
            return false;
        }

        $hash = $subscriber->emailHash;

        Database::delete('nl_subscribers', 'id = :id', ['id' => $id]);

        Database::execute(
            'UPDATE nl_events SET subscriber_id = NULL, email_hash = NULL WHERE subscriber_id = :id',
            ['id' => $id]
        );

        if ($keepHash === true) {
            Suppressions::addHash($hash, Suppressions::REASON_GDPR, 'Erased on request');
        }

        Log::info('Subscriber erased', ['id' => $id, 'keptHash' => $keepHash]);

        return true;
    }

    public static function setStatus(int $id, string $status): void
    {
        $now    = time();
        $update = ['status' => $status, 'updated_at' => $now];

        if ($status === Subscriber::STATUS_UNSUBSCRIBED) {
            $update['unsubscribed_at'] = $now;
        }

        Database::update('nl_subscribers', $update, 'id = :id', ['id' => $id]);
    }

    /**
     * Recomputes the person-level status from their subscriptions.
     *
     * Called after any per-list change: someone stays `active` while at least
     * one list remains, and only becomes `unsubscribed` when the last one goes.
     * Bounced and complained are terminal and never recomputed away.
     */
    public static function syncStatus(int $id): void
    {
        $subscriber = static::find($id);

        if ($subscriber === null) {
            return;
        }

        if (in_array($subscriber->status, [
            Subscriber::STATUS_BOUNCED,
            Subscriber::STATUS_COMPLAINED,
        ], true) === true) {
            return;
        }

        $active = (int)Database::value(
            'SELECT COUNT(*) FROM nl_subscriptions WHERE subscriber_id = :id AND status = "active"',
            ['id' => $id]
        );

        if ($active > 0) {
            if ($subscriber->status !== Subscriber::STATUS_ACTIVE) {
                static::setStatus($id, Subscriber::STATUS_ACTIVE);
            }

            return;
        }

        $pending = (int)Database::value(
            'SELECT COUNT(*) FROM nl_subscriptions WHERE subscriber_id = :id AND status = "pending"',
            ['id' => $id]
        );

        $target = $pending > 0 ? Subscriber::STATUS_PENDING : Subscriber::STATUS_UNSUBSCRIBED;

        if ($subscriber->status !== $target) {
            static::setStatus($id, $target);
        }
    }

    public static function touchSent(int $id, int|null $at = null): void
    {
        Database::update(
            'nl_subscribers',
            ['last_sent_at' => $at ?? time(), 'updated_at' => time()],
            'id = :id',
            ['id' => $id]
        );
    }

    /**
     * Resets the soft-bounce strike counter after a successful delivery.
     */
    public static function resetBounceScore(int $id): void
    {
        Database::update(
            'nl_subscribers',
            ['bounce_score' => 0, 'updated_at' => time()],
            'id = :id AND bounce_score > 0',
            ['id' => $id]
        );
    }

    public static function incrementBounceScore(int $id): int
    {
        Database::execute(
            'UPDATE nl_subscribers SET bounce_score = bounce_score + 1, updated_at = :now WHERE id = :id',
            ['id' => $id, 'now' => time()]
        );

        return (int)Database::value(
            'SELECT bounce_score FROM nl_subscribers WHERE id = :id',
            ['id' => $id]
        );
    }

    public static function count(string|null $status = null): int
    {
        if ($status === null) {
            return (int)Database::value('SELECT COUNT(*) FROM nl_subscribers');
        }

        return (int)Database::value(
            'SELECT COUNT(*) FROM nl_subscribers WHERE status = :status',
            ['status' => $status]
        );
    }

    /**
     * Paginated, filtered listing for the Panel.
     *
     * Filtering and paging happen in SQL rather than in PHP because the table
     * holds thousands of rows and the Panel must not load them all to show 50.
     *
     * @return array{data: array<int, Subscriber>, total: int, page: int, pages: int}
     */
    public static function paginate(array $filter = [], int $page = 1, int $limit = 50): array
    {
        $where    = ['1 = 1'];
        $bindings = [];

        if (($filter['query'] ?? '') !== '') {
            $where[] = '(email_norm LIKE :q OR name LIKE :q)';
            $bindings['q'] = '%' . mb_strtolower(trim($filter['query']), 'UTF-8') . '%';
        }

        if (($filter['status'] ?? null) !== null) {
            $where[] = 'status = :status';
            $bindings['status'] = $filter['status'];
        }

        if (($filter['language'] ?? null) !== null) {
            $where[] = 'language = :language';
            $bindings['language'] = $filter['language'];
        }

        if (($filter['list'] ?? null) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM nl_subscriptions s
                                 WHERE s.subscriber_id = nl_subscribers.id
                                   AND s.list_uuid = :list AND s.status = "active")';
            $bindings['list'] = $filter['list'];
        }

        $clause = implode(' AND ', $where);
        $total  = (int)Database::value(
            'SELECT COUNT(*) FROM nl_subscribers WHERE ' . $clause,
            $bindings
        );

        $sort = in_array($filter['sort'] ?? '', static::SORTABLE, true)
            ? $filter['sort']
            : 'created_at';

        $direction = strtolower((string)($filter['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $page  = max(1, $page);
        $limit = max(1, min(500, $limit));

        $rows = Database::query(
            'SELECT * FROM nl_subscribers WHERE ' . $clause
            . ' ORDER BY ' . $sort . ' ' . $direction . ', id ' . $direction
            . ' LIMIT :limit OFFSET :offset',
            [...$bindings, 'limit' => $limit, 'offset' => ($page - 1) * $limit]
        );

        return [
            'data'  => array_map(Subscriber::fromRow(...), $rows),
            'total' => $total,
            'page'  => $page,
            'pages' => (int)ceil($total / $limit),
        ];
    }

    /**
     * Applies the configured IP retention policy.
     *
     * `anonymize` keeps enough to show a signup came from a plausible network
     * without storing an identifier for a person, which is the proportionate
     * default under revDSG and GDPR.
     */
    public static function ip(string|null $ip): string|null
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return match ((string)Newsletter::option('privacy.storeIp', 'anonymize')) {
            'none'      => null,
            'full'      => $ip,
            default     => static::anonymizeIp($ip),
        };
    }

    public static function anonymizeIp(string $ip): string|null
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);

            if ($packed === false) {
                return null;
            }

            // Zero the last 80 bits, keeping the /48 network prefix.
            return inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10));
        }

        return null;
    }

    protected static function truncate(string|null $value, int $length): string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length, 'UTF-8');
    }
}

<?php

namespace E9li\Newsletter\Repository;

use E9li\Newsletter\Database;
use E9li\Newsletter\EmailAddress;
use E9li\Newsletter\Newsletter;

/**
 * The suppression list — the single gate every outgoing message passes.
 *
 * Keyed on `email_hash` rather than the address, so a row keeps blocking after
 * a GDPR erasure has removed the address itself. That is what stops a stale CSV
 * from resurrecting someone who complained or asked to be forgotten.
 */
final class Suppressions
{
    public const REASON_HARD_BOUNCE = 'hard_bounce';
    public const REASON_COMPLAINT   = 'complaint';
    public const REASON_MANUAL      = 'manual';
    public const REASON_INVALID     = 'invalid';
    public const REASON_PRUNE       = 'prune';
    public const REASON_GDPR        = 'gdpr';

    /**
     * Reasons that must never be undone by an import or a new signup.
     * A complaint in particular is a legal signal, not a preference.
     */
    public const PERMANENT = [
        self::REASON_COMPLAINT,
        self::REASON_GDPR,
    ];

    public static function isSuppressed(string $email): bool
    {
        return static::isHashSuppressed(EmailAddress::hash($email));
    }

    public static function isHashSuppressed(string $hash): bool
    {
        $row = Database::first(
            'SELECT reason, expires_at FROM nl_suppressions
              WHERE email_hash = :hash AND (expires_at IS NULL OR expires_at > :now)',
            ['hash' => $hash, 'now' => time()]
        );

        return $row !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $email): array|null
    {
        return Database::first(
            'SELECT * FROM nl_suppressions WHERE email_hash = :hash',
            ['hash' => EmailAddress::hash($email)]
        );
    }

    /**
     * Adds or refreshes a suppression.
     *
     * Upserts on the hash, so recording the same hard bounce twice does not
     * create a duplicate — webhooks retry, and the send loop can race a
     * webhook for the same message.
     */
    public static function add(
        string $email,
        string $reason,
        string|null $detail = null,
        string|null $source = null,
        int|null $campaignId = null,
        int|null $expiresAt = null,
    ): void {
        $now  = time();
        $norm = EmailAddress::normalize($email);

        Database::execute(
            'INSERT INTO nl_suppressions
                (email_hash, email_norm, reason, detail, source, campaign_id, created_at, expires_at)
             VALUES (:hash, :norm, :reason, :detail, :source, :campaign, :now, :expires)
             ON CONFLICT(email_hash) DO UPDATE SET
                reason     = excluded.reason,
                detail     = excluded.detail,
                source     = excluded.source,
                campaign_id = excluded.campaign_id,
                expires_at = excluded.expires_at',
            [
                'hash'     => EmailAddress::hash($norm),
                'norm'     => $norm,
                'reason'   => $reason,
                'detail'   => $detail === null ? null : mb_substr($detail, 0, 1000, 'UTF-8'),
                'source'   => $source,
                'campaign' => $campaignId,
                'now'      => $now,
                'expires'  => $expiresAt,
            ]
        );
    }

    /**
     * Adds a hash-only suppression, for use after an erasure.
     */
    public static function addHash(string $hash, string $reason, string|null $detail = null): void
    {
        Database::execute(
            'INSERT INTO nl_suppressions (email_hash, email_norm, reason, detail, created_at)
             VALUES (:hash, NULL, :reason, :detail, :now)
             ON CONFLICT(email_hash) DO UPDATE SET
                email_norm = NULL,
                reason     = excluded.reason,
                detail     = excluded.detail',
            [
                'hash'   => $hash,
                'reason' => $reason,
                'detail' => $detail,
                'now'    => time(),
            ]
        );
    }

    /**
     * Removes a suppression.
     *
     * Refuses for permanent reasons unless explicitly forced, because lifting a
     * complaint-based suppression by accident is how a sender ends up mailing
     * someone who reported them as spam.
     */
    public static function remove(string $email, bool $force = false): bool
    {
        $existing = static::find($email);

        if ($existing === null) {
            return false;
        }

        if ($force === false && in_array($existing['reason'], static::PERMANENT, true) === true) {
            return false;
        }

        Database::delete(
            'nl_suppressions',
            'email_hash = :hash',
            ['hash' => EmailAddress::hash($email)]
        );

        return true;
    }

    /**
     * Removes a suppression by its row id.
     *
     * Erasure rows have no address left, so the Panel cannot look them up
     * by email. Complaint and GDPR rows still refuse unless forced.
     */
    public static function removeById(int $id, bool $force = false): bool
    {
        $existing = Database::first(
            'SELECT * FROM nl_suppressions WHERE id = :id',
            ['id' => $id]
        );

        if ($existing === null) {
            return false;
        }

        if ($force === false && in_array($existing['reason'], static::PERMANENT, true) === true) {
            return false;
        }

        Database::delete('nl_suppressions', 'id = :id', ['id' => $id]);

        return true;
    }

    /**
     * One address per line, stored as a manual suppression.
     *
     * @return array{added: int, skipped: int}
     */
    public static function importLines(string $text): array
    {
        $added   = 0;
        $skipped = 0;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $email = trim($line);

            if ($email === '') {
                continue;
            }

            if (EmailAddress::isValid($email) === false) {
                $skipped++;

                continue;
            }

            static::add($email, static::REASON_MANUAL, 'Imported in the Panel', 'panel');
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, page: int, total: int, pages: int}
     */
    public static function paginate(int $page, int $limit): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(200, $limit));
        $total = static::count();
        $pages = max(1, (int)ceil($total / $limit));
        $page  = min($page, $pages);
        $offset = ($page - 1) * $limit;

        $rows = Database::query(
            'SELECT * FROM nl_suppressions ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );

        return [
            'data'  => $rows,
            'page'  => $page,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    public static function count(string|null $reason = null): int
    {
        if ($reason === null) {
            return (int)Database::value('SELECT COUNT(*) FROM nl_suppressions');
        }

        return (int)Database::value(
            'SELECT COUNT(*) FROM nl_suppressions WHERE reason = :reason',
            ['reason' => $reason]
        );
    }

    /**
     * @return array<string, int> reason => count
     */
    public static function byReason(): array
    {
        $rows = Database::query(
            'SELECT reason, COUNT(*) AS total FROM nl_suppressions GROUP BY reason ORDER BY total DESC'
        );

        return array_column($rows, 'total', 'reason');
    }

    /**
     * Removes expired temporary suppressions. Called by the hygiene command.
     */
    public static function prune(): int
    {
        return Database::delete(
            'nl_suppressions',
            'expires_at IS NOT NULL AND expires_at <= :now',
            ['now' => time()]
        );
    }
}

<?php

namespace E9li\Newsletter\Repository;

use E9li\Newsletter\Database;
use E9li\Newsletter\Model\Subscription;
use E9li\Newsletter\Newsletter;

/**
 * Reads and writes `nl_subscriptions`.
 *
 * Double opt-in tokens live here and are deliberately the mirror image of the
 * HMAC unsubscribe tokens: random rather than derived, single-use, expiring,
 * and stored only as a SHA-256 hash — so a database leak cannot be used to
 * confirm subscriptions on someone else's behalf.
 */
final class Subscriptions
{
    public static function find(int $subscriberId, string $listUuid): Subscription|null
    {
        $row = Database::first(
            'SELECT * FROM nl_subscriptions WHERE subscriber_id = :id AND list_uuid = :list',
            ['id' => $subscriberId, 'list' => $listUuid]
        );

        return $row === null ? null : Subscription::fromRow($row);
    }

    /**
     * @return array<int, Subscription>
     */
    public static function forSubscriber(int $subscriberId, string|null $status = null): array
    {
        $sql = 'SELECT * FROM nl_subscriptions WHERE subscriber_id = :id';
        $bindings = ['id' => $subscriberId];

        if ($status !== null) {
            $sql .= ' AND status = :status';
            $bindings['status'] = $status;
        }

        return array_map(
            Subscription::fromRow(...),
            Database::query($sql . ' ORDER BY created_at', $bindings)
        );
    }

    /**
     * Creates or refreshes a pending subscription and returns the plaintext
     * opt-in token to be mailed.
     *
     * Returns null when the subscription is already active — there is nothing
     * to confirm, and re-sending a confirmation to an active subscriber is
     * both confusing and a way for someone to be spammed via your form.
     */
    public static function requestOptin(
        int $subscriberId,
        string $listUuid,
        string|null $language = null,
        string|null $ip = null,
    ): string|null {
        $existing = static::find($subscriberId, $listUuid);

        if ($existing !== null && $existing->isActive() === true) {
            return null;
        }

        $now     = time();
        $token   = bin2hex(random_bytes(32));
        $expires = $now + (int)Newsletter::option('optin.ttl', 604800);

        $data = [
            'status'           => Subscription::STATUS_PENDING,
            'language'         => Newsletter::language($language),
            'optin_token_hash' => hash('sha256', $token),
            'optin_expires_at' => $expires,
            'optin_sent_at'    => $now,
            'updated_at'       => $now,
        ];

        if ($existing === null) {
            Database::insert('nl_subscriptions', [
                ...$data,
                'subscriber_id' => $subscriberId,
                'list_uuid'     => $listUuid,
                'optin_resends' => 0,
                'created_at'    => $now,
            ]);

            return $token;
        }

        // Re-requesting clears any previous unsubscribe, because the person is
        // asking again — but only ever into `pending`, never straight to active.
        $data['optin_resends']   = $existing->optinResends + 1;
        $data['unsubscribed_at'] = null;
        $data['unsub_reason']    = null;

        Database::update('nl_subscriptions', $data, 'id = :id', ['id' => $existing->id]);

        return $token;
    }

    /**
     * Whether the opt-in mail may be sent again for this pair.
     *
     * Caps resends so the form cannot be used to bomb someone's inbox by
     * submitting their address repeatedly.
     */
    public static function canResend(int $subscriberId, string $listUuid): bool
    {
        $existing = static::find($subscriberId, $listUuid);

        if ($existing === null) {
            return true;
        }

        return $existing->optinResends < (int)Newsletter::option('optin.maxResends', 3);
    }

    /**
     * Confirms a subscription from a plaintext opt-in token.
     *
     * The token is looked up by its hash, checked for expiry, and cleared on
     * success so it cannot be replayed.
     *
     * @return array{subscription: Subscription, subscriberId: int}|null
     */
    public static function confirm(string $token, string|null $ip = null): array|null
    {
        $row = Database::first(
            'SELECT * FROM nl_subscriptions WHERE optin_token_hash = :hash',
            ['hash' => hash('sha256', $token)]
        );

        if ($row === null) {
            return null;
        }

        $subscription = Subscription::fromRow($row);

        if ($subscription->isOptinExpired() === true) {
            return null;
        }

        $now = time();

        Database::update('nl_subscriptions', [
            'status'           => Subscription::STATUS_ACTIVE,
            'confirmed_at'     => $now,
            'confirm_ip'       => Subscribers::ip($ip),
            'optin_token_hash' => null,
            'optin_expires_at' => null,
            'unsubscribed_at'  => null,
            'unsub_reason'     => null,
            'updated_at'       => $now,
        ], 'id = :id', ['id' => $subscription->id]);

        // Mirror of unsubscribe(): a person-level status that disagrees with
        // their subscriptions would leave them permanently unmailable despite
        // holding an active list.
        Subscribers::syncStatus($subscription->subscriberId);

        return [
            'subscription' => static::find($subscription->subscriberId, $subscription->listUuid),
            'subscriberId' => $subscription->subscriberId,
        ];
    }

    /**
     * Activates a subscription without a confirmation step.
     *
     * Only for imports of already-consented contacts and Panel actions — never
     * reachable from a public form, where `optin.required` governs.
     */
    public static function activate(
        int $subscriberId,
        string $listUuid,
        string|null $language = null,
        int|null $confirmedAt = null,
    ): Subscription {
        $now      = time();
        $existing = static::find($subscriberId, $listUuid);

        $data = [
            'status'           => Subscription::STATUS_ACTIVE,
            'language'         => Newsletter::language($language),
            'confirmed_at'     => $confirmedAt ?? $now,
            'optin_token_hash' => null,
            'optin_expires_at' => null,
            'unsubscribed_at'  => null,
            'unsub_reason'     => null,
            'updated_at'       => $now,
        ];

        if ($existing === null) {
            Database::insert('nl_subscriptions', [
                ...$data,
                'subscriber_id' => $subscriberId,
                'list_uuid'     => $listUuid,
                'created_at'    => $now,
            ]);
        } else {
            Database::update('nl_subscriptions', $data, 'id = :id', ['id' => $existing->id]);
        }

        Subscribers::syncStatus($subscriberId);

        return static::find($subscriberId, $listUuid);
    }

    /**
     * Unsubscribes one list, or every list when $listUuid is null.
     *
     * Returns the number of subscriptions actually changed, so the caller can
     * tell "unsubscribed" from "was already unsubscribed" and show the right
     * message instead of a misleading confirmation.
     */
    public static function unsubscribe(
        int $subscriberId,
        string|null $listUuid = null,
        string $reason = Subscription::REASON_LINK,
        int|null $campaignId = null,
    ): int {
        $now = time();

        $data = [
            'status'            => Subscription::STATUS_UNSUBSCRIBED,
            'unsubscribed_at'   => $now,
            'unsub_reason'      => $reason,
            'unsub_campaign_id' => $campaignId,
            'optin_token_hash'  => null,
            'updated_at'        => $now,
        ];

        $where    = 'subscriber_id = :id AND status != "unsubscribed"';
        $bindings = ['id' => $subscriberId];

        if ($listUuid !== null) {
            $where .= ' AND list_uuid = :list';
            $bindings['list'] = $listUuid;
        }

        $affected = Database::update('nl_subscriptions', $data, $where, $bindings);

        Subscribers::syncStatus($subscriberId);

        return $affected;
    }

    /**
     * Number of active subscribers on a list, optionally per language.
     */
    public static function countActive(string $listUuid, string|null $language = null): int
    {
        $sql = 'SELECT COUNT(*) FROM nl_subscriptions sub
                  JOIN nl_subscribers s ON s.id = sub.subscriber_id
                 WHERE sub.list_uuid = :list AND sub.status = "active" AND s.status = "active"';

        $bindings = ['list' => $listUuid];

        if ($language !== null) {
            $sql .= ' AND COALESCE(sub.language, s.language, :fallback) = :language';
            $bindings['language'] = $language;
            $bindings['fallback'] = Newsletter::defaultLanguage() ?? '';
        }

        return (int)Database::value($sql, $bindings);
    }

    /**
     * Active subscriber counts for a list, broken down by language.
     *
     * @return array<string, int>
     */
    public static function countByLanguage(string $listUuid): array
    {
        $rows = Database::query(
            'SELECT COALESCE(sub.language, s.language, :fallback) AS lang, COUNT(*) AS total
               FROM nl_subscriptions sub
               JOIN nl_subscribers s ON s.id = sub.subscriber_id
              WHERE sub.list_uuid = :list AND sub.status = "active" AND s.status = "active"
              GROUP BY lang ORDER BY total DESC',
            ['list' => $listUuid, 'fallback' => Newsletter::defaultLanguage() ?? '']
        );

        return array_column($rows, 'total', 'lang');
    }

    /**
     * @return array<string, int> status => count
     */
    public static function statusCounts(string $listUuid): array
    {
        $rows = Database::query(
            'SELECT status, COUNT(*) AS total FROM nl_subscriptions
              WHERE list_uuid = :list GROUP BY status',
            ['list' => $listUuid]
        );

        return array_column($rows, 'total', 'status');
    }

    /**
     * Deletes pending subscriptions whose confirmation window closed long ago.
     *
     * Kept well past expiry rather than deleted at expiry, so a late click on a
     * confirmation link can still produce an honest "this link has expired"
     * rather than a bare 404.
     */
    public static function pruneExpiredOptins(int $graceSeconds = 2592000): int
    {
        return Database::delete(
            'nl_subscriptions',
            'status = "pending" AND optin_expires_at IS NOT NULL AND optin_expires_at < :cutoff',
            ['cutoff' => time() - $graceSeconds]
        );
    }

    /**
     * Writes the consent evidence onto one subscription.
     *
     * A null hash is deliberate: reconsent and a preference-centre opt-in
     * clear it until the person confirms, and the confirm path fills it in.
     *
     * @param array{hash?: string|null, url?: string|null, source?: string|null} $consent
     */
    public static function stampConsent(int $subscriberId, string $listUuid, array $consent): void
    {
        Database::update('nl_subscriptions', [
            'consent_text_hash' => $consent['hash'] ?? null,
            'consent_url'       => $consent['url'] ?? null,
            'consent_source'    => $consent['source'] ?? null,
        ], 'subscriber_id = :sid AND list_uuid = :list', [
            'sid'  => $subscriberId,
            'list' => $listUuid,
        ]);
    }

    /**
     * Puts every active subscription on a list back to pending and forgets
     * the previous consent, without sending mail. The reconsent command
     * queues the opt-in messages afterwards.
     */
    public static function markReconsent(string $listUuid): int
    {
        $ids = Database::query(
            'SELECT DISTINCT subscriber_id FROM nl_subscriptions
              WHERE list_uuid = :list AND status = "active"',
            ['list' => $listUuid]
        );

        $affected = Database::update('nl_subscriptions', [
            'status'            => Subscription::STATUS_PENDING,
            'optin_token_hash'  => null,
            'optin_expires_at'  => null,
            'consent_text_hash' => null,
            'consent_source'    => null,
        ], 'list_uuid = :list AND status = "active"', ['list' => $listUuid]);

        foreach ($ids as $row) {
            Subscribers::syncStatus((int)$row['subscriber_id']);
        }

        return $affected;
    }
}

<?php

namespace E9li\Newsletter\Model;

/**
 * One row of `nl_subscriptions` — a (subscriber, list) pair with its own
 * status, its own language and its own consent record.
 *
 * The per-list language is what lets someone take the German main list and the
 * French events list; the per-list consent record is what makes the double
 * opt-in evidence chain hold up per list rather than only per person.
 */
final class Subscription
{
    public const STATUS_PENDING      = 'pending';
    public const STATUS_ACTIVE       = 'active';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const REASON_LINK      = 'link';
    public const REASON_ONECLICK  = 'oneclick';
    public const REASON_COMPLAINT = 'complaint';
    public const REASON_BOUNCE    = 'bounce';
    public const REASON_ADMIN     = 'admin';
    public const REASON_PRUNE     = 'prune';

    public function __construct(
        public readonly int $id,
        public readonly int $subscriberId,
        public readonly string $listUuid,
        public readonly string $status,
        public readonly string|null $language,
        public readonly int|null $optinExpiresAt,
        public readonly int|null $optinSentAt,
        public readonly int $optinResends,
        public readonly int|null $confirmedAt,
        public readonly string|null $confirmIp,
        public readonly string|null $consentTextHash,
        public readonly string|null $consentUrl,
        public readonly string|null $consentSource,
        public readonly int|null $unsubscribedAt,
        public readonly string|null $unsubReason,
        public readonly int $createdAt,
        public readonly int $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int)$row['id'],
            subscriberId:   (int)$row['subscriber_id'],
            listUuid:       (string)$row['list_uuid'],
            status:         (string)$row['status'],
            language:       $row['language'] !== null ? (string)$row['language'] : null,
            optinExpiresAt: $row['optin_expires_at'] !== null ? (int)$row['optin_expires_at'] : null,
            optinSentAt:    $row['optin_sent_at'] !== null ? (int)$row['optin_sent_at'] : null,
            optinResends:   (int)($row['optin_resends'] ?? 0),
            confirmedAt:    $row['confirmed_at'] !== null ? (int)$row['confirmed_at'] : null,
            confirmIp:      $row['confirm_ip'] !== null ? (string)$row['confirm_ip'] : null,
            consentTextHash: isset($row['consent_text_hash']) && $row['consent_text_hash'] !== null
                ? (string)$row['consent_text_hash']
                : null,
            consentUrl: isset($row['consent_url']) && $row['consent_url'] !== null
                ? (string)$row['consent_url']
                : null,
            consentSource: isset($row['consent_source']) && $row['consent_source'] !== null
                ? (string)$row['consent_source']
                : null,
            unsubscribedAt: $row['unsubscribed_at'] !== null ? (int)$row['unsubscribed_at'] : null,
            unsubReason:    $row['unsub_reason'] !== null ? (string)$row['unsub_reason'] : null,
            createdAt:      (int)$row['created_at'],
            updatedAt:      (int)$row['updated_at'],
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Whether the confirmation window has closed.
     *
     * An expired pending subscription is not the same as no subscription: the
     * person did ask, they just never confirmed, so resending is legitimate
     * where a cold signup would not be.
     */
    public function isOptinExpired(int|null $now = null): bool
    {
        if ($this->optinExpiresAt === null) {
            return false;
        }

        return $this->optinExpiresAt < ($now ?? time());
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'listUuid'    => $this->listUuid,
            'status'      => $this->status,
            'language'    => $this->language,
            'confirmedAt' => $this->confirmedAt,
            'consentTextHash' => $this->consentTextHash,
            'consentUrl'      => $this->consentUrl,
            'consentSource'   => $this->consentSource,
            'unsubscribedAt' => $this->unsubscribedAt,
            'unsubReason' => $this->unsubReason,
            'createdAt'   => $this->createdAt,
        ];
    }
}

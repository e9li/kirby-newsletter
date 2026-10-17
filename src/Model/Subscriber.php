<?php

namespace E9li\Newsletter\Model;

use E9li\Newsletter\EmailAddress;

/**
 * One row of `nl_subscribers`, typed.
 */
final class Subscriber
{
    public const STATUS_PENDING      = 'pending';
    public const STATUS_ACTIVE       = 'active';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';
    public const STATUS_BOUNCED      = 'bounced';
    public const STATUS_COMPLAINED   = 'complained';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_UNSUBSCRIBED,
        self::STATUS_BOUNCED,
        self::STATUS_COMPLAINED,
    ];

    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $emailNorm,
        public readonly string $emailHash,
        public readonly string|null $name,
        public readonly string|null $language,
        public readonly string $status,
        public readonly string|null $source,
        public readonly string|null $sourceDetail,
        public readonly int|null $signupAt,
        public readonly string|null $signupIp,
        public readonly int|null $confirmedAt,
        public readonly int|null $unsubscribedAt,
        public readonly int $bounceScore,
        public readonly int|null $lastSentAt,
        public readonly int|null $lastOpenAt,
        public readonly int|null $lastClickAt,
        public readonly array $meta,
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
            email:          (string)$row['email'],
            emailNorm:      (string)$row['email_norm'],
            emailHash:      (string)$row['email_hash'],
            name:           $row['name'] !== null ? (string)$row['name'] : null,
            language:       $row['language'] !== null ? (string)$row['language'] : null,
            status:         (string)$row['status'],
            source:         $row['source'] !== null ? (string)$row['source'] : null,
            sourceDetail:   $row['source_detail'] !== null ? (string)$row['source_detail'] : null,
            signupAt:       $row['signup_at'] !== null ? (int)$row['signup_at'] : null,
            signupIp:       $row['signup_ip'] !== null ? (string)$row['signup_ip'] : null,
            confirmedAt:    $row['confirmed_at'] !== null ? (int)$row['confirmed_at'] : null,
            unsubscribedAt: $row['unsubscribed_at'] !== null ? (int)$row['unsubscribed_at'] : null,
            bounceScore:    (int)($row['bounce_score'] ?? 0),
            lastSentAt:     $row['last_sent_at'] !== null ? (int)$row['last_sent_at'] : null,
            lastOpenAt:     $row['last_open_at'] !== null ? (int)$row['last_open_at'] : null,
            lastClickAt:    $row['last_click_at'] !== null ? (int)$row['last_click_at'] : null,
            meta:           static::decodeMeta($row['meta'] ?? null),
            createdAt:      (int)$row['created_at'],
            updatedAt:      (int)$row['updated_at'],
        );
    }

    protected static function decodeMeta(mixed $meta): array
    {
        if (is_string($meta) === false || $meta === '') {
            return [];
        }

        $decoded = json_decode($meta, true);

        return is_array($decoded) === true ? $decoded : [];
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
     * Whether this person can be sent to at all, before per-list and
     * suppression checks.
     */
    public function isMailable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Best-effort first name for the salutation merge tag.
     *
     * Returns null rather than guessing when the stored name is a single word
     * that might be a surname or a company — a wrong first name in a greeting
     * reads worse than no greeting.
     */
    public function firstName(): string|null
    {
        if ($this->name === null || trim($this->name) === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY);

        return count($parts) > 1 ? $parts[0] : null;
    }

    public function lastName(): string|null
    {
        if ($this->name === null || trim($this->name) === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY);

        return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : $parts[0];
    }

    public function domain(): string
    {
        return EmailAddress::domain($this->emailNorm);
    }

    /**
     * Whether this person has opened or clicked within the given window.
     * Drives the engagement-first send ordering during warmup.
     */
    public function isEngaged(int $since): bool
    {
        return ($this->lastOpenAt ?? 0) >= $since || ($this->lastClickAt ?? 0) >= $since;
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * Panel/API representation. Never includes the raw signup IP.
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'email'       => $this->email,
            'name'        => $this->name,
            'language'    => $this->language,
            'status'      => $this->status,
            'source'      => $this->source,
            'signupAt'    => $this->signupAt,
            'confirmedAt' => $this->confirmedAt,
            'lastOpenAt'  => $this->lastOpenAt,
            'bounceScore' => $this->bounceScore,
            'meta'        => $this->meta,
        ];
    }
}

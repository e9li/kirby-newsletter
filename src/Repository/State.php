<?php

namespace E9li\Newsletter\Repository;

use E9li\Newsletter\Database;

/**
 * Small key/value store for operational state that outlives a request:
 * when warmup started, when the last tick ran, when hygiene last swept.
 *
 * Deliberately in the database rather than a cache, because losing it would
 * silently restart the warmup ramp — and a sender that jumps back to 50
 * messages a day mid-campaign looks broken, while one that skips the ramp
 * entirely looks like a spammer.
 */
final class State
{
    public const WARMUP_STARTED = 'warmup.started_at';
    public const TICK_LAST      = 'tick.last_at';
    public const HYGIENE_LAST   = 'hygiene.last_at';

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Database::value(
            'SELECT value FROM nl_state WHERE key = :key',
            ['key' => $key]
        );

        return $value ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = static::get($key);

        return $value === null ? $default : (int)$value;
    }

    public static function set(string $key, mixed $value): void
    {
        Database::execute(
            'INSERT INTO nl_state (key, value, updated_at) VALUES (:key, :value, :now)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [
                'key'   => $key,
                'value' => $value === null ? null : (string)$value,
                'now'   => time(),
            ]
        );
    }

    /**
     * Sets a key only if it does not exist yet, and returns the effective value.
     *
     * Used for `warmup.started_at`, where the first send must stamp the start
     * date and every later send must leave it alone.
     */
    public static function remember(string $key, mixed $value): mixed
    {
        $existing = static::get($key);

        if ($existing !== null) {
            return $existing;
        }

        static::set($key, $value);

        return $value;
    }

    public static function forget(string $key): void
    {
        Database::delete('nl_state', 'key = :key', ['key' => $key]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $rows = Database::query('SELECT key, value FROM nl_state ORDER BY key');

        return array_column($rows, 'value', 'key');
    }
}

<?php

namespace E9li\Newsletter;

/**
 * Stateless HMAC tokens for unsubscribe, preferences and archive links.
 *
 * The payload carries everything the route needs, so verifying a token is one
 * HMAC plus one primary-key lookup — no table scan by email address, and no
 * token table to grow at one row per recipient per campaign.
 *
 * Format: `v1.<id>.<language>.<scope>.<signature>`
 * Example: v1.4217.de.all.9f3c1b7e2d4a6058c1f39ab2e7d40516
 *
 * Note the contrast with double opt-in tokens, which are the opposite design:
 * random, single-use, expiring, and stored only as a hash (see Signup). An
 * unsubscribe link must work forever; a confirmation link must not.
 */
final class Token
{
    public const VERSION = 'v1';

    /**
     * Purposes are part of the signed payload, so a preferences token can never
     * be replayed as an unsubscribe token.
     */
    public const PURPOSES = ['unsub', 'prefs', 'webview', 'open', 'click'];

    /**
     * Signature length in hex characters. 32 hex = 128 bits, which is far
     * beyond guessable and short enough that the URL will not wrap in a mail
     * client — wrapped links are a real source of broken unsubscribes.
     */
    public const SIGNATURE_LENGTH = 32;

    /**
     * Mints a token.
     *
     * @param string $scope 'all', a list UUID, or 'c<campaignId>'
     */
    public static function create(
        string $purpose,
        int $id,
        string|null $language = null,
        string $scope = 'all'
    ): string {
        $payload = static::payload($id, $language, $scope);

        return $payload . '.' . static::sign($purpose, $payload, Secret::key());
    }

    /**
     * Verifies a token and returns its payload, or null if it does not verify.
     *
     * @return array{version: string, id: int, language: string|null, scope: string}|null
     */
    public static function verify(string $purpose, string $token): array|null
    {
        $parts = explode('.', $token);

        if (count($parts) !== 5) {
            return null;
        }

        $signature = array_pop($parts);
        $payload   = implode('.', $parts);

        [$version, $id, $language, $scope] = $parts;

        if ($version !== static::VERSION) {
            return null;
        }

        if (ctype_digit($id) === false) {
            return null;
        }

        if (static::matches($purpose, $payload, $signature) === false) {
            return null;
        }

        return [
            'version'  => $version,
            'id'       => (int)$id,
            'language' => $language === '-' ? null : $language,
            'scope'    => $scope,
        ];
    }

    /**
     * Compares a signature against the current key and any legacy keys.
     */
    protected static function matches(string $purpose, string $payload, string $signature): bool
    {
        foreach (Secret::verificationKeys() as $key) {
            // hash_equals, never ===, so the comparison cannot be timed to
            // recover the signature byte by byte.
            if (hash_equals(static::sign($purpose, $payload, $key), $signature) === true) {
                return true;
            }
        }

        return false;
    }

    protected static function payload(int $id, string|null $language, string $scope): string
    {
        return implode('.', [
            static::VERSION,
            $id,
            // '-' rather than an empty segment, so the token always has exactly
            // five parts and a missing language cannot be confused with a
            // missing scope.
            $language === null || $language === '' ? '-' : $language,
            $scope === '' ? 'all' : $scope,
        ]);
    }

    protected static function sign(string $purpose, string $payload, string $key): string
    {
        return substr(
            hash_hmac('sha256', $purpose . '|' . $payload, $key),
            0,
            static::SIGNATURE_LENGTH
        );
    }
}

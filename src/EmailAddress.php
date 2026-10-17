<?php

namespace E9li\Newsletter;

/**
 * Email address normalisation, hashing and validation.
 *
 * Every address entering the system goes through `normalize()` first, because
 * the normalised form is the identity key: without it the same person signs up
 * three times as `Anna@Example.com`, `anna@example.com ` and `ANNA@EXAMPLE.COM`,
 * and unsubscribing one leaves the other two receiving mail.
 */
final class EmailAddress
{
    /**
     * Domains that exist only to throw away. Signups from these are almost
     * always throwaway tests or bots, and they bounce later.
     */
    public const DISPOSABLE = [
        '10minutemail.com', 'guerrillamail.com', 'guerrillamail.net',
        'mailinator.com', 'tempmail.com', 'temp-mail.org', 'throwawaymail.com',
        'yopmail.com', 'trashmail.com', 'trashmail.de', 'sharklasers.com',
        'getnada.com', 'dispostable.com', 'maildrop.cc', 'fakeinbox.com',
        'spamgourmet.com', 'mailnesia.com', 'tempinbox.com', 'wegwerfmail.de',
        'byom.de', 'einrot.com', 'mytrashmail.com', 'emailondeck.com',
    ];

    /**
     * Mailbox names that reach a group rather than a person. Allowed, but they
     * correlate with high complaint rates because whoever opens them never
     * signed up.
     */
    public const ROLE_ADDRESSES = [
        'abuse', 'admin', 'administrator', 'billing', 'compliance', 'contact',
        'devnull', 'ftp', 'hostmaster', 'info', 'inoc', 'ispfeedback', 'list',
        'list-request', 'noc', 'no-reply', 'noreply', 'null', 'postmaster',
        'privacy', 'root', 'security', 'spam', 'support', 'sysadmin',
        'tech', 'undisclosed-recipients', 'unsubscribe', 'usenet', 'uucp',
        'webmaster', 'www',
    ];

    /**
     * The identity key: trimmed and lowercased.
     *
     * Deliberately does NOT strip Gmail dots or `+tags`. Those are provider
     * specific, and silently rewriting someone's address means the unsubscribe
     * link they click no longer matches what they typed.
     */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /**
     * Stable hash of the normalised address.
     *
     * Survives a GDPR erasure, so a suppression can keep working after the
     * address itself has been deleted.
     */
    public static function hash(string $email): string
    {
        return hash('sha256', static::normalize($email));
    }

    public static function isValid(string $email): bool
    {
        $email = static::normalize($email);

        if ($email === '' || mb_strlen($email, 'UTF-8') > 254) {
            return false;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        // filter_var accepts a trailing dot and a bare hostname; neither is
        // deliverable in practice.
        $domain = static::domain($email);

        return $domain !== '' && str_contains($domain, '.') === true && str_ends_with($domain, '.') === false;
    }

    public static function domain(string $email): string
    {
        $position = strrpos(static::normalize($email), '@');

        return $position === false ? '' : substr(static::normalize($email), $position + 1);
    }

    public static function localPart(string $email): string
    {
        $position = strrpos(static::normalize($email), '@');

        return $position === false ? '' : substr(static::normalize($email), 0, $position);
    }

    /**
     * Whether the domain has an MX (or, per RFC 5321 fallback, an A) record.
     *
     * Run at signup so typos like `@gmial.com` never reach the database — a
     * bounce avoided at signup is a bounce that never touches your reputation.
     */
    public static function hasMx(string $email): bool
    {
        $domain = static::domain($email);

        if ($domain === '') {
            return false;
        }

        if (checkdnsrr($domain, 'MX') === true) {
            return true;
        }

        // RFC 5321 §5.1: with no MX, the A record is the fallback target.
        return checkdnsrr($domain, 'A') === true || checkdnsrr($domain, 'AAAA') === true;
    }

    public static function isDisposable(string $email): bool
    {
        $domain = static::domain($email);
        $extra  = Newsletter::option('optin.disposableDomains', []);
        $list   = is_array($extra) === true ? [...static::DISPOSABLE, ...$extra] : static::DISPOSABLE;

        return in_array($domain, $list, true);
    }

    public static function isRole(string $email): bool
    {
        $local = static::localPart($email);

        // Strip a +tag before comparing, so `info+news@` still reads as a role.
        $local = explode('+', $local)[0];

        return in_array($local, static::ROLE_ADDRESSES, true);
    }

    /**
     * Masks an address for display in logs and the Panel event list.
     */
    public static function mask(string $email): string
    {
        $local  = static::localPart($email);
        $domain = static::domain($email);

        if ($local === '' || $domain === '') {
            return '***';
        }

        $visible = mb_substr($local, 0, min(2, mb_strlen($local, 'UTF-8')), 'UTF-8');

        return $visible . str_repeat('*', max(1, mb_strlen($local, 'UTF-8') - 2)) . '@' . $domain;
    }
}

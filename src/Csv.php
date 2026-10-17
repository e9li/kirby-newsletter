<?php

namespace E9li\Newsletter;

use E9li\Newsletter\Model\Subscriber;
use E9li\Newsletter\Model\Subscription;
use E9li\Newsletter\Repository\Subscribers;
use E9li\Newsletter\Repository\Subscriptions;
use E9li\Newsletter\Repository\Suppressions;
use Kirby\Filesystem\F;

/**
 * CSV import and export.
 *
 * Import is deliberately two-phase: `analyse()` produces a full diff that
 * changes nothing, and only `apply()` writes. Importing 5,000 rows is not
 * undoable, and the failure mode — mailing people who never asked — is the one
 * that gets a sending domain blacklisted rather than merely annoying someone.
 */
final class Csv
{
    /**
     * Row outcomes, in the order the summary presents them.
     */
    public const OUTCOME_CREATE     = 'create';
    public const OUTCOME_SUBSCRIBE  = 'subscribe';   // exists, not yet on this list
    public const OUTCOME_UPDATE     = 'update';      // already on the list
    public const OUTCOME_SUPPRESSED = 'suppressed';
    public const OUTCOME_INVALID    = 'invalid';
    public const OUTCOME_DUPLICATE  = 'duplicate';   // repeated within the file

    public const OUTCOMES = [
        self::OUTCOME_CREATE,
        self::OUTCOME_SUBSCRIBE,
        self::OUTCOME_UPDATE,
        self::OUTCOME_SUPPRESSED,
        self::OUTCOME_INVALID,
        self::OUTCOME_DUPLICATE,
    ];

    /**
     * Fields an import may map a column onto.
     */
    public const FIELDS = ['email', 'name', 'language', 'consentAt', 'ignore'];

    /**
     * Header names we can map automatically, per field.
     *
     * Covers what Campaign Monitor, Mailchimp and a hand-rolled export
     * typically emit, in the languages this plugin ships.
     */
    public const ALIASES = [
        'email'     => ['email', 'e-mail', 'email address', 'emailaddress', 'mail',
                        'e-mail-adresse', 'e-mailadresse', 'adresse e-mail', 'indirizzo e-mail'],
        'name'      => ['name', 'full name', 'fullname', 'vorname nachname', 'nom', 'nome'],
        'language'  => ['language', 'lang', 'locale', 'sprache', 'langue', 'lingua'],
        'consentAt' => ['consent', 'consent date', 'confirmed', 'confirmed at', 'date subscribed',
                        'subscribed at', 'signup date', 'opt-in date', 'optin date',
                        'anmeldedatum', 'bestätigt am'],
    ];

    /**
     * Hard cap on rows per import.
     *
     * A file larger than this is almost always a mistake, and processing it in
     * one request would time out half way and leave a partial import.
     */
    public const MAX_ROWS = 25000;

    // ── Parsing ─────────────────────────────────────────────────────────────

    /**
     * Reads a CSV file into a header row plus data rows.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, delimiter: string}
     */
    public static function parseFile(string $file, string|null $delimiter = null): array
    {
        return static::parse((string)F::read($file), $delimiter);
    }

    /**
     * Parses CSV *contents* — not a path.
     *
     * The Panel import pastes the file rather than uploading it, so contents
     * are what the whole wizard passes around.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, delimiter: string}
     */
    public static function parse(string $contents, string|null $delimiter = null): array
    {
        if (trim($contents) === '') {
            throw new \Kirby\Exception\InvalidArgumentException(
                message: 'The file is empty.'
            );
        }

        // Strip a UTF-8 BOM: Excel writes one, and it would otherwise become
        // part of the first header name and break auto-mapping.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        // Excel on a German or French locale writes semicolons. Guessing wrong
        // yields one giant column, so sniff rather than assume.
        $delimiter ??= static::sniff($contents);

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');

        if ($headers === false || $headers === [null]) {
            fclose($handle);

            throw new \Kirby\Exception\InvalidArgumentException(
                message: 'The file has no header row.'
            );
        }

        $headers = array_map(static fn ($h): string => trim((string)$h), $headers);
        $rows    = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            // fgetcsv yields [null] for a blank line.
            if ($row === [null]) {
                continue;
            }

            if (count(array_filter($row, static fn ($v): bool => trim((string)$v) !== '')) === 0) {
                continue;
            }

            $rows[] = array_map(static fn ($v): string => trim((string)$v), $row);

            if (count($rows) > static::MAX_ROWS) {
                break;
            }
        }

        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    /**
     * Picks the delimiter that yields the most columns on the header line.
     */
    protected static function sniff(string $contents): string
    {
        $line = strtok($contents, "\r\n") ?: '';
        $best = ',';
        $most = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = count(str_getcsv($line, $candidate, '"', '\\'));

            if ($count > $most) {
                $most = $count;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Guesses a field for each header, so the mapping step starts pre-filled.
     *
     * @param array<int, string> $headers
     * @return array<int, string> column index => field name
     */
    public static function autoMap(array $headers): array
    {
        $mapping = [];
        $taken   = [];

        foreach ($headers as $index => $header) {
            $normalised = mb_strtolower(trim($header), 'UTF-8');
            $mapping[$index] = 'ignore';

            foreach (static::ALIASES as $field => $aliases) {
                if (in_array($field, $taken, true) === true) {
                    continue;
                }

                if (in_array($normalised, $aliases, true) === true) {
                    $mapping[$index] = $field;
                    $taken[] = $field;

                    break;
                }
            }
        }

        // Nothing matched by name: fall back to the first column that actually
        // looks like an address, which is right more often than not.
        if (in_array('email', $mapping, true) === false) {
            foreach ($headers as $index => $header) {
                if (str_contains(mb_strtolower($header, 'UTF-8'), 'mail') === true) {
                    $mapping[$index] = 'email';

                    break;
                }
            }
        }

        return $mapping;
    }

    // ── Dry run ─────────────────────────────────────────────────────────────

    /**
     * Works out what an import would do, without doing any of it.
     *
     * @param array<int, array<int, string>> $rows
     * @param array<int, string> $mapping column index => field
     * @param array<int, string> $listUuids
     *
     * @return array{summary: array<string, int>, rows: array<int, array<string, mixed>>, total: int}
     */
    public static function analyse(array $rows, array $mapping, array $listUuids): array
    {
        $emailColumn = array_search('email', $mapping, true);

        if ($emailColumn === false) {
            throw new \Kirby\Exception\InvalidArgumentException(
                message: 'No column is mapped to the email field.'
            );
        }

        $summary  = array_fill_keys(static::OUTCOMES, 0);
        $analysed = [];
        $seen     = [];

        foreach ($rows as $index => $row) {
            $values = static::values($row, $mapping);
            $email  = $values['email'] ?? '';
            $norm   = EmailAddress::normalize($email);

            $entry = [
                'line'     => $index + 2,     // +1 for the header, +1 for 1-based
                'email'    => $email,
                'name'     => $values['name'] ?? null,
                'language' => $values['language'] ?? null,
                'consentAt' => static::parseDate($values['consentAt'] ?? null),
                'outcome'  => null,
                'reason'   => null,
            ];

            if ($email === '' || EmailAddress::isValid($email) === false) {
                $entry['outcome'] = static::OUTCOME_INVALID;
                $entry['reason']  = $email === '' ? 'empty' : 'malformed';
            } elseif (isset($seen[$norm]) === true) {
                $entry['outcome'] = static::OUTCOME_DUPLICATE;
                $entry['reason']  = 'line ' . $seen[$norm];
            } elseif (Suppressions::isSuppressed($norm) === true) {
                // The single most important branch in this file. Someone on the
                // suppression list complained, hard-bounced or asked to be
                // forgotten; a CSV must never bring them back.
                $entry['outcome'] = static::OUTCOME_SUPPRESSED;
                $entry['reason']  = Suppressions::find($norm)['reason'] ?? 'suppressed';
            } else {
                $seen[$norm] = $entry['line'];
                $subscriber  = Subscribers::findByEmail($norm);

                if ($subscriber === null) {
                    $entry['outcome'] = static::OUTCOME_CREATE;
                } else {
                    $entry['subscriberId'] = $subscriber->id;
                    $onEveryList = true;

                    foreach ($listUuids as $uuid) {
                        $existing = Subscriptions::find($subscriber->id, $uuid);

                        if ($existing === null || $existing->isActive() === false) {
                            $onEveryList = false;

                            break;
                        }
                    }

                    $entry['outcome'] = $onEveryList === true
                        ? static::OUTCOME_UPDATE
                        : static::OUTCOME_SUBSCRIBE;
                }
            }

            if ($entry['outcome'] === static::OUTCOME_DUPLICATE) {
                // Duplicates are still counted against the first occurrence's
                // seen-map, so they never double-write.
            }

            $entry['role'] = $email !== '' && EmailAddress::isRole($email);

            $summary[$entry['outcome']]++;
            $analysed[] = $entry;
        }

        return [
            'summary' => $summary,
            'rows'    => $analysed,
            'total'   => count($analysed),
        ];
    }

    /**
     * @param array<int, string> $row
     * @param array<int, string> $mapping
     * @return array<string, string>
     */
    protected static function values(array $row, array $mapping): array
    {
        $values = [];

        foreach ($mapping as $index => $field) {
            if ($field === 'ignore' || $field === null) {
                continue;
            }

            $value = trim((string)($row[$index] ?? ''));

            if ($value !== '') {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    /**
     * Parses a consent date. Returns null for anything unparseable rather than
     * guessing — a wrong consent date is worse than none, because it is the
     * evidence you would rely on if challenged.
     */
    protected static function parseDate(string|null $value): int|null
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false || $timestamp <= 0 || $timestamp > time() + 86400) {
            return null;
        }

        return $timestamp;
    }

    // ── Apply ───────────────────────────────────────────────────────────────

    /**
     * Writes an analysed import.
     *
     * @param array<int, array<string, mixed>> $rows analysed rows
     * @param array<int, string> $listUuids
     * @param bool $activate  bypass the confirmation mail (only for a migration
     *                        where consent demonstrably already exists)
     * @param string $source  free-text provenance, stored per subscriber
     *
     * @return array<string, int>
     */
    public static function apply(
        array $rows,
        array $listUuids,
        bool $activate,
        string $source,
        string|null $defaultLanguage = null,
    ): array {
        $applied = array_fill_keys(static::OUTCOMES, 0);
        $applied['skipped'] = 0;
        $mailed = 0;

        foreach ($rows as $row) {
            $outcome = $row['outcome'] ?? null;

            // Only these three ever write. Suppressed, invalid and duplicate
            // rows are counted and dropped.
            if (in_array($outcome, [
                static::OUTCOME_CREATE,
                static::OUTCOME_SUBSCRIBE,
                static::OUTCOME_UPDATE,
            ], true) === false) {
                $applied[$outcome ?? 'skipped'] = ($applied[$outcome ?? 'skipped'] ?? 0) + 1;

                continue;
            }

            $email = (string)$row['email'];

            // Re-check the suppression list at write time. The analysis may be
            // minutes old, and a bounce webhook could have landed in between.
            if (Suppressions::isSuppressed($email) === true) {
                $applied[static::OUTCOME_SUPPRESSED]++;

                continue;
            }

            $subscriber = Subscribers::upsert($email, [
                'name'         => $row['name'] ?? null,
                'language'     => $row['language'] ?? $defaultLanguage,
                'source'       => 'import',
                'sourceDetail' => $source,
                'meta'         => [],
            ]);

            $consent = [
                'hash'   => ($row['consentAt'] ?? null) !== null ? hash('sha256', $source) : null,
                'url'    => null,
                'source' => 'import',
            ];

            foreach ($listUuids as $uuid) {
                if ($activate === true) {
                    Subscriptions::activate(
                        $subscriber->id,
                        $uuid,
                        $row['language'] ?? $defaultLanguage,
                        $row['consentAt'] ?? null
                    );
                    Subscriptions::stampConsent($subscriber->id, $uuid, $consent);

                    continue;
                }

                // No proof of consent in the file, so treat them like any other
                // signup and make them confirm.
                $token = Subscriptions::requestOptin(
                    $subscriber->id,
                    $uuid,
                    $row['language'] ?? $defaultLanguage
                );

                // Without this the imported person sits at `pending` forever
                // with a token nobody ever sent them — unable to confirm, and
                // never mailable.
                $list = Lists::find($uuid);

                if ($token !== null && $list !== null) {
                    Subscriptions::stampConsent($subscriber->id, $uuid, $consent);
                    Signup::sendOptin(
                        $subscriber,
                        $list,
                        $token,
                        $row['language'] ?? $defaultLanguage
                    );
                    $mailed++;
                }
            }

            Subscribers::syncStatus($subscriber->id);
            $applied[$outcome]++;
        }

        Log::info('CSV import applied', [
            'source'   => $source,
            'lists'    => count($listUuids),
            'activate' => $activate,
            'optins'   => $mailed,
            'counts'   => $applied,
        ]);

        $applied['mailed'] = $mailed;

        return $applied;
    }

    // ── Export ──────────────────────────────────────────────────────────────

    /**
     * Builds a CSV export.
     *
     * Includes the consent evidence columns, because the main reason to export
     * is to move to another system — and the consent record is the part you
     * cannot reconstruct later.
     *
     * @param array{list?: string, status?: string, language?: string} $filter
     */
    public static function export(array $filter = []): string
    {
        $where    = ['1 = 1'];
        $bindings = [];

        if (($filter['list'] ?? null) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM nl_subscriptions x
                                 WHERE x.subscriber_id = s.id AND x.list_uuid = :list
                                   AND x.status = "active")';
            $bindings['list'] = $filter['list'];
        }

        if (($filter['status'] ?? null) !== null) {
            $where[] = 's.status = :status';
            $bindings['status'] = $filter['status'];
        }

        if (($filter['language'] ?? null) !== null) {
            $where[] = 's.language = :language';
            $bindings['language'] = $filter['language'];
        }

        $rows = Database::query(
            'SELECT s.*, (
                SELECT GROUP_CONCAT(x.list_uuid, "|") FROM nl_subscriptions x
                 WHERE x.subscriber_id = s.id AND x.status = "active"
             ) AS lists,
             (
                SELECT GROUP_CONCAT(COALESCE(x.consent_source, ""), "|") FROM nl_subscriptions x
                 WHERE x.subscriber_id = s.id
             ) AS consent_source,
             (
                SELECT GROUP_CONCAT(COALESCE(x.consent_url, ""), "|") FROM nl_subscriptions x
                 WHERE x.subscriber_id = s.id
             ) AS consent_url,
             (
                SELECT GROUP_CONCAT(COALESCE(x.consent_text_hash, ""), "|") FROM nl_subscriptions x
                 WHERE x.subscriber_id = s.id
             ) AS consent_text_hash
             FROM nl_subscribers s
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY s.id',
            $bindings
        );

        $handle = fopen('php://memory', 'r+');

        // BOM, so Excel opens UTF-8 correctly instead of mangling umlauts.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'email', 'name', 'language', 'status', 'lists',
            'signup_at', 'confirmed_at', 'unsubscribed_at',
            'source', 'source_detail', 'last_sent_at', 'last_open_at',
            'consent_source', 'consent_url', 'consent_text_hash',
        ], ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['email'],
                $row['name'],
                $row['language'],
                $row['status'],
                static::listTitles((string)($row['lists'] ?? '')),
                static::date($row['signup_at'] ?? null),
                static::date($row['confirmed_at'] ?? null),
                static::date($row['unsubscribed_at'] ?? null),
                $row['source'],
                $row['source_detail'],
                static::date($row['last_sent_at'] ?? null),
                static::date($row['last_open_at'] ?? null),
                $row['consent_source'] ?? '',
                $row['consent_url'] ?? '',
                $row['consent_text_hash'] ?? '',
            ], ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * One person's row and their subscriptions. Nobody else's.
     */
    public static function personal(\E9li\Newsletter\Model\Subscriber $subscriber): string
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'email', 'name', 'list', 'status', 'confirmed_at', 'consent_source',
        ], ',', '"', '\\');

        $subscriptions = \E9li\Newsletter\Repository\Subscriptions::forSubscriber($subscriber->id);

        if ($subscriptions === []) {
            fputcsv($handle, [
                $subscriber->email,
                $subscriber->name,
                '',
                $subscriber->status,
                static::date($subscriber->confirmedAt),
                '',
            ], ',', '"', '\\');
        }

        foreach ($subscriptions as $subscription) {
            $list = \E9li\Newsletter\Lists::find($subscription->listUuid);

            fputcsv($handle, [
                $subscriber->email,
                $subscriber->name,
                $list === null ? $subscription->listUuid : $list->title()->value(),
                $subscription->status,
                static::date($subscription->confirmedAt),
                $subscription->consentSource,
            ], ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    protected static function listTitles(string $concatenated): string
    {
        if ($concatenated === '') {
            return '';
        }

        $titles = [];

        foreach (explode('|', $concatenated) as $uuid) {
            $page = Lists::find($uuid);
            $titles[] = $page === null ? $uuid : $page->title()->value();
        }

        return implode(', ', $titles);
    }

    protected static function date(mixed $timestamp): string
    {
        $timestamp = (int)$timestamp;

        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}

<?php

namespace E9li\Newsletter;

use Kirby\Database\Database as KirbyDatabase;
use Kirby\Exception\LogicException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;

/**
 * Versioned schema migrations.
 *
 * Migrations are raw DDL rather than Kirby's `Sql` builder, which cannot
 * express foreign keys, composite uniques, partial indexes or `ON CONFLICT` —
 * all of which this schema depends on.
 */
final class Migrations
{
    /**
     * The schema version this code expects.
     *
     * Bump it in the same commit that adds a migration file.
     */
    public const SCHEMA = 2;

    /**
     * Checks the schema before any query runs.
     *
     * The check is a single `file_get_contents` against a marker file, not a
     * SQL query, so the common case — schema already current — costs almost
     * nothing even though it runs on every connection.
     */
    public static function guard(KirbyDatabase $db): void
    {
        $applied = static::version($db);

        if ($applied === static::SCHEMA) {
            return;
        }

        // An empty database is an unambiguous fresh install: nothing can be
        // lost, so set it up without asking.
        if ($applied === null) {
            static::run($db);

            return;
        }

        if ($applied > static::SCHEMA) {
            throw new LogicException(
                message: 'The newsletter database was created by a newer version of the plugin '
                    . '(schema ' . $applied . ' > ' . static::SCHEMA . '). Refusing to write.'
            );
        }

        if (Newsletter::option('migrate.auto', false) === true) {
            static::run($db);

            return;
        }

        throw new LogicException(
            message: 'The newsletter database schema is out of date (' . $applied . ' < '
                . static::SCHEMA . '). Run `kirby newsletter:migrate` or set the '
                . '`e9li.kirby-newsletter.migrate.auto` option.'
        );
    }

    /**
     * Applies every migration newer than the recorded version.
     *
     * @return array<int, string> the names of the migrations that ran
     */
    public static function run(KirbyDatabase $db, bool $pretend = false): array
    {
        static::table($db);

        $applied = static::applied($db);
        $ran     = [];

        foreach (static::available() as $version => $file) {
            if (in_array($version, $applied, true) === true) {
                continue;
            }

            $name = F::name($file);

            if ($pretend === true) {
                $ran[] = $name;
                continue;
            }

            $migration = require static::dir() . '/' . $file;

            if (is_callable($migration) === false) {
                throw new LogicException(
                    message: 'Migration ' . $name . ' must return a callable.'
                );
            }

            // SQLite runs DDL inside transactions, so a migration that fails
            // half way leaves no partial schema behind.
            $db->connection()->exec('BEGIN');

            try {
                $migration($db);

                $db->execute(
                    'INSERT INTO nl_migrations (version, name, applied_at) VALUES (:v, :n, :t)',
                    ['v' => $version, 'n' => $name, 't' => time()]
                );

                $db->connection()->exec('COMMIT');
            } catch (\Throwable $e) {
                $db->connection()->exec('ROLLBACK');

                throw $e;
            }

            $ran[] = $name;
        }

        if ($pretend === false) {
            static::marker(static::SCHEMA);
        }

        return $ran;
    }

    /**
     * Every migration file, keyed and ordered by its numeric version.
     *
     * @return array<int, string>
     */
    public static function available(): array
    {
        $files = [];

        foreach (Dir::files(static::dir()) as $file) {
            if (preg_match('/^(\d+)-.+\.php$/', $file, $match) !== 1) {
                continue;
            }

            $files[(int)$match[1]] = $file;
        }

        ksort($files);

        return $files;
    }

    /**
     * Versions already recorded in the database.
     *
     * @return array<int, int>
     */
    public static function applied(KirbyDatabase $db): array
    {
        $rows = $db->query(
            'SELECT version FROM nl_migrations ORDER BY version',
            [],
            ['fetch' => 'array', 'iterator' => 'array']
        );

        return array_map('intval', array_column($rows ?: [], 'version'));
    }

    /**
     * Versions that have not been applied yet.
     *
     * @return array<int, int>
     */
    public static function pending(KirbyDatabase $db): array
    {
        static::table($db);

        return array_values(array_diff(
            array_keys(static::available()),
            static::applied($db)
        ));
    }

    /**
     * Creates the bookkeeping table itself. Not a migration — it has to exist
     * before any migration can be recorded.
     */
    protected static function table(KirbyDatabase $db): void
    {
        $db->execute(
            'CREATE TABLE IF NOT EXISTS nl_migrations (
                version    INTEGER PRIMARY KEY,
                name       TEXT    NOT NULL,
                applied_at INTEGER NOT NULL
            )'
        );
    }

    /**
     * The schema version actually in effect.
     *
     * Returns null for a database that has never been migrated.
     *
     * The marker file is only a fast path. It cannot be the source of truth,
     * because opening the connection creates an empty SQLite file before this
     * ever runs — so "file exists" says nothing about whether the schema does.
     * When the marker is missing or stale we ask the database itself, which
     * also makes a restored backup and an interrupted install reconcile
     * correctly instead of hard-failing.
     */
    public static function version(KirbyDatabase $db): int|null
    {
        $marker = static::marker();

        if ($marker !== null && $marker === static::SCHEMA) {
            return $marker;
        }

        if (static::hasTable($db, 'nl_migrations') === false) {
            return null;
        }

        $highest = $db->query(
            'SELECT MAX(version) AS version FROM nl_migrations',
            [],
            ['fetch' => 'array', 'iterator' => 'array']
        );

        $version = $highest[0]['version'] ?? null;

        // The bookkeeping table exists but holds nothing: an install that died
        // between creating the table and applying the first migration.
        return $version === null ? null : (int)$version;
    }

    protected static function hasTable(KirbyDatabase $db, string $table): bool
    {
        $rows = $db->query(
            'SELECT name FROM sqlite_master WHERE type = "table" AND name = :name',
            ['name' => $table],
            ['fetch' => 'array', 'iterator' => 'array']
        );

        return ($rows[0] ?? null) !== null;
    }

    /**
     * Reads or writes the schema marker file.
     *
     * Returns null when the marker does not exist — never a version number
     * inferred from the filesystem.
     */
    public static function marker(int|null $write = null): int|null
    {
        $file = Newsletter::root() . '/.schema';

        if ($write !== null) {
            F::write($file, (string)$write);

            return $write;
        }

        if (is_file($file) === false) {
            return null;
        }

        $value = trim((string)F::read($file));

        return $value === '' ? null : (int)$value;
    }

    public static function dir(): string
    {
        return dirname(__DIR__) . '/migrations';
    }
}

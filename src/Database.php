<?php

namespace E9li\Newsletter;

use Kirby\Database\Database as KirbyDatabase;
use PDO;
use Throwable;

/**
 * The plugin's own SQLite connection.
 *
 * Deliberately not `Kirby\Database\Db`: that class holds a single static
 * connection for the whole site, so using it here would hijack (or be hijacked
 * by) a project's own database. We own this connection end to end.
 */
final class Database
{
    protected static KirbyDatabase|null $db = null;

    protected static bool $migrated = false;

    /**
     * Absolute path of the SQLite file.
     */
    public static function file(): string
    {
        return Newsletter::root() . '/newsletter.sqlite';
    }

    /**
     * Returns the connection, creating the data root and running pending
     * migrations on first use.
     *
     * Lazy on purpose: a page view that never touches the newsletter pays
     * nothing, because nothing calls this.
     */
    public static function connection(): KirbyDatabase
    {
        if (static::$db !== null) {
            return static::$db;
        }

        Newsletter::bootstrap();

        $db = new KirbyDatabase([
            'type'     => 'sqlite',
            'database' => static::file(),
            'id'       => 'e9li-newsletter',
        ]);

        // Kirby's Database swallows every SQL error and returns false unless
        // failing is switched on. A queue that silently loses writes is far
        // worse than one that crashes, and the send loop's compare-and-swap
        // claim depends on a failed UPDATE being distinguishable from a
        // no-op — so we always fail loudly.
        $db->fail(true);

        $pdo = $db->connection();

        // Kirby forces this on for SQLite for backwards compatibility, which
        // turns every integer column into a string. We own this connection, so
        // we can have real types and skip casting in every model.
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

        static::pragmas($pdo);
        static::secure();

        static::$db = $db;

        if (static::$migrated === false) {
            static::$migrated = true;
            Migrations::guard($db);
        }

        return static::$db;
    }

    /**
     * Applies the connection pragmas.
     *
     * WAL matters most: the web request that records a signup and the cron tick
     * that drains the queue write concurrently, and the default rollback
     * journal would make them block each other.
     */
    protected static function pragmas(PDO $pdo): void
    {
        try {
            $pdo->exec('PRAGMA journal_mode = WAL');
        } catch (Throwable) {
            // WAL is unavailable on some network filesystems. TRUNCATE is the
            // safe fallback; the settings view surfaces which mode is active.
            $pdo->exec('PRAGMA journal_mode = TRUNCATE');
        }

        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA temp_store = MEMORY');
    }

    /**
     * Restricts the database files to the owner.
     *
     * The default umask would leave a world-readable .sqlite holding every
     * subscriber address. Called from both the connection and the migrate
     * command, since the latter creates the file without going through
     * connection().
     */
    public static function secure(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            $file = static::file() . $suffix;

            if (is_file($file) === true) {
                @chmod($file, 0600);
            }
        }
    }

    /**
     * The journal mode actually in effect, for the Panel settings view.
     */
    public static function journalMode(): string
    {
        return (string)static::connection()
            ->connection()
            ->query('PRAGMA journal_mode')
            ->fetchColumn();
    }

    /**
     * Runs a SELECT and returns plain associative arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function query(string $sql, array $bindings = []): array
    {
        $result = static::connection()->query($sql, $bindings, [
            'fetch'    => 'array',
            'iterator' => 'array',
        ]);

        return $result === false ? [] : $result;
    }

    /**
     * Returns the first row of a SELECT, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function first(string $sql, array $bindings = []): array|null
    {
        return static::query($sql, $bindings)[0] ?? null;
    }

    /**
     * Returns the first column of the first row — for COUNT(*) and friends.
     */
    public static function value(string $sql, array $bindings = []): mixed
    {
        $row = static::first($sql, $bindings);

        return $row === null ? null : reset($row);
    }

    /**
     * Returns one column of every row.
     *
     * @return array<int, mixed>
     */
    public static function column(string $sql, array $bindings = [], string $column = 'id'): array
    {
        return array_column(static::query($sql, $bindings), $column);
    }

    public static function execute(string $sql, array $bindings = []): bool
    {
        return static::connection()->execute($sql, $bindings);
    }

    public static function affected(): int
    {
        return static::connection()->affected() ?? 0;
    }

    public static function lastId(): int
    {
        return (int)static::connection()->lastId();
    }

    /**
     * Inserts a row and returns its id.
     */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $holders = array_map(static fn (string $c): string => ':' . $c, $columns);

        static::execute(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') '
            . 'VALUES (' . implode(', ', $holders) . ')',
            $data
        );

        return static::lastId();
    }

    /**
     * Updates rows and returns the number affected.
     *
     * Data keys are prefixed with `set_` in the bindings so a column name can
     * also appear in the WHERE clause without the two colliding.
     */
    public static function update(string $table, array $data, string $where, array $bindings = []): int
    {
        $assignments = [];
        $dataBindings = [];

        foreach ($data as $column => $value) {
            $assignments[] = $column . ' = :set_' . $column;
            $dataBindings['set_' . $column] = $value;
        }

        static::execute(
            'UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $where,
            [...$dataBindings, ...$bindings]
        );

        return static::affected();
    }

    public static function delete(string $table, string $where, array $bindings = []): int
    {
        static::execute('DELETE FROM ' . $table . ' WHERE ' . $where, $bindings);

        return static::affected();
    }

    /**
     * Runs a callback inside a transaction.
     *
     * `BEGIN IMMEDIATE` takes the write lock up front rather than on the first
     * write. That is what makes the queue claim safe: two concurrent ticks can
     * never both read the same rows and then both try to claim them.
     */
    public static function transaction(callable $callback, bool $immediate = true): mixed
    {
        $pdo = static::connection()->connection();

        $pdo->exec($immediate === true ? 'BEGIN IMMEDIATE' : 'BEGIN');

        try {
            $result = $callback();
            $pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');

            throw $e;
        }
    }

    /**
     * Whether the database file exists yet.
     */
    public static function exists(): bool
    {
        return is_file(static::file());
    }

    /**
     * Size of the database on disk, including the WAL sibling.
     */
    public static function size(): int
    {
        $size = 0;

        foreach (['', '-wal', '-shm'] as $suffix) {
            $file = static::file() . $suffix;

            if (is_file($file) === true) {
                $size += (int)filesize($file);
            }
        }

        return $size;
    }

    /**
     * Drops the memoised connection. Used by tests and the migrate command.
     */
    public static function flush(): void
    {
        static::$db = null;
        static::$migrated = false;
    }
}

<?php

namespace E9li\Newsletter;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Throwable;

/**
 * Append-only plain-text log.
 *
 * Send runs happen on cron with nobody watching, so anything that goes wrong
 * has to leave a trace somewhere durable. Operational detail goes here;
 * per-message outcomes go to the `nl_events` table, which is queryable.
 */
final class Log
{
    public const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public static function debug(string $message, array $context = []): void
    {
        static::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        static::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        static::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        static::write('error', $message, $context);
    }

    public static function exception(Throwable $e, string $message = ''): void
    {
        static::error($message !== '' ? $message : $e->getMessage(), [
            'exception' => $e::class,
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        if (static::enabled($level) === false) {
            return;
        }

        $line = sprintf(
            '[%s] %-7s %s%s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $file = static::file();

        if (is_dir(dirname($file)) === false) {
            Dir::make(dirname($file), true);
        }

        // Appended with LOCK_EX because the cron tick and a web request can
        // both be writing; without it, interleaved lines corrupt each other.
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    protected static function enabled(string $level): bool
    {
        $minimum = (string)Newsletter::option('log.level', 'info');

        return (static::LEVELS[$level] ?? 1) >= (static::LEVELS[$minimum] ?? 1);
    }

    public static function file(): string
    {
        $file = Newsletter::option('log.file');

        if (is_string($file) === true && $file !== '') {
            return $file;
        }

        return App::instance()->root('logs') . '/newsletter.log';
    }
}

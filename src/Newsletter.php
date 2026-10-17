<?php

namespace E9li\Newsletter;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Toolkit\A;

/**
 * Plugin façade.
 *
 * Owns option access, the data root and the language helpers that every other
 * class in the plugin needs. Deliberately the only place that knows the option
 * prefix, so renaming the plugin is a one-line change.
 */
final class Newsletter
{
    public const VERSION = '0.4.0';

    public const PREFIX = 'e9li.kirby-newsletter.';

    /**
     * Directories created inside the data root on first use.
     */
    public const DIRS = ['locks', 'artifacts', 'outbox'];

    protected static string|null $root = null;

    /**
     * Reads a plugin option.
     *
     * Options are flat and dot-separated (`smtp.host`, not a nested array), so
     * a site can override a single leaf without restating the whole group.
     */
    public static function option(string $key, mixed $default = null): mixed
    {
        return App::instance()->option(static::PREFIX . $key, $default);
    }

    /**
     * The data root holding the SQLite database, the secret, locks and artifacts.
     *
     * Defaults to `site/newsletter`. On a stock Kirby layout `site/` sits inside
     * the docroot and is protected only by the shipped `.htaccess`, which nginx
     * ignores — hence the guards in bootstrap() and the `root` option for
     * moving the whole directory somewhere genuinely unreachable.
     */
    public static function root(): string
    {
        if (static::$root !== null) {
            return static::$root;
        }

        $root = static::option('root');

        if (is_string($root) === false || $root === '') {
            // realpath on the site root, because Kirby resolves it relative to
            // the index root (`public/../site`) and the unresolved `..` would
            // show up in every log line and Panel status view.
            $site = App::instance()->root('site');
            $root = (realpath($site) ?: $site) . '/newsletter';
        }

        return static::$root = rtrim($root, '/');
    }

    /**
     * Creates the data root and its deny-all guards.
     *
     * Both guards are belt-and-braces: the `.htaccess` covers Apache, the
     * `index.php` covers any server that would otherwise happily serve a
     * directory listing or the raw .sqlite file.
     */
    public static function bootstrap(): void
    {
        $root = static::root();

        if (is_dir($root) === false) {
            Dir::make($root, true);
            @chmod($root, 0700);
        }

        $htaccess = $root . '/.htaccess';

        if (is_file($htaccess) === false) {
            F::write($htaccess, implode("\n", [
                '# Apache 2.4+',
                '<IfModule mod_authz_core.c>',
                '    Require all denied',
                '</IfModule>',
                '',
                '# Apache 2.2',
                '<IfModule !mod_authz_core.c>',
                '    Order allow,deny',
                '    Deny from all',
                '</IfModule>',
                '',
            ]));
        }

        $index = $root . '/index.php';

        if (is_file($index) === false) {
            F::write($index, '<?php http_response_code(404);' . "\n");
        }

        foreach (static::DIRS as $dir) {
            if (is_dir($root . '/' . $dir) === false) {
                Dir::make($root . '/' . $dir, true);
            }
        }
    }

    /**
     * All language codes of the site.
     *
     * Returns `[null]` on a single-language site so callers can loop uniformly
     * without branching on multilang everywhere.
     *
     * @return array<string|null>
     */
    public static function languages(): array
    {
        $kirby = App::instance();

        if ($kirby->multilang() === false) {
            return [null];
        }

        return $kirby->languages()->codes();
    }

    /**
     * The site's default language code, or null on a single-language site.
     */
    public static function defaultLanguage(): string|null
    {
        $kirby = App::instance();

        if ($kirby->multilang() === false) {
            return null;
        }

        return $kirby->defaultLanguage()?->code();
    }

    /**
     * Normalises a user-supplied language code against the site's languages.
     *
     * Falls back from a regional code to its base (`de-CH` → `de`) before
     * giving up, because signup forms and imports routinely carry either form.
     */
    public static function language(string|null $code): string|null
    {
        $kirby = App::instance();

        if ($kirby->multilang() === false || $code === null || $code === '') {
            return static::defaultLanguage();
        }

        $codes = $kirby->languages()->codes();

        if (in_array($code, $codes, true) === true) {
            return $code;
        }

        $base = strtolower(explode('-', $code)[0]);

        foreach ($codes as $candidate) {
            if (strtolower(explode('-', $candidate)[0]) === $base) {
                return $candidate;
            }
        }

        return static::defaultLanguage();
    }

    /**
     * Runs a callback with a given language active, then restores the previous one.
     *
     * `App::setCurrentLanguage()` also mutates `Locale::set()` and
     * `Str::$language`, so it is process-global: without the finally, a Panel
     * request that renders a French preview would leave the whole request in
     * French. Every language switch in this plugin goes through here.
     */
    public static function withLanguage(string|null $code, callable $callback): mixed
    {
        $kirby = App::instance();

        if ($kirby->multilang() === false) {
            return $callback();
        }

        $previous = $kirby->language()?->code();

        try {
            $kirby->setCurrentLanguage($code ?? static::defaultLanguage());

            return $callback();
        } finally {
            $kirby->setCurrentLanguage($previous);
        }
    }

    /**
     * Merges the plugin's translations with per-language option overrides.
     *
     * Mirrors the pattern used by kirby-consent so the two plugins behave the
     * same way when a project wants to reword a single string.
     */
    public static function translations(): array
    {
        $translations = [];
        $overrides    = static::option('translations', []);

        foreach (Dir::files(dirname(__DIR__) . '/translations') as $file) {
            if (F::extension($file) !== 'php') {
                continue;
            }

            $code = F::name($file);
            $strings = require dirname(__DIR__) . '/translations/' . $file;

            if (is_array($strings) === false) {
                continue;
            }

            if (isset($overrides[$code]) === true && is_array($overrides[$code]) === true) {
                $strings = A::merge($strings, $overrides[$code], A::MERGE_REPLACE);
            }

            $translations[$code] = $strings;
        }

        return $translations;
    }

    /**
     * Overrides the data root for the rest of the process.
     *
     * Exists so the smoke test can run against a throwaway database. Kirby's
     * plugin options are resolved once at boot and cannot be rewritten from a
     * CLI command, so an explicit seam is safer than trying to mutate them.
     */
    public static function useRoot(string $root): void
    {
        static::$root = rtrim($root, '/');
    }

    /**
     * Resets memoised state. Only used by tests and long-running CLI processes.
     */
    public static function flush(): void
    {
        static::$root = null;
    }
}

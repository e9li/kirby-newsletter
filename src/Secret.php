<?php

namespace E9li\Newsletter;

use Kirby\Exception\LogicException;
use Kirby\Filesystem\F;

/**
 * The HMAC key behind every unsubscribe, preferences and archive link.
 *
 * Deliberately not Kirby's `content.salt`: its default value is the content
 * path (see `Kirby\Cms\App::contentSalt()`), which is guessable from outside
 * and would make every unsubscribe token forgeable.
 */
final class Secret
{
    protected static string|null $key = null;

    /**
     * Returns the signing key, generating and persisting one on first use.
     */
    public static function key(): string
    {
        if (static::$key !== null) {
            return static::$key;
        }

        $option = Newsletter::option('secret');

        if (is_string($option) === true && $option !== '') {
            return static::$key = $option;
        }

        $file = static::file();

        if (is_file($file) === true) {
            $key = trim((string)F::read($file));

            if ($key !== '') {
                return static::$key = $key;
            }
        }

        return static::$key = static::generate();
    }

    /**
     * Previously used keys, so links from before a rotation keep working.
     *
     * Unsubscribe links live in inboxes forever; breaking them would leave
     * people unable to opt out, which is the one failure mode that turns into
     * spam complaints.
     *
     * @return array<int, string>
     */
    public static function legacyKeys(): array
    {
        $keys = Newsletter::option('secretLegacy', []);

        return is_array($keys) === true ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * Every key a signature may be verified against, current first.
     *
     * @return array<int, string>
     */
    public static function verificationKeys(): array
    {
        return [static::key(), ...static::legacyKeys()];
    }

    /**
     * Creates a new key and writes it to the data root with 0600 permissions.
     */
    public static function generate(): string
    {
        $key  = bin2hex(random_bytes(32));
        $file = static::file();

        Newsletter::bootstrap();

        if (F::write($file, $key) === false) {
            throw new LogicException(
                message: 'Could not write the newsletter secret to ' . $file
                    . '. Set the `e9li.kirby-newsletter.secret` option instead.'
            );
        }

        @chmod($file, 0600);

        return static::$key = $key;
    }

    /**
     * Whether a key exists without creating one — for the Panel settings view.
     */
    public static function exists(): bool
    {
        $option = Newsletter::option('secret');

        if (is_string($option) === true && $option !== '') {
            return true;
        }

        return is_file(static::file()) === true;
    }

    public static function file(): string
    {
        return Newsletter::root() . '/secret.key';
    }

    public static function flush(): void
    {
        static::$key = null;
    }
}

<?php

namespace E9li\Newsletter\Send;

use E9li\Newsletter\Log;
use E9li\Newsletter\Newsletter;
use Kirby\Cms\App;

/**
 * How a tick hands a message to the network.
 *
 * `kirby` is not a bulk transport: Kirby's mailer opens one SMTP connection
 * per message. Postal and sendmail are left for later; asking for them fails
 * the send rather than silently writing a log and calling it delivered.
 */
abstract class Transport
{
    protected static self|null $forced = null;

    abstract public function send(Message $message): SendResult;

    public function close(): void
    {
    }

    /**
     * Pins a transport for the rest of the process. The smoke test uses this
     * so a debug install still asserts against the outbox.
     */
    public static function force(self|null $transport): void
    {
        static::$forced = $transport;
    }

    public static function resolve(): self
    {
        if (static::$forced !== null) {
            return static::$forced;
        }

        $name = Newsletter::option('transport');

        if (is_string($name) === false || $name === '') {
            $name = App::instance()->option('debug') === true ? 'log' : 'smtp';
        }

        return match ($name) {
            'log'   => new LogTransport(),
            'smtp'  => new SmtpTransport(),
            default => new UnavailableTransport($name),
        };
    }

    public static function name(): string
    {
        $name = Newsletter::option('transport');

        if (is_string($name) === true && $name !== '') {
            return $name;
        }

        return App::instance()->option('debug') === true ? 'log' : 'smtp';
    }

    /**
     * Strips CR/LF so a name or subject cannot break out of a header.
     */
    public static function header(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    /**
     * SMTP settings, plugin options first, then Kirby's own email transport
     * so the Mailpit block in config.php is reused without a second copy.
     *
     * @return array{host: string, port: int, security: mixed, auth: bool, username: string|null, password: string|null, timeout: int, localDomain: string|null, keepAlive: bool, maxPerConnection: int}
     */
    public static function smtpConfig(): array
    {
        $kirby = App::instance()->option('email.transport', []);
        $kirby = is_array($kirby) === true ? $kirby : [];

        $host = Newsletter::option('smtp.host');

        if (is_string($host) === false || $host === '') {
            $host = $kirby['host'] ?? '';
        }

        $security = Newsletter::option('smtp.security', 'tls');

        if (array_key_exists('security', $kirby) === true && Newsletter::option('smtp.host') === null) {
            $security = $kirby['security'];
        }

        $auth = Newsletter::option('smtp.auth', true);

        if (Newsletter::option('smtp.host') === null && array_key_exists('auth', $kirby) === true) {
            $auth = $kirby['auth'];
        }

        $port = Newsletter::option('smtp.port', null);

        if ($port === null || (Newsletter::option('smtp.host') === null && isset($kirby['port']) === true)) {
            $port = $kirby['port'] ?? $port ?? 587;
        }

        $username = Newsletter::option('smtp.username');
        $password = Newsletter::option('smtp.password');

        if ((is_string($username) === false || $username === '') && isset($kirby['username']) === true) {
            $username = $kirby['username'];
        }

        if ((is_string($password) === false || $password === '') && isset($kirby['password']) === true) {
            $password = $kirby['password'];
        }

        $local = Newsletter::option('smtp.localDomain');

        return [
            'host'             => (string)$host,
            'port'             => (int)$port,
            'security'         => $security,
            'auth'             => $auth === true,
            'username'         => is_string($username) && $username !== '' ? $username : null,
            'password'         => is_string($password) && $password !== '' ? $password : null,
            'timeout'          => (int)Newsletter::option('smtp.timeout', 30),
            'localDomain'      => is_string($local) && $local !== '' ? $local : null,
            'keepAlive'        => Newsletter::option('smtp.keepAlive', true) === true,
            'maxPerConnection' => max(1, (int)Newsletter::option('smtp.maxPerConnection', 200)),
        ];
    }
}

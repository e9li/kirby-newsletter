<?php

namespace E9li\Newsletter\Send;

use E9li\Newsletter\Newsletter;
use Kirby\Filesystem\F;

/**
 * Writes the MIME to the outbox instead of opening a socket.
 *
 * Used when `transport` is `log`, and when `debug` is on and `transport` is
 * empty — the path that works with no Mailpit and no network.
 */
final class LogTransport extends Transport
{
    public function send(Message $message): SendResult
    {
        $dir = Newsletter::option('log.outbox');

        if (is_string($dir) === false || $dir === '') {
            $dir = Newsletter::root() . '/outbox';
        }

        if (is_dir($dir) === false) {
            \Kirby\Filesystem\Dir::make($dir, true);
        }

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $message->messageId) ?: 'message';
        $file = rtrim($dir, '/') . '/' . $name . '.eml';

        if (F::write($file, static::mime($message)) === false) {
            return new SendResult(false, null, 'Could not write the outbox file.');
        }

        return new SendResult(true, 250, null);
    }

    public static function mime(Message $message): string
    {
        $from = static::address(Transport::header($message->from), $message->fromName);
        $to   = static::address(Transport::header($message->to), $message->toName);
        $id   = Transport::header($message->messageId);
        $boundary = 'nl_' . bin2hex(random_bytes(8));

        $headers = [
            'From: ' . $from,
            'To: ' . $to,
            'Subject: ' . static::encoded(Transport::header($message->subject)),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: ' . $id,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        if ($message->replyTo !== null && $message->replyTo !== '') {
            $headers[] = 'Reply-To: ' . Transport::header($message->replyTo);
        }

        foreach ($message->headers as $name => $value) {
            $headers[] = Transport::header((string)$name) . ': ' . Transport::header($value);
        }

        $text = static::part($boundary, 'text/plain; charset=UTF-8', $message->text);
        $html = static::part($boundary, 'text/html; charset=UTF-8', $message->html);

        return implode("\r\n", $headers)
            . "\r\n\r\n"
            . $text
            . $html
            . '--' . $boundary . "--\r\n";
    }

    protected static function part(string $boundary, string $type, string $body): string
    {
        return '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $type . "\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body))
            . "\r\n";
    }

    protected static function address(string $email, string|null $name): string
    {
        $name = $name === null ? '' : Transport::header($name);

        if ($name === '') {
            return $email;
        }

        return static::encoded($name) . ' <' . $email . '>';
    }

    protected static function encoded(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) === 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }
}

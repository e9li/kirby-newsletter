<?php

namespace E9li\Newsletter;

use Kirby\Cms\App;
use Throwable;

/**
 * Transactional mail: opt-in confirmations, unsubscribe receipts, admin reports.
 *
 * These deliberately go through Kirby's own `$kirby->email()`. They are low
 * volume, one per user action, and inherit whatever transport the site already
 * configured. Campaign sending does NOT use this — `Kirby\Email\Email` sends
 * inside its constructor and builds a fresh PHPMailer per message, so 5,000
 * recipients would open 5,000 SMTP connections.
 */
final class Mailer
{
    /**
     * Sends a transactional message.
     *
     * Returns false rather than throwing: a confirmation mail that cannot be
     * sent must not turn into a 500 for the visitor who just submitted the
     * form. The failure is logged and surfaced as a friendly error instead.
     */
    public static function send(
        string $to,
        string $subject,
        string $template,
        array $data = [],
        string|null $language = null,
        array $headers = [],
    ): bool {
        $kirby = App::instance();

        $from = static::from();

        if ($from === null) {
            Log::error('Cannot send transactional mail: no `from` address configured', [
                'template' => $template,
            ]);

            return false;
        }

        try {
            $kirby->email([
                'template' => 'newsletter/' . $template,
                'from'     => $from,
                'fromName' => Newsletter::option('fromName'),
                'replyTo'  => Newsletter::option('replyTo') ?? $from,
                'to'       => $to,
                'subject'  => $subject,
                'data'     => [
                    ...$data,
                    'language' => $language,
                    'site'     => $kirby->site(),
                ],
                // The only header escape hatch Kirby\Email\PHPMailer offers.
                // Must NOT be a static closure: Kirby invokes it with
                // Closure::call(), which is an error for static closures.
                'beforeSend' => function ($mailer) use ($headers) {
                    // Transactional mail must never look like a campaign to a
                    // filter, and must never be auto-replied to by an
                    // out-of-office responder.
                    $mailer->addCustomHeader('Auto-Submitted', 'auto-generated');
                    $mailer->addCustomHeader('X-Auto-Response-Suppress', 'All');

                    foreach ($headers as $name => $value) {
                        $mailer->addCustomHeader($name, $value);
                    }

                    return $mailer;
                },
            ]);

            Log::info('Transactional mail sent', [
                'template' => $template,
                'to'       => EmailAddress::mask($to),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::exception($e, 'Transactional mail failed (' . $template . ')');

            return false;
        }
    }

    /**
     * The sender address, falling back to the site's own configured `from`.
     */
    public static function from(): string|null
    {
        $from = Newsletter::option('from');

        if (is_string($from) === true && $from !== '') {
            return $from;
        }

        $fallback = App::instance()->option('email.presets.default.from');

        return is_string($fallback) === true && $fallback !== '' ? $fallback : null;
    }

    /**
     * The domain messages are sent from.
     *
     * Used for Message-ID and List-Id, and checked against DMARC alignment in
     * preflight — a `From` that does not align with the signing domain is the
     * most common reason a technically valid message still lands in spam.
     */
    public static function sendingDomain(): string|null
    {
        $domain = Newsletter::option('sendingDomain');

        if (is_string($domain) === true && $domain !== '') {
            return $domain;
        }

        $from = static::from();

        if ($from === null) {
            return null;
        }

        $domain = EmailAddress::domain($from);

        return $domain === '' ? null : $domain;
    }
}

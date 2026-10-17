<?php

namespace E9li\Newsletter\Send;

use E9li\Newsletter\Log;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * One SMTP connection per tick.
 *
 * PHPMailer's SMTPKeepAlive is what campaign sending needs and what
 * `$kirby->email()` cannot do: that path builds a fresh client per message.
 */
final class SmtpTransport extends Transport
{
    protected PHPMailer|null $mailer = null;

    protected int $sentOnConnection = 0;

    public function send(Message $message): SendResult
    {
        try {
            $mailer = $this->connection();
        } catch (MailException $e) {
            return new SendResult(false, static::codeOf($e->getMessage()), $e->getMessage());
        }

        if ($mailer === null) {
            return new SendResult(false, null, 'SMTP host is not configured.');
        }

        try {
            $mailer->clearAllRecipients();
            $mailer->clearReplyTos();
            $mailer->clearCustomHeaders();
            $mailer->clearAttachments();

            $mailer->setFrom(
                Transport::header($message->from),
                $message->fromName === null ? '' : Transport::header($message->fromName)
            );

            if ($message->replyTo !== null && $message->replyTo !== '') {
                $mailer->addReplyTo(Transport::header($message->replyTo));
            }

            $mailer->addAddress(
                Transport::header($message->to),
                $message->toName === null ? '' : Transport::header($message->toName)
            );

            $mailer->Subject   = Transport::header($message->subject);
            $mailer->MessageID = Transport::header($message->messageId);
            $mailer->isHTML(true);
            $mailer->Body    = $message->html;
            $mailer->AltBody = $message->text;
            $mailer->CharSet = 'UTF-8';

            foreach ($message->headers as $name => $value) {
                $mailer->addCustomHeader(Transport::header((string)$name), Transport::header($value));
            }

            $mailer->send();
            $this->sentOnConnection++;

            $cap = Transport::smtpConfig()['maxPerConnection'];

            if ($this->sentOnConnection >= $cap) {
                $this->close();
            }

            return new SendResult(true, 250, null);
        } catch (MailException $e) {
            Log::warning('SMTP send failed', ['error' => $e->getMessage()]);

            // The connection may be wedged after an error. Drop it so the
            // next message dials again instead of reusing a dead socket.
            $this->close();

            return new SendResult(false, static::codeOf($e->getMessage()), $e->getMessage());
        }
    }

    public function close(): void
    {
        if ($this->mailer !== null) {
            $this->mailer->smtpClose();
            $this->mailer = null;
        }

        $this->sentOnConnection = 0;
    }

    /**
     * @throws MailException
     */
    protected function connection(): PHPMailer|null
    {
        if ($this->mailer !== null) {
            return $this->mailer;
        }

        $config = Transport::smtpConfig();

        if ($config['host'] === '') {
            return null;
        }

        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host        = $config['host'];
        $mailer->Port        = $config['port'];
        $mailer->SMTPAuth    = $config['auth'];
        $mailer->Username    = $config['username'] ?? '';
        $mailer->Password    = $config['password'] ?? '';
        $mailer->Timeout     = $config['timeout'];
        $mailer->SMTPKeepAlive = $config['keepAlive'];
        $mailer->Hostname    = $config['localDomain'] ?? $config['host'];

        $security = $config['security'];

        if ($security === false || $security === '' || $security === 'false') {
            $mailer->SMTPSecure  = false;
            $mailer->SMTPAutoTLS = false;
        } else {
            $mailer->SMTPSecure = $security === true ? 'tls' : (string)$security;
        }

        $this->mailer = $mailer;
        $this->sentOnConnection = 0;

        return $mailer;
    }

    /**
     * Pulls a three-digit SMTP code out of PHPMailer's exception text.
     */
    public static function codeOf(string $error): int|null
    {
        if (preg_match('/\b([245]\d{2})\b/', $error, $match) === 1) {
            return (int)$match[1];
        }

        return null;
    }
}

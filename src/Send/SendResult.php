<?php

namespace E9li\Newsletter\Send;

/**
 * Outcome of handing one message to a transport.
 *
 * `$code` is the SMTP status when we have one. 4xx defers the whole domain;
 * any failure is still retried on this row until the attempt cap.
 */
final class SendResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int|null $code = null,
        public readonly string|null $error = null,
    ) {
    }

    public function defersDomain(): bool
    {
        return $this->ok === false && $this->code !== null && $this->code >= 400 && $this->code < 500;
    }
}

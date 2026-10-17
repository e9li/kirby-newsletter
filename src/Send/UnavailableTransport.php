<?php

namespace E9li\Newsletter\Send;

use E9li\Newsletter\Log;

/**
 * Placeholder for a transport this version does not ship.
 * A tick must not report those messages as delivered.
 */
final class UnavailableTransport extends Transport
{
    public function __construct(protected string $configured)
    {
    }

    public function send(Message $message): SendResult
    {
        Log::error('Bulk transport is not available', ['transport' => $this->configured]);

        return new SendResult(false, null, 'Transport "' . $this->configured . '" is not available.');
    }
}

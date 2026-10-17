<?php

namespace E9li\Newsletter\Send;

/**
 * One outbound campaign message, already rendered for a single recipient.
 *
 * @param array<string, string> $headers extra headers, names already canonical
 */
final class Message
{
    public function __construct(
        public readonly string $to,
        public readonly string|null $toName,
        public readonly string $from,
        public readonly string|null $fromName,
        public readonly string|null $replyTo,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly string $messageId,
        public readonly array $headers = [],
    ) {
    }
}

<?php

namespace E9li\Newsletter;

/**
 * Outcome of a public use-case.
 *
 * Returned rather than thrown: everything in `Signup` is reached from an
 * anonymous HTTP request, where the only useful outcome is a message on a page,
 * and where an uncaught exception would leak internals to a visitor.
 */
final class Result
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly array $data = [],
    ) {
    }

    public static function ok(string $code, array $data = []): self
    {
        return new self(true, $code, $data);
    }

    public static function error(string $code, array $data = []): self
    {
        return new self(false, $code, $data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * The translation key for this outcome, so templates never hard-code copy.
     */
    public function message(): string
    {
        return 'e9li.newsletter.message.' . $this->code;
    }

    public function toArray(): array
    {
        return [
            'ok'      => $this->ok,
            'code'    => $this->code,
            'message' => $this->message(),
        ];
    }
}

<?php declare(strict_types=1);

namespace Taxmod\Core\Http;

/** What came back: status, body, and the API's trace id when it sends one (D-1409). */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $traceId = '',
    ) {
    }

    /** @return array<string, mixed> `mixed` because each API answers in its own shape */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}

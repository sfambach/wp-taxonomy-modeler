<?php declare(strict_types=1);

namespace Taxmod\Core\Http;

/**
 * One call to a web API, as the core describes it; the edge sends it (CD-1). The one request of every API module
 * (plan-dubletten row 3, D-1409; was a copy in DHL, eBay, GLS and JLCPCB).
 */
final class HttpRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }
}

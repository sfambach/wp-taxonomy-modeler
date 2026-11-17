<?php declare(strict_types=1);

namespace Taxmod\WordPress\Http;

use Taxmod\Core\Http\HttpRequest;
use Taxmod\Core\Http\HttpResponse;
use Taxmod\Core\Http\HttpTransport;

/**
 * The core's HTTP port over `wp_remote_request` — the one place an API module reaches the web, with one timeout (D-1409).
 * The module names its own failure, so its handlers keep catching its own exception.
 */
final class WpHttpTransport implements HttpTransport
{
    /**
     * @param \Closure(string): \Throwable $unreachable the module's exception for «nothing came back», given WordPress's reason
     * @param string                      $traceHeader the response header carrying the API's trace id, if any
     */
    public function __construct(
        private readonly \Closure $unreachable,
        private readonly string $traceHeader = '',
        private readonly int $timeout = 20,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $answer = wp_remote_request($request->url, [
            'method'  => $request->method,
            'headers' => $request->headers,
            'body'    => $request->body === '' ? null : $request->body,
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($answer)) {
            throw ($this->unreachable)($answer->get_error_message());
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($answer),
            (string) wp_remote_retrieve_body($answer),
            $this->traceHeader === '' ? '' : (string) wp_remote_retrieve_header($answer, $this->traceHeader),
        );
    }
}

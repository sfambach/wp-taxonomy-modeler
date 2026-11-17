<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Http\HttpRequest;
use Taxmod\Core\Http\HttpResponse;

/** The shared HTTP shapes of the API modules (D-1409). */
final class HttpResponseTest extends TestCase
{
    #[Test]
    public function json_reads_an_object_and_gives_nothing_for_anything_else(): void
    {
        $this->assertSame(['a' => 1], (new HttpResponse(200, '{"a":1}'))->json());
        $this->assertSame([], (new HttpResponse(500, 'Bad Gateway'))->json());
        $this->assertSame([], (new HttpResponse(200, '"text"'))->json());
    }

    #[Test]
    public function a_request_keeps_what_it_was_given(): void
    {
        $request = new HttpRequest('POST', 'https://api.example/x', ['Accept' => 'application/json'], '{}');

        $this->assertSame(['POST', 'https://api.example/x', ['Accept' => 'application/json'], '{}'], [$request->method, $request->url, $request->headers, $request->body]);
        $this->assertSame('', (new HttpResponse(204, ''))->traceId);
    }
}

<?php declare(strict_types=1);

namespace Taxmod\Core\Http;

/** The core's port to the web; a test hands in its own (D-1409). */
interface HttpTransport
{
    /** @throws \Throwable the module's own exception when nothing came back */
    public function send(HttpRequest $request): HttpResponse;
}

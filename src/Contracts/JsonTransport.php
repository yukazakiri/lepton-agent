<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Contracts;

use Yukazakiri\Lepton\DTOs\HttpResponse;

interface JsonTransport
{
    /**
     * No automatic retries or redirects. Callers own mutation recovery.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $body
     */
    public function request(string $method, string $url, #[\SensitiveParameter] array $headers = [], #[\SensitiveParameter] ?array $body = null): HttpResponse;
}

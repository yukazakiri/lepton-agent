<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Support;

use JsonException;
use Yukazakiri\Lepton\Contracts\JsonTransport;
use Yukazakiri\Lepton\DTOs\HttpResponse;

final readonly class CurlJsonTransport implements JsonTransport
{
    public function __construct(private int $timeout = 30, private int $connectTimeout = 5) {}

    public function request(string $method, string $url, #[\SensitiveParameter] array $headers = [], #[\SensitiveParameter] ?array $body = null): HttpResponse
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new LeptonRuntimeException('SDK HTTP endpoints must use HTTPS without embedded credentials.');
        }

        $responseHeaders = [];
        $handle = curl_init($url);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n]/', $name.$value)) {
                throw new LeptonRuntimeException('Invalid HTTP header.');
            }
            $headerLines[] = $name.': '.$value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $raw = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            throw new LeptonRuntimeException('SDK HTTP transport failed; mutation outcome may be unknown.');
        }
        if ($status < 200 || $status >= 300) {
            return new HttpResponse($status, headers: $responseHeaders);
        }
        if ($status === 204 && $raw === '') {
            return new HttpResponse($status, headers: $responseHeaders);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new LeptonRuntimeException('SDK endpoint returned invalid JSON; mutation outcome may be unknown.');
        }
        if (! is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            throw new LeptonRuntimeException('SDK endpoint returned an unexpected JSON shape.');
        }

        return new HttpResponse($status, $decoded, $responseHeaders);
    }
}

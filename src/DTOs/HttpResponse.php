<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\DTOs;

final readonly class HttpResponse
{
    /** @param array<string, mixed> $data @param array<string, string> $headers */
    public function __construct(public int $status, public array $data = [], public array $headers = []) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($name, $key) === 0) {
                return $value;
            }
        }

        return null;
    }
}

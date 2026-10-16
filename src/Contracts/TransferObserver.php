<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Contracts;

use Yukazakiri\Lepton\DTOs\TransferResolution;

interface TransferObserver
{
    /** @param array<string, mixed> $options */
    public function resolve(string $handle, array $options = []): TransferResolution;
}

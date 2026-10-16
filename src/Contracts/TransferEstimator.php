<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Contracts;

interface TransferEstimator
{
    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function estimateTransfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): array;
}

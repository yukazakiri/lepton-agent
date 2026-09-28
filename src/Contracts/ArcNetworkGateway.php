<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Contracts;

interface ArcNetworkGateway
{
    public function rpcUrl(): string;

    public function chainCode(): string;

    public function chainId(): int;

    public function blockNumber(): string;

    public function treasuryAddress(): ?string;

    public function explorerUrl(string $txHash): string;
}

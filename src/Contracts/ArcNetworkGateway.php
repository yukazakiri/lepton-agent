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

    public function addressExplorerUrl(string $address): string;

    /**
     * Generic JSON-RPC passthrough, for reads the Circle CLI does not cover
     * (native Arc USDC balances, receipts, contract calls, ...).
     *
     * @param  array<int, mixed>  $params
     */
    public function rpc(string $method, array $params = []): mixed;
}

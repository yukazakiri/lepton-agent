<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Gateways;

use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Support\CliRunner;

final class ArcCanteenGateway implements ArcNetworkGateway
{
    public function __construct(
        private readonly CliRunner $arc,
        private readonly string $chainCode = 'ARC-TESTNET',
        private readonly int $chainId = 5042002,
        private readonly ?string $treasuryAddress = null,
        private readonly ?string $configuredRpcUrl = null,
        private readonly string $testnetExplorer = 'https://testnet.arcscan.app/tx/',
        private readonly string $mainnetExplorer = 'https://arcscan.app/tx/',
    ) {}

    public function rpcUrl(): string
    {
        if (is_string($this->configuredRpcUrl) && $this->configuredRpcUrl !== '') {
            return $this->configuredRpcUrl;
        }

        $out = $this->arc->run(['rpc-url'], false);

        return is_string($out) ? trim($out) : '';
    }

    public function chainCode(): string
    {
        return $this->chainCode;
    }

    public function chainId(): int
    {
        return $this->chainId;
    }

    public function blockNumber(): string
    {
        $out = $this->arc->run(['rpc', 'eth_blockNumber'], false);

        return is_string($out) ? trim($out, "\" \n") : '';
    }

    public function treasuryAddress(): ?string
    {
        return $this->treasuryAddress;
    }

    public function explorerUrl(string $txHash): string
    {
        return rtrim($this->explorerBase(), '/').'/tx/'.$txHash;
    }

    public function addressExplorerUrl(string $address): string
    {
        return rtrim($this->explorerBase(), '/').'/address/'.$address;
    }

    public function rpc(string $method, array $params = []): mixed
    {
        $args = ['rpc', $method];

        if ($params !== []) {
            $args[] = json_encode($params, JSON_THROW_ON_ERROR);
        }

        $out = $this->arc->run($args, false);

        if (! is_string($out) || trim($out) === '') {
            return null;
        }

        $decoded = json_decode(trim($out), true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : trim($out);
    }

    private function explorerBase(): string
    {
        $base = $this->chainCode === 'ARC' ? $this->mainnetExplorer : $this->testnetExplorer;

        // Config historically stored a "/tx/" suffix; normalise either shape.
        return rtrim(preg_replace('#/tx/?$#', '', $base) ?? $base, '/');
    }
}

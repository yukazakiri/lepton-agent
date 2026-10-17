<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Gateways;

use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\JsonTransport;
use Yukazakiri\Lepton\Support\Amounts;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\CurlJsonTransport;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

final class ArcCanteenGateway implements ArcNetworkGateway
{
    private bool $networkVerified = false;

    public const TESTNET_RPC_URL = 'https://rpc.testnet.arc.io';

    public const MAINNET_RPC_URL = 'https://rpc.mainnet.arc.io';

    public function __construct(
        private readonly ?CliRunner $arc = null,
        private readonly string $chainCode = 'ARC-TESTNET',
        private readonly int $chainId = 5042002,
        private readonly ?string $treasuryAddress = null,
        private readonly ?string $configuredRpcUrl = null,
        private readonly string $testnetExplorer = 'https://testnet.arcscan.app/tx/',
        private readonly string $mainnetExplorer = 'https://arcscan.app/tx/',
        private readonly int $timeout = 10,
        private readonly ?JsonTransport $transport = null,
    ) {}

    public function rpcUrl(): string
    {
        if (is_string($this->configuredRpcUrl) && $this->configuredRpcUrl !== '') {
            return $this->configuredRpcUrl;
        }

        return $this->defaultPublicRpcUrl();
    }

    public function defaultPublicRpcUrl(): string
    {
        return match ($this->chainCode) {
            'ARC-TESTNET' => self::TESTNET_RPC_URL,
            'ARC' => self::MAINNET_RPC_URL,
            default => throw new LeptonRuntimeException('Unsupported Arc network; no mainnet fallback.'),
        };
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
        $res = $this->rpc('eth_blockNumber');

        return is_string($res) ? $res : '';
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
        if (! $this->networkVerified) {
            $network = $this->httpJsonRpc($this->rpcUrl(), 'eth_chainId', []);
            $validNetworks = [
                ['ARC', 5040],
                ['ARC', 5040001],
                ['ARC', 5042],
                ['ARC-TESTNET', 5042],
                ['ARC-TESTNET', 5042002],
            ];
            if (! is_string($network) || Amounts::fromHexQuantity($network, 0) !== (string) $this->chainId
                || ! in_array([$this->chainCode, $this->chainId], $validNetworks, true)) {
                throw new LeptonRuntimeException('Arc RPC chain ID does not match the configured network.');
            }
            $this->networkVerified = true;
            if ($method === 'eth_chainId') {
                return $network;
            }
        }

        return $this->httpJsonRpc($this->rpcUrl(), $method, $params);
    }

    private function httpJsonRpc(string $url, string $method, array $params): mixed
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ];
        $response = ($this->transport ?? new CurlJsonTransport($this->timeout))->request('POST', $url,
            ['Accept' => 'application/json', 'Content-Type' => 'application/json'], $payload);
        $decoded = $response->data;
        if ($response->status !== 200 || ($decoded['jsonrpc'] ?? null) !== '2.0' || ($decoded['id'] ?? null) !== 1
            || isset($decoded['error']) || ! array_key_exists('result', $decoded)) {
            throw new LeptonRuntimeException('Arc RPC rejected or malformed the response; no alternate network was queried.');
        }

        return $decoded['result'];
    }

    private function explorerBase(): string
    {
        $base = $this->chainCode === 'ARC' ? $this->mainnetExplorer : $this->testnetExplorer;

        // Config historically stored a "/tx/" suffix; normalise either shape.
        return rtrim(preg_replace('#/tx/?$#', '', $base) ?? $base, '/');
    }
}

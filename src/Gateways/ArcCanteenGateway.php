<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Gateways;

use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

final class ArcCanteenGateway implements ArcNetworkGateway
{
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
    ) {}

    public function rpcUrl(): string
    {
        if (is_string($this->configuredRpcUrl) && $this->configuredRpcUrl !== '') {
            return $this->configuredRpcUrl;
        }

        if ($this->arc !== null) {
            try {
                $out = $this->arc->run(['rpc-url'], false);
                $url = is_string($out) ? trim($out) : '';
                if ($url !== '') {
                    return $url;
                }
            } catch (Throwable) {
                // Fall through to public endpoint
            }
        }

        return $this->defaultPublicRpcUrl();
    }

    public function defaultPublicRpcUrl(): string
    {
        return str_contains(strtoupper($this->chainCode), 'TESTNET')
            ? self::TESTNET_RPC_URL
            : self::MAINNET_RPC_URL;
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
        $url = $this->rpcUrl();

        if ($url !== '') {
            try {
                return $this->httpJsonRpc($url, $method, $params);
            } catch (Throwable $httpException) {
                if ($this->arc === null) {
                    throw $httpException;
                }
            }
        }

        if ($this->arc !== null) {
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

        return null;
    }

    private function httpJsonRpc(string $url, string $method, array $params): mixed
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ], JSON_THROW_ON_ERROR);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
            ]);
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw === false || $code >= 400) {
                throw new LeptonRuntimeException("Arc RPC HTTP request to {$url} failed (code {$code}): {$err}");
            }

            $decoded = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
            if (isset($decoded['error'])) {
                $msg = is_array($decoded['error']) ? ($decoded['error']['message'] ?? json_encode($decoded['error'])) : (string) $decoded['error'];
                throw new LeptonRuntimeException("Arc RPC error: {$msg}");
            }

            return $decoded['result'] ?? null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new LeptonRuntimeException("Arc RPC HTTP request failed to {$url}");
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (isset($decoded['error'])) {
            $msg = is_array($decoded['error']) ? ($decoded['error']['message'] ?? json_encode($decoded['error'])) : (string) $decoded['error'];
            throw new LeptonRuntimeException("Arc RPC error: {$msg}");
        }

        return $decoded['result'] ?? null;
    }

    private function explorerBase(): string
    {
        $base = $this->chainCode === 'ARC' ? $this->mainnetExplorer : $this->testnetExplorer;

        // Config historically stored a "/tx/" suffix; normalise either shape.
        return rtrim(preg_replace('#/tx/?$#', '', $base) ?? $base, '/');
    }
}

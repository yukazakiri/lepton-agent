<?php

declare(strict_types=1);

use Yukazakiri\Lepton\Contracts\JsonTransport;
use Yukazakiri\Lepton\DTOs\HttpResponse;
use Yukazakiri\Lepton\Gateways\ArcCanteenGateway;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

it('fetches block number and executes rpc queries via direct HTTP JSON-RPC', function (): void {
    $gateway = new ArcCanteenGateway(
        arc: null,
        chainCode: 'ARC-TESTNET',
        chainId: 5042002,
        configuredRpcUrl: 'https://rpc.testnet.arc.io',
        transport: new class implements JsonTransport
        {
            public function request(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse
            {
                expect($url)->toBe('https://rpc.testnet.arc.io');

                return new HttpResponse(200, ['jsonrpc' => '2.0', 'id' => 1, 'result' => match ($body['method']) {
                    'eth_blockNumber' => '0x402ac5a', 'eth_chainId' => '0x4cef52', default => '0x0',
                }]);
            }
        },
    );

    expect($gateway->rpcUrl())->toBe('https://rpc.testnet.arc.io')
        ->and($gateway->chainCode())->toBe('ARC-TESTNET')
        ->and($gateway->chainId())->toBe(5042002);

    $block = $gateway->blockNumber();
    expect($block)->toBeString()->toStartWith('0x');

    $balance = $gateway->rpc('eth_getBalance', ['0x0000000000000000000000000000000000000000', 'latest']);
    expect($balance)->toBeString()->toStartWith('0x');
});

it('defaults public rpc url when configured url is omitted', function (): void {
    $testnetGateway = new ArcCanteenGateway(arc: null, chainCode: 'ARC-TESTNET');
    expect($testnetGateway->rpcUrl())->toBe(ArcCanteenGateway::TESTNET_RPC_URL);

    $mainnetGateway = new ArcCanteenGateway(arc: null, chainCode: 'ARC');
    expect($mainnetGateway->rpcUrl())->toBe(ArcCanteenGateway::MAINNET_RPC_URL);
});

it('rejects the wrong Arc chain and never falls back to a different transport', function (): void {
    $gateway = new ArcCanteenGateway(transport: new class implements JsonTransport
    {
        public function request(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse
        {
            return new HttpResponse(200, ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x13b2']);
        }
    });
    expect(fn () => $gateway->blockNumber())->toThrow(LeptonRuntimeException::class);
});

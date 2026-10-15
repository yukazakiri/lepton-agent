<?php

declare(strict_types=1);

use Yukazakiri\Lepton\Gateways\ArcCanteenGateway;
use Yukazakiri\Lepton\Support\CliRunner;

it('fetches block number and executes rpc queries via direct HTTP JSON-RPC', function (): void {
    $gateway = new ArcCanteenGateway(
        arc: null,
        chainCode: 'ARC-TESTNET',
        chainId: 5042002,
        configuredRpcUrl: 'https://rpc.testnet.arc.io',
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

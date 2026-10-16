<?php

declare(strict_types=1);

use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\CircleGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Contracts\X402Gateway;

/** tests/Feature -> tests -> package root */
function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

function readmeText(): string
{
    return (string) file_get_contents(packageRoot().'/README.md');
}

function leptonConfigText(): string
{
    return (string) file_get_contents(packageRoot().'/config/lepton.php');
}

it('documents only environment variables that exist in config', function (): void {
    preg_match_all('/\bLEPTON_[A-Z_]+\b/', readmeText(), $matches);

    expect($matches[0])->not->toBeEmpty();

    $config = leptonConfigText();

    $missing = array_values(array_filter(
        array_unique($matches[0]),
        fn (string $variable): bool => ! str_contains($config, $variable),
    ));

    expect($missing)->toBe([], 'README documents variables absent from config/lepton.php: '.implode(', ', $missing));
});

it('only references classes that exist', function (): void {
    preg_match_all('/\bYukazakiri\\\\Lepton\\\\[A-Za-z0-9_\\\\]+/', readmeText(), $matches);

    expect($matches[0])->not->toBeEmpty();

    $missing = [];

    foreach (array_unique($matches[0]) as $reference) {
        $class = str_replace('\\\\', '\\', $reference);
        $relative = substr($class, strlen('Yukazakiri\\Lepton\\'));
        $path = packageRoot().'/src/'.str_replace('\\', '/', $relative).'.php';

        if (! file_exists($path)) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([], 'README references classes with no source file: '.implode(', ', $missing));
});

it('only documents commands that are registered', function (): void {
    preg_match_all('/php artisan (lepton:[a-z-]+)/', readmeText(), $matches);

    expect($matches[1])->not->toBeEmpty();

    $sources = '';
    foreach (glob(packageRoot().'/src/Console/Commands/*.php') ?: [] as $file) {
        $sources .= (string) file_get_contents($file);
    }

    // Signatures may wrap across lines, so compare on whitespace-collapsed text.
    $haystack = (string) preg_replace('/\s+/', ' ', $sources);

    $missing = array_values(array_filter(
        array_unique($matches[1]),
        fn (string $command): bool => ! str_contains($haystack, $command),
    ));

    expect($missing)->toBe([], 'README documents commands with no matching Artisan command: '.implode(', ', $missing));
});

it('documents every contract method it advertises', function (): void {
    $expectations = [
        AuthGateway::class => ['authStatus', 'beginLogin', 'completeLogin', 'isAuthenticatedFor'],
        WalletGateway::class => ['transfer', 'balance', 'transactions', 'limits'],
        ArcNetworkGateway::class => ['rpc', 'blockNumber', 'explorerUrl', 'addressExplorerUrl', 'chainId', 'chainCode', 'rpcUrl', 'treasuryAddress'],
        X402Gateway::class => ['searchServices', 'inspectService', 'payService', 'gatewayBalance'],
        CircleGateway::class => ['run', 'wallets', 'createWallet', 'fundWallet', 'signMessage', 'signTypedData', 'execute', 'gatewayDeposit', 'gatewayWithdraw', 'bridgeTransfer', 'bridgeStatus', 'bridgeFee', 'contractAddress', 'contractQuery', 'blockchains'],
    ];

    foreach ($expectations as $interface => $methods) {
        $source = (string) file_get_contents((new ReflectionClass($interface))->getFileName());

        foreach ($methods as $method) {
            expect($source)->toContain($method.'(');
        }
    }
});

it('links to the official Circle and Arc documentation', function (): void {
    $readme = readmeText();
    $expected = [
        'https://developers.circle.com/agent-stack/circle-cli',
        'https://developers.circle.com/agent-stack/agent-wallets/wallet-operations/authenticate',
        'https://developers.circle.com/agent-stack/agent-wallets/wallet-operations/transfer',
        'https://docs.arc.io',
        'https://arc-node.thecanteenapp.com/',
    ];

    foreach ($expected as $url) {
        expect($readme)->toContain($url);
    }
});

it('documents the Arc-specific facts that break naive implementations', function (): void {
    $readme = readmeText();
    // These are the failure modes we hit in production; the README must keep
    // warning about them even if the wording changes.
    expect($readme)->toContain('5042002')          // testnet chain id
        ->and($readme)->toContain('5042')           // mainnet chain id
        ->and($readme)->toContain('18 decimals')     // native USDC precision
        ->and($readme)->toContain('gas token')      // USDC pays gas on Arc
        ->and($readme)->toContain('fromHexQuantity') // hexdec() overflow guard
        ->and($readme)->toContain('not allowed by the proxy') // RPC allowlist
        ->and($readme)->toContain('agent')          // agent vs local wallet trap
        ->and($readme)->toContain('claim, not proof'); // hash verification
});

it('never presents the fake driver as settling real funds', function (): void {
    $readme = readmeText();
    expect($readme)->toMatch('/no network, no credentials, and no cost/i')
        ->and($readme)->toMatch('/`fake`/')
        ->and($readme)->toContain('LEPTON_DRIVER=circle');
});

it('documents unresolved REST production gates and the notification trust boundary', function (): void {
    $readme = readmeText();

    expect($readme)->toContain('[Production readiness](#production-readiness)')
        ->and($readme)->toContain('Implemented hardening is not certification.')
        ->and($readme)->toContain('GET /v2/notifications/publicKey/{keyId}')
        ->and($readme)->toContain('ECDSA_SHA_256')
        ->and($readme)->toContain('unique `notificationId`')
        ->and($readme)->toContain('persisted UUID v4')
        ->and($readme)->toContain('Mock-only tests do not satisfy live gates.')
        ->and($readme)->not->toContain('no polling loop is');
});

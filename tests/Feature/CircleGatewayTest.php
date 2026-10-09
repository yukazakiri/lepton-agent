<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Yukazakiri\Lepton\Contracts\CircleGateway;
use Yukazakiri\Lepton\Facades\Lepton;
use Yukazakiri\Lepton\Gateways\CircleCliGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\LeptonManager;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

it('binds CircleGateway to FakeLeptonGateway under fake driver', function (): void {
    config(['lepton.default' => 'fake']);

    $circle = app(CircleGateway::class);

    expect($circle)->toBeInstanceOf(FakeLeptonGateway::class);
});

it('exercises all CircleGateway methods on FakeLeptonGateway', function (): void {
    $fake = new FakeLeptonGateway(treasuryAddress: '0xTreasury123');

    // Wallets
    $wallets = $fake->wallets();
    expect($wallets)->not->toBeEmpty()
        ->and($wallets[0]['address'])->toBe('0xTreasury123');

    $newWallet = $fake->createWallet(['testnet' => true]);
    expect($newWallet['status'])->toBe('created')
        ->and($newWallet['wallets'][0]['address'])->toStartWith('0x')
        ->and(count($fake->wallets()))->toBe(2);

    // Fund
    $funded = $fake->fundWallet('0xRecipient', ['chain' => 'ARC-TESTNET']);
    expect($funded['status'])->toBe('funded')
        ->and($fake->balance('0xRecipient')->amountBaseUnits)->toBe(20_000000);

    // Sign
    $signedMsg = $fake->signMessage('0xTreasury123', 'hello');
    expect($signedMsg['signature'])->toStartWith('0x');

    $signedTyped = $fake->signTypedData('0xTreasury123', '{"domain":{}}');
    expect($signedTyped['signature'])->toStartWith('0x');

    // Execute
    $exec = $fake->execute('0xTreasury123', '0xContract', 'transfer(address,uint256)', ['0xTo', '100']);
    expect($exec['txHash'])->toStartWith('0x');

    // Gateway
    $deposit = $fake->gatewayDeposit('0xTreasury123', '5.0');
    expect($deposit['status'])->toBe('deposited');

    $withdraw = $fake->gatewayWithdraw('0xTreasury123', 5_000000);
    expect($withdraw['status'])->toBe('withdrawn');

    // Bridge
    $bridge = $fake->bridgeTransfer('0xTreasury123', 'BASE', '10.0');
    expect($bridge['burnTxHash'])->toStartWith('0x');

    $status = $fake->bridgeStatus('0xBurnTx');
    expect($status['status'])->toBe('complete');

    $fee = $fake->bridgeFee('ARC-TESTNET', 'BASE');
    expect($fee['fee'])->toBe('0.50');

    // Contracts
    $contracts = $fake->contractAddress('USDC');
    expect($contracts)->not->toBeEmpty();

    $query = $fake->contractQuery('0xContract', 'name()');
    expect($query['contract'])->toBe('0xContract');

    // Blockchains
    $blockchains = $fake->blockchains();
    expect($blockchains)->toHaveCount(2);

    // Generic run
    $run = $fake->run(['blockchain', 'list']);
    expect($run['blockchains'])->toBeArray();

    // Stub run
    $fake->stubRun('custom cmd', ['custom' => 'ok']);
    expect($fake->run(['custom', 'cmd']))->toBe(['custom' => 'ok']);
});

it('exposes CircleGateway methods on LeptonManager and Facade', function (): void {
    config(['lepton.default' => 'fake']);

    $manager = app(LeptonManager::class);

    expect($manager->circle())->toBeInstanceOf(CircleGateway::class);

    $created = $manager->createWallet(['testnet' => true]);
    expect($created['status'])->toBe('created');

    $funded = $manager->fundWallet('0xTestUser');
    expect($funded['status'])->toBe('funded');

    $wallets = $manager->listWallets();
    expect($wallets)->toBeArray();

    // Via Facade
    expect(Lepton::circle())->toBeInstanceOf(CircleGateway::class)
        ->and(Lepton::createWallet()['status'])->toBe('created')
        ->and(Lepton::fundWallet('0xUser2')['status'])->toBe('funded')
        ->and(Lepton::listWallets())->toBeArray();
});

it('constructs correct CLI arguments in CircleCliGateway', function (): void {
    $runner = new class extends CliRunner
    {
        public array $ranArgs = [];

        public array $ranEnv = [];

        public function __construct()
        {
            parent::__construct('circle', 60);
        }

        public function run(array $args, bool $expectJson = true, array $env = []): array|string
        {
            $this->ranArgs = $args;
            $this->ranEnv = $env;

            return $expectJson ? ['data' => ['success' => true]] : 'raw output';
        }

        public function runJson(array $args, array $env = []): array|string
        {
            $this->ranArgs = $args;
            $this->ranEnv = $env;

            return ['data' => ['success' => true]];
        }
    };

    $gateway = new CircleCliGateway($runner, usdcDecimals: 6, dryRun: false, defaultRpcUrl: 'https://rpc.arc');

    // Wallets
    $gateway->wallets(['chain' => 'ARC-TESTNET', 'type' => 'agent']);
    expect($runner->ranArgs)->toContain('wallet', 'list', '--chain', 'ARC-TESTNET', '--type', 'agent');

    // Create wallet
    $gateway->createWallet(['testnet' => true, 'idempotencyKey' => 'key-123']);
    expect($runner->ranArgs)->toContain('wallet', 'create', '--testnet', '--idempotency-key', 'key-123');

    // Fund wallet
    $gateway->fundWallet('0xDest', ['chain' => 'ARC-TESTNET', 'amount' => '20', 'token' => 'usdc']);
    expect($runner->ranArgs)->toContain('wallet', 'fund', '--address', '0xDest', '--chain', 'ARC-TESTNET', '--amount', '20', '--token', 'usdc');

    // Sign message
    $gateway->signMessage('0xFrom', 'msg', ['chain' => 'ARC-TESTNET']);
    expect($runner->ranArgs)->toContain('wallet', 'sign', 'message', 'msg', '--address', '0xFrom');

    // Execute contract
    $gateway->execute('0xFrom', '0xContract', 'mint(address)', ['0xTo'], ['chain' => 'ARC-TESTNET', 'estimate' => true]);
    expect($runner->ranArgs)->toContain('wallet', 'execute', 'mint(address)', '0xTo', '--contract', '0xContract', '--address', '0xFrom', '--estimate');

    // Gateway deposit
    $gateway->gatewayDeposit('0xFrom', 15_000000, ['chain' => 'BASE-SEPOLIA', 'method' => 'eco']);
    expect($runner->ranArgs)->toContain('gateway', 'deposit', '--amount', '15', '--address', '0xFrom', '--chain', 'BASE-SEPOLIA', '--method', 'eco');

    // Bridge transfer
    $gateway->bridgeTransfer('0xFrom', 'ARB', '10.5', ['chain' => 'BASE', 'recipient' => '0xRecip']);
    expect($runner->ranArgs)->toContain('bridge', 'transfer', 'ARB', '0xRecip', '--amount', '10.5', '--address', '0xFrom', '--chain', 'BASE');

    // Bridge status
    $gateway->bridgeStatus('0xBurnTx123', ['chain' => 'BASE']);
    expect($runner->ranArgs)->toContain('bridge', 'status', '0xBurnTx123', '--chain', 'BASE');

    // Bridge fee
    $gateway->bridgeFee('ARC-TESTNET', 'BASE');
    expect($runner->ranArgs)->toBe(['bridge', 'get-fee', 'BASE', '--chain', 'ARC-TESTNET']);

    // Contract address
    $gateway->contractAddress('usdc');
    expect($runner->ranArgs)->toContain('contract', 'address', 'usdc');

    // Contract query
    $gateway->contractQuery('0xContract', 'balanceOf(address)', ['0xUser'], ['chain' => 'ARC-TESTNET']);
    expect($runner->ranArgs)->toContain('contract', 'query', 'balanceOf(address)', '0xUser', '--contract', '0xContract');

    // Blockchains
    $gateway->blockchains();
    expect($runner->ranArgs)->toContain('blockchain', 'list');
});

it('executes lepton:circle command when allowed', function (): void {
    config([
        'lepton.default' => 'fake',
        'lepton.allow_raw_commands' => true,
    ]);

    Artisan::call('lepton:circle', ['cliArgs' => ['blockchain', 'list']]);

    $output = Artisan::output();
    expect($output)->toContain('ARC-TESTNET');
});

it('guards mutating CircleCliGateway methods under dryRun mode', function (): void {
    $runner = new class extends CliRunner
    {
        public function __construct()
        {
            parent::__construct('circle', 60);
        }

        public function run(array $args, bool $expectJson = true, array $env = []): array|string
        {
            return ['data' => ['success' => true]];
        }

        public function runJson(array $args, array $env = []): array|string
        {
            return ['data' => ['success' => true]];
        }
    };

    $dryGateway = new CircleCliGateway($runner, usdcDecimals: 6, dryRun: true, defaultRpcUrl: 'https://rpc.arc');

    expect(fn () => $dryGateway->createWallet())->toThrow(LeptonRuntimeException::class)
        ->and(fn () => $dryGateway->fundWallet('0xAddr'))->toThrow(LeptonRuntimeException::class)
        ->and(fn () => $dryGateway->gatewayDeposit('0xAddr', '5.0'))->toThrow(LeptonRuntimeException::class)
        ->and(fn () => $dryGateway->gatewayWithdraw('0xAddr', '5.0'))->toThrow(LeptonRuntimeException::class)
        ->and(fn () => $dryGateway->bridgeTransfer('0xAddr', 'BASE', '5.0'))->toThrow(LeptonRuntimeException::class);
});

it('blocks raw Circle CLI commands when allow_raw_commands is disabled', function (): void {
    config([
        'lepton.default' => 'fake',
        'lepton.allow_raw_commands' => false,
    ]);

    $exit = Artisan::call('lepton:circle', ['cliArgs' => ['blockchain', 'list']]);
    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('disabled');

    $manager = app(LeptonManager::class);
    expect(fn () => $manager->runCircle(['blockchain', 'list']))->toThrow(LeptonRuntimeException::class);
});

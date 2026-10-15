<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Facades\Lepton;

beforeEach(function (): void {
    config([
        'lepton.default' => 'fake',
        'lepton.arc.treasury' => '0xTreasuryOriginal',
        'lepton.arc.chain' => 'ARC-TESTNET',
    ]);
});

it('lists wallets via lepton:wallets command', function (): void {
    Artisan::call('lepton:wallets');
    $output = Artisan::output();

    expect($output)->toContain('Address')
        ->and($output)->toContain('0xTreasuryOriginal')
        ->and($output)->toContain('(treasury)');
});

it('lists wallets as JSON via lepton:wallets --json', function (): void {
    Artisan::call('lepton:wallets', ['--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toBeArray()
        ->and($data[0]['address'])->toBe('0xTreasuryOriginal');
});

it('creates wallet via lepton:wallet:create', function (): void {
    Artisan::call('lepton:wallet:create', ['--testnet' => true]);
    $output = Artisan::output();

    expect($output)->toContain('Wallet created successfully')
        ->and($output)->toContain('0x');

    // Confirm wallet now appears in list
    Artisan::call('lepton:wallets');
    expect(Artisan::output())->toContain('ARC-TESTNET');
});

it('creates wallet as JSON via lepton:wallet:create --json', function (): void {
    Artisan::call('lepton:wallet:create', ['--testnet' => true, '--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toBeArray()
        ->and($data['status'])->toBe('created')
        ->and($data['wallets'][0]['address'])->toStartWith('0x');
});

it('funds wallet via lepton:wallet:fund with explicit address', function (): void {
    Artisan::call('lepton:wallet:fund', ['address' => '0xRecipient123']);
    $output = Artisan::output();

    expect($output)->toContain('Funding request submitted for 0xRecipient123')
        ->and($output)->toContain('ARC-TESTNET');

    // Confirm balance updated in fake gateway
    expect(Lepton::balance('0xRecipient123')->amountBaseUnits)->toBe(20_000000);
});

it('funds treasury wallet via lepton:wallet:fund without address argument', function (): void {
    Artisan::call('lepton:wallet:fund');
    $output = Artisan::output();

    expect($output)->toContain('Funding request submitted for 0xTreasuryOriginal');
});

it('funds wallet as JSON via lepton:wallet:fund --json', function (): void {
    Artisan::call('lepton:wallet:fund', ['address' => '0xRecipient456', '--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toBeArray()
        ->and($data['status'])->toBe('funded')
        ->and($data['address'])->toBe('0xRecipient456');
});

it('dispatches list, create, and fund via unified lepton:wallet command', function (): void {
    // List
    Artisan::call('lepton:wallet', ['action' => 'list']);
    expect(Artisan::output())->toContain('0xTreasuryOriginal');

    // Create
    Artisan::call('lepton:wallet', ['action' => 'create', '--testnet' => true]);
    expect(Artisan::output())->toContain('Wallet created successfully');

    // Fund
    Artisan::call('lepton:wallet', ['action' => 'fund', 'address' => '0xUnifiedTarget']);
    expect(Artisan::output())->toContain('Funding request submitted for 0xUnifiedTarget');
});

it('provides programmatic wallet management on WalletGateway contract', function (): void {
    $wallets = app(WalletGateway::class);

    $list = $wallets->wallets();
    expect($list)->toBeArray()->not->toBeEmpty();

    $created = $wallets->createWallet(['testnet' => true]);
    expect($created['status'])->toBe('created')
        ->and($created['wallets'][0]['address'])->toStartWith('0x');

    $funded = $wallets->fundWallet('0xProgrammaticUser');
    expect($funded['status'])->toBe('funded')
        ->and($wallets->balance('0xProgrammaticUser')->amountBaseUnits)->toBe(20_000000);
});

it('provides programmatic wallet management on Lepton facade', function (): void {
    $created = Lepton::createWallet(['testnet' => true]);
    expect($created['status'])->toBe('created');

    $list = Lepton::listWallets();
    expect($list)->toBeArray()->not->toBeEmpty();

    $funded = Lepton::fundWallet('0xFacadeUser');
    expect($funded['status'])->toBe('funded')
        ->and(Lepton::balance('0xFacadeUser')->amountBaseUnits)->toBe(20_000000);

    // Via Lepton::wallets() gateway instance
    $walletsGateway = Lepton::wallets();
    expect($walletsGateway->wallets())->toBeArray()
        ->and($walletsGateway->createWallet(['testnet' => true])['status'])->toBe('created')
        ->and($walletsGateway->fundWallet('0xGatewayUser')['status'])->toBe('funded');
});

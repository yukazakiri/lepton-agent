<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

it('completes a split login without requiring the email argument again', function (): void {
    config(['lepton.default' => 'fake']);
    $auth = app(FakeLeptonGateway::class);
    $request = $auth->beginLogin('operator@example.test');
    $exit = Artisan::call('lepton:login', ['--request' => $request, '--otp' => 'B1X-123456']);
    expect($exit)->toBe(0)->and(Artisan::output())->toContain('Logged in');
});

it('estimates via Artisan without returning a transfer or changing balances', function (): void {
    config(['lepton.default' => 'fake']);
    $fake = app(FakeLeptonGateway::class);
    $fake->seedBalance('0xTreasury', 10_000000);
    $exit = Artisan::call('lepton:transfer', ['to' => '0xRecipient', '--from' => '0xTreasury', '--amount' => '1', '--estimate' => true]);
    expect($exit)->toBe(0)->and($fake->balance('0xTreasury')->amountBaseUnits)->toBe(10_000000)
        ->and(Artisan::output())->toContain('broadcast');
});

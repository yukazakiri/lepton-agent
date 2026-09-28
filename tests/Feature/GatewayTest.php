<?php

declare(strict_types=1);

use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\Amounts;

it('converts decimal strings to base units without floats', function (): void {
    expect(Amounts::fromDecimalString('100.00'))->toBe(100_000000)
        ->and(Amounts::fromDecimalString('0.01'))->toBe(10_000)
        ->and(Amounts::toCliAmount(100_000000))->toBe('100')
        ->and(Amounts::toCliAmount(100_500000))->toBe('100.5');
});

it('fake gateway tracks balances in memory', function (): void {
    $gateway = new FakeLeptonGateway;
    $gateway->seedBalance('0xSRC', 500_000000);

    $result = $gateway->transfer('0xSRC', '0xDST', 100_000000);

    expect($result->isFake)->toBeTrue()
        ->and($gateway->balance('0xSRC')->amountBaseUnits)->toBe(400_000000)
        ->and($gateway->balance('0xDST')->amountBaseUnits)->toBe(100_000000);
});

it('converts hex quantities above PHP_INT_MAX exactly', function (string $hex, int $decimals, string $expected): void {
    expect(Amounts::fromHexQuantity($hex, $decimals))->toBe($expected);
})->with([
    // Would overflow a 64-bit int cast: 20e18 wei.
    ['0x1158e460913d00000', 18, '20'],
    ['0x4563918244f40000', 18, '5'],
    ['0x0', 18, '0.000000000000000000'],
    ['0x1', 18, '0.000000000000000001'],
    ['0xde0b6b3a7640000', 18, '1'],
    ['0x3d667a7', 0, '64382887'],
    ['0x0', 0, '0'],
    ['0xf4240', 6, '1'],
    ['0x186a0', 6, '0.1'],
]);

it('rejects malformed hex quantities', function (): void {
    expect(fn () => Amounts::fromHexQuantity('nothex'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Amounts::fromHexQuantity(''))->toThrow(InvalidArgumentException::class);
});

<?php

declare(strict_types=1);

use Yukazakiri\Lepton\DTOs\TransferResolution;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\Gateways\CircleCliGateway;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

/**
 * The asynchronous rail.
 *
 * Circle agent wallets answer a transfer with a transaction id, not a hash, and
 * settle later. These tests pin the three properties that make that safe: a
 * transfer with no hash records *no* reference, a handle is never accepted
 * without being shaped like one, and a rail that cannot be asked is unknown
 * rather than failed.
 */

function circleGateway(callable $runner): CircleCliGateway
{
    return new CircleCliGateway(new class($runner) extends CliRunner
    {
        public function __construct(private $runner)
        {
            parent::__construct('circle', 30);
        }

        public function run(array $args, bool $expectJson = true, array $env = []): array|string
        {
            return ($this->runner)($args, $expectJson);
        }
    });
}

test('a transfer with no on-chain reference carries a handle and no hash', function (): void {
    $gateway = circleGateway(fn (array $args): array => [
        'data' => ['id' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3', 'state' => 'ACCEPTED'],
    ]);

    $result = $gateway->transfer('0x'.str_repeat('1', 40), '0x'.str_repeat('2', 40), 30_000000);

    expect($result)->toBeInstanceOf(TransferResult::class)
        ->and($result->txHash)->toBeNull()
        ->and($result->handle)->toBe('116c7ce7-8f2f-53ac-8b06-f18aac151ce3')
        ->and($result->state)->toBe('accepted');
});

test('a response carrying neither a hash nor a handle is refused outright', function (): void {
    // A fee preview is the shape `circle wallet transfer` prints without
    // `--quiet`. It is not evidence that a transfer exists, and it must never
    // become a recorded reference.
    $gateway = circleGateway(fn (array $args): array => [
        'data' => ['blockchain' => 'ARC-TESTNET', 'medium' => ['gasLimit' => '262092', 'networkFee' => '0.0177']],
    ]);

    expect(fn (): TransferResult => $gateway->transfer('0x'.str_repeat('1', 40), '0x'.str_repeat('2', 40), 30_000000))
        ->toThrow(LeptonRuntimeException::class, 'neither an on-chain reference nor a transaction identifier');
});

test('a handle that is not a UUID is not a handle', function (): void {
    $gateway = circleGateway(fn (array $args): array => ['data' => ['id' => 'transfer-accepted']]);

    expect(fn (): TransferResult => $gateway->transfer('0x'.str_repeat('1', 40), '0x'.str_repeat('2', 40), 30_000000))
        ->toThrow(LeptonRuntimeException::class);
});

test('a preflight is an estimate and never a transfer outcome', function (): void {
    $gateway = circleGateway(fn (array $args): array => ['data' => ['medium' => ['networkFee' => '0.0177']]]);

    expect(fn (): TransferResult => $gateway->transfer(
        '0x'.str_repeat('1', 40), '0x'.str_repeat('2', 40), 30_000000, ['estimate' => true],
    ))->toThrow(LeptonRuntimeException::class, 'an estimate, not a submission');
});

test('a completed transaction resolves to a real hash', function (): void {
    $hash = '0x'.str_repeat('f', 64);
    $gateway = circleGateway(fn (array $args): array => [
        'data' => ['transactions' => [['id' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3', 'state' => 'COMPLETE', 'txHash' => $hash]]],
    ]);

    $resolution = $gateway->resolve('116c7ce7-8f2f-53ac-8b06-f18aac151ce3');

    expect($resolution->hasHash())->toBeTrue()
        ->and($resolution->txHash)->toBe($hash)
        ->and($resolution->state)->toBe('completed');
});

test('a transfer that is complete but has no hash yet is still pending', function (): void {
    $gateway = circleGateway(fn (array $args): array => [
        'data' => ['transactions' => [['id' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3', 'state' => 'COMPLETE', 'txHash' => null]]],
    ]);

    expect($gateway->resolve('116c7ce7-8f2f-53ac-8b06-f18aac151ce3')->state)->toBe('pending');
});

test('a rail that cannot be asked is unknown, never failed', function (): void {
    // The distinction is the whole point: a network error is not a conclusion
    // about a payment.
    $gateway = circleGateway(fn (array $args): array => ['data' => ['transactions' => []]]);

    $resolution = $gateway->resolve('116c7ce7-8f2f-53ac-8b06-f18aac151ce3');

    expect($resolution->state)->toBe('unknown')
        ->and($resolution->txHash)->toBeNull();
});

test('a failed transfer is reported as failed', function (): void {
    $gateway = circleGateway(fn (array $args): array => [
        'data' => ['transactions' => [['id' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3', 'state' => 'FAILED']]],
    ]);

    expect($gateway->resolve('116c7ce7-8f2f-53ac-8b06-f18aac151ce3')->state)->toBe('failed');
});

test('a resolution never claims a hash that does not exist', function (): void {
    $gateway = circleGateway(fn (array $args): array => ['data' => ['transactions' => []]]);

    expect($gateway->resolve('116c7ce7-8f2f-53ac-8b06-f18aac151ce3')->hasHash())->toBeFalse();
});

test('the resolution value object cannot be built into a false settlement', function (): void {
    // `pending` and `unknown` both answer hasHash() false, so neither can be
    // promoted into a completed payment by a caller that checks it.
    expect(TransferResolution::pending()->hasHash())->toBeFalse()
        ->and(TransferResolution::unknown('unreachable')->hasHash())->toBeFalse()
        ->and(TransferResolution::failed('rejected')->hasHash())->toBeFalse();
});

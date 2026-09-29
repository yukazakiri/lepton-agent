<?php

declare(strict_types=1);

use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

it('reports no session before login', function (): void {
    $auth = new FakeLeptonGateway;

    $status = $auth->authStatus();

    expect($status['type'])->toBe('agent')
        ->and($status['mainnet']['authenticated'])->toBeFalse()
        ->and($status['testnet']['authenticated'])->toBeFalse()
        ->and($status['testnet']['status'])->toBe('NOT_LOGGED_IN')
        ->and($auth->isAuthenticatedFor('ARC-TESTNET'))->toBeFalse();
});

it('authenticates through the two-step request id and otp flow', function (): void {
    $auth = new FakeLeptonGateway;

    $requestId = $auth->beginLogin('agent@example.test');
    expect($requestId)->not->toBeEmpty()
        ->and($auth->authStatus()['testnet']['authenticated'])->toBeFalse();

    $result = $auth->completeLogin($requestId, 'B1X-123456');

    expect($result['email'])->toBe('agent@example.test')
        ->and($auth->isAuthenticatedFor('ARC-TESTNET'))->toBeTrue()
        ->and($auth->isAuthenticatedFor('ARC'))->toBeTrue();
});

it('rejects an unknown or already-consumed request id', function (): void {
    $auth = new FakeLeptonGateway;
    $auth->beginLogin('agent@example.test');

    expect(fn () => $auth->completeLogin('not-the-id', 'B1X-123456'))
        ->toThrow(LeptonRuntimeException::class);

    $requestId = $auth->beginLogin('agent@example.test');
    $auth->completeLogin($requestId, 'B1X-123456');

    // Request IDs are one-shot.
    expect(fn () => $auth->completeLogin($requestId, 'B1X-123456'))
        ->toThrow(LeptonRuntimeException::class);
});

it('rejects a malformed otp', function (): void {
    $auth = new FakeLeptonGateway;
    $requestId = $auth->beginLogin('agent@example.test');

    expect(fn () => $auth->completeLogin($requestId, '12345'))
        ->toThrow(LeptonRuntimeException::class);
});

it('can be forced into an authenticated state for tests', function (): void {
    $auth = new FakeLeptonGateway;

    expect($auth->fakeAuthenticated()->isAuthenticatedFor('ARC-TESTNET'))->toBeTrue()
        ->and($auth->fakeAuthenticated(false)->isAuthenticatedFor('ARC-TESTNET'))->toBeFalse();
});

<?php

declare(strict_types=1);

use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\HttpResponse;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\Facades\Lepton;
use Yukazakiri\Lepton\Gateways\CircleRestGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\Amounts;
use Yukazakiri\Lepton\Support\ApiException;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;
use Yukazakiri\Lepton\Support\UnsupportedOperation;

it('implements facade transfer and estimator without requiring consumer CLI strings', function (): void {
    config(['lepton.default' => 'fake']);
    app(FakeLeptonGateway::class)->seedBalance('0xTreasury', 20_000000);
    $result = Lepton::transfer('0xTreasury', '0xRecipient', 1_000000);
    expect($result)->toBeInstanceOf(TransferResult::class)
        ->and($result->isFake)->toBeTrue()
        ->and(Lepton::balance('0xTreasury')->amountBaseUnits)->toBe(19_000000)
        ->and(Lepton::estimateTransfer('0xTreasury', '0xRecipient', 1_000000)['broadcast'])->toBeFalse();
});

it('keeps legacy four-method wallet implementations compatible', function (): void {
    $legacy = new class implements WalletGateway
    {
        public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult
        {
            throw new RuntimeException('Read-only stub.');
        }

        public function balance(string $address, array $options = []): BalanceResult
        {
            return new BalanceResult($address, 0, 'ARC-TESTNET', true);
        }

        public function transactions(string $address, array $filters = []): array
        {
            return [];
        }

        public function limits(string $address, array $options = []): array
        {
            return [];
        }
    };
    expect($legacy->balance('0xTreasury')->amountBaseUnits)->toBe(0);
});

it('does not accept integer overflow or floating-point money', function (): void {
    expect(Amounts::fromDecimalString('9223372036854.775807'))->toBe(PHP_INT_MAX);
    expect(fn () => Amounts::fromDecimalString('9223372036854.775808'))->toThrow(OverflowException::class);
    expect(fn () => Amounts::fromDecimalString('1e6'))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid drivers instead of selecting a signer', function (): void {
    config(['lepton.default' => 'typo']);
    expect(fn () => app(WalletGateway::class))->toThrow(InvalidArgumentException::class);
});

it('requires persisted UUID v4 idempotency for every REST transfer', function (): void {
    $calls = [];
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: function ($method, $url, $query, $body) use (&$calls): array {
        $calls[] = $method;

        return ['data' => ['wallets' => [developerWallet()]]];
    });
    expect(fn () => $gateway->transfer(developerWallet()['address'], '0x'.str_repeat('2', 40), 1_000000))
        ->toThrow(InvalidArgumentException::class);
    expect($calls)->not->toContain('POST');
});

it('preserves exact Arc USDC across two interfaces and ignores other six-decimal tokens', function (): void {
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: static function ($method, $url): array {
        if (str_ends_with($url, '/wallets')) {
            return ['data' => ['wallets' => [developerWallet()]]];
        }

        return ['data' => ['tokenBalances' => [
            ['amount' => '45.500000000000000001', 'token' => ['isNative' => true, 'decimals' => 18, 'blockchain' => 'ARC-TESTNET']],
            ['amount' => '45.5', 'token' => ['isNative' => false, 'decimals' => 6, 'blockchain' => 'ARC-TESTNET', 'tokenAddress' => CircleRestGateway::USDC_ADDRESS]],
            ['amount' => '99999', 'token' => ['symbol' => 'USDC', 'isNative' => false, 'decimals' => 6, 'blockchain' => 'ARC-TESTNET', 'tokenAddress' => '0x'.str_repeat('9', 40)]],
        ]]];
    });
    expect($gateway->balance(developerWallet()['address'])->amountBaseUnits)->toBe(45_500000);
});

it('refuses conflicting balance observations instead of counting both', function (): void {
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: static function ($method, $url): array {
        return str_ends_with($url, '/wallets') ? ['data' => ['wallets' => [developerWallet()]]] : ['data' => ['tokenBalances' => [
            ['amount' => '1', 'token' => ['isNative' => true, 'decimals' => 18, 'blockchain' => 'ARC-TESTNET']],
            ['amount' => '2', 'token' => ['decimals' => 6, 'blockchain' => 'ARC-TESTNET', 'tokenAddress' => CircleRestGateway::USDC_ADDRESS]],
        ]]];
    });
    expect(fn () => $gateway->balance(developerWallet()['address']))->toThrow(LeptonRuntimeException::class, 'disagree');
});

it('paginates within wallet scope without following a provider supplied host', function (): void {
    $count = 0;
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: static function ($method, $url, $query) use (&$count): HttpResponse {
        expect($url)->toBe('https://api.circle.com/v1/w3s/wallets');
        $count++;
        if ($count === 1) {
            return new HttpResponse(200, ['data' => ['wallets' => [developerWallet()]]],
                ['Link' => '<https://evil.example/wallets?pageAfter=01899cf2-d415-7052-a207-f9862157e546>; rel="next"']);
        }
        expect($query['pageAfter'])->toBe(developerWallet()['id']);

        return new HttpResponse(200, ['data' => ['wallets' => []]]);
    });
    expect($gateway->wallets())->toHaveCount(1)->and($count)->toBe(2);
});

it('never expands a failed wallet lookup to account-wide history', function (): void {
    $calls = [];
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: static function ($method, $url) use (&$calls): array {
        $calls[] = $url;

        return ['data' => ['wallets' => []]];
    });
    expect(fn () => $gateway->transactions(developerWallet()['address']))->toThrow(LeptonRuntimeException::class);
    expect($calls)->toBe(['https://api.circle.com/v1/w3s/wallets']);
});

it('keeps CONFIRMED pending and network failures unknown', function (): void {
    $id = '116c7ce7-8f2f-53ac-8b06-f18aac151ce3';
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: static fn (): array => ['data' => ['transaction' => [
        'id' => $id, 'state' => 'CONFIRMED', 'txHash' => '0x'.str_repeat('a', 64), 'blockchain' => 'ARC-TESTNET',
    ]]]);
    expect($gateway->resolve($id)->state)->toBe('pending');
    $unavailable = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: static fn () => throw new RuntimeException('timeout'));
    expect($unavailable->resolve($id)->state)->toBe('unknown');
});

it('distinguishes verified API access from mere credentials and network mismatch', function (): void {
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: static fn (): array => ['data' => ['wallets' => []]]);
    expect($gateway->authStatus()['testnet']['status'])->toBe('API_ACCESS_VERIFIED')
        ->and($gateway->authStatus()['mainnet']['authenticated'])->toBeFalse();
    expect(fn () => $gateway->wallets(['chain' => 'ARC']))->toThrow(LeptonRuntimeException::class);
    $invalid = new CircleRestGateway(apiKey: 'present-but-invalid');
    expect($invalid->authStatus()['testnet']['authenticated'])->toBeFalse();
});

it('fails unsupported balances explicitly instead of inventing zero', function (): void {
    $gateway = new CircleRestGateway;
    expect(fn () => $gateway->gatewayBalance('0xTreasury'))->toThrow(UnsupportedOperation::class)
        ->and(fn () => $gateway->contractAddress('USDC'))->toThrow(UnsupportedOperation::class);
});

it('blocks signing in dry-run mode before any request', function (): void {
    $gateway = new CircleRestGateway(dryRun: true, httpClient: static fn () => throw new RuntimeException('must not call'));
    expect(fn () => $gateway->signMessage(developerWallet()['address'], 'hello'))->toThrow(LeptonRuntimeException::class, 'Dry-run')
        ->and(fn () => $gateway->signTypedData(developerWallet()['address'], '{}'))->toThrow(LeptonRuntimeException::class, 'Dry-run');
});

it('sends fee estimates without encryption or transfer mutation', function (): void {
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', dryRun: true, httpClient: static function ($method, $url, $query, $body): array {
        if ($method === 'GET') {
            return ['data' => ['wallets' => [developerWallet()]]];
        }
        expect($url)->toBe('https://api.circle.com/v1/w3s/transactions/transfer/estimateFee')
            ->and($body)->not->toHaveKeys(['entitySecretCiphertext', 'idempotencyKey']);

        return ['data' => ['medium' => ['networkFee' => '0.01']]];
    });
    expect($gateway->estimateTransfer(developerWallet()['address'], '0x'.str_repeat('2', 40), 1_000000))
        ->toHaveKey('medium');
});

it('does not leak provider error payloads or API keys', function (): void {
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:super-secret', httpClient: static fn (): HttpResponse => new HttpResponse(401, ['message' => 'super-secret'], ['X-Request-Id' => 'request-123']));
    try {
        $gateway->wallets();
        test()->fail('Expected API rejection.');
    } catch (ApiException $exception) {
        expect($exception->status)->toBe(401)
            ->and($exception->requestId)->toBe('request-123')
            ->and($exception->getMessage())->not->toContain('super-secret');
    }
});

it('passes message and typed signing payloads according to the REST schema', function (): void {
    [$public] = createTestRsaKeyPair();
    $typed = json_encode(['types' => ['EIP712Domain' => [], 'Data' => []], 'primaryType' => 'Data', 'domain' => ['chainId' => 5042002], 'message' => []]);
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', entitySecret: str_repeat('a', 64),
        httpClient: static function ($method, $url, $query, $body) use ($public, $typed): array {
            if (str_ends_with($url, '/publicKey')) {
                return ['data' => ['publicKey' => $public]];
            }
            if ($method === 'GET') {
                return ['data' => ['wallets' => [developerWallet()]]];
            }
            expect($body)->toHaveKey('entitySecretCiphertext')->not->toHaveKey('idempotencyKey');
            if (str_ends_with($url, '/message')) {
                expect($body['message'])->toBe('hello')->and($body['encodedByHex'])->toBeFalse();
            } else {
                expect($body['data'])->toBe($typed);
            }

            return ['data' => ['signature' => 'signed-fixture']];
        });
    expect($gateway->signMessage(developerWallet()['address'], 'hello')['signature'])->toBe('signed-fixture')
        ->and($gateway->signTypedData(developerWallet()['address'], $typed)['signature'])->toBe('signed-fixture');
});

it('rejects claimed fee ceilings rather than silently ignoring them', function (): void {
    $calls = [];
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: static function ($method, $url) use (&$calls): array {
        $calls[] = $method;

        return ['data' => ['wallets' => [developerWallet()]]];
    });
    expect(fn () => $gateway->transfer(developerWallet()['address'], '0x'.str_repeat('2', 40), 1_000000,
        ['idempotencyKey' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'max_fee_base_units' => 100]))
        ->toThrow(UnsupportedOperation::class);
    expect($calls)->not->toContain('POST');
});

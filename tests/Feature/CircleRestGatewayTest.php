<?php

declare(strict_types=1);

use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\CircleGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\CircleRestGateway;
use Yukazakiri\Lepton\Support\CircleWebhookVerifier;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

function developerWallet(array $overrides = []): array
{
    return array_replace([
        'id' => '01899cf2-d415-7052-a207-f9862157e546',
        'address' => '0x'.str_repeat('1', 40), 'blockchain' => 'ARC-TESTNET',
        'accountType' => 'EOA', 'custodyType' => 'DEVELOPER',
        'walletSetId' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
    ], $overrides);
}

function createTestRsaKeyPair(): array
{
    $res = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($res, $privateKeyPem);
    $details = openssl_pkey_get_details($res);
    $publicKeyPem = $details['key'];

    return [$publicKeyPem, $privateKeyPem];
}

it('binds CircleRestGateway under developer and circle-rest drivers', function (string $driver): void {
    config([
        'lepton.default' => $driver,
        'lepton.developer.api_key' => 'TEST_API_KEY:fixture:secret',
        'lepton.developer.entity_secret' => str_repeat('a', 64),
    ]);

    expect(app(WalletGateway::class))->toBeInstanceOf(CircleRestGateway::class)
        ->and(app(CircleGateway::class))->toBeInstanceOf(CircleRestGateway::class)
        ->and(app(AuthGateway::class))->toBeInstanceOf(CircleRestGateway::class);
})->with(['developer', 'circle-rest']);

it('generates entity secret ciphertext using RSA-OAEP SHA-256', function (): void {
    [$pubKey, $privKey] = createTestRsaKeyPair();

    $mockClient = function (string $method, string $url) use ($pubKey): array {
        if (str_contains($url, '/config/entity/publicKey')) {
            return ['data' => ['publicKey' => $pubKey]];
        }

        return ['data' => []];
    };

    $rawSecretHex = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    $gateway = new CircleRestGateway(
        apiKey: 'TEST_API_KEY:fixture:secret',
        entitySecret: $rawSecretHex,
        httpClient: $mockClient,
    );

    $ciphertext = $gateway->generateEntitySecretCiphertext();
    expect($ciphertext)->toBeString()->not->toBeEmpty();

    $encryptedBytes = base64_decode($ciphertext, true);
    expect($encryptedBytes)->not->toBeFalse();

    $decrypted = '';
    $success = openssl_private_decrypt(
        $encryptedBytes,
        $decrypted,
        $privKey,
        OPENSSL_PKCS1_OAEP_PADDING,
        'sha256'
    );

    expect($success)->toBeTrue()
        ->and(bin2hex($decrypted))->toBe($rawSecretHex);
    expect($gateway->generateEntitySecretCiphertext())->not->toBe($ciphertext);
});

it('lists developer wallets via REST API', function (): void {
    $mockClient = function (string $method, string $url, array $query): array {
        expect($method)->toBe('GET')
            ->and($url)->toBe('https://api.circle.com/v1/w3s/wallets');

        return [
            'data' => [
                'wallets' => [
                    developerWallet(),
                ],
            ],
        ];
    };

    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: $mockClient);
    $wallets = $gateway->wallets(['chain' => 'ARC-TESTNET']);

    expect($wallets)->toHaveCount(1)
        ->and($wallets[0]['address'])->toBe(developerWallet()['address'])
        ->and($wallets[0]['id'])->toBe(developerWallet()['id']);
});

it('creates developer-controlled wallet via REST API', function (): void {
    [$pubKey] = createTestRsaKeyPair();

    $mockClient = function (string $method, string $url, array $query, ?array $body) use ($pubKey): array {
        if (str_contains($url, '/config/entity/publicKey')) {
            return ['data' => ['publicKey' => $pubKey]];
        }
        if (str_contains($url, '/developer/wallets')) {
            expect($method)->toBe('POST')
                ->and($body['accountType'])->toBe('EOA')
                ->and($body['entitySecretCiphertext'])->toBeString();

            return [
                'data' => [
                    'wallets' => [
                        developerWallet(),
                    ],
                ],
            ];
        }

        return ['data' => []];
    };

    $gateway = new CircleRestGateway(
        apiKey: 'TEST_API_KEY:fixture:secret',
        entitySecret: str_repeat('b', 64),
        walletSetId: developerWallet()['walletSetId'],
        httpClient: $mockClient,
    );

    $created = $gateway->createWallet(['chain' => 'ARC-TESTNET', 'idempotencyKey' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11']);

    expect($created['status'])->toBe('created')
        ->and($created['wallets'][0]['address'])->toBe(developerWallet()['address']);
});

it('executes transfer transaction via REST API and returns accepted result with handle', function (): void {
    [$pubKey] = createTestRsaKeyPair();

    $mockClient = function (string $method, string $url, array $query, ?array $body) use ($pubKey): array {
        if (str_contains($url, '/config/entity/publicKey')) {
            return ['data' => ['publicKey' => $pubKey]];
        }
        if ($url === 'https://api.circle.com/v1/w3s/wallets' && $method === 'GET') {
            return [
                'data' => [
                    'wallets' => [
                        developerWallet(),
                    ],
                ],
            ];
        }
        if (str_contains($url, '/developer/transactions/transfer')) {
            expect($body['destinationAddress'])->toBe('0x'.str_repeat('2', 40))
                ->and($body['amounts'])->toBe(['10'])
                ->and($body['walletId'])->toBe(developerWallet()['id'])
                ->and($body['tokenAddress'])->toBe(CircleRestGateway::USDC_ADDRESS)
                ->and($body['feeLevel'])->toBe('MEDIUM')
                ->and($body)->not->toHaveKey('fee');

            return [
                'data' => [
                    'id' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3',
                    'state' => 'INITIATED',
                ],
            ];
        }

        return ['data' => []];
    };

    $gateway = new CircleRestGateway(
        apiKey: 'TEST_API_KEY:fixture:secret',
        entitySecret: str_repeat('c', 64),
        httpClient: $mockClient,
    );

    $res = $gateway->transfer(developerWallet()['address'], '0x'.str_repeat('2', 40), 10_000000,
        ['chain' => 'ARC-TESTNET', 'idempotencyKey' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11']);

    expect($res->handle)->toBe('116c7ce7-8f2f-53ac-8b06-f18aac151ce3')
        ->and($res->txHash)->toBeNull()
        ->and($res->state)->toBe('accepted');
});

it('resolves transaction state via REST API to completed resolution', function (): void {
    $realHash = '0x'.str_repeat('e', 64);

    $mockClient = function (string $method, string $url) use ($realHash): array {
        expect($url)->toBe('https://api.circle.com/v1/w3s/transactions/116c7ce7-8f2f-53ac-8b06-f18aac151ce3');

        return [
            'data' => [
                'transaction' => [
                    'id' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3',
                    'blockchain' => 'ARC-TESTNET',
                    'state' => 'COMPLETE',
                    'txHash' => $realHash,
                    'blockHeight' => 84000,
                ],
            ],
        ];
    };

    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: $mockClient);
    $res = $gateway->resolve('116c7ce7-8f2f-53ac-8b06-f18aac151ce3');

    expect($res->hasHash())->toBeTrue()
        ->and($res->txHash)->toBe($realHash)
        ->and($res->state)->toBe('completed');
});

it('reads wallet token balances and converts to minor units', function (): void {
    $mockClient = function (string $method, string $url): array {
        if ($url === 'https://api.circle.com/v1/w3s/wallets') {
            return [
                'data' => [
                    'wallets' => [
                        developerWallet(),
                    ],
                ],
            ];
        }

        expect($url)->toBe('https://api.circle.com/v1/w3s/wallets/'.developerWallet()['id'].'/balances');

        return [
            'data' => [
                'tokenBalances' => [
                    [
                        'token' => ['symbol' => 'USDC', 'decimals' => 6, 'blockchain' => 'ARC-TESTNET', 'tokenAddress' => CircleRestGateway::USDC_ADDRESS],
                        'amount' => '45.50',
                    ],
                ],
            ],
        ];
    };

    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret', httpClient: $mockClient);
    $balance = $gateway->balance(developerWallet()['address']);

    expect($balance->amountBaseUnits)->toBe(45_500000)
        ->and($balance->address)->toBe(developerWallet()['address']);
});

it('uses a dedicated authenticated notification verifier instead of entity encryption keys', function (): void {
    $gateway = new CircleRestGateway(apiKey: 'TEST_API_KEY:fixture:secret');
    expect($gateway->webhooks())->toBeInstanceOf(CircleWebhookVerifier::class);
    expect(fn () => $gateway->webhooks()->verify('{}', '', 'invalid', []))->toThrow(LeptonRuntimeException::class);
});

it('guards mutating actions in dryRun mode', function (): void {
    $gateway = new CircleRestGateway(apiKey: 'TEST_KEY', dryRun: true);

    expect(fn () => $gateway->createWallet())->toThrow(LeptonRuntimeException::class)
        ->and(fn () => $gateway->fundWallet('0x123'))->toThrow(LeptonRuntimeException::class)
        ->and(fn () => $gateway->transfer('0xFrom', '0xTo', 10_000000))->toThrow(LeptonRuntimeException::class);
});

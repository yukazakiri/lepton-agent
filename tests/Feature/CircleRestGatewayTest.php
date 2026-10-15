<?php

declare(strict_types=1);

use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\CircleGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\CircleRestGateway;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

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
        'lepton.developer.api_key' => 'TEST_KEY',
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
        apiKey: 'TEST_KEY',
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
});

it('lists developer wallets via REST API', function (): void {
    $mockClient = function (string $method, string $url, array $query): array {
        expect($method)->toBe('GET')
            ->and($url)->toContain('/developer/wallets');

        return [
            'data' => [
                'wallets' => [
                    [
                        'id' => 'w-123',
                        'address' => '0xTreasuryDev',
                        'blockchain' => 'ARC-TESTNET',
                        'accountType' => 'SCA',
                    ],
                ],
            ],
        ];
    };

    $gateway = new CircleRestGateway(apiKey: 'TEST_KEY', httpClient: $mockClient);
    $wallets = $gateway->wallets(['chain' => 'ARC-TESTNET']);

    expect($wallets)->toHaveCount(1)
        ->and($wallets[0]['address'])->toBe('0xTreasuryDev')
        ->and($wallets[0]['id'])->toBe('w-123');
});

it('creates developer-controlled wallet via REST API', function (): void {
    [$pubKey] = createTestRsaKeyPair();

    $mockClient = function (string $method, string $url, array $query, ?array $body) use ($pubKey): array {
        if (str_contains($url, '/config/entity/publicKey')) {
            return ['data' => ['publicKey' => $pubKey]];
        }
        if (str_contains($url, '/developer/wallets')) {
            expect($method)->toBe('POST')
                ->and($body['accountType'])->toBe('SCA')
                ->and($body['entitySecretCiphertext'])->toBeString();

            return [
                'data' => [
                    'wallets' => [
                        [
                            'id' => 'w-new',
                            'address' => '0xNewDevWallet',
                            'blockchain' => 'ARC-TESTNET',
                        ],
                    ],
                ],
            ];
        }

        return ['data' => []];
    };

    $gateway = new CircleRestGateway(
        apiKey: 'TEST_KEY',
        entitySecret: str_repeat('b', 64),
        walletSetId: 'ws-default',
        httpClient: $mockClient,
    );

    $created = $gateway->createWallet(['chain' => 'ARC-TESTNET']);

    expect($created['status'])->toBe('created')
        ->and($created['wallets'][0]['address'])->toBe('0xNewDevWallet');
});

it('executes transfer transaction via REST API and returns accepted result with handle', function (): void {
    [$pubKey] = createTestRsaKeyPair();

    $mockClient = function (string $method, string $url, array $query, ?array $body) use ($pubKey): array {
        if (str_contains($url, '/config/entity/publicKey')) {
            return ['data' => ['publicKey' => $pubKey]];
        }
        if (str_contains($url, '/developer/wallets') && $method === 'GET') {
            return [
                'data' => [
                    'wallets' => [
                        ['id' => 'wallet-uuid-1', 'address' => '0xfromaddr', 'blockchain' => 'ARC-TESTNET'],
                    ],
                ],
            ];
        }
        if (str_contains($url, '/developer/transactions/transfer')) {
            expect($body['destinationAddress'])->toBe('0xToAddr')
                ->and($body['amounts'])->toBe(['10'])
                ->and($body['walletId'])->toBe('wallet-uuid-1');

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
        apiKey: 'TEST_KEY',
        entitySecret: str_repeat('c', 64),
        httpClient: $mockClient,
    );

    $res = $gateway->transfer('0xfromaddr', '0xToAddr', 10_000000, ['chain' => 'ARC-TESTNET']);

    expect($res->handle)->toBe('116c7ce7-8f2f-53ac-8b06-f18aac151ce3')
        ->and($res->txHash)->toBeNull()
        ->and($res->state)->toBe('accepted');
});

it('resolves transaction state via REST API to completed resolution', function (): void {
    $realHash = '0x'.str_repeat('e', 64);

    $mockClient = function (string $method, string $url) use ($realHash): array {
        expect($url)->toContain('/developer/transactions/tx-uuid-456');

        return [
            'data' => [
                'transaction' => [
                    'id' => 'tx-uuid-456',
                    'state' => 'COMPLETE',
                    'txHash' => $realHash,
                    'blockHeight' => 84000,
                ],
            ],
        ];
    };

    $gateway = new CircleRestGateway(apiKey: 'TEST_KEY', httpClient: $mockClient);
    $res = $gateway->resolve('tx-uuid-456');

    expect($res->hasHash())->toBeTrue()
        ->and($res->txHash)->toBe($realHash)
        ->and($res->state)->toBe('completed');
});

it('reads wallet token balances and converts to minor units', function (): void {
    $mockClient = function (string $method, string $url): array {
        if (str_contains($url, '/developer/wallets') && ! str_contains($url, '/balances')) {
            return [
                'data' => [
                    'wallets' => [
                        ['id' => 'wallet-1', 'address' => '0xtreasury', 'blockchain' => 'ARC-TESTNET'],
                    ],
                ],
            ];
        }

        return [
            'data' => [
                'tokenBalances' => [
                    [
                        'token' => ['symbol' => 'USDC', 'decimals' => 6],
                        'amount' => '45.50',
                    ],
                ],
            ],
        ];
    };

    $gateway = new CircleRestGateway(apiKey: 'TEST_KEY', httpClient: $mockClient);
    $balance = $gateway->balance('0xtreasury');

    expect($balance->amountBaseUnits)->toBe(45_500000)
        ->and($balance->address)->toBe('0xtreasury');
});

it('verifies incoming webhook signatures with Circle public key', function (): void {
    [$pubKey, $privKey] = createTestRsaKeyPair();

    $gateway = new CircleRestGateway(apiKey: 'TEST_KEY');
    $payload = json_encode(['notificationType' => 'transactions', 'data' => ['state' => 'COMPLETE']]);

    openssl_sign($payload, $signature, $privKey, OPENSSL_ALGO_SHA256);
    $sigBase64 = base64_encode($signature);

    expect($gateway->verifyWebhookSignature($payload, $sigBase64, $pubKey))->toBeTrue();
    expect($gateway->verifyWebhookSignature($payload.'tampered', $sigBase64, $pubKey))->toBeFalse();
});

it('guards mutating actions in dryRun mode', function (): void {
    $gateway = new CircleRestGateway(apiKey: 'TEST_KEY', dryRun: true);

    expect(fn () => $gateway->createWallet())->toThrow(LeptonRuntimeException::class)
        ->and(fn () => $gateway->fundWallet('0x123'))->toThrow(LeptonRuntimeException::class)
        ->and(fn () => $gateway->transfer('0xFrom', '0xTo', 10_000000))->toThrow(LeptonRuntimeException::class);
});

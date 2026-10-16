<?php

declare(strict_types=1);

use Yukazakiri\Lepton\Contracts\JsonTransport;
use Yukazakiri\Lepton\DTOs\HttpResponse;
use Yukazakiri\Lepton\Support\ApiException;
use Yukazakiri\Lepton\Support\CircleWebhookVerifier;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

function notificationFixture(array $overrides = []): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $details = openssl_pkey_get_details($key);
    $base64 = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"], '', $details['key']);
    $payload = json_encode(array_replace([
        'version' => 2, 'notificationId' => '01899cf2-d415-7052-a207-f9862157e546',
        'subscriptionId' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'notificationType' => 'transactions.outbound',
        'notification' => ['id' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3', 'state' => 'COMPLETE', 'blockchain' => 'ARC-TESTNET'],
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ], $overrides), JSON_THROW_ON_ERROR);
    openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);

    return ['raw' => $payload, 'signature' => base64_encode($signature), 'key' => $base64,
        'keyId' => '879dc113-5ca4-4ff7-a6b7-54652083fcf8', 'subscription' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'];
}

function notificationVerifier(array $fixture, string $algorithm = 'ECDSA_SHA_256'): CircleWebhookVerifier
{
    $transport = new class($fixture, $algorithm) implements JsonTransport
    {
        public function __construct(private array $fixture, private string $algorithm) {}

        public function request(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse
        {
            expect($method)->toBe('GET')->and($url)->toBe('https://api.circle.com/v2/notifications/publicKey/'.$this->fixture['keyId']);

            return new HttpResponse(200, ['data' => ['id' => $this->fixture['keyId'], 'algorithm' => $this->algorithm, 'publicKey' => $this->fixture['key']]]);
        }
    };

    return new CircleWebhookVerifier($transport, 'TEST_API_KEY:fixture:secret');
}

it('verifies a Circle ECDSA notification with key ID, subscription and raw bytes', function (): void {
    $fixture = notificationFixture();
    $notification = notificationVerifier($fixture)->verify($fixture['raw'], $fixture['signature'], $fixture['keyId'], [$fixture['subscription']]);
    expect($notification->notificationType)->toBe('transactions.outbound')
        ->and($notification->payloadDigest)->toBe(hash('sha256', $fixture['raw']));
});

it('rejects tampering, missing headers, wrong subscription and algorithm', function (): void {
    $fixture = notificationFixture();
    $verifier = notificationVerifier($fixture);
    expect(fn () => $verifier->verify($fixture['raw'].' ', $fixture['signature'], $fixture['keyId'], [$fixture['subscription']]))->toThrow(LeptonRuntimeException::class)
        ->and(fn () => $verifier->verify($fixture['raw'], '', $fixture['keyId'], [$fixture['subscription']]))->toThrow(LeptonRuntimeException::class)
        ->and(fn () => $verifier->verify($fixture['raw'], $fixture['signature'], $fixture['keyId'], []))->toThrow(LeptonRuntimeException::class)
        ->and(fn () => $verifier->verify($fixture['raw'], $fixture['signature'], $fixture['keyId'], ['01899cf2-d415-7052-a207-f9862157e546']))->toThrow(LeptonRuntimeException::class)
        ->and(fn () => notificationVerifier($fixture, 'RSA_SHA256')->verify($fixture['raw'], $fixture['signature'], $fixture['keyId'], [$fixture['subscription']]))->toThrow(LeptonRuntimeException::class);
});

it('rejects expired and future notifications with configurable replay window', function (int $offset): void {
    $fixture = notificationFixture(['timestamp' => gmdate('Y-m-d\TH:i:s\Z', time() + $offset)]);
    expect(fn () => notificationVerifier($fixture)->verify($fixture['raw'], $fixture['signature'], $fixture['keyId'], [$fixture['subscription']]))
        ->toThrow(LeptonRuntimeException::class);
})->with([-90000, 1000]);

it('fails closed when the notification key service is unavailable', function (): void {
    $fixture = notificationFixture();
    $transport = new class implements JsonTransport
    {
        public function request(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse
        {
            return new HttpResponse(503);
        }
    };
    $verifier = new CircleWebhookVerifier($transport, 'TEST_API_KEY:fixture:secret');
    expect(fn () => $verifier->verify($fixture['raw'], $fixture['signature'], $fixture['keyId'], [$fixture['subscription']]))
        ->toThrow(ApiException::class);
});

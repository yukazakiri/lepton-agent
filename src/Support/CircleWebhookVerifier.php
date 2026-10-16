<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Support;

use DateTimeImmutable;
use Throwable;
use Yukazakiri\Lepton\Contracts\JsonTransport;
use Yukazakiri\Lepton\Contracts\WebhookVerifier;
use Yukazakiri\Lepton\DTOs\VerifiedNotification;

final class CircleWebhookVerifier implements WebhookVerifier
{
    /** @var array<string, string> */
    private array $publicKeys = [];

    public function __construct(
        private readonly JsonTransport $transport,
        #[\SensitiveParameter] private readonly ?string $apiKey,
        private readonly string $apiOrigin = 'https://api.circle.com',
        private readonly int $maxAgeSeconds = 86400,
    ) {}

    public function verify(string $rawPayload, string $signatureBase64, string $keyId, array $allowedSubscriptions): VerifiedNotification
    {
        if (strlen($rawPayload) > 1048576 || $rawPayload === '' || $allowedSubscriptions === []
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $keyId) !== 1) {
            throw new LeptonRuntimeException('Missing or invalid webhook verification context.');
        }
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false || strlen($signature) < 8 || strlen($signature) > 256) {
            throw new LeptonRuntimeException('Invalid Circle notification signature.');
        }
        $publicKey = $this->notificationKey($keyId);
        if (openssl_verify($rawPayload, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new LeptonRuntimeException('Circle notification signature verification failed.');
        }

        try {
            $data = json_decode($rawPayload, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($data) || ($data['version'] ?? null) !== 2 || ! is_array($data['notification'] ?? null)
                || ! is_string($data['notificationType'] ?? null) || $data['notificationType'] === ''
                || ! is_string($data['timestamp'] ?? null)
                || ! in_array($data['subscriptionId'] ?? null, $allowedSubscriptions, true)) {
                throw new LeptonRuntimeException('Invalid Circle notification envelope or subscription.');
            }
            foreach (['notificationId', 'subscriptionId'] as $id) {
                if (! is_string($data[$id] ?? null) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $data[$id]) !== 1) {
                    throw new LeptonRuntimeException('Invalid Circle notification identity.');
                }
            }
            if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z$/D', $data['timestamp']) !== 1) {
                throw new LeptonRuntimeException('Circle notification timestamp must be UTC ISO-8601.');
            }
            $timestamp = new DateTimeImmutable(preg_replace('/(\.\d{6})\d+Z$/', '$1Z', $data['timestamp']));
            $age = time() - $timestamp->getTimestamp();
            if ($age < -300 || $age > $this->maxAgeSeconds) {
                throw new LeptonRuntimeException('Circle notification timestamp is outside the configured acceptance window.');
            }
        } catch (Throwable $exception) {
            throw new LeptonRuntimeException('Circle notification envelope rejected.', previous: $exception);
        }

        return new VerifiedNotification($data['notificationId'], $data['subscriptionId'], $data['notificationType'],
            $data['notification'], $timestamp, hash('sha256', $rawPayload));
    }

    private function notificationKey(string $keyId): string
    {
        if (isset($this->publicKeys[$keyId])) {
            return $this->publicKeys[$keyId];
        }
        if (! is_string($this->apiKey) || $this->apiKey === '' || parse_url($this->apiOrigin, PHP_URL_SCHEME) !== 'https'
            || parse_url($this->apiOrigin, PHP_URL_USER) !== null || parse_url($this->apiOrigin, PHP_URL_QUERY) !== null
            || parse_url($this->apiOrigin, PHP_URL_FRAGMENT) !== null) {
            throw new LeptonRuntimeException('Circle notification key lookup is not configured.');
        }
        $response = $this->transport->request('GET', rtrim($this->apiOrigin, '/').'/v2/notifications/publicKey/'.$keyId,
            ['Authorization' => 'Bearer '.$this->apiKey, 'Accept' => 'application/json']);
        if ($response->status !== 200) {
            throw new ApiException($response->status, $response->header('X-Request-Id'));
        }
        $data = $response->data['data'] ?? null;
        if (! is_array($data) || ($data['id'] ?? null) !== $keyId || ($data['algorithm'] ?? null) !== 'ECDSA_SHA_256'
            || ! is_string($data['publicKey'] ?? null) || base64_decode($data['publicKey'], true) === false) {
            throw new LeptonRuntimeException('Invalid Circle notification signing key or algorithm.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($data['publicKey'], 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC || $details['bits'] !== 256
            || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new LeptonRuntimeException('Circle notification signing key is not an ECDSA P-256 key.');
        }

        return $this->publicKeys[$keyId] = $pem;
    }
}

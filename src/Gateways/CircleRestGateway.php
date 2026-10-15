<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Gateways;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\CircleGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Contracts\X402Gateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransactionRecord;
use Yukazakiri\Lepton\DTOs\TransferResolution;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\Support\Amounts;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

/**
 * Pure HTTP REST API gateway for Circle Developer-Controlled Wallets.
 *
 * Interacts directly with Circle's Web3 Services REST API (`api.circle.com/v1/w3s`),
 * completely eliminating external binary execution (no `circle` CLI, no Node.js).
 *
 * Authentication uses Circle API Key + 32-byte Entity Secret (encrypted asymmetrically
 * per-request with Circle's entity public key via RSA-OAEP SHA-256).
 */
class CircleRestGateway implements AuthGateway, CircleGateway, WalletGateway, X402Gateway
{
    public const DEFAULT_BASE_URL = 'https://api.circle.com/v1/w3s';

    /**
     * In-memory cache for wallet addresses to wallet IDs.
     *
     * @var array<string,string>
     */
    private array $walletIdMap = [];

    /**
     * Cached entity public key string.
     */
    private ?string $cachedEntityPublicKey = null;

    /**
     * @param  (callable(string,string,array<string,string>,?array):array)|null  $httpClient  Custom HTTP handler for testing/mocking.
     */
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $entitySecret = null,
        private ?string $walletSetId = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $usdcDecimals = 6,
        private readonly bool $dryRun = false,
        private readonly ?string $defaultRpcUrl = null,
        private readonly int $timeout = 30,
        private $httpClient = null,
    ) {}

    // =========================================================================
    // WalletGateway Implementation
    // =========================================================================

    public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');

        if ($this->dryRun || ! empty($options['estimate'])) {
            throw new LeptonRuntimeException(
                'A preflight is an estimate, not a submission. Read the fee from it and submit separately; '
                .'do not treat an estimated amount as a transfer outcome.'
            );
        }

        $walletId = $this->resolveWalletId($fromAddress, $chain);
        $amount = Amounts::toCliAmount($amountBaseUnits, $this->usdcDecimals);

        $body = [
            'idempotencyKey' => (string) ($options['idempotencyKey'] ?? (string) Str::uuid()),
            'walletId' => $walletId,
            'destinationAddress' => $toAddress,
            'amounts' => [$amount],
            'fee' => [
                'type' => 'level',
                'config' => [
                    'feeLevel' => strtoupper((string) ($options['feeLevel'] ?? 'MEDIUM')),
                ],
            ],
        ];

        if (! empty($options['token'])) {
            $tokenParam = (string) $options['token'];
            if (str_starts_with($tokenParam, '0x')) {
                $body['tokenAddress'] = $tokenParam;
            } else {
                $body['tokenId'] = $tokenParam;
            }
        }

        /** @var array<string,mixed> $response */
        $response = $this->request('POST', '/developer/transactions/transfer', [], $body, true);
        $data = $response['data'] ?? $response;

        $txHash = $this->hash($data);
        if ($txHash !== null) {
            return new TransferResult(
                txHash: $txHash,
                fromAddress: $fromAddress,
                toAddress: $toAddress,
                amountBaseUnits: $amountBaseUnits,
                chain: $chain,
                isFake: false,
                explorerUrl: app(\Yukazakiri\Lepton\Contracts\ArcNetworkGateway::class)->explorerUrl($txHash),
                raw: $response,
                state: 'submitted',
            );
        }

        $handle = $this->handle($data);
        if ($handle !== null) {
            return new TransferResult(
                txHash: null,
                fromAddress: $fromAddress,
                toAddress: $toAddress,
                amountBaseUnits: $amountBaseUnits,
                chain: $chain,
                isFake: false,
                explorerUrl: null,
                raw: $response,
                handle: $handle,
                state: 'accepted',
            );
        }

        throw new LeptonRuntimeException(
            'Circle REST API returned neither an on-chain reference nor a transaction identifier. '
            .'Raw response: '.json_encode($response)
        );
    }

    public function resolve(string $handle, array $options = []): TransferResolution
    {
        try {
            $response = $this->request('GET', '/developer/transactions/'.$handle);
            $tx = $response['data']['transaction'] ?? $response['data'] ?? $response;

            if (! is_array($tx) || $tx === []) {
                return TransferResolution::unknown(
                    'The rail did not return this transaction. Its fate is unresolved; observe again before any retry.',
                    $handle,
                );
            }

            $state = strtoupper((string) ($tx['state'] ?? ''));

            return match ($state) {
                'COMPLETE', 'CONFIRMED' => $this->resolutionFor($tx, $handle),
                'FAILED', 'CANCELLED', 'CANCELED', 'DENIED', 'REJECTED' => TransferResolution::failed(
                    'Circle reported the transfer as '.$state.'. No USDC moved; a retry needs a new submission.',
                    $handle,
                    $tx,
                ),
                default => TransferResolution::pending($handle, $tx),
            };
        } catch (Throwable $e) {
            return TransferResolution::unknown(
                'Lookup failed: '.$e->getMessage(),
                $handle,
            );
        }
    }

    public function balance(string $address, array $options = []): BalanceResult
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $walletId = $this->resolveWalletId($address, $chain);

        $response = $this->request('GET', "/developer/wallets/{$walletId}/balances");
        $tokenBalances = $response['data']['tokenBalances'] ?? [];

        $totalUnits = 0;
        $matched = false;

        foreach ((array) $tokenBalances as $tb) {
            if (! is_array($tb) || ! isset($tb['amount']) || ! is_numeric($tb['amount'])) {
                continue;
            }

            $token = is_array($tb['token'] ?? null) ? $tb['token'] : [];
            $symbol = strtoupper((string) ($token['symbol'] ?? ''));
            $decimals = isset($token['decimals']) ? (int) $token['decimals'] : $this->usdcDecimals;

            if ($symbol === 'USDC' || $decimals === $this->usdcDecimals) {
                $totalUnits += Amounts::fromDecimalString((string) $tb['amount'], $decimals);
                $matched = true;
            }
        }

        if (! $matched && isset($response['data']['amount']) && is_numeric($response['data']['amount'])) {
            $totalUnits = Amounts::fromDecimalString((string) $response['data']['amount'], $this->usdcDecimals);
        }

        return new BalanceResult($address, $totalUnits, $chain, false, $response);
    }

    public function transactions(string $address, array $filters = []): array
    {
        $chain = $filters['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $query = [];

        try {
            $walletId = $this->resolveWalletId($address, $chain);
            $query['walletIds'] = $walletId;
        } catch (Throwable) {
            // If wallet ID not resolved, continue without filtering or pass empty
        }

        if (! empty($filters['state'])) {
            $query['state'] = (string) $filters['state'];
        }
        if (! empty($filters['limit'])) {
            $query['pageSize'] = (int) $filters['limit'];
        }

        $response = $this->request('GET', '/developer/transactions', $query);
        $rows = $response['data']['transactions'] ?? [];

        return array_values(array_filter(array_map(
            fn (array $t): ?TransactionRecord => new TransactionRecord(
                id: (string) ($t['id'] ?? ''),
                txHash: is_string($t['txHash'] ?? null) ? $t['txHash'] : null,
                state: (string) ($t['state'] ?? 'unknown'),
                operation: (string) ($t['operation'] ?? $t['transactionType'] ?? 'transfer'),
                raw: $t,
            ),
            (array) $rows,
        ), fn (?TransactionRecord $record): bool => $record instanceof TransactionRecord && $record->id !== ''));
    }

    public function limits(string $address, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');

        return [
            'address' => $address,
            'chain' => $chain,
            'declared_only' => true,
            'type' => 'developer',
        ];
    }

    public function wallets(array $options = []): array
    {
        $query = [];
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        if (is_string($chain) && $chain !== '') {
            $query['blockchain'] = $chain;
        }
        if (! empty($options['walletSetId']) || $this->walletSetId) {
            $query['walletSetId'] = (string) ($options['walletSetId'] ?? $this->walletSetId);
        }

        $response = $this->request('GET', '/developer/wallets', $query);
        $wallets = $response['data']['wallets'] ?? (array_is_list($response) ? $response : []);

        foreach ((array) $wallets as $w) {
            if (isset($w['address'], $w['id'])) {
                $this->walletIdMap[strtolower((string) $w['address'])] = (string) $w['id'];
            }
        }

        return (array) $wallets;
    }

    public function createWallet(array $options = []): array
    {
        if ($this->dryRun) {
            throw new LeptonRuntimeException('Cannot create wallet while dry-run mode is enabled.');
        }

        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $walletSetId = $this->ensureWalletSetId($options['walletSetId'] ?? null);

        $body = [
            'idempotencyKey' => (string) ($options['idempotencyKey'] ?? (string) Str::uuid()),
            'accountType' => strtoupper((string) ($options['type'] ?? 'SCA')),
            'blockchains' => [(string) $chain],
            'count' => (int) ($options['count'] ?? 1),
            'walletSetId' => $walletSetId,
        ];

        $response = $this->request('POST', '/developer/wallets', [], $body, true);
        $wallets = $response['data']['wallets'] ?? (isset($response['data']) ? [$response['data']] : []);

        foreach ((array) $wallets as $w) {
            if (isset($w['address'], $w['id'])) {
                $this->walletIdMap[strtolower((string) $w['address'])] = (string) $w['id'];
            }
        }

        return [
            'status' => 'created',
            'wallets' => $wallets,
            'raw' => $response,
        ];
    }

    public function fundWallet(string $address, array $options = []): array
    {
        if ($this->dryRun) {
            throw new LeptonRuntimeException('Cannot fund wallet while dry-run mode is enabled.');
        }

        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');

        $body = [
            'address' => $address,
            'blockchain' => $chain,
            'native' => true,
            'usdc' => true,
        ];

        if (! empty($options['token']) && strtolower((string) $options['token']) === 'eurc') {
            $body['eurc'] = true;
        }

        // Faucet endpoint lives under /v1/faucet/drips
        $faucetBase = preg_replace('#/w3s/?$#', '', $this->baseUrl);
        $response = $this->requestRaw('POST', "{$faucetBase}/faucet/drips", [], $body);

        return [
            'status' => 'funded',
            'address' => $address,
            'chain' => $chain,
            'raw' => $response,
        ];
    }

    // =========================================================================
    // AuthGateway Implementation
    // =========================================================================

    public function authStatus(): array
    {
        $hasKey = is_string($this->apiKey) && trim($this->apiKey) !== '';
        $hasSecret = is_string($this->entitySecret) && trim($this->entitySecret) !== '';
        $authenticated = $hasKey && $hasSecret;

        return [
            'type' => 'developer',
            'mainnet' => [
                'authenticated' => $authenticated,
                'email' => $authenticated ? 'developer-api' : null,
                'status' => $authenticated ? 'VALID' : 'MISSING_CREDENTIALS',
                'expires_in' => 'never',
            ],
            'testnet' => [
                'authenticated' => $authenticated,
                'email' => $authenticated ? 'developer-api' : null,
                'status' => $authenticated ? 'VALID' : 'MISSING_CREDENTIALS',
                'expires_in' => 'never',
            ],
        ];
    }

    public function beginLogin(string $email): string
    {
        throw new LeptonRuntimeException(
            'CircleRestGateway uses API Key + Entity Secret authentication (LEPTON_CIRCLE_API_KEY / LEPTON_ENTITY_SECRET), not interactive email OTP.'
        );
    }

    public function completeLogin(string $requestId, string $otp): array
    {
        throw new LeptonRuntimeException(
            'CircleRestGateway uses API Key + Entity Secret authentication (LEPTON_CIRCLE_API_KEY / LEPTON_ENTITY_SECRET), not interactive email OTP.'
        );
    }

    public function isAuthenticatedFor(string $chainCode = 'ARC-TESTNET'): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== ''
            && is_string($this->entitySecret) && trim($this->entitySecret) !== '';
    }

    // =========================================================================
    // CircleGateway Implementation
    // =========================================================================

    public function run(array $args, bool $json = true, array $options = []): array|string
    {
        $cmd = implode(' ', $args);

        if (str_starts_with($cmd, 'wallet list')) {
            return $this->wallets($options);
        }
        if (str_starts_with($cmd, 'wallet create')) {
            return $this->createWallet($options);
        }
        if (str_starts_with($cmd, 'wallet fund') && isset($args[2])) {
            return $this->fundWallet($args[2], $options);
        }

        throw new LeptonRuntimeException(
            "Raw Circle CLI commands are not executable over CircleRestGateway ([{$cmd}]). "
            .'Use the programmatic WalletGateway or CircleGateway methods instead.'
        );
    }

    public function signMessage(string $address, string $message, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $walletId = $this->resolveWalletId($address, $chain);

        $body = [
            'idempotencyKey' => (string) Str::uuid(),
            'walletId' => $walletId,
            'message' => base64_encode($message),
        ];

        return $this->request('POST', '/developer/sign/message', [], $body, true);
    }

    public function signTypedData(string $address, string $typedData, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $walletId = $this->resolveWalletId($address, $chain);

        $body = [
            'idempotencyKey' => (string) Str::uuid(),
            'walletId' => $walletId,
            'data' => is_array($typedData) ? $typedData : json_decode($typedData, true),
        ];

        return $this->request('POST', '/developer/sign/typedData', [], $body, true);
    }

    public function execute(string $address, string $contract, string $signature, array $params = [], array $options = []): array
    {
        if ($this->dryRun) {
            throw new LeptonRuntimeException('Cannot execute smart contract write call while dry-run mode is enabled.');
        }

        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $walletId = $this->resolveWalletId($address, $chain);

        $body = [
            'idempotencyKey' => (string) ($options['idempotencyKey'] ?? (string) Str::uuid()),
            'walletId' => $walletId,
            'contractAddress' => $contract,
            'abiFunctionSignature' => $signature,
            'abiParameters' => $params,
            'fee' => [
                'type' => 'level',
                'config' => [
                    'feeLevel' => strtoupper((string) ($options['feeLevel'] ?? 'MEDIUM')),
                ],
            ],
        ];

        return $this->request('POST', '/developer/transactions/contractExecution', [], $body, true);
    }

    public function gatewayDeposit(string $address, int|string $amount, array $options = []): array
    {
        throw new LeptonRuntimeException('Gateway deposit via CircleRestGateway is not currently supported; use CircleCliGateway.');
    }

    public function gatewayWithdraw(string $address, int|string $amount, array $options = []): array
    {
        throw new LeptonRuntimeException('Gateway withdraw via CircleRestGateway is not currently supported; use CircleCliGateway.');
    }

    public function bridgeTransfer(string $fromAddress, string $toChain, int|string $amount, array $options = []): array
    {
        throw new LeptonRuntimeException('CCTP bridge transfer via CircleRestGateway is not currently supported; use CircleCliGateway.');
    }

    public function bridgeStatus(string $burnTxHash, array $options = []): array
    {
        throw new LeptonRuntimeException('Bridge status via CircleRestGateway is not currently supported; use CircleCliGateway.');
    }

    public function bridgeFee(string $fromChain, string $toChain, array $options = []): array
    {
        throw new LeptonRuntimeException('Bridge fee via CircleRestGateway is not currently supported; use CircleCliGateway.');
    }

    public function contractAddress(?string $name = null, array $options = []): array
    {
        return [];
    }

    public function contractQuery(string $contract, string $signature, array $params = [], array $options = []): mixed
    {
        throw new LeptonRuntimeException('Contract read query via CircleRestGateway is not supported; query via ArcNetworkGateway::rpc.');
    }

    public function blockchains(array $options = []): array
    {
        return [
            ['blockchain' => 'ARC', 'name' => 'Arc', 'testnet' => false],
            ['blockchain' => 'ARC-TESTNET', 'name' => 'Arc Testnet', 'testnet' => true],
        ];
    }

    // =========================================================================
    // X402Gateway Implementation
    // =========================================================================

    public function searchServices(string $query): array
    {
        $response = $this->requestRaw('GET', 'https://api.circle.com/v2/x402/discovery/resources', ['query' => $query]);

        return $response['resources'] ?? (array_is_list($response) ? $response : []);
    }

    public function inspectService(string $url, array $options = []): array
    {
        return $this->requestRaw('GET', 'https://api.circle.com/v2/x402/discovery/resources', ['url' => $url]);
    }

    public function payService(string $url, string $fromAddress, array $options = []): array
    {
        throw new LeptonRuntimeException('x402 micropayments via CircleRestGateway require client-side session negotiation.');
    }

    public function gatewayBalance(string $address, array $options = []): array
    {
        return ['address' => $address, 'balance' => '0', 'unsupported' => true];
    }

    // =========================================================================
    // Webhook Utilities
    // =========================================================================

    /**
     * Verify a digital signature on an incoming Circle Webhook request.
     */
    public function verifyWebhookSignature(string $rawPayload, string $signatureBase64, string $publicKeyPem): bool
    {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false) {
            return false;
        }

        return openssl_verify($rawPayload, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }

    // =========================================================================
    // Cryptographic & Internal Helpers
    // =========================================================================

    /**
     * Generate one-time Entity Secret Ciphertext using RSA-OAEP with SHA-256.
     */
    public function generateEntitySecretCiphertext(?string $entitySecret = null): string
    {
        $secretHex = $entitySecret ?? $this->entitySecret;
        if (! is_string($secretHex) || trim($secretHex) === '') {
            throw new LeptonRuntimeException('Circle Developer Entity Secret is not configured.');
        }

        $secretHex = trim($secretHex);
        if (strlen($secretHex) !== 64 || ! ctype_xdigit($secretHex)) {
            throw new LeptonRuntimeException('Entity Secret must be a 32-byte hex string (64 hexadecimal characters).');
        }

        $publicKeyPem = $this->fetchEntityPublicKey();
        $binarySecret = (string) hex2bin($secretHex);

        $encrypted = '';
        $success = openssl_public_encrypt(
            $binarySecret,
            $encrypted,
            $publicKeyPem,
            OPENSSL_PKCS1_OAEP_PADDING,
            'sha256'
        );

        if (! $success) {
            throw new LeptonRuntimeException('Failed to encrypt Entity Secret with Circle public key.');
        }

        return base64_encode($encrypted);
    }

    /**
     * Fetch Circle entity public key.
     */
    public function fetchEntityPublicKey(): string
    {
        if ($this->cachedEntityPublicKey !== null) {
            return $this->cachedEntityPublicKey;
        }

        $response = $this->request('GET', '/config/entity/publicKey');
        $key = $response['data']['publicKey'] ?? $response['publicKey'] ?? null;

        if (! is_string($key) || ! str_contains($key, 'PUBLIC KEY')) {
            throw new LeptonRuntimeException('Could not retrieve valid Circle entity public key.');
        }

        return $this->cachedEntityPublicKey = $key;
    }

    /**
     * Ensure a wallet set ID is available, creating a default one if necessary.
     */
    public function ensureWalletSetId(?string $explicitId = null): string
    {
        if (is_string($explicitId) && $explicitId !== '') {
            return $explicitId;
        }

        if (is_string($this->walletSetId) && $this->walletSetId !== '') {
            return $this->walletSetId;
        }

        // Attempt to fetch existing wallet sets
        try {
            $existing = $this->request('GET', '/developer/walletSets');
            $sets = $existing['data']['walletSets'] ?? [];
            if (is_array($sets) && isset($sets[0]['id'])) {
                return $this->walletSetId = (string) $sets[0]['id'];
            }
        } catch (Throwable) {
            // Fall through to create
        }

        // Create default wallet set
        $created = $this->request('POST', '/developer/walletSets', [], [
            'idempotencyKey' => (string) Str::uuid(),
            'name' => 'Default Wallet Set',
        ], true);

        $setId = $created['data']['walletSet']['id'] ?? $created['id'] ?? null;
        if (! is_string($setId) || $setId === '') {
            throw new LeptonRuntimeException('Failed to create or obtain a Circle wallet set.');
        }

        return $this->walletSetId = $setId;
    }

    /**
     * Resolve a wallet address or UUID to an authoritative Circle wallet ID.
     */
    public function resolveWalletId(string $addressOrId, string $blockchain): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $addressOrId) === 1) {
            return $addressOrId;
        }

        $lookupKey = strtolower($addressOrId);
        if (isset($this->walletIdMap[$lookupKey])) {
            return $this->walletIdMap[$lookupKey];
        }

        $wallets = $this->wallets(['chain' => $blockchain]);
        foreach ($wallets as $w) {
            if (isset($w['address'], $w['id'])) {
                $addr = strtolower((string) $w['address']);
                $this->walletIdMap[$addr] = (string) $w['id'];
                if ($addr === $lookupKey) {
                    return (string) $w['id'];
                }
            }
        }

        throw new LeptonRuntimeException("Wallet address [{$addressOrId}] not found in Circle developer wallet set for {$blockchain}.");
    }

    private function resolutionFor(array $transaction, string $handle): TransferResolution
    {
        $hash = $transaction['txHash'] ?? null;

        if (! is_string($hash) || preg_match('/^0x[0-9a-fA-F]{64}$/D', $hash) !== 1) {
            return TransferResolution::pending($handle, [
                'state' => 'COMPLETE',
                'reason' => 'The rail reports the transfer complete but has not published a reference yet.',
            ]);
        }

        return TransferResolution::completed($hash, $handle, [
            'block_height' => $transaction['blockHeight'] ?? null,
        ]);
    }

    private function handle(array $payload): ?string
    {
        $candidate = $payload['id']
            ?? $payload['transactionId']
            ?? $payload['transaction_id']
            ?? null;

        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate) === 1
            ? $candidate
            : null;
    }

    private function hash(array $payload): ?string
    {
        $value = $payload['txHash'] ?? $payload['transactionHash'] ?? $payload['hash'] ?? null;

        return is_string($value) && preg_match('/^0x[0-9a-fA-F]{64}$/D', $value) === 1
            ? $value
            : null;
    }

    /**
     * Send authenticated JSON request to Circle Developer REST API.
     *
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>|null  $body
     * @return array<string,mixed>
     */
    public function request(
        string $method,
        string $endpoint,
        array $query = [],
        ?array $body = null,
        bool $needsCiphertext = false,
    ): array {
        if ($needsCiphertext) {
            $body = is_array($body) ? $body : [];
            $body['entitySecretCiphertext'] = $this->generateEntitySecretCiphertext();
        }

        $url = rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/');

        return $this->requestRaw($method, $url, $query, $body);
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>|null  $body
     * @return array<string,mixed>
     */
    private function requestRaw(
        string $method,
        string $url,
        array $query = [],
        ?array $body = null,
    ): array {
        if ($this->httpClient !== null) {
            return ($this->httpClient)($method, $url, $query, $body);
        }

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if (is_string($this->apiKey) && $this->apiKey !== '') {
            $headers[] = "Authorization: Bearer {$this->apiKey}";
        }

        $encodedBody = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
            ]);

            if ($encodedBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedBody);
            }

            $raw = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw === false || $statusCode >= 400) {
                $errDetail = $raw !== false ? (string) $raw : $err;
                throw new LeptonRuntimeException("Circle API request [{$method} {$url}] failed ({$statusCode}): {$errDetail}");
            }

            return (array) json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
        }

        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers)."\r\n",
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ];

        if ($encodedBody !== null) {
            $options['http']['content'] = $encodedBody;
        }

        $raw = @file_get_contents($url, false, stream_context_create($options));
        if ($raw === false) {
            throw new LeptonRuntimeException("Circle API request [{$method} {$url}] failed.");
        }

        return (array) json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
}

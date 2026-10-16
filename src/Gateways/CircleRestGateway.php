<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Gateways;

use Closure;
use InvalidArgumentException;
use Throwable;
use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\CircleGateway;
use Yukazakiri\Lepton\Contracts\JsonTransport;
use Yukazakiri\Lepton\Contracts\TransferEstimator;
use Yukazakiri\Lepton\Contracts\TransferObserver;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Contracts\WalletManagementGateway;
use Yukazakiri\Lepton\Contracts\X402Gateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\HttpResponse;
use Yukazakiri\Lepton\DTOs\TransactionRecord;
use Yukazakiri\Lepton\DTOs\TransferResolution;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\Support\Amounts;
use Yukazakiri\Lepton\Support\ApiException;
use Yukazakiri\Lepton\Support\CircleWebhookVerifier;
use Yukazakiri\Lepton\Support\CurlJsonTransport;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;
use Yukazakiri\Lepton\Support\UnsupportedOperation;

/**
 * Laravel-first SDK for Circle Developer-Controlled Wallets on Arc.
 * Mutations are never automatically retried. The caller persists idempotency
 * identity and reconciles an ambiguous submission instead of inventing a retry.
 */
class CircleRestGateway implements AuthGateway, CircleGateway, TransferEstimator, TransferObserver, WalletGateway, WalletManagementGateway, X402Gateway
{
    public const DEFAULT_BASE_URL = 'https://api.circle.com/v1/w3s';

    public const USDC_ADDRESS = '0x3600000000000000000000000000000000000000';

    /** @var array<string, array<string, mixed>> */
    private array $walletsByContext = [];

    private ?string $cachedEntityPublicKey = null;

    private JsonTransport $transport;

    /** @var Closure(string, string, array<string, mixed>, ?array): (array|HttpResponse)|null */
    private ?Closure $httpClient;

    public function __construct(
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        #[\SensitiveParameter] private readonly ?string $entitySecret = null,
        private readonly ?string $walletSetId = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $usdcDecimals = 6,
        private readonly bool $dryRun = false,
        private readonly ?string $defaultRpcUrl = null,
        private readonly int $timeout = 30,
        ?callable $httpClient = null,
        ?JsonTransport $transport = null,
    ) {
        $this->transport = $transport ?? new CurlJsonTransport($timeout);
        $this->httpClient = $httpClient === null ? null : Closure::fromCallable($httpClient);
        if ($usdcDecimals !== 6) {
            throw new InvalidArgumentException('The SDK USDC boundary uses six decimal base units.');
        }
        if (parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' || ! str_ends_with(rtrim($baseUrl, '/'), '/v1/w3s')
            || parse_url($baseUrl, PHP_URL_USER) !== null || parse_url($baseUrl, PHP_URL_PASS) !== null
            || parse_url($baseUrl, PHP_URL_QUERY) !== null || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null) {
            throw new InvalidArgumentException('Circle base URL must be HTTPS and end with /v1/w3s.');
        }
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['wallets', 'createWallet', 'createWalletSet', 'fundTestnetWallet', 'balance', 'transfer',
            'estimateTransfer', 'resolve', 'transactions', 'signMessage', 'signTypedData', 'execute', 'webhookVerification'];
    }

    public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult
    {
        $this->assertWritable();
        if (! empty($options['estimate'])) {
            throw new LeptonRuntimeException('A preflight is an estimate, not a submission. Use estimateTransfer().');
        }
        $body = $this->transferBody($fromAddress, $toAddress, $amountBaseUnits, $options);
        $body['idempotencyKey'] = $this->idempotencyKey($options);
        $body += $this->fee($options);
        $response = $this->request('POST', '/developer/transactions/transfer', body: $body, needsCiphertext: true);
        $data = $this->data($response);
        $handle = $this->uuid((string) ($data['id'] ?? ''), 'transaction ID');

        return new TransferResult(null, $fromAddress, $toAddress, $amountBaseUnits, $this->chain($options),
            raw: $response, handle: $handle, state: 'accepted');
    }

    public function estimateTransfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): array
    {
        return $this->data($this->request('POST', '/transactions/transfer/estimateFee',
            body: $this->transferBody($fromAddress, $toAddress, $amountBaseUnits, $options)));
    }

    public function resolve(string $handle, array $options = []): TransferResolution
    {
        $handle = $this->uuid($handle, 'transaction ID');
        $chain = $this->chain($options);
        try {
            $data = $this->data($this->request('GET', '/transactions/'.$handle));
            $tx = $data['transaction'] ?? null;
            if (! is_array($tx) || ($tx['id'] ?? null) !== $handle || ($tx['blockchain'] ?? null) !== $chain
                || (! empty($options['walletId']) && ($tx['walletId'] ?? null) !== $options['walletId'])
                || (! empty($options['address']) && strcasecmp((string) ($tx['sourceAddress'] ?? ''), $options['address']) !== 0)) {
                return TransferResolution::unknown('Transaction context does not match the requested identity and chain.', $handle);
            }
            $state = $tx['state'] ?? '';
            $hash = $tx['txHash'] ?? null;
            if ($state === 'COMPLETE') {
                return is_string($hash) && preg_match('/^0x[0-9a-fA-F]{64}$/D', $hash) === 1
                    ? TransferResolution::completed($hash, $handle, $tx)
                    : TransferResolution::pending($handle, $tx);
            }
            if (in_array($state, ['FAILED', 'DENIED', 'CANCELLED'], true)) {
                return TransferResolution::failed('Provider reports '.$state.'. Reconcile before any retry; fees may have been charged.', $handle, $tx);
            }
            if (in_array($state, ['INITIATED', 'WAITING', 'CLEARED', 'QUEUED', 'SENT', 'STUCK', 'CONFIRMED'], true)) {
                return TransferResolution::pending($handle, $tx);
            }

            return TransferResolution::unknown('Unrecognized provider transaction state.', $handle);
        } catch (Throwable) {
            return TransferResolution::unknown('Transaction lookup unavailable; do not resubmit blindly.', $handle);
        }
    }

    public function balance(string $address, array $options = []): BalanceResult
    {
        $chain = $this->chain($options);
        $wallet = $this->wallet($address, $chain);
        $rows = $this->paginate('/wallets/'.$wallet['id'].'/balances', 'tokenBalances', ['includeAll' => 'true']);
        $canonical = null;
        $native = null;
        foreach ($rows as $row) {
            $token = $row['token'] ?? null;
            if (! is_array($token) || ($token['blockchain'] ?? null) !== $chain) {
                throw new LeptonRuntimeException('Balance response contains missing or mismatched token context.');
            }
            $isNative = ($token['isNative'] ?? false) === true;
            $isUsdc = strtolower((string) ($token['tokenAddress'] ?? $token['address'] ?? '')) === self::USDC_ADDRESS;
            if (! $isNative && ! $isUsdc) {
                continue;
            }
            $scale = $isNative ? 18 : 6;
            if (($token['decimals'] ?? null) !== $scale || ! is_string($row['amount'] ?? null)
                || preg_match('/^\d+(?:\.\d{1,'.$scale.'})?$/D', $row['amount']) !== 1) {
                throw new LeptonRuntimeException('USDC balance contains invalid precision or amount.');
            }
            [$whole, $fraction] = array_pad(explode('.', $row['amount'], 2), 2, '');
            $units = Amounts::fromDecimalString($whole.'.'.str_pad(substr($fraction, 0, 6), 6, '0'));
            if ($isNative) {
                if ($native !== null && $native !== $units) {
                    throw new LeptonRuntimeException('Ambiguous native USDC balance.');
                }
                $native = $units;
            } else {
                if ($canonical !== null && $canonical !== $units) {
                    throw new LeptonRuntimeException('Ambiguous ERC-20 USDC balance.');
                }
                $canonical = $units;
            }
        }
        if ($canonical !== null && $native !== null && $canonical !== $native) {
            throw new LeptonRuntimeException('Arc native and ERC-20 USDC observations disagree.');
        }

        return new BalanceResult($address, $canonical ?? $native ?? 0, $chain, false,
            ['tokenBalances' => $rows, 'normalization' => 'six_decimal_floor_no_double_count']);
    }

    public function transactions(string $address, array $filters = []): array
    {
        $chain = $this->chain($filters);
        $wallet = $this->wallet($address, $chain);
        $query = ['walletIds' => $wallet['id'], 'blockchain' => $chain];
        foreach (['state', 'txType'] as $filter) {
            if (isset($filters[$filter])) {
                $query[$filter] = $filters[$filter];
            }
        }
        $rows = $this->paginate('/transactions', 'transactions', $query);
        $records = [];
        foreach ($rows as $row) {
            if (($row['walletId'] ?? null) !== $wallet['id'] || ($row['blockchain'] ?? null) !== $chain) {
                throw new LeptonRuntimeException('Transaction list escaped its wallet/network scope.');
            }
            $records[] = new TransactionRecord($this->uuid((string) ($row['id'] ?? ''), 'transaction ID'),
                isset($row['txHash']) ? (string) $row['txHash'] : null, (string) ($row['state'] ?? 'unknown'),
                (string) ($row['operation'] ?? 'transfer'), $row);
        }

        return isset($filters['limit']) ? array_slice($records, 0, max(0, (int) $filters['limit'])) : $records;
    }

    public function limits(string $address, array $options = []): array
    {
        return ['address' => $address, 'chain' => $this->chain($options), 'declared_only' => true,
            'enforced' => false, 'supported' => false, 'type' => 'developer'];
    }

    public function wallets(array $options = []): array
    {
        $chain = $this->chain($options);
        $query = ['blockchain' => $chain];
        $set = $options['walletSetId'] ?? $this->walletSetId;
        if ($set !== null && $set !== '') {
            $query['walletSetId'] = $this->uuid($set, 'wallet set ID');
        }
        $rows = $this->paginate('/wallets', 'wallets', $query);
        foreach ($rows as $row) {
            $this->assertWallet($row, $chain, $set);
        }

        return $rows;
    }

    public function createWallet(array $options = []): array
    {
        $this->assertWritable();
        $chain = $this->chain($options);
        if ((isset($options['testnet']) && (bool) $options['testnet'] !== ($chain === 'ARC-TESTNET'))) {
            throw new InvalidArgumentException('Wallet creation testnet option conflicts with chain.');
        }
        $set = $this->uuid((string) ($options['walletSetId'] ?? $this->walletSetId ?? ''), 'wallet set ID');
        $type = strtoupper((string) ($options['accountType'] ?? 'EOA'));
        if (! in_array($type, ['EOA', 'SCA'], true)) {
            throw new InvalidArgumentException('REST wallet accountType must be EOA or SCA.');
        }
        $count = $options['count'] ?? 1;
        if (! is_int($count) || $count < 1 || $count > 200) {
            throw new InvalidArgumentException('Wallet count must be between 1 and 200.');
        }
        $response = $this->request('POST', '/developer/wallets', body: [
            'idempotencyKey' => $this->idempotencyKey($options), 'accountType' => $type,
            'blockchains' => [$chain], 'count' => $count, 'walletSetId' => $set,
        ], needsCiphertext: true);
        $rows = $this->data($response)['wallets'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== $count) {
            throw new LeptonRuntimeException('Wallet creation returned an unexpected response; reconcile before retrying.');
        }
        foreach ($rows as $row) {
            $this->assertWallet($row, $chain, $set);
        }

        return ['status' => 'created', 'wallets' => $rows, 'raw' => $response];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function createWalletSet(string $name, array $options = []): array
    {
        $this->assertWritable();
        if (trim($name) === '' || strlen($name) > 255) {
            throw new InvalidArgumentException('A wallet set name is required (up to 255 bytes).');
        }

        return $this->data($this->request('POST', '/developer/walletSets', body: [
            'idempotencyKey' => $this->idempotencyKey($options), 'name' => $name,
        ], needsCiphertext: true));
    }

    public function fundWallet(string $address, array $options = []): array
    {
        $this->assertWritable();
        $chain = $this->chain($options);
        if ($chain !== 'ARC-TESTNET') {
            throw new UnsupportedOperation('REST funding is a testnet faucet request, never a mainnet funding operation.');
        }
        $this->address($address);
        $this->wallet($address, $chain);
        $response = $this->send('POST', $this->origin().'/v1/faucet/drips', [], [
            'address' => $address, 'blockchain' => $chain, 'usdc' => true, 'native' => false,
        ])->data;

        return ['status' => 'requested', 'address' => $address, 'chain' => $chain, 'raw' => $response];
    }

    public function authStatus(): array
    {
        $empty = ['authenticated' => false, 'email' => null, 'status' => 'UNVERIFIED', 'expires_in' => null];
        $status = ['type' => 'developer', 'mainnet' => $empty, 'testnet' => $empty];
        try {
            $network = $this->keyNetwork();
            $data = $this->data($this->request('GET', '/wallets', ['pageSize' => 1]));
            if (! is_array($data['wallets'] ?? null)) {
                return $status;
            }
            $status[$network] = ['authenticated' => true, 'email' => null, 'status' => 'API_ACCESS_VERIFIED', 'expires_in' => null];
        } catch (Throwable) {
            return $status;
        }

        return $status;
    }

    public function beginLogin(string $email): string
    {
        throw new UnsupportedOperation('Developer-Controlled Wallets use an API key and registered Entity Secret, not email OTP.');
    }

    public function completeLogin(string $requestId, string $otp): array
    {
        throw new UnsupportedOperation('Developer-Controlled Wallets use an API key and registered Entity Secret, not email OTP.');
    }

    public function isAuthenticatedFor(string $chainCode = 'ARC-TESTNET'): bool
    {
        try {
            $chain = $this->chain(['chain' => $chainCode]);
        } catch (LeptonRuntimeException) {
            return false;
        }

        return $this->authStatus()[$chain === 'ARC-TESTNET' ? 'testnet' : 'mainnet']['authenticated'];
    }

    public function run(array $args, bool $json = true, array $options = []): array|string
    {
        throw new UnsupportedOperation('Raw CLI commands are not supported by the REST SDK. Use typed gateway methods.');
    }

    public function signMessage(string $address, string $message, array $options = []): array
    {
        $this->assertWritable();
        $wallet = $this->wallet($address, $this->chain($options));
        if ($message === '') {
            throw new InvalidArgumentException('Signing message cannot be empty.');
        }
        if (! empty($options['encodedByHex']) && preg_match('/^0x(?:[0-9a-fA-F]{2})+$/D', $message) !== 1) {
            throw new InvalidArgumentException('Hex signing messages must contain complete bytes.');
        }

        return $this->data($this->request('POST', '/developer/sign/message', body: [
            'walletId' => $wallet['id'], 'message' => $message, 'encodedByHex' => (bool) ($options['encodedByHex'] ?? false),
        ], needsCiphertext: true));
    }

    public function signTypedData(string $address, string $typedData, array $options = []): array
    {
        $this->assertWritable();
        $decoded = json_decode($typedData, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! isset($decoded['types'], $decoded['domain'], $decoded['message'], $decoded['primaryType'])) {
            throw new InvalidArgumentException('Expected EIP-712 JSON with types, domain, primaryType, and message.');
        }
        $wallet = $this->wallet($address, $this->chain($options));

        if (isset($decoded['domain']['chainId']) && ! in_array($decoded['domain']['chainId'],
            $this->chain($options) === 'ARC' ? [5042, '5042', '0x13b2', '0x13B2'] : [5042002, '5042002', '0x4cef52', '0x4CEF52'], true)) {
            throw new InvalidArgumentException('EIP-712 domain chain ID conflicts with the selected network.');
        }

        return $this->data($this->request('POST', '/developer/sign/typedData', body: [
            'walletId' => $wallet['id'], 'data' => $typedData,
        ], needsCiphertext: true));
    }

    public function execute(string $address, string $contract, string $signature, array $params = [], array $options = []): array
    {
        if (empty($options['estimate'])) {
            $this->assertWritable();
        }
        $this->address($contract);
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\(.*\)$/D', $signature) !== 1) {
            throw new InvalidArgumentException('Invalid ABI function signature.');
        }
        $wallet = $this->wallet($address, $this->chain($options));
        $body = ['walletId' => $wallet['id'], 'contractAddress' => $contract,
            'abiFunctionSignature' => $signature, 'abiParameters' => $params];
        if (isset($options['amount'])) {
            if (! is_string($options['amount'])) {
                throw new InvalidArgumentException('Native contract value must be an exact decimal string.');
            }
            Amounts::fromDecimalString($options['amount']);
            $body['amount'] = $options['amount'];
        }
        if (! empty($options['estimate'])) {
            return $this->data($this->request('POST', '/transactions/contractExecution/estimateFee', body: $body));
        }
        $body['idempotencyKey'] = $this->idempotencyKey($options);
        $body += $this->fee($options);

        return $this->data($this->request('POST', '/developer/transactions/contractExecution', body: $body, needsCiphertext: true));
    }

    public function gatewayDeposit(string $address, int|string $amount, array $options = []): array
    {
        throw new UnsupportedOperation('REST driver does not implement Gateway deposits.');
    }

    public function gatewayWithdraw(string $address, int|string $amount, array $options = []): array
    {
        throw new UnsupportedOperation('REST driver does not implement Gateway withdrawals.');
    }

    public function bridgeTransfer(string $fromAddress, string $toChain, int|string $amount, array $options = []): array
    {
        throw new UnsupportedOperation('REST driver does not implement CCTP bridging.');
    }

    public function bridgeStatus(string $burnTxHash, array $options = []): array
    {
        throw new UnsupportedOperation('REST driver does not implement CCTP observation.');
    }

    public function bridgeFee(string $fromChain, string $toChain, array $options = []): array
    {
        throw new UnsupportedOperation('REST driver does not implement CCTP fees.');
    }

    public function contractAddress(?string $name = null, array $options = []): array
    {
        throw new UnsupportedOperation('REST driver does not provide a contract registry.');
    }

    public function contractQuery(string $contract, string $signature, array $params = [], array $options = []): mixed
    {
        throw new UnsupportedOperation('Use ArcNetworkGateway::rpc for contract reads.');
    }

    public function blockchains(array $options = []): array
    {
        return [['blockchain' => 'ARC', 'testnet' => false], ['blockchain' => 'ARC-TESTNET', 'testnet' => true]];
    }

    public function searchServices(string $query): array
    {
        throw new UnsupportedOperation('REST driver does not implement x402 discovery.');
    }

    public function inspectService(string $url, array $options = []): array
    {
        throw new UnsupportedOperation('REST driver does not implement x402 inspection.');
    }

    public function payService(string $url, string $fromAddress, array $options = []): array
    {
        throw new UnsupportedOperation('REST driver does not implement x402 payments.');
    }

    public function gatewayBalance(string $address, array $options = []): array
    {
        throw new UnsupportedOperation('REST driver does not implement Gateway balance reads.');
    }

    public function webhooks(): CircleWebhookVerifier
    {
        return new CircleWebhookVerifier($this->transport, $this->apiKey, $this->origin());
    }

    public function generateEntitySecretCiphertext(#[\SensitiveParameter] ?string $entitySecret = null): string
    {
        $secret = $entitySecret ?? $this->entitySecret;
        if (! is_string($secret) || preg_match('/^[a-fA-F0-9]{64}$/D', $secret) !== 1) {
            throw new LeptonRuntimeException('Entity Secret must be a registered 32-byte hex value.');
        }
        if (! openssl_public_encrypt(hex2bin($secret), $encrypted, $this->fetchEntityPublicKey(), OPENSSL_PKCS1_OAEP_PADDING, 'sha256')) {
            throw new LeptonRuntimeException('Entity Secret encryption failed.');
        }

        return base64_encode($encrypted);
    }

    public function fetchEntityPublicKey(): string
    {
        if ($this->cachedEntityPublicKey !== null) {
            return $this->cachedEntityPublicKey;
        }
        $data = $this->data($this->request('GET', '/config/entity/publicKey'));
        $key = $data['publicKey'] ?? null;
        $public = is_string($key) ? openssl_pkey_get_public($key) : false;
        $details = $public === false ? false : openssl_pkey_get_details($public);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new LeptonRuntimeException('Invalid RSA entity encryption key.');
        }

        return $this->cachedEntityPublicKey = $key;
    }

    /** @param array<string, mixed> $query @param array<string, mixed>|null $body @return array<string, mixed> */
    public function request(string $method, string $endpoint, array $query = [], #[\SensitiveParameter] ?array $body = null, bool $needsCiphertext = false): array
    {
        if (! str_starts_with($endpoint, '/') || str_contains($endpoint, '..') || str_contains($endpoint, '?') || str_contains($endpoint, '#')) {
            throw new InvalidArgumentException('Expected an absolute Circle API path without query or traversal.');
        }
        if (strtoupper($method) !== 'GET' && ! str_ends_with($endpoint, '/estimateFee')) {
            $this->assertWritable();
        }
        if ($needsCiphertext) {
            $body ??= [];
            $body['entitySecretCiphertext'] = $this->generateEntitySecretCiphertext();
        }

        return $this->send($method, rtrim($this->baseUrl, '/').$endpoint, $query, $body)->data;
    }

    /** @param array<string, mixed> $query @param array<string, mixed>|null $body */
    private function send(string $method, string $url, array $query = [], #[\SensitiveParameter] ?array $body = null): HttpResponse
    {
        $this->keyNetwork();
        if ($this->httpClient !== null) {
            $response = ($this->httpClient)($method, $url, $query, $body);
            $response = $response instanceof HttpResponse ? $response : new HttpResponse(200, $response);
        } else {
            if ($query !== []) {
                $url .= '?'.http_build_query($query);
            }
            $response = $this->transport->request($method, $url,
                ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer '.$this->apiKey], $body);
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new ApiException($response->status, $response->header('X-Request-Id'));
        }

        return $response;
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private function data(array $response): array
    {
        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            throw new LeptonRuntimeException('Circle response is missing its data envelope; outcome may be unknown.');
        }

        return $data;
    }

    /** @param array<string, mixed> $query @return list<array<string, mixed>> */
    private function paginate(string $path, string $collection, array $query = []): array
    {
        $rows = [];
        $seen = [];
        $query['pageSize'] = 50;
        for ($page = 0; $page < 100; $page++) {
            $response = $this->send('GET', rtrim($this->baseUrl, '/').$path, $query);
            $data = $this->data($response->data);
            $batch = $data[$collection] ?? null;
            if (! is_array($batch) || ! array_is_list($batch)) {
                throw new LeptonRuntimeException('Malformed paginated Circle response.');
            }
            foreach ($batch as $row) {
                if (! is_array($row)) {
                    throw new LeptonRuntimeException('Malformed Circle collection item.');
                }
                $rows[] = $row;
            }
            $cursor = $response->data['pagination']['nextPageAfter'] ?? $data['nextPageAfter'] ?? null;
            if (preg_match('/<([^>]+)>;\s*rel="next"/', $response->header('Link') ?? '', $matches) === 1) {
                parse_str((string) parse_url($matches[1], PHP_URL_QUERY), $parameters);
                $cursor = $parameters['pageAfter'] ?? null;
            }
            if ($cursor === null && count($batch) === 50) {
                $cursor = $batch[array_key_last($batch)]['id'] ?? null;
            }
            if ($cursor === null || $cursor === '') {
                return $rows;
            }
            $cursor = $this->uuid((string) $cursor, 'pagination cursor');
            if (isset($seen[$cursor])) {
                throw new LeptonRuntimeException('Circle pagination repeated a cursor.');
            }
            $seen[$cursor] = true;
            $query['pageAfter'] = $cursor;
        }

        throw new LeptonRuntimeException('Circle pagination exceeded the bounded page limit.');
    }

    /** @return array<string, mixed> */
    private function wallet(string $addressOrId, string $chain): array
    {
        $key = $chain.':'.($this->walletSetId ?? '').':'.strtolower($addressOrId);
        if (isset($this->walletsByContext[$key])) {
            return $this->walletsByContext[$key];
        }
        if (preg_match('/^[0-9a-f-]{36}$/iD', $addressOrId) === 1) {
            $id = $this->uuid($addressOrId, 'wallet ID');
            $wallet = $this->data($this->request('GET', '/wallets/'.$id))['wallet'] ?? [];
            $this->assertWallet($wallet, $chain, $this->walletSetId);
            if ($wallet['id'] !== $id) {
                throw new LeptonRuntimeException('Wallet lookup returned a different wallet ID.');
            }
        } else {
            $this->address($addressOrId);
            $matches = array_values(array_filter($this->wallets(['chain' => $chain]),
                fn (array $wallet): bool => strcasecmp($wallet['address'], $addressOrId) === 0));
            if (count($matches) !== 1) {
                throw new LeptonRuntimeException('Wallet address is missing or ambiguous in the selected chain and wallet set.');
            }
            $wallet = $matches[0];
        }

        return $this->walletsByContext[$key] = $wallet;
    }

    /** @param array<string, mixed> $wallet */
    private function assertWallet(array $wallet, string $chain, ?string $set): void
    {
        $this->uuid((string) ($wallet['id'] ?? ''), 'wallet ID');
        $this->address((string) ($wallet['address'] ?? ''));
        if (($wallet['blockchain'] ?? null) !== $chain || ($wallet['custodyType'] ?? null) !== 'DEVELOPER'
            || ! in_array($wallet['accountType'] ?? null, ['EOA', 'SCA'], true)
            || ($set !== null && $set !== '' && ($wallet['walletSetId'] ?? null) !== $set)) {
            throw new LeptonRuntimeException('Wallet does not match developer custody, account type, network, or wallet set.');
        }
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function transferBody(string $from, string $to, int $units, array $options): array
    {
        $this->address($to);
        if ($units <= 0) {
            throw new InvalidArgumentException('Transfer amount must be positive integer USDC base units.');
        }
        $chain = $this->chain($options);
        $wallet = $this->wallet($from, $chain);
        $token = $options['token'] ?? self::USDC_ADDRESS;
        if (! is_string($token) || ! in_array(strtolower($token), [self::USDC_ADDRESS, 'native'], true)) {
            throw new UnsupportedOperation('This release transfers canonical Arc USDC only; token identifiers are not guessed.');
        }

        return ['walletId' => $wallet['id'], 'destinationAddress' => $to,
            'amounts' => [Amounts::toCliAmount($units)], 'blockchain' => $chain,
            'tokenAddress' => strtolower($token) === 'native' ? '' : self::USDC_ADDRESS];
    }

    /** @param array<string, mixed> $options @return array<string, string> */
    private function fee(array $options): array
    {
        foreach (['maxFeeBaseUnits', 'max_fee_base_units', 'feeCeiling', 'gasLimit', 'gasPrice', 'maxFee', 'priorityFee'] as $cap) {
            if (array_key_exists($cap, $options)) {
                throw new UnsupportedOperation('REST fee estimates/levels do not enforce a payer fee ceiling.');
            }
        }
        $level = strtoupper((string) ($options['feeLevel'] ?? 'MEDIUM'));
        if (! in_array($level, ['LOW', 'MEDIUM', 'HIGH'], true)) {
            throw new InvalidArgumentException('Invalid Circle fee level.');
        }

        return ['feeLevel' => $level];
    }

    /** @param array<string, mixed> $options */
    private function idempotencyKey(array $options): string
    {
        $key = $this->uuid((string) ($options['idempotencyKey'] ?? ''), 'persisted idempotency key');
        if ($key[14] !== '4' || ! in_array(strtolower($key[19]), ['8', '9', 'a', 'b'], true)) {
            throw new InvalidArgumentException('Circle idempotency key must be a persisted UUID v4.');
        }

        return $key;
    }

    /** @param array<string, mixed> $options */
    private function chain(array $options): string
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        if (! is_string($chain) || ! in_array($chain, ['ARC', 'ARC-TESTNET'], true)) {
            throw new UnsupportedOperation('REST SDK currently supports ARC and ARC-TESTNET only.');
        }
        if ($this->keyNetwork() !== ($chain === 'ARC-TESTNET' ? 'testnet' : 'mainnet')) {
            throw new LeptonRuntimeException('Circle API key environment does not match the selected network.');
        }

        return $chain;
    }

    private function keyNetwork(): string
    {
        if (! is_string($this->apiKey) || preg_match('/^(TEST|LIVE)_API_KEY:[^:\s]+:[^:\s]+$/D', $this->apiKey, $match) !== 1) {
            throw new LeptonRuntimeException('Circle API key must use TEST_API_KEY or LIVE_API_KEY with ID and secret.');
        }

        return $match[1] === 'TEST' ? 'testnet' : 'mainnet';
    }

    private function assertWritable(): void
    {
        if ($this->dryRun) {
            throw new LeptonRuntimeException('Dry-run blocks wallet mutations and signing. Use the explicit estimate methods.');
        }
    }

    private function uuid(string $value, string $label): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) !== 1) {
            throw new InvalidArgumentException('Invalid '.$label.'.');
        }

        return $value;
    }

    private function address(string $value): void
    {
        if (preg_match('/^0x[0-9a-fA-F]{40}$/D', $value) !== 1 || strtolower($value) === '0x'.str_repeat('0', 40)) {
            throw new InvalidArgumentException('Expected a nonzero EVM address with exactly 40 hex characters.');
        }
    }

    private function origin(): string
    {
        return 'https://'.parse_url($this->baseUrl, PHP_URL_HOST).(parse_url($this->baseUrl, PHP_URL_PORT) === null ? '' : ':'.parse_url($this->baseUrl, PHP_URL_PORT));
    }
}

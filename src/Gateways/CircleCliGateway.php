<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Gateways;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\CircleGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Contracts\X402Gateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransactionRecord;
use Yukazakiri\Lepton\DTOs\TransferResolution;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\Support\Amounts;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

/**
 * The Circle CLI, including the part that does not fit a synchronous contract.
 *
 * Circle agent wallets do not transact from their own address and do not answer a
 * transfer with an on-chain hash. They answer with a transaction id and settle
 * later, which is why this gateway has both `transfer()` and `resolve()`.
 *
 * Two behaviours were confirmed against the live CLI on ARC-TESTNET, and both are
 * the reason the naive reading of `circle wallet transfer` is wrong:
 *
 *  - **without** `--quiet`, the command answers with a *fee preview* —
 *    `gasLimit`, `networkFee`, `baseFee`, `priorityFee`, `maxFee` — which is
 *    byte-for-byte the shape of `--estimate` and broadcasts nothing. Checking
 *    `circle transaction list` afterwards shows no new transaction, and the
 *    wallet balance is unchanged.
 *  - **with** `--quiet`, an agent wallet returns HTTP 400 "Invalid request
 *    body". The flag is documented as yielding a transaction id for agent
 *    wallets, but the service rejects the request it builds.
 *
 * Neither is a guess, and neither is silently worked around. A transfer that
 * yields neither a hash nor a recognizable handle throws, because the
 * alternative is recording an invented reference — the exact failure this
 * package exists to prevent.
 */
class CircleCliGateway implements AuthGateway, CircleGateway, WalletGateway, X402Gateway
{
    public function __construct(
        private readonly CliRunner $circle,
        private readonly int $usdcDecimals = 6,
        private readonly bool $dryRun = false,
        private readonly ?string $defaultRpcUrl = null,
    ) {}

    public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $amount = Amounts::toCliAmount($amountBaseUnits, $this->usdcDecimals);

        $args = ['wallet', 'transfer', $toAddress, '--amount', $amount, '--address', $fromAddress, '--chain', $chain];

        $rpcUrl = $options['rpcUrl'] ?? $this->defaultRpcUrl;
        if (is_string($rpcUrl) && $rpcUrl !== '') {
            $args[] = '--rpc-url';
            $args[] = $rpcUrl;
        }

        if (! empty($options['token'])) {
            $args[] = '--token';
            $args[] = (string) $options['token'];
        }

        $args[] = '--idempotency-key';
        $args[] = (string) ($options['idempotencyKey'] ?? (string) Str::uuid());

        if ($this->dryRun || ! empty($options['estimate'])) {
            $args[] = '--estimate';
        }

        $out = $this->circle->run($args);
        $payload = self::unwrap($out);

        // An estimate is not a transfer, and must never be promoted into one.
        if ($this->dryRun || ! empty($options['estimate'])) {
            throw new LeptonRuntimeException(
                'A preflight is an estimate, not a submission. Read the fee from it and submit separately; '
                .'do not treat an estimated amount as a transfer outcome.'
            );
        }

        $txHash = $this->hash($payload);

        if ($txHash !== null) {
            return new TransferResult(
                txHash: $txHash,
                fromAddress: $fromAddress,
                toAddress: $toAddress,
                amountBaseUnits: $amountBaseUnits,
                chain: $chain,
                isFake: false,
                explorerUrl: app(\Yukazakiri\Lepton\Contracts\ArcNetworkGateway::class)->explorerUrl($txHash),
                raw: $out,
                state: 'submitted',
            );
        }

        // No hash. On an asynchronous rail this is the ordinary case, and the
        // rail's own handle is the legitimate answer. Anything that is not
        // shaped like a handle is not evidence that a transfer exists.
        $handle = $this->handle($payload);

        if ($handle !== null) {
            return new TransferResult(
                txHash: null,
                fromAddress: $fromAddress,
                toAddress: $toAddress,
                amountBaseUnits: $amountBaseUnits,
                chain: $chain,
                isFake: false,
                explorerUrl: null,
                raw: $out,
                handle: $handle,
                state: 'accepted',
            );
        }

        throw new LeptonRuntimeException(
            'Circle CLI returned neither an on-chain reference nor a transaction identifier. '
            .'Raw response: '.json_encode($out)
        );
    }

    public function resolve(string $handle, array $options = []): TransferResolution
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');

        $out = $this->circle->run(['transaction', 'list', '--chain', $chain, '--output', 'json']);
        $transactions = self::unwrap($out)['transactions'] ?? [];

        foreach ((array) $transactions as $transaction) {
            if (! is_array($transaction) || (string) ($transaction['id'] ?? '') !== $handle) {
                continue;
            }

            $state = strtoupper((string) ($transaction['state'] ?? ''));

            return match ($state) {
                'COMPLETE' => $this->resolutionFor($transaction, $handle),
                'FAILED', 'CANCELED', 'REJECTED' => TransferResolution::failed(
                    'Circle reported the transfer as '.$state.'. No USDC moved; a retry needs a new submission.',
                    $handle,
                    ['state' => $state],
                ),
                default => TransferResolution::pending($handle, ['state' => $state]),
            };
        }

        // Absent from the history is not evidence the transfer was never made.
        return TransferResolution::unknown(
            'The rail did not return this transaction. Its fate is unresolved; observe again before any retry.',
            $handle,
        );
    }

    public function balance(string $address, array $options = []): BalanceResult
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $out = self::unwrap($this->circle->run(['wallet', 'balance', '--address', $address, '--chain', $chain, '--output', 'json']));

        $native = 0;
        foreach ((array) ($out['balances'] ?? []) as $token) {
            if (($token['token']['isNative'] ?? false) === true) {
                $native += (int) round((float) ($token['amount'] ?? 0) * (10 ** $this->usdcDecimals));
            }
        }

        return new BalanceResult($address, $native, $chain, false, $out);
    }

    public function transactions(string $address, array $filters = []): array
    {
        $chain = $filters['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $out = self::unwrap($this->circle->run(['transaction', 'list', '--address', $address, '--chain', $chain, '--output', 'json']));

        return array_values(array_filter(array_map(
            fn (array $t): ?TransactionRecord => new TransactionRecord(
                id: (string) ($t['id'] ?? ''),
                txHash: is_string($t['txHash'] ?? null) ? $t['txHash'] : null,
                state: (string) ($t['state'] ?? 'unknown'),
                operation: (string) ($t['operation'] ?? 'transfer'),
                raw: $t,
            ),
            (array) ($out['transactions'] ?? []),
        ), fn (?TransactionRecord $record): bool => $record instanceof TransactionRecord && $record->id !== ''));
    }

    public function limits(string $address, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');

        try {
            $out = $this->circle->run(['wallet', 'limit', '--address', $address, '--chain', $chain, '--output', 'json']);
        } catch (LeptonRuntimeException $e) {
            // Spending policy is mainnet-only. A testnet wallet is not an error
            // state; it is a rail that does not answer this question there.
            return ['address' => $address, 'chain' => $chain, 'declared_only' => true, 'error' => $e->getMessage()];
        }

        return (array) self::unwrap($out);
    }

    public function searchServices(string $query): array
    {
        /** @var array<string,mixed>|string $out */
        $out = $this->circle->run(['services', 'search', $query, '--output', 'json']);
        $payload = self::unwrap($out);

        return $payload['services'] ?? (array_is_list($payload) ? $payload : [$payload]);
    }

    public function inspectService(string $url, array $options = []): array
    {
        /** @var array<string,mixed>|string $out */
        $out = $this->circle->run(['services', 'inspect', $url, '--output', 'json']);

        return self::unwrap($out);
    }

    public function payService(string $url, string $fromAddress, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['services', 'pay', $url, '--address', $fromAddress, '--chain', $chain, '--output', 'json'];

        foreach (['method' => '--method', 'data' => '--data', 'maxAmount' => '--max-amount', 'timeout' => '--timeout'] as $key => $flag) {
            if (! empty($options[$key])) {
                array_push($args, $flag, (string) $options[$key]);
            }
        }

        /** @var array<string,mixed>|string $out */
        $out = $this->circle->run($args);

        return self::unwrap($out);
    }

    public function gatewayBalance(string $address, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['gateway', 'balance', '--address', $address, '--chain', $chain, '--output', 'json'];

        /** @var array<string,mixed>|string $out */
        $out = $this->circle->run($args);

        return self::unwrap($out);
    }

    public function run(array $args, bool $json = true, array $options = []): array|string
    {
        $env = array_merge(['CIRCLE_ACCEPT_TERMS' => '1'], (array) ($options['env'] ?? []));

        if (! $json) {
            /** @var string $output */
            $output = $this->circle->run($args, false, $env);

            return $output;
        }

        /** @var array<string,mixed>|string $out */
        $out = $this->circle->run($args, true, $env);

        return self::unwrap($out);
    }

    public function wallets(array $options = []): array
    {
        $args = ['wallet', 'list'];
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        if (is_string($chain) && $chain !== '') {
            array_push($args, '--chain', $chain);
        }
        if (! empty($options['type'])) {
            array_push($args, '--type', (string) $options['type']);
        }

        $out = $this->run($args, true, $options);
        $payload = is_array($out) ? self::unwrap($out) : [];

        return $payload['wallets'] ?? (array_is_list($payload) ? $payload : []);
    }

    public function createWallet(array $options = []): array
    {
        if ($this->dryRun) {
            throw new LeptonRuntimeException('Cannot create wallet while dry-run mode is enabled (no CLI estimation supported).');
        }

        $args = ['wallet', 'create'];
        if (! empty($options['testnet'])) {
            $args[] = '--testnet';
        }
        if (! empty($options['type'])) {
            array_push($args, '--type', (string) $options['type']);
        }
        if (! empty($options['idempotencyKey'])) {
            array_push($args, '--idempotency-key', (string) $options['idempotencyKey']);
        }

        $out = $this->run($args, true, $options);

        return is_array($out) ? self::unwrap($out) : ['raw' => $out];
    }

    public function fundWallet(string $address, array $options = []): array
    {
        if ($this->dryRun) {
            throw new LeptonRuntimeException('Cannot fund wallet while dry-run mode is enabled (no CLI estimation supported).');
        }

        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['wallet', 'fund', '--address', $address, '--chain', $chain, '--no-open'];

        if (! empty($options['token'])) {
            array_push($args, '--token', (string) $options['token']);
        }
        if (isset($options['amount'])) {
            array_push($args, '--amount', $this->formatAmount($options['amount']));
        }
        if (! empty($options['method'])) {
            array_push($args, '--method', (string) $options['method']);
        }

        $out = $this->run($args, true, $options);

        return is_array($out) ? self::unwrap($out) : ['raw' => $out];
    }

    public function signMessage(string $address, string $message, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['wallet', 'sign', 'message', $message, '--address', $address];
        if (is_string($chain) && $chain !== '') {
            array_push($args, '--chain', $chain);
        }

        $out = $this->run($args, true, $options);

        return is_array($out) ? self::unwrap($out) : ['raw' => $out];
    }

    public function signTypedData(string $address, string $typedData, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['wallet', 'sign', 'typed-data', $typedData, '--address', $address];
        if (is_string($chain) && $chain !== '') {
            array_push($args, '--chain', $chain);
        }

        $out = $this->run($args, true, $options);

        return is_array($out) ? self::unwrap($out) : ['raw' => $out];
    }

    public function execute(string $address, string $contract, string $signature, array $params = [], array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['wallet', 'execute', $signature];

        foreach ($params as $param) {
            $args[] = (string) $param;
        }

        array_push($args, '--contract', $contract, '--address', $address, '--chain', $chain);

        if (isset($options['amount'])) {
            array_push($args, '--amount', (string) $options['amount']);
        }
        if ($this->dryRun || ! empty($options['estimate'])) {
            $args[] = '--estimate';
        }
        if (! empty($options['idempotencyKey'])) {
            array_push($args, '--idempotency-key', (string) $options['idempotencyKey']);
        }
        $rpcUrl = $options['rpcUrl'] ?? $this->defaultRpcUrl;
        if (is_string($rpcUrl) && $rpcUrl !== '') {
            array_push($args, '--rpc-url', $rpcUrl);
        }

        $out = $this->run($args, true, $options);

        return is_array($out) ? self::unwrap($out) : ['raw' => $out];
    }

    public function gatewayDeposit(string $address, int|string $amount, array $options = []): array
    {
        if ($this->dryRun) {
            throw new LeptonRuntimeException('Cannot deposit to gateway while dry-run mode is enabled (no CLI estimation supported).');
        }

        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $formattedAmount = $this->formatAmount($amount);
        $args = ['gateway', 'deposit', '--amount', $formattedAmount, '--address', $address, '--chain', $chain];

        if (! empty($options['method'])) {
            array_push($args, '--method', (string) $options['method']);
        }
        if (! empty($options['timeout'])) {
            array_push($args, '--timeout', (string) $options['timeout']);
        }
        if (! empty($options['noWait'])) {
            $args[] = '--no-wait';
        }

        $out = $this->run($args, true, $options);

        return is_array($out) ? self::unwrap($out) : ['raw' => $out];
    }

    public function gatewayWithdraw(string $address, int|string $amount, array $options = []): array
    {
        if ($this->dryRun) {
            throw new LeptonRuntimeException('Cannot withdraw from gateway while dry-run mode is enabled (no CLI estimation supported).');
        }

        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $formattedAmount = $this->formatAmount($amount);
        $args = ['gateway', 'withdraw', '--amount', $formattedAmount, '--address', $address, '--chain', $chain];

        $out = $this->run($args, true, $options);

        return is_array($out) ? self::unwrap($out) : ['raw' => $out];
    }

    public function bridgeTransfer(string $fromAddress, string $toChain, int|string $amount, array $options = []): array
    {
        if ($this->dryRun) {
            throw new LeptonRuntimeException('Cannot bridge transfer while dry-run mode is enabled (no CLI estimation supported).');
        }

        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $formattedAmount = $this->formatAmount($amount);
        $args = ['bridge', 'transfer', $toChain];

        if (! empty($options['recipient'])) {
            $args[] = (string) $options['recipient'];
        }
        array_push($args, '--amount', $formattedAmount, '--address', $fromAddress, '--chain', $chain);

        if (! empty($options['idempotencyKey'])) {
            array_push($args, '--idempotency-key', (string) $options['idempotencyKey']);
        }
        if (! empty($options['rpcUrl'])) {
            array_push($args, '--rpc-url', (string) $options['rpcUrl']);
        }

        $out = $this->run($args, true, $options);

        return is_array($out) ? self::unwrap($out) : ['raw' => $out];
    }

    public function bridgeStatus(string $burnTxHash, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['bridge', 'status', $burnTxHash];
        if (is_string($chain) && $chain !== '') {
            array_push($args, '--chain', $chain);
        }

        $out = $this->run($args, true, $options);

        return is_array($out) ? self::unwrap($out) : ['raw' => $out];
    }

    public function bridgeFee(string $fromChain, string $toChain, array $options = []): array
    {
        $out = $this->run(['bridge', 'get-fee', $toChain, '--chain', $fromChain], true, $options);

        return is_array($out) ? self::unwrap($out) : ['raw' => $out];
    }

    public function contractAddress(?string $name = null, array $options = []): array
    {
        $args = ['contract', 'address'];
        if ($name !== null && $name !== '') {
            $args[] = $name;
        }

        $out = $this->run($args, true, $options);
        $payload = is_array($out) ? self::unwrap($out) : [];

        return $payload['contracts'] ?? (array_is_list($payload) ? $payload : (array) $payload);
    }

    public function contractQuery(string $contract, string $signature, array $params = [], array $options = []): mixed
    {
        $args = ['contract', 'query', $signature];

        foreach ($params as $param) {
            $args[] = (string) $param;
        }

        array_push($args, '--contract', $contract);

        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        if (is_string($chain) && $chain !== '') {
            array_push($args, '--chain', $chain);
        }
        if (! empty($options['rpcUrl'])) {
            array_push($args, '--rpc-url', (string) $options['rpcUrl']);
        }

        $out = $this->run($args, true, $options);

        return is_array($out) ? self::unwrap($out) : $out;
    }

    public function blockchains(array $options = []): array
    {
        $out = $this->run(['blockchain', 'list'], true, $options);
        $payload = is_array($out) ? self::unwrap($out) : [];

        return $payload['blockchains'] ?? (array_is_list($payload) ? $payload : []);
    }

    public function authStatus(): array
    {
        $empty = ['authenticated' => false, 'email' => null, 'status' => null, 'expires_in' => null];

        $out = self::unwrap($this->circle->run(['wallet', 'status', '--type', 'agent', '--output', 'json']));

        $read = function (?array $section) use ($empty): array {
            if ($section === null) {
                return $empty;
            }

            $status = isset($section['tokenStatus']) ? (string) $section['tokenStatus'] : null;

            return [
                'authenticated' => strtoupper((string) $status) === 'VALID',
                'email' => isset($section['email']) ? (string) $section['email'] : null,
                'status' => $status,
                'expires_in' => isset($section['expiresIn']) ? (string) $section['expiresIn'] : null,
            ];
        };

        return [
            'type' => (string) ($out['type'] ?? 'agent'),
            'mainnet' => $read(is_array($out['mainnet'] ?? null) ? $out['mainnet'] : null),
            'testnet' => $read(is_array($out['testnet'] ?? null) ? $out['testnet'] : null),
        ];
    }

    public function beginLogin(string $email): string
    {
        $out = self::unwrap($this->circle->run(
            ['wallet', 'login', $email, '--init', '--output', 'json'],
            true,
            ['CIRCLE_ACCEPT_TERMS' => '1'],
        ));

        $requestId = $out['requestId'] ?? $out['request_id'] ?? $out['id'] ?? null;

        if (! is_string($requestId) || $requestId === '') {
            throw new LeptonRuntimeException(
                'Circle CLI did not return a login request ID. Raw response: '.json_encode($out)
            );
        }

        return $requestId;
    }

    public function completeLogin(string $requestId, string $otp): array
    {
        $out = self::unwrap($this->circle->run(['wallet', 'login', '--request', $requestId, '--otp', $otp, '--output', 'json']));

        return [
            'email' => isset($out['email']) ? (string) $out['email'] : null,
            'status' => isset($out['status']) ? (string) $out['status'] : 'VALID',
        ];
    }

    public function isAuthenticatedFor(string $chainCode = 'ARC-TESTNET'): bool
    {
        $status = $this->authStatus();
        $key = str_contains(strtoupper($chainCode), 'TESTNET') ? 'testnet' : 'mainnet';

        return $status[$key]['authenticated'] === true;
    }

    /**
     * A completed transfer still has to yield a real on-chain reference.
     *
     * @param  array<string,mixed>  $transaction
     */
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

    /**
     * The rail's own identifier for a transfer.
     *
     * @param  array<string,mixed>  $payload
     */
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

    /**
     * @param  array<string,mixed>|string  $payload
     */
    private function hash(array|string $payload): ?string
    {
        $value = is_array($payload) ? ($payload['txHash'] ?? $payload['transactionHash'] ?? $payload['hash'] ?? null) : null;

        return is_string($value) && preg_match('/^0x[0-9a-fA-F]{64}$/D', $value) === 1
            ? $value
            : null;
    }

    /**
     * The CLI wraps most responses in `data`.
     *
     * @param  array<string,mixed>|string  $out
     * @return array<string,mixed>
     */
    private static function unwrap(array|string $out): array
    {
        if (! is_array($out)) {
            return [];
        }

        return is_array($out['data'] ?? null) ? $out['data'] : $out;
    }

    private function formatAmount(int|string $amount): string
    {
        if (is_int($amount)) {
            return Amounts::toCliAmount($amount, $this->usdcDecimals);
        }

        $trimmed = trim((string) $amount);
        if (! is_numeric($trimmed)) {
            throw new InvalidArgumentException("Invalid amount [{$amount}].");
        }

        return $trimmed;
    }
}

<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Gateways;

use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Contracts\X402Gateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransactionRecord;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\Support\Amounts;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;
use Illuminate\Support\Str;

final class CircleCliGateway implements WalletGateway, X402Gateway, AuthGateway
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
            array_push($args, '--rpc-url', $rpcUrl);
        }

        if (! empty($options['token'])) {
            array_push($args, '--token', (string) $options['token']);
        }

        array_push($args, '--idempotency-key', (string) ($options['idempotencyKey'] ?? (string) Str::uuid()));

        if ($this->dryRun || ! empty($options['estimate'])) {
            $args[] = '--estimate';
        }

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);
        $payload = self::unwrap($out);

        // Never fall back to the UUID "id": a missing hash must surface as
        // missing rather than be recorded as if it were a settlement receipt.
        $txHash = $payload['txHash'] ?? $payload['transactionHash'] ?? $payload['hash'] ?? null;

        if (! is_string($txHash) || $txHash === '') {
            throw new LeptonRuntimeException(
                'Circle CLI returned no tx hash for the transfer. Raw response: '.json_encode($out)
            );
        }

        return new TransferResult(
            txHash: $txHash,
            fromAddress: $fromAddress,
            toAddress: $toAddress,
            amountBaseUnits: $amountBaseUnits,
            chain: $chain,
            isFake: false,
            explorerUrl: app(ArcNetworkGateway::class)->explorerUrl($txHash),
            raw: $out,
        );
    }

    /**
     * Circle CLI wraps every successful payload in a top-level "data" key.
     *
     * @param  array<string,mixed>  $out
     * @return array<string,mixed>
     */
    private static function unwrap(array $out): array
    {
        if (isset($out['data']) && is_array($out['data'])) {
            return $out['data'];
        }

        return $out;
    }

    public function balance(string $address, array $options = []): BalanceResult
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['wallet', 'balance', '--address', $address, '--chain', $chain];

        if (! empty($options['token'])) {
            array_push($args, '--token', (string) $options['token']);
        }
        $rpcUrl = $options['rpcUrl'] ?? $this->defaultRpcUrl;
        if (is_string($rpcUrl) && $rpcUrl !== '') {
            array_push($args, '--rpc-url', $rpcUrl);
        }

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);

        $amountBaseUnits = $this->extractBalance(self::unwrap($out));

        return new BalanceResult($address, $amountBaseUnits, $chain, false, $out);
    }

    /**
     * @return array<int, TransactionRecord>
     */
    public function transactions(string $address, array $filters = []): array
    {
        $chain = $filters['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['transaction', 'list', '--address', $address, '--chain', $chain];

        foreach (['operation' => '--operation', 'state' => '--state', 'limit' => '--limit'] as $key => $flag) {
            if (! empty($filters[$key])) {
                array_push($args, $flag, (string) $filters[$key]);
            }
        }

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);
        $payload = self::unwrap($out);
        $rows = $payload['transactions'] ?? (array_is_list($payload) ? $payload : []);

        $records = [];
        foreach ((array) $rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $records[] = new TransactionRecord(
                id: (string) ($row['id'] ?? $row['txHash'] ?? uniqid()),
                txHash: isset($row['txHash']) ? (string) $row['txHash'] : null,
                state: (string) ($row['state'] ?? $row['status'] ?? 'unknown'),
                operation: isset($row['operation']) ? (string) $row['operation'] : null,
                raw: $row,
            );
        }

        return $records;
    }

    public function limits(string $address, array $options = []): array
    {
        $chain = $options['chain'] ?? 'ARC';
        $args = ['wallet', 'limit', '--address', $address, '--chain', $chain];

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);

        return $out;
    }

    public function searchServices(string $query): array
    {
        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson(['services', 'search', $query]);

        return $out['services'] ?? (array_is_list($out) ? $out : [$out]);
    }

    public function inspectService(string $url, array $options = []): array
    {
        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson(['services', 'inspect', $url]);

        return $out;
    }

    public function payService(string $url, string $fromAddress, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['services', 'pay', $url, '--address', $fromAddress, '--chain', $chain];

        foreach (['method' => '--method', 'data' => '--data', 'maxAmount' => '--max-amount', 'timeout' => '--timeout'] as $key => $flag) {
            if (! empty($options[$key])) {
                array_push($args, $flag, (string) $options[$key]);
            }
        }

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);

        return $out;
    }

    public function gatewayBalance(string $address, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['gateway', 'balance', '--address', $address, '--chain', $chain];

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);

        return $out;
    }

    /**
     * @param  array<string,mixed>  $out
     */
    /**
     * @param  array<string,mixed>  $payload  Already unwrapped from the "data" envelope.
     *
     * Arc reports USDC twice: once as the 18-decimal native gas asset and once
     * as the 6-decimal ERC20. Only rows whose precision matches the configured
     * USDC scale are summed, otherwise the two units would be mixed.
     */
    private function extractBalance(array $payload): int
    {
        $rows = $payload['balances'] ?? (array_is_list($payload) ? $payload : []);

        $total = 0;
        $matched = false;

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['amount']) || ! is_numeric($row['amount'])) {
                continue;
            }

            $token = is_array($row['token'] ?? null) ? $row['token'] : [];
            $decimals = isset($token['decimals']) ? (int) $token['decimals'] : $this->usdcDecimals;

            if ($decimals !== $this->usdcDecimals) {
                continue;
            }

            $total += Amounts::fromDecimalString((string) $row['amount'], $decimals);
            $matched = true;
        }

        if ($matched) {
            return $total;
        }

        // Single-scale response, or an unfamiliar shape: fall back to a scalar field.
        foreach (['balance', 'amount', 'total'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return Amounts::fromDecimalString((string) $payload[$key], $this->usdcDecimals);
            }
        }

        return 0;
    }

    public function authStatus(): array
    {
        $empty = ['authenticated' => false, 'email' => null, 'status' => null, 'expires_in' => null];

        $out = $this->circle->runJson(['wallet', 'status', '--type', 'agent']);
        $payload = self::unwrap($out);

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
            'type' => (string) ($payload['type'] ?? 'agent'),
            'mainnet' => $read(is_array($payload['mainnet'] ?? null) ? $payload['mainnet'] : null),
            'testnet' => $read(is_array($payload['testnet'] ?? null) ? $payload['testnet'] : null),
        ];
    }

    public function beginLogin(string $email): string
    {
        // CIRCLE_ACCEPT_TERMS stops the CLI pausing for the Terms of Use on a
        // first run, which would otherwise block a non-interactive caller.
        $out = $this->circle->runJson(
            ['wallet', 'login', $email, '--init'],
            ['CIRCLE_ACCEPT_TERMS' => '1'],
        );
        $payload = self::unwrap($out);

        $requestId = $payload['requestId'] ?? $payload['request_id'] ?? $payload['id'] ?? null;

        if (! is_string($requestId) || $requestId === '') {
            throw new LeptonRuntimeException(
                'Circle CLI did not return a login request ID. Raw response: '.json_encode($out)
            );
        }

        return $requestId;
    }

    public function completeLogin(string $requestId, string $otp): array
    {
        $out = $this->circle->runJson(['wallet', 'login', '--request', $requestId, '--otp', $otp]);
        $payload = self::unwrap($out);

        return [
            'email' => isset($payload['email']) ? (string) $payload['email'] : null,
            'status' => isset($payload['status']) ? (string) $payload['status'] : 'VALID',
        ];
    }

    public function isAuthenticatedFor(string $chainCode = 'ARC-TESTNET'): bool
    {
        $status = $this->authStatus();
        $key = str_contains(strtoupper($chainCode), 'TESTNET') ? 'testnet' : 'mainnet';

        return $status[$key]['authenticated'] === true;
    }
}

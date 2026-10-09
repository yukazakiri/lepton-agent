<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Gateways;

use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\CircleGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Contracts\X402Gateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransactionRecord;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

/**
 * In-memory driver for Pest tests and offline demo. No CLI, no chain.
 */
final class FakeLeptonGateway implements ArcNetworkGateway, AuthGateway, CircleGateway, WalletGateway, X402Gateway
{
    /** @var array<string,int> */
    private array $ledger = [];

    /** @var array<int, array<string,mixed>> */
    private array $transactions = [];

    /** @var array<string,string> */
    private array $rpcStubs = [];

    /** @var array<string,mixed> */
    private array $runStubs = [];

    /** @var array<int, array<string,mixed>> */
    private array $wallets = [];

    private bool $authenticated = false;

    private ?string $pendingRequestId = null;

    public function __construct(
        private readonly ?string $treasuryAddress = null,
        private readonly string $chainCode = 'ARC-TESTNET',
        private readonly int $chainId = 5042002,
    ) {
        if ($this->treasuryAddress !== null && $this->treasuryAddress !== '') {
            $this->wallets[] = [
                'type' => 'agent',
                'address' => $this->treasuryAddress,
                'blockchain' => $this->chainCode,
                'createDate' => date('c'),
            ];
        }
    }

    /**
     * Pretend a valid session exists, so tests can exercise authenticated paths.
     */
    public function fakeAuthenticated(bool $authenticated = true): self
    {
        $this->authenticated = $authenticated;

        return $this;
    }

    /**
     * Seed a JSON-RPC return value so callers can exercise chain reads
     * (eth_getBalance, receipts, ...) without a real node.
     */
    public function stubRpc(string $method, string $value): void
    {
        $this->rpcStubs[$method] = $value;
    }

    /**
     * Seed arbitrary output for circle run commands.
     */
    public function stubRun(string $commandKey, mixed $value): self
    {
        $this->runStubs[$commandKey] = $value;

        return $this;
    }

    public function seedBalance(string $address, int $amountBaseUnits): void
    {
        $this->ledger[strtolower($address)] = $amountBaseUnits;
    }

    public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult
    {
        $from = strtolower($fromAddress);
        $to = strtolower($toAddress);
        $chain = $options['chain'] ?? 'ARC-TESTNET';

        $this->ledger[$from] ??= 0;
        $this->ledger[$to] ??= 0;

        if ($this->ledger[$from] < $amountBaseUnits) {
            throw new \InvalidArgumentException("Insufficient fake balance for [{$fromAddress}].");
        }

        $this->ledger[$from] -= $amountBaseUnits;
        $this->ledger[$to] += $amountBaseUnits;

        $txHash = '0x'.bin2hex(random_bytes(32));
        $this->transactions[] = ['id' => $txHash, 'txHash' => $txHash, 'state' => 'confirmed'];

        return new TransferResult($txHash, $fromAddress, $toAddress, $amountBaseUnits, $chain, true, 'https://testnet.arcscan.app/tx/'.$txHash, ['fake' => true]);
    }

    public function balance(string $address, array $options = []): BalanceResult
    {
        return new BalanceResult($address, $this->ledger[strtolower($address)] ?? 0, $options['chain'] ?? 'ARC-TESTNET', true, ['fake' => true]);
    }

    public function transactions(string $address, array $filters = []): array
    {
        return array_map(fn (array $t): TransactionRecord => new TransactionRecord($t['id'], $t['txHash'], $t['state'], 'transfer', $t), $this->transactions);
    }

    public function limits(string $address, array $options = []): array
    {
        return ['driver' => 'fake', 'perTx' => null, 'daily' => null, 'declaredOnly' => true];
    }

    public function rpcUrl(): string
    {
        return 'fake://arc-testnet';
    }

    public function chainCode(): string
    {
        return $this->chainCode;
    }

    public function chainId(): int
    {
        return $this->chainId;
    }

    public function blockNumber(): string
    {
        return '0x0';
    }

    public function treasuryAddress(): ?string
    {
        return $this->treasuryAddress;
    }

    public function explorerUrl(string $txHash): string
    {
        return 'https://testnet.arcscan.app/tx/'.$txHash;
    }

    public function addressExplorerUrl(string $address): string
    {
        return 'https://testnet.arcscan.app/address/'.$address;
    }

    public function rpc(string $method, array $params = []): mixed
    {
        if (isset($this->rpcStubs[$method])) {
            return $this->rpcStubs[$method];
        }

        return match ($method) {
            'eth_chainId' => '0x'.dechex($this->chainId),
            'eth_blockNumber' => '0x0',
            'eth_getBalance' => '0x0',
            default => null,
        };
    }

    public function authStatus(): array
    {
        $section = fn (): array => $this->authenticated
            ? ['authenticated' => true, 'email' => 'agent@example.test', 'status' => 'VALID', 'expires_in' => '7d 0h 0m']
            : ['authenticated' => false, 'email' => null, 'status' => 'NOT_LOGGED_IN', 'expires_in' => null];

        return [
            'type' => 'agent',
            'mainnet' => $section(),
            'testnet' => $section(),
        ];
    }

    public function beginLogin(string $email): string
    {
        $this->pendingRequestId = 'fake-request-'.substr(hash('sha256', $email), 0, 8);

        return $this->pendingRequestId;
    }

    public function completeLogin(string $requestId, string $otp): array
    {
        if ($this->pendingRequestId === null || $requestId !== $this->pendingRequestId) {
            throw new LeptonRuntimeException('Unknown or already-consumed login request ID.');
        }

        if (! preg_match('/^[A-Z0-9]{3}-\d{6}$/i', $otp)) {
            throw new LeptonRuntimeException('Malformed OTP. Expected the form B1X-123456.');
        }

        $this->pendingRequestId = null;
        $this->authenticated = true;

        return ['email' => 'agent@example.test', 'status' => 'VALID'];
    }

    public function isAuthenticatedFor(string $chainCode = 'ARC-TESTNET'): bool
    {
        return $this->authenticated;
    }

    public function searchServices(string $query): array
    {
        return [];
    }

    public function inspectService(string $url, array $options = []): array
    {
        return ['url' => $url, 'fake' => true];
    }

    public function payService(string $url, string $fromAddress, array $options = []): array
    {
        return ['url' => $url, 'paid' => false, 'fake' => true];
    }

    public function gatewayBalance(string $address, array $options = []): array
    {
        return ['address' => $address, 'balance' => '0', 'fake' => true];
    }

    public function run(array $args, bool $json = true, array $options = []): array|string
    {
        $key = implode(' ', $args);
        if (isset($this->runStubs[$key])) {
            return $this->runStubs[$key];
        }

        $sub = $args[0] ?? 'unknown';

        return match ($sub) {
            'wallet' => match ($args[1] ?? '') {
                'list' => ['wallets' => $this->wallets($options)],
                'create' => $this->createWallet($options),
                'fund' => $this->fundWallet($args[2] ?? ($this->treasuryAddress ?? '0xfake'), $options),
                'status' => $this->authStatus(),
                default => ['command' => $args, 'fake' => true],
            },
            'contract' => match ($args[1] ?? '') {
                'address' => ['contracts' => $this->contractAddress($args[2] ?? null, $options)],
                default => ['command' => $args, 'fake' => true],
            },
            'blockchain' => ['blockchains' => $this->blockchains($options)],
            default => $json ? ['command' => $args, 'fake' => true] : json_encode(['command' => $args, 'fake' => true], JSON_PRETTY_PRINT),
        };
    }

    public function wallets(array $options = []): array
    {
        if ($this->wallets === []) {
            $addr = $this->treasuryAddress ?? '0x'.bin2hex(random_bytes(20));
            $this->wallets[] = [
                'type' => 'agent',
                'address' => $addr,
                'blockchain' => $options['chain'] ?? $this->chainCode,
                'createDate' => date('c'),
            ];
        }

        return $this->wallets;
    }

    public function createWallet(array $options = []): array
    {
        $addr = '0x'.bin2hex(random_bytes(20));
        $wallet = [
            'type' => (string) ($options['type'] ?? 'agent'),
            'address' => $addr,
            'blockchain' => ! empty($options['testnet']) ? 'ARC-TESTNET' : 'ARC',
            'createDate' => date('c'),
        ];
        $this->wallets[] = $wallet;

        return [
            'wallets' => [$wallet],
            'status' => 'created',
            'fake' => true,
        ];
    }

    public function fundWallet(string $address, array $options = []): array
    {
        $addr = strtolower($address);
        // Default testnet faucet gives 20 USDC
        $this->ledger[$addr] = ($this->ledger[$addr] ?? 0) + 20_000000;

        return [
            'address' => $address,
            'amount' => '20',
            'chain' => $options['chain'] ?? $this->chainCode,
            'status' => 'funded',
            'fake' => true,
        ];
    }

    public function signMessage(string $address, string $message, array $options = []): array
    {
        return [
            'address' => $address,
            'message' => $message,
            'signature' => '0x'.bin2hex(random_bytes(65)),
            'fake' => true,
        ];
    }

    public function signTypedData(string $address, string $typedData, array $options = []): array
    {
        return [
            'address' => $address,
            'typedData' => $typedData,
            'signature' => '0x'.bin2hex(random_bytes(65)),
            'fake' => true,
        ];
    }

    public function execute(string $address, string $contract, string $signature, array $params = [], array $options = []): array
    {
        $txHash = '0x'.bin2hex(random_bytes(32));

        return [
            'address' => $address,
            'contract' => $contract,
            'signature' => $signature,
            'txHash' => $txHash,
            'fake' => true,
        ];
    }

    public function gatewayDeposit(string $address, int|string $amount, array $options = []): array
    {
        return [
            'address' => $address,
            'amount' => (string) $amount,
            'status' => 'deposited',
            'fake' => true,
        ];
    }

    public function gatewayWithdraw(string $address, int|string $amount, array $options = []): array
    {
        return [
            'address' => $address,
            'amount' => (string) $amount,
            'status' => 'withdrawn',
            'fake' => true,
        ];
    }

    public function bridgeTransfer(string $fromAddress, string $toChain, int|string $amount, array $options = []): array
    {
        return [
            'from' => $fromAddress,
            'toChain' => $toChain,
            'amount' => (string) $amount,
            'burnTxHash' => '0x'.bin2hex(random_bytes(32)),
            'fake' => true,
        ];
    }

    public function bridgeStatus(string $burnTxHash, array $options = []): array
    {
        return [
            'burnTxHash' => $burnTxHash,
            'status' => 'complete',
            'fake' => true,
        ];
    }

    public function bridgeFee(string $fromChain, string $toChain, array $options = []): array
    {
        return [
            'from' => $fromChain,
            'to' => $toChain,
            'fee' => '0.50',
            'fake' => true,
        ];
    }

    public function contractAddress(?string $name = null, array $options = []): array
    {
        $contracts = [
            ['blockchain' => 'ARC-TESTNET', 'contract' => 'USDC', 'address' => '0x3600000000000000000000000000000000000000'],
            ['blockchain' => 'ARC-TESTNET', 'contract' => 'GatewayWallet', 'address' => '0x0077777d7EBA4688BDeF3E311b846F25870A19B9'],
        ];

        if ($name !== null && $name !== '') {
            $contracts = array_values(array_filter($contracts, fn (array $c): bool => strcasecmp($c['contract'], $name) === 0));
        }

        return $contracts;
    }

    public function contractQuery(string $contract, string $signature, array $params = [], array $options = []): mixed
    {
        return ['contract' => $contract, 'signature' => $signature, 'result' => '0x0', 'fake' => true];
    }

    public function blockchains(array $options = []): array
    {
        return [
            ['blockchain' => 'ARC-TESTNET', 'name' => 'Arc Testnet', 'evmChainId' => 5042002],
            ['blockchain' => 'ARC', 'name' => 'Arc', 'evmChainId' => 5042],
        ];
    }
}

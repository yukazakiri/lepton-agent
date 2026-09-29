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
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

/**
 * In-memory driver for Pest tests and offline demo. No CLI, no chain.
 */
final class FakeLeptonGateway implements ArcNetworkGateway, WalletGateway, X402Gateway, AuthGateway
{
    /** @var array<string,int> */
    private array $ledger = [];

    /** @var array<int, array<string,mixed>> */
    private array $transactions = [];

    /** @var array<string,string> */
    private array $rpcStubs = [];

    private bool $authenticated = false;

    private ?string $pendingRequestId = null;

    public function __construct(
        private readonly ?string $treasuryAddress = null,
        private readonly string $chainCode = 'ARC-TESTNET',
        private readonly int $chainId = 5042002,
    ) {}

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
}

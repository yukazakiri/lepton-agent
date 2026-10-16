<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton;

use InvalidArgumentException;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\CircleGateway;
use Yukazakiri\Lepton\Contracts\TransferEstimator;
use Yukazakiri\Lepton\Contracts\TransferObserver;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Contracts\WebhookVerifier;
use Yukazakiri\Lepton\Contracts\X402Gateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransferResolution;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\Gateways\CircleRestGateway;
use Yukazakiri\Lepton\Support\Amounts;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;
use Yukazakiri\Lepton\Support\UnsupportedOperation;

final class LeptonManager
{
    public function __construct(
        private readonly WalletGateway $wallets,
        private readonly ArcNetworkGateway $arc,
        private readonly X402Gateway $x402,
        private readonly AuthGateway $auth,
        private readonly ?CircleGateway $circle = null,
    ) {}

    public function wallets(): WalletGateway
    {
        return $this->wallets;
    }

    public function auth(): AuthGateway
    {
        return $this->auth;
    }

    public function circle(): CircleGateway
    {
        if ($this->circle !== null) {
            return $this->circle;
        }

        if ($this->wallets instanceof CircleGateway) {
            return $this->wallets;
        }

        return app(CircleGateway::class);
    }

    /**
     * Run any Circle CLI command via the configured runner.
     *
     * @param  array<int,string>  $args
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|string
     */
    public function runCircle(array $args, bool $json = true, array $options = []): array|string
    {
        if (! config('lepton.allow_raw_commands', false) && empty($options['force'])) {
            throw new LeptonRuntimeException('Raw Circle CLI commands are disabled. Set LEPTON_ALLOW_RAW_CIRCLE=true to allow execution.');
        }

        return $this->circle()->run($args, $json, $options);
    }

    /**
     * List wallets on chain.
     *
     * @param  array<string,mixed>  $options
     * @return array<int,array<string,mixed>>
     */
    public function listWallets(array $options = []): array
    {
        return $this->circle()->wallets($options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function createWalletSet(string $name, array $options = []): array
    {
        $gateway = $this->circle();
        if (! $gateway instanceof CircleRestGateway) {
            throw new UnsupportedOperation('Wallet set creation is supported by the developer REST driver only.');
        }

        return $gateway->createWalletSet($name, $options);
    }

    /**
     * Create agent-controlled SCA wallets on supported chains.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function createWallet(array $options = []): array
    {
        return $this->circle()->createWallet($options);
    }

    /**
     * Fund a wallet (faucet on testnet, Transak on mainnet).
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function fundWallet(string $address, array $options = []): array
    {
        return $this->circle()->fundWallet($address, $options);
    }

    /**
     * Per-network session state: mainnet and testnet authenticate independently.
     *
     * @return array{mainnet: array{authenticated: bool, email: ?string, status: ?string, expires_in: ?string}, testnet: array{authenticated: bool, email: ?string, status: ?string, expires_in: ?string}, type: string}
     */
    public function authStatus(): array
    {
        return $this->auth->authStatus();
    }

    /**
     * Whether the configured chain has a usable agent session.
     */
    public function isAuthenticated(?string $chainCode = null): bool
    {
        return $this->auth->isAuthenticatedFor($chainCode ?? (string) config('lepton.arc.chain', 'ARC-TESTNET'));
    }

    public function arc(): ArcNetworkGateway
    {
        return $this->arc;
    }

    public function services(): X402Gateway
    {
        return $this->x402;
    }

    public function webhooks(): WebhookVerifier
    {
        return app(WebhookVerifier::class);
    }

    /** @param array<string, mixed> $options */
    public function transfer(string $from, string $to, int $amountBaseUnits, array $options = []): TransferResult
    {
        return $this->wallets->transfer($from, $to, $amountBaseUnits, $options);
    }

    /** @param array<string, mixed> $options */
    public function resolve(string $handle, array $options = []): TransferResolution
    {
        if (! $this->wallets instanceof TransferObserver) {
            throw new UnsupportedOperation('This wallet driver does not support asynchronous observation.');
        }

        return $this->wallets->resolve($handle, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function estimateTransfer(string $from, string $to, int $amountBaseUnits, array $options = []): array
    {
        if (! $this->wallets instanceof TransferEstimator) {
            throw new UnsupportedOperation('This wallet driver does not support transfer estimation.');
        }

        return $this->wallets->estimateTransfer($from, $to, $amountBaseUnits, $options);
    }

    /**
     * Convenience: transfer decimal string like "450.00" without floats.
     */
    public function transferDecimal(string $from, string $to, string $decimalAmount, array $options = []): TransferResult
    {
        $decimals = (int) config('lepton.usdc_decimals', 6);

        return $this->wallets->transfer($from, $to, Amounts::fromDecimalString($decimalAmount, $decimals), $options);
    }

    public function balance(string $address, array $options = []): BalanceResult
    {
        return $this->wallets->balance($address, $options);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(string $address): array
    {
        if ($address === '') {
            throw new InvalidArgumentException('Lepton status requires a wallet address.');
        }

        $chain = config('lepton.arc.chain', 'ARC-TESTNET');

        return [
            'chain' => $chain,
            'chainId' => $this->arc->chainId(),
            'rpcUrl' => $this->arc->rpcUrl(),
            'block' => $this->arc->blockNumber(),
            'balance' => $this->wallets->balance($address, ['chain' => $chain])->amountBaseUnits,
            'limits' => $this->wallets->limits($address),
            'authenticated' => $this->isAuthenticated($chain),
        ];
    }
}

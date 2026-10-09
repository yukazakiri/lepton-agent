<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Contracts;

interface CircleGateway
{
    /**
     * Run any Circle CLI command with arguments.
     *
     * @param  array<int,string>  $args
     * @param  array<string,mixed>  $options  Supported options: json (bool), env (array)
     * @return array<string,mixed>|string
     */
    public function run(array $args, bool $json = true, array $options = []): array|string;

    /**
     * List wallets on a blockchain.
     *
     * @param  array<string,mixed>  $options  Supported: chain, type
     * @return array<int,array<string,mixed>>
     */
    public function wallets(array $options = []): array;

    /**
     * Create agent-controlled SCA wallets on supported chains.
     *
     * @param  array<string,mixed>  $options  Supported: testnet, type, idempotencyKey
     * @return array<string,mixed>
     */
    public function createWallet(array $options = []): array;

    /**
     * Fund a wallet (faucet on testnet, Transak on mainnet).
     *
     * @param  array<string,mixed>  $options  Supported: chain, token, amount, method, open
     * @return array<string,mixed>
     */
    public function fundWallet(string $address, array $options = []): array;

    /**
     * Sign a message using an agent wallet.
     *
     * @param  array<string,mixed>  $options  Supported: chain
     * @return array<string,mixed>
     */
    public function signMessage(string $address, string $message, array $options = []): array;

    /**
     * Sign EIP-712 typed structured data.
     *
     * @param  array<string,mixed>  $options  Supported: chain
     * @return array<string,mixed>
     */
    public function signTypedData(string $address, string $typedData, array $options = []): array;

    /**
     * Execute a smart contract function (write).
     *
     * @param  array<int,mixed>  $params
     * @param  array<string,mixed>  $options  Supported: chain, amount, estimate, idempotencyKey, rpcUrl
     * @return array<string,mixed>
     */
    public function execute(string $address, string $contract, string $signature, array $params = [], array $options = []): array;

    /**
     * Deposit USDC into Circle Gateway for nanopayments.
     *
     * @param  int|string  $amount  Integer base units (minor units) or decimal string (e.g. "1.50")
     * @param  array<string,mixed>  $options  Supported: chain, method (eco|direct), timeout, noWait
     * @return array<string,mixed>
     */
    public function gatewayDeposit(string $address, int|string $amount, array $options = []): array;

    /**
     * Withdraw USDC from Circle Gateway back to a wallet.
     *
     * @param  int|string  $amount  Integer base units or decimal string
     * @param  array<string,mixed>  $options  Supported: chain
     * @return array<string,mixed>
     */
    public function gatewayWithdraw(string $address, int|string $amount, array $options = []): array;

    /**
     * Bridge USDC to another blockchain via CCTP Forwarding Service.
     *
     * @param  int|string  $amount  Integer base units or decimal string
     * @param  array<string,mixed>  $options  Supported: chain (source), recipient, rpcUrl, idempotencyKey
     * @return array<string,mixed>
     */
    public function bridgeTransfer(string $fromAddress, string $toChain, int|string $amount, array $options = []): array;

    /**
     * Check status of a CCTP bridge transfer.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function bridgeStatus(string $burnTxHash, array $options = []): array;

    /**
     * Show CCTP fee schedule between chains.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function bridgeFee(string $fromChain, string $toChain, array $options = []): array;

    /**
     * Show Circle contract addresses.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function contractAddress(?string $name = null, array $options = []): array;

    /**
     * Query a smart contract function (read-only).
     *
     * @param  array<int,mixed>  $params
     * @param  array<string,mixed>  $options  Supported: chain, rpcUrl
     */
    public function contractQuery(string $contract, string $signature, array $params = [], array $options = []): mixed;

    /**
     * List supported blockchains and public RPC defaults.
     *
     * @param  array<string,mixed>  $options
     * @return array<int,array<string,mixed>>
     */
    public function blockchains(array $options = []): array;
}

<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Contracts;

use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransactionRecord;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

interface WalletGateway
{
    /**
     * Transfer USDC (or native Arc USDC) between wallets.
     *
     * A synchronous rail answers with a non-null `txHash`. An asynchronous one --
     * a Circle agent wallet -- answers with a `handle` and a `state`, and no
     * hash at all. Both are honest; see `TransferResult` for why the two must
     * not be conflated.
     *
     * @param  int  $amountBaseUnits  Integer minor units (e.g. 100_000000 = 100 USDC).
     * @param  array<string,mixed>  $options  Supported: chain, rpcUrl, token, idempotencyKey, estimate
     *
     * @throws LeptonRuntimeException when the rail cannot be reached, or
     *                                answers in a shape that carries no
     *                                usable identifier at all.
     */
    public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult;

    public function balance(string $address, array $options = []): BalanceResult;

    /**
     * @return array<int, TransactionRecord>
     */
    public function transactions(string $address, array $filters = []): array;

    /**
     * Spending policy view. Mainnet-only on Circle side; testnet returns declared-only.
     *
     * @return array<string,mixed>
     */
    public function limits(string $address, array $options = []): array;
}

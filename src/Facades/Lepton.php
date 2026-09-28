<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Facades;

use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\LeptonManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static TransferResult transfer(string $from, string $to, int $amountBaseUnits, array $options = [])
 * @method static TransferResult transferDecimal(string $from, string $to, string $decimalAmount, array $options = [])
 * @method static BalanceResult balance(string $address, array $options = [])
 * @method static array status(string $address)
 *
 * @see LeptonManager
 */
final class Lepton extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LeptonManager::class;
    }
}

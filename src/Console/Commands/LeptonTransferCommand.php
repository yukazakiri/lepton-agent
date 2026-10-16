<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Console\Commands;

use Illuminate\Console\Command;
use Yukazakiri\Lepton\LeptonManager;
use Yukazakiri\Lepton\Support\Amounts;

final class LeptonTransferCommand extends Command
{
    protected $signature = 'lepton:transfer {to : Destination 0x address} {--amount= : Decimal USDC like 450.00} {--from= : Source wallet (defaults to LEPTON_TREASURY_ADDRESS)} {--chain= : Override chain} {--idempotency-key= : Persisted UUID v4 for REST recovery} {--estimate : Fee estimate only}';

    protected $description = 'Transfer USDC via Circle CLI gateway using integer base units internally.';

    public function handle(LeptonManager $lepton): int
    {
        $to = (string) $this->argument('to');
        $amount = (string) ($this->option('amount') ?? '');
        $from = (string) ($this->option('from') ?: config('lepton.arc.treasury', ''));

        if ($from === '' || $to === '' || $amount === '') {
            $this->error('Usage: php artisan lepton:transfer 0xDEST --amount=1.00 --from=0xSRC');

            return self::FAILURE;
        }

        $options = [
            'chain' => $this->option('chain') ?: config('lepton.arc.chain', 'ARC-TESTNET'),
        ];
        if ($this->option('idempotency-key')) {
            $options['idempotencyKey'] = (string) $this->option('idempotency-key');
        }
        try {
            if ($this->option('estimate')) {
                $this->line(json_encode($lepton->estimateTransfer($from, $to, Amounts::fromDecimalString($amount), $options), JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $result = $lepton->transferDecimal($from, $to, $amount, $options);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result->isFake ? 'Simulated transfer; no real funds moved.' : 'Submission returned; not settlement proof.');
        $this->line('Provider ID: '.($result->handle ?? 'none'));
        $this->line('Tx: '.($result->txHash ?? 'not published yet'));
        $this->line("Explorer: {$result->explorerUrl}");

        return self::SUCCESS;
    }
}

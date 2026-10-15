<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Yukazakiri\Lepton\LeptonManager;

final class LeptonWalletFundCommand extends Command
{
    protected $signature = 'lepton:wallet:fund {address? : Wallet address to fund (defaults to LEPTON_TREASURY_ADDRESS)} {--chain= : Blockchain to target (defaults to config)} {--amount= : Amount to fund (required for mainnet, ignored on testnet)} {--token=usdc : Token to fund (usdc, eth, eurc, native)} {--method= : Funding method: fiat or crypto (mainnet)} {--json : Output as raw JSON}';

    protected $aliases = ['lepton:wallet-fund', 'lepton:fund'];

    protected $description = 'Fund a wallet with tokens (faucet on testnet, Transak/QR on mainnet).';

    public function handle(LeptonManager $lepton): int
    {
        $address = (string) ($this->argument('address') ?: config('lepton.arc.treasury', ''));

        if ($address === '') {
            $this->error('No wallet address specified. Pass an address or configure LEPTON_TREASURY_ADDRESS.');

            return self::FAILURE;
        }

        $chain = (string) ($this->option('chain') ?: config('lepton.arc.chain', 'ARC-TESTNET'));

        $options = [
            'chain' => $chain,
            'token' => (string) ($this->option('token') ?? 'usdc'),
        ];

        if ($amount = $this->option('amount')) {
            $options['amount'] = (string) $amount;
        }

        if ($method = $this->option('method')) {
            $options['method'] = (string) $method;
        }

        try {
            $result = $lepton->fundWallet($address, $options);
        } catch (Throwable $e) {
            $this->error('Funding failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("✓ Funding request submitted for {$address}.");
        $this->line("  Chain: <fg=cyan>{$chain}</> | Token: <fg=cyan>{$options['token']}</>");

        if (str_contains(strtoupper($chain), 'TESTNET')) {
            $this->line('  <fg=gray>Faucet drip requested. Testnet transactions may take a few seconds to settle onchain.</>');
            $this->newLine();
            $this->line('  Verify balance with:');
            $this->line("  <fg=cyan>php artisan lepton:status --address={$address}</>");
        } elseif (isset($result['url'])) {
            $this->line("  Payment URL: {$result['url']}");
        }

        return self::SUCCESS;
    }
}

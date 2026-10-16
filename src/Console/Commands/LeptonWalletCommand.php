<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Console\Commands;

use Illuminate\Console\Command;

final class LeptonWalletCommand extends Command
{
    protected $signature = 'lepton:wallet {action=list : Action to perform: list, create, fund} {address? : Wallet address for fund action} {--chain= : Blockchain to target} {--type=agent : Wallet type} {--account-type=EOA : REST account type} {--wallet-set= : REST wallet set ID} {--testnet : Target testnets} {--mainnet : Target mainnet} {--amount= : Amount to fund} {--token=usdc : Token to fund} {--method= : Funding method} {--idempotency-key= : Idempotency key} {--json : Output as raw JSON}';

    protected $description = 'Manage Circle agent wallets (list, create, fund).';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));

        return match ($action) {
            'create', 'new' => $this->call('lepton:wallet:create', [
                '--type' => $this->option('type') ?? 'agent',
                '--account-type' => $this->option('account-type'),
                '--wallet-set' => $this->option('wallet-set'),
                '--testnet' => (bool) $this->option('testnet'),
                '--mainnet' => (bool) $this->option('mainnet'),
                '--idempotency-key' => $this->option('idempotency-key'),
                '--json' => (bool) $this->option('json'),
            ]),
            'fund' => $this->call('lepton:wallet:fund', [
                'address' => $this->argument('address'),
                '--chain' => $this->option('chain'),
                '--amount' => $this->option('amount'),
                '--token' => $this->option('token') ?? 'usdc',
                '--method' => $this->option('method'),
                '--json' => (bool) $this->option('json'),
            ]),
            'list', 'wallets' => $this->call('lepton:wallets', [
                '--chain' => $this->option('chain'),
                '--type' => $this->option('type') ?? 'agent',
                '--json' => (bool) $this->option('json'),
            ]),
            default => $this->invalidAction($action),
        };
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown wallet action [{$action}]. Supported actions: list, create, fund.");
        $this->line('Usage:');
        $this->line('  php artisan lepton:wallet list');
        $this->line('  php artisan lepton:wallet create [--testnet]');
        $this->line('  php artisan lepton:wallet fund 0xAddress');

        return self::FAILURE;
    }
}

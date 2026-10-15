<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Yukazakiri\Lepton\LeptonManager;

final class LeptonWalletListCommand extends Command
{
    protected $signature = 'lepton:wallets {--chain= : Blockchain to list wallets on (defaults to configured chain)} {--type=agent : Filter by wallet type: agent, local, or all} {--json : Output as raw JSON}';

    protected $aliases = ['lepton:wallet:list', 'lepton:wallet-list'];

    protected $description = 'List Circle agent or local wallets on a blockchain.';

    public function handle(LeptonManager $lepton): int
    {
        $chain = (string) ($this->option('chain') ?: config('lepton.arc.chain', 'ARC-TESTNET'));
        $typeOption = (string) ($this->option('type') ?? 'agent');
        $type = $typeOption === 'all' ? null : $typeOption;

        $options = ['chain' => $chain];
        if ($type !== null && $type !== '') {
            $options['type'] = $type;
        }

        try {
            $wallets = $lepton->listWallets($options);
        } catch (Throwable $e) {
            $this->error('Failed to list wallets: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($wallets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($wallets === []) {
            $this->warn("No {$typeOption} wallets found on {$chain}.");
            $this->line('  Create an agent wallet with: <fg=cyan>php artisan lepton:wallet:create</>');

            return self::SUCCESS;
        }

        $treasury = strtolower((string) config('lepton.arc.treasury', ''));

        $rows = array_map(function (array $w) use ($treasury): array {
            $addr = (string) ($w['address'] ?? '');
            $isTreasury = $addr !== '' && strtolower($addr) === $treasury;
            $displayAddr = $isTreasury ? $addr.' <fg=yellow>(treasury)</>' : $addr;

            return [
                $displayAddr,
                $w['blockchain'] ?? $w['chain'] ?? '—',
                $w['type'] ?? 'agent',
                $w['createDate'] ?? $w['created_at'] ?? '—',
            ];
        }, $wallets);

        $this->table(['Address', 'Blockchain', 'Type', 'Created'], $rows);

        return self::SUCCESS;
    }
}

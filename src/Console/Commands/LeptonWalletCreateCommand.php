<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Yukazakiri\Lepton\LeptonManager;

final class LeptonWalletCreateCommand extends Command
{
    protected $signature = 'lepton:wallet:create {--type=agent : Wallet type: agent (default)} {--testnet : Create wallets on supported testnets} {--mainnet : Force mainnet wallet creation} {--idempotency-key= : Idempotency key for request deduplication} {--json : Output as raw JSON}';

    protected $aliases = ['lepton:wallet-create'];

    protected $description = 'Create agent-controlled smart contract account (SCA) wallets.';

    public function handle(LeptonManager $lepton): int
    {
        $isTestnet = (bool) $this->option('testnet');
        if (! $isTestnet && ! (bool) $this->option('mainnet')) {
            $configuredChain = (string) config('lepton.arc.chain', 'ARC-TESTNET');
            $isTestnet = str_contains(strtoupper($configuredChain), 'TESTNET');
        }

        $options = [
            'type' => (string) ($this->option('type') ?? 'agent'),
            'testnet' => $isTestnet,
        ];

        if ($idempotencyKey = $this->option('idempotency-key')) {
            $options['idempotencyKey'] = (string) $idempotencyKey;
        }

        try {
            $result = $lepton->createWallet($options);
        } catch (Throwable $e) {
            $this->error('Failed to create wallet: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('✓ Wallet created successfully.');

        $wallets = $result['wallets'] ?? (isset($result['address']) ? [$result] : []);

        if (is_array($wallets) && $wallets !== []) {
            $rows = array_map(fn (array $w): array => [
                $w['address'] ?? '—',
                $w['blockchain'] ?? ($isTestnet ? 'Testnets' : 'Mainnets'),
                $w['type'] ?? 'agent',
            ], $wallets);

            $this->table(['Address', 'Blockchain', 'Type'], $rows);

            $firstAddress = $wallets[0]['address'] ?? null;
            if ($firstAddress) {
                $this->newLine();
                $this->line('  To use as your treasury wallet:');
                $this->line('  <fg=cyan>LEPTON_TREASURY_ADDRESS='.$firstAddress.'</> in <fg=gray>.env</>');
                if ($isTestnet) {
                    $this->newLine();
                    $this->line('  To fund with testnet tokens from faucet:');
                    $this->line('  <fg=cyan>php artisan lepton:wallet:fund '.$firstAddress.'</>');
                }
            }
        }

        return self::SUCCESS;
    }
}

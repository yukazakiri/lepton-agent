<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton;

use Illuminate\Support\ServiceProvider;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\CircleGateway;
use Yukazakiri\Lepton\Contracts\JsonTransport;
use Yukazakiri\Lepton\Contracts\TransferEstimator;
use Yukazakiri\Lepton\Contracts\TransferObserver;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Contracts\WalletManagementGateway;
use Yukazakiri\Lepton\Contracts\WebhookVerifier;
use Yukazakiri\Lepton\Contracts\X402Gateway;
use Yukazakiri\Lepton\Gateways\ArcCanteenGateway;
use Yukazakiri\Lepton\Gateways\CircleCliGateway;
use Yukazakiri\Lepton\Gateways\CircleRestGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\CircleWebhookVerifier;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\CurlJsonTransport;

final class LeptonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lepton.php', 'lepton');

        $this->app->scoped(JsonTransport::class, fn (): JsonTransport => new CurlJsonTransport(
            (int) config('lepton.developer.timeout', 30),
        ));
        $this->app->scoped(WebhookVerifier::class, fn (): WebhookVerifier => new CircleWebhookVerifier(
            $this->app->make(JsonTransport::class), config('lepton.developer.api_key'),
            (string) config('lepton.webhooks.api_origin', 'https://api.circle.com'),
            (int) config('lepton.webhooks.max_age_seconds', 86400),
        ));

        // Octane-safe: scoped() avoids leaking Process state across requests.
        $this->app->scoped(CliRunner::class.'circle', fn (): CliRunner => new CliRunner(
            (string) config('lepton.circle.bin', 'circle'),
            (int) config('lepton.arc.timeout', 60),
        ));

        $this->app->scoped(CliRunner::class.'arc', fn (): CliRunner => new CliRunner(
            (string) config('lepton.arc.bin', 'arc-canteen'),
            (int) config('lepton.arc.timeout', 60),
        ));

        $this->app->scoped(FakeLeptonGateway::class, fn (): FakeLeptonGateway => new FakeLeptonGateway(
            treasuryAddress: config('lepton.arc.treasury'),
            chainCode: (string) config('lepton.arc.chain', 'ARC-TESTNET'),
            chainId: (int) config('lepton.arc.chain_id', 5042002),
        ));

        $this->app->scoped(CircleRestGateway::class, fn (): CircleRestGateway => new CircleRestGateway(
            apiKey: config('lepton.developer.api_key'),
            entitySecret: config('lepton.developer.entity_secret'),
            walletSetId: config('lepton.developer.wallet_set_id'),
            baseUrl: (string) config('lepton.developer.base_url', CircleRestGateway::DEFAULT_BASE_URL),
            usdcDecimals: (int) config('lepton.usdc_decimals', 6),
            dryRun: (bool) config('lepton.dry_run', false),
            defaultRpcUrl: config('lepton.arc.rpc_url'),
            transport: $this->app->make(JsonTransport::class),
        ));

        $this->app->scoped(ArcNetworkGateway::class, function (): ArcNetworkGateway {
            if ($this->driver() === 'fake') {
                return $this->app->make(FakeLeptonGateway::class);
            }

            return new ArcCanteenGateway(
                arc: null,
                chainCode: (string) config('lepton.arc.chain', 'ARC-TESTNET'),
                chainId: (int) config('lepton.arc.chain_id', 5042002),
                treasuryAddress: config('lepton.arc.treasury'),
                configuredRpcUrl: config('lepton.arc.rpc_url'),
                testnetExplorer: (string) config('lepton.explorer.testnet'),
                mainnetExplorer: (string) config('lepton.explorer.mainnet'),
                transport: $this->app->make(JsonTransport::class),
            );
        });

        $this->app->scoped(WalletGateway::class, function (): WalletGateway {
            if ($this->driver() === 'fake') {
                return $this->app->make(FakeLeptonGateway::class);
            }

            if (in_array($this->driver(), ['developer', 'circle-rest'], true)) {
                return $this->app->make(CircleRestGateway::class);
            }

            return new CircleCliGateway(
                circle: $this->app->make(CliRunner::class.'circle'),
                usdcDecimals: (int) config('lepton.usdc_decimals', 6),
                dryRun: (bool) config('lepton.dry_run', false),
                defaultRpcUrl: config('lepton.arc.rpc_url'),
            );
        });

        $this->app->scoped(X402Gateway::class, function (): X402Gateway {
            if ($this->driver() === 'fake') {
                return $this->app->make(FakeLeptonGateway::class);
            }

            if (in_array($this->driver(), ['developer', 'circle-rest'], true)) {
                return $this->app->make(CircleRestGateway::class);
            }

            // Circle CLI handles wallet + x402 + circle, reuse same runner.
            return $this->app->make(WalletGateway::class) instanceof X402Gateway
                ? $this->app->make(WalletGateway::class)
                : new CircleCliGateway($this->app->make(CliRunner::class.'circle'));
        });

        $this->app->scoped(AuthGateway::class, function (): AuthGateway {
            if ($this->driver() === 'fake') {
                return $this->app->make(FakeLeptonGateway::class);
            }

            if (in_array($this->driver(), ['developer', 'circle-rest'], true)) {
                return $this->app->make(CircleRestGateway::class);
            }

            return $this->app->make(WalletGateway::class) instanceof AuthGateway
                ? $this->app->make(WalletGateway::class)
                : new CircleCliGateway($this->app->make(CliRunner::class.'circle'));
        });

        $this->app->scoped(CircleGateway::class, function (): CircleGateway {
            if ($this->driver() === 'fake') {
                return $this->app->make(FakeLeptonGateway::class);
            }

            if (in_array($this->driver(), ['developer', 'circle-rest'], true)) {
                return $this->app->make(CircleRestGateway::class);
            }

            return $this->app->make(WalletGateway::class) instanceof CircleGateway
                ? $this->app->make(WalletGateway::class)
                : new CircleCliGateway($this->app->make(CliRunner::class.'circle'));
        });

        $this->app->scoped(LeptonManager::class, fn (): LeptonManager => new LeptonManager(
            $this->app->make(WalletGateway::class),
            $this->app->make(ArcNetworkGateway::class),
            $this->app->make(X402Gateway::class),
            $this->app->make(AuthGateway::class),
            $this->app->make(CircleGateway::class),
        ));

        $this->app->alias(LeptonManager::class, 'lepton');

        $this->app->scoped(WalletManagementGateway::class, fn () => $this->app->make(CircleGateway::class));
        $this->app->scoped(TransferObserver::class, fn () => $this->app->make(WalletGateway::class));
        $this->app->scoped(TransferEstimator::class, fn () => $this->app->make(WalletGateway::class));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/lepton.php' => config_path('lepton.php')], 'lepton-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\LeptonStatusCommand::class,
                Console\Commands\LeptonTransferCommand::class,
                Console\Commands\LeptonAuthCommand::class,
                Console\Commands\LeptonCircleCommand::class,
                Console\Commands\LeptonWalletCommand::class,
                Console\Commands\LeptonWalletListCommand::class,
                Console\Commands\LeptonWalletCreateCommand::class,
                Console\Commands\LeptonWalletFundCommand::class,
            ]);
        }
    }

    private function driver(): string
    {
        $driver = (string) config('lepton.default', 'circle');

        if (! in_array($driver, ['circle', 'developer', 'circle-rest', 'fake'], true)) {
            throw new \InvalidArgumentException('Unsupported Lepton driver; refusing to fall back to a live signer.');
        }

        return $driver;
    }
}

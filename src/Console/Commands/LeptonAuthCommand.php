<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Yukazakiri\Lepton\Contracts\AuthGateway;

/**
 * Non-interactive agent wallet login.
 *
 * Circle CLI's interactive flow needs a human at a terminal. This command
 * drives the split flow instead: it sends the OTP, prints the request ID,
 * and completes the login once you supply the code.
 *
 * Sessions last seven days. Mainnet and testnet are authenticated
 * independently, so run this once per environment.
 */
final class LeptonAuthCommand extends Command
{
    protected $signature = 'lepton:login {email? : Email address for authentication} {--request= : Resume a login started earlier by request ID} {--otp= : One-time code from the Circle email, e.g. B1X-123456} {--testnet : Target the testnet session instead of mainnet} {--status : Show current session state and exit}';

    protected $description = 'Authenticate a Circle agent wallet, or show the current session state.';

    public function handle(AuthGateway $auth): int
    {
        if ($this->option('status') || $this->argument('email') === null) {
            return $this->showStatus($auth);
        }

        $email = (string) $this->argument('email');

        try {
            $requestId = $this->option('request') ?: $auth->beginLogin($email);
        } catch (Throwable $e) {
            $this->error('Could not start login: '.$e->getMessage());

            return self::FAILURE;
        }

        $otp = (string) ($this->option('otp') ?? '');

        if ($otp === '') {
            $this->newLine();
            $this->line('  <fg=green>OTP sent.</> Check the inbox for '.$email.'.');
            $this->line('  Request ID: <fg=cyan>'.$requestId.'</>  <fg=gray>(expires in 10 minutes)</>');
            $this->newLine();
            $this->line('  Then finish with:');
            $this->line('  <fg=cyan>php artisan lepton:login --request='.$requestId.' --otp=B1X-123456</>');
            $this->newLine();

            return self::SUCCESS;
        }

        try {
            $result = $auth->completeLogin((string) $requestId, $otp);
        } catch (Throwable $e) {
            $this->error('Login failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Logged in as '.($result['email'] ?? $email));
        $this->line('Agent wallets are provisioned on all supported chains automatically.');

        return $this->showStatus($auth);
    }

    private function showStatus(AuthGateway $auth): int
    {
        try {
            $status = $auth->authStatus();
        } catch (Throwable $e) {
            $this->error('Could not read Circle session: '.$e->getMessage());
            $this->line('  Is the Circle CLI installed?  npm install -g @circle-fin/cli');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  <fg=cyan>Circle agent session</> ('.$status['type'].')');
        $this->line('  '.str_repeat('─', 60));

        foreach (['mainnet', 'testnet'] as $network) {
            $section = $status[$network];

            $this->line(sprintf(
                '  %s  %-9s %-18s %s',
                $section['authenticated'] ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $network,
                $section['email'] ?? 'not signed in',
                $section['authenticated'] ? 'expires in '.$section['expires_in'] : ''
            ));
        }

        $target = $this->option('testnet') ? 'testnet' : 'mainnet';
        $this->newLine();

        if (! $status[$target]['authenticated']) {
            $this->line('  <fg=yellow>No '.$target.' session.</> Transfers on that chain will fail until you log in.');
        }

        return self::SUCCESS;
    }
}

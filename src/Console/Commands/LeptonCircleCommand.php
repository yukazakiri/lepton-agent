<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Console\Commands;

use Illuminate\Console\Command;
use Yukazakiri\Lepton\Contracts\CircleGateway;
use Yukazakiri\Lepton\Support\LeptonCliException;

final class LeptonCircleCommand extends Command
{
    protected $signature = 'lepton:circle {cliArgs* : Arguments to pass directly to Circle CLI}';

    protected $description = 'Execute Circle CLI commands through the Lepton runner.';

    public function handle(CircleGateway $circle): int
    {
        if (! config('lepton.allow_raw_commands', false)) {
            $this->error('Raw Circle CLI commands are disabled. Set LEPTON_ALLOW_RAW_CIRCLE=true to allow execution.');

            return self::FAILURE;
        }

        /** @var array<int,string> $args */
        $args = (array) $this->argument('cliArgs');

        try {
            $output = $circle->run($args, false);
            if (is_array($output)) {
                $output = (string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
            $this->line((string) $output);

            return self::SUCCESS;
        } catch (LeptonCliException $e) {
            $this->error($e->getMessage());

            return $e->exitCode ?: self::FAILURE;
        }
    }
}

<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class LeptonCliException extends RuntimeException
{
    /**
     * @param  array<int,string>  $command
     */
    public function __construct(
        public readonly array $command,
        public readonly int $exitCode,
        public readonly string $stderr,
        string $message = '',
    ) {
        parent::__construct($message ?: 'Lepton CLI failed: '.implode(' ', $command).' — '.$stderr);
    }
}

final class CliRunner
{
    public function __construct(
        private readonly string $binary,
        private readonly int $timeout = 60,
    ) {}

    /**
     * Run binary with argv, never via shell string.
     *
     * @param  array<int,string>  $args
     * @param  array<string,string>  $env  Extra environment for this process only.
     * @return array<string,mixed>|string
     */
    public function run(array $args, bool $expectJson = true, array $env = []): array|string
    {
        $process = new Process(array_merge([$this->binary], $args), null, $env === [] ? null : $env);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new LeptonCliException(
                command: array_merge([$this->binary], $args),
                exitCode: $process->getExitCode() ?? 1,
                stderr: trim($process->getErrorOutput() ?: $process->getOutput()),
            );
        }

        $output = trim($process->getOutput());

        if (! $expectJson) {
            return $output;
        }

        $decoded = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new LeptonCliException(
                command: array_merge([$this->binary], $args),
                exitCode: 0,
                stderr: 'Non-JSON output: '.substr($output, 0, 500),
            );
        }

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /**
     * @param  array<int,string>  $args
     * @param  array<string,string>  $env
     * @return array<string,mixed>|string
     */
    public function runJson(array $args, array $env = []): array|string
    {
        if (! in_array('--output', $args, true)) {
            $args = array_merge($args, ['--output', 'json']);
        }

        return $this->run($args, true, $env);
    }
}

<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Laravel Boost discovers a package's AI resources by convention:
 *
 *   resources/boost/guidelines/core.blade.php   -> loaded upfront by boost:install
 *   resources/boost/skills/{name}/SKILL.md      -> installed on demand
 *
 * If the frontmatter is malformed Boost silently skips the skill, and if a
 * guideline references a class or command that no longer exists an agent will
 * generate code that cannot work. These tests fail instead.
 */
function boostRoot(): string
{
    return dirname(__DIR__, 2).'/resources/boost';
}

it('ships a core guidelines file at the path boost discovers', function (): void {
    expect(boostGuidelinesFile())->toBeFile();
});

it('ships at least one agent skill at the path boost discovers', function (): void {
    $skills = boostSkillFiles();

    expect($skills)->not->toBeEmpty();
});

it('gives every skill the frontmatter boost requires', function (): void {
    foreach (boostSkillFiles() as $file) {
        $content = (string) file_get_contents($file);

        expect($content)->toMatch('/^\s*---\s*\n(.*?)\n---\s*\n/s');

        $frontmatter = Yaml::parse((string) preg_match('/^\s*---\s*\n(.*?)\n---\s*\n/s', $content, $m) ? $m[1] : '');

        // Boost skips a skill silently when either key is missing.
        expect($frontmatter)->toBeArray("{$file} has unparseable frontmatter.");
        expect($frontmatter)->toHaveKeys(['name', 'description'], "{$file} is missing required frontmatter.");
        expect($frontmatter['name'])->toBeString()->not->toBeEmpty();
        expect($frontmatter['description'])->toBeString()->not->toBeEmpty();
    }
});

it('names each skill after its directory so boost can install it', function (): void {
    foreach (boostSkillFiles() as $file) {
        $content = (string) file_get_contents($file);
        preg_match('/^\s*---\s*\n(.*?)\n---\s*\n/s', $content, $m);
        $frontmatter = Yaml::parse($m[1] ?? '') ?? [];

        // Boost derives the package from the parent directory name.
        expect($frontmatter['name'])->toBe(basename(dirname($file)));
    }
});

it('only documents artisan commands the package actually registers', function (): void {
    $sources = '';
    foreach (glob(dirname(__DIR__, 2).'/src/Console/Commands/*.php') ?: [] as $command) {
        $sources .= (string) file_get_contents($command);
    }
    $haystack = (string) preg_replace('/\s+/', ' ', $sources);

    $documented = [];
    foreach (array_merge(boostResourceText(), boostSkillText()) as $text) {
        // Only lepton:* is attributable to this package; built-ins like
        // `artisan list` are Laravel's, and a package cannot add them.
        preg_match_all('/\b(lepton:[a-z-]+)/', $text, $matches);
        $documented = array_merge($documented, $matches[1]);
    }

    expect($documented)->not->toBeEmpty();

    $missing = array_values(array_unique(array_filter(
        $documented,
        fn (string $command): bool => ! str_contains($haystack, $command),
    )));

    expect($missing)->toBe([], 'Boost resources document commands with no matching class: '.implode(', ', $missing));
});

it('only documents classes that exist in the package', function (): void {
    $root = dirname(__DIR__, 2);
    $referenced = [];

    foreach (array_merge(boostResourceText(), boostSkillText()) as $text) {
        preg_match_all('/\bYukazakiri\\\\Lepton\\\\[A-Za-z0-9_\\\\]+/', $text, $matches);
        $referenced = array_merge($referenced, $matches[0]);
    }

    $missing = [];

    foreach (array_unique($referenced) as $reference) {
        $class = str_replace('\\\\', '\\', $reference);
        $relative = substr($class, strlen('Yukazakiri\\Lepton\\'));
        $path = $root.'/src/'.str_replace('\\', '/', $relative).'.php';

        if (! file_exists($path)) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([], 'Boost resources reference classes with no source file: '.implode(', ', $missing));
});

it('only documents methods that exist on the contracts it names', function (): void {
    $text = implode("\n", array_merge(boostResourceText(), boostSkillText()));
    $root = dirname(__DIR__, 2);

    $contracts = [
        'WalletGateway' => ['path' => 'Contracts/WalletGateway.php', 'methods' => ['transfer', 'balance', 'transactions', 'limits']],
        'ArcNetworkGateway' => ['path' => 'Contracts/ArcNetworkGateway.php', 'methods' => ['rpc', 'blockNumber', 'chainId', 'rpcUrl', 'treasuryAddress', 'explorerUrl', 'addressExplorerUrl']],
        'AuthGateway' => ['path' => 'Contracts/AuthGateway.php', 'methods' => ['authStatus', 'beginLogin', 'completeLogin', 'isAuthenticatedFor']],
        'X402Gateway' => ['path' => 'Contracts/X402Gateway.php', 'methods' => ['searchServices', 'inspectService', 'payService', 'gatewayBalance']],
        'Amounts' => ['path' => 'Support/Amounts.php', 'methods' => ['fromDecimalString', 'toDecimalString', 'toCliAmount', 'fromHexQuantity']],
    ];

    $missing = [];

    foreach ($contracts as $class => $spec) {
        $source = (string) file_get_contents($root.'/src/'.$spec['path']);

        foreach ($spec['methods'] as $method) {
            if (! str_contains($source, $method.'(')) {
                $missing[] = "{$class}::{$method}";
            }
        }
    }

    expect($missing)->toBe([], 'Boost resources advertise methods that do not exist: '.implode(', ', $missing));

    // And they must actually mention the contracts they claim to describe.
    foreach (['WalletGateway', 'ArcNetworkGateway', 'AuthGateway', 'X402Gateway', 'Amounts'] as $class) {
        expect($text)->toContain($class);
    }
});

it('keeps the traps the package actually hit in the guidelines', function (): void {
    $text = implode("\n", boostResourceText());

    // Each of these is a real failure this package or its users encountered.
    expect($text)->toContain('hexdec')          // 18-decimal overflow
        ->and($text)->toContain('not proof')     // hash is a claim
        ->and($text)->toContain('40 hex')        // destination validation
        ->and($text)->toContain('not allowed by the proxy')
        ->and($text)->toContain('independently')  // mainnet vs testnet
        ->and($text)->toContain('agent')          // agent vs local wallet
        ->and($text)->toContain('base units');    // no floats
});

it('never tells an agent a fake-driver run settles real funds', function (): void {
    $text = implode("\n", array_merge(boostResourceText(), boostSkillText()));

    expect($text)->toContain('LEPTON_DRIVER=fake')
        ->and($text)->toMatch('/no network, no credentials and no cost/i');
});

/**
 * @return list<string>
 */
function boostSkillFiles(): array
{
    $files = glob(boostRoot().'/skills/*/SKILL.md') ?: [];

    sort($files);

    return array_values($files);
}

function boostGuidelinesFile(): string
{
    foreach (['.blade.php', '.md'] as $extension) {
        $path = boostRoot().'/guidelines/core'.$extension;

        if (file_exists($path)) {
            return $path;
        }
    }

    return boostRoot().'/guidelines/core.blade.php';
}

/** @return list<string> */
function boostResourceText(): array
{
    return [(string) file_get_contents(boostGuidelinesFile())];
}

/** @return list<string> */
function boostSkillText(): array
{
    return array_map(
        fn (string $file): string => (string) file_get_contents($file),
        boostSkillFiles(),
    );
}

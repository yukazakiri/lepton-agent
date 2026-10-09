<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Yukazakiri\Lepton\LeptonServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LeptonServiceProvider::class,
        ];
    }
}

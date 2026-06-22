<?php

declare(strict_types=1);

namespace Boquizo\Hew\Tests;

use Boquizo\Hew\HewServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [HewServiceProvider::class];
    }
}

<?php

namespace Rizer\PlugNotas\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rizer\PlugNotas\PlugNotasServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PlugNotasServiceProvider::class];
    }
}

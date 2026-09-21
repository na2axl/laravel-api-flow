<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow\Tests;

use Na2axl\LaravelApiFlow\LaravelApiFlowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelApiFlowServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }
}

<?php

namespace Pikant\LaravelEasyHttpFake\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Pikant\LaravelEasyHttpFake\LaravelEasyHttpFakeServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LaravelEasyHttpFakeServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        // Point base_path() to the package root so that ReplayStorage
        // resolves paths correctly (Testbench defaults to its own skeleton).
        $app->setBasePath(dirname(__DIR__));
    }
}

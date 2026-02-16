<?php

namespace EYOND\LaravelHttpReplay\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use EYOND\LaravelHttpReplay\LaravelHttpReplayServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LaravelHttpReplayServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        // Point base_path() to the package root so that ReplayStorage
        // resolves paths correctly (Testbench defaults to its own skeleton).
        $app->setBasePath(dirname(__DIR__));
    }
}

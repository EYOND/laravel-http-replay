<?php

namespace EYOND\LaravelHttpReplay\Tests;

use EYOND\LaravelHttpReplay\LaravelHttpReplayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

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

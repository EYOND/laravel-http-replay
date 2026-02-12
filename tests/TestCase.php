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
}

<?php

namespace Pikant\LaravelEasyHttpFake;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Pikant\LaravelEasyHttpFake\Commands\LaravelEasyHttpFakeCommand;

class LaravelEasyHttpFakeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-easy-http-fake')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_easy_http_fake_table')
            ->hasCommand(LaravelEasyHttpFakeCommand::class);
    }
}

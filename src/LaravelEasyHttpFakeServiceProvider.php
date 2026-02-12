<?php

namespace Pikant\LaravelEasyHttpFake;

use Illuminate\Http\Client\Factory;
use Pikant\LaravelEasyHttpFake\Commands\ReplayFreshCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelEasyHttpFakeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-easy-http-fake')
            ->hasConfigFile()
            ->hasCommand(ReplayFreshCommand::class);
    }

    public function packageBooted(): void
    {
        $this->registerHttpMacro();
    }

    protected function registerHttpMacro(): void
    {
        Factory::macro('replay', function (): ReplayBuilder {
            return new ReplayBuilder;
        });
    }
}

<?php

namespace EYOND\LaravelHttpReplay;

use Illuminate\Http\Client\Factory;
use EYOND\LaravelHttpReplay\Commands\ReplayPruneCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelHttpReplayServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-http-replay')
            ->hasConfigFile()
            ->hasCommand(ReplayPruneCommand::class);
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

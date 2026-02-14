<?php

namespace Pikant\LaravelHttpReplay\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Pikant\LaravelHttpReplay\LaravelHttpReplay
 */
class Replay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Pikant\LaravelHttpReplay\LaravelHttpReplay::class;
    }
}

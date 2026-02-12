<?php

namespace Pikant\LaravelEasyHttpFake\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Pikant\LaravelEasyHttpFake\LaravelEasyHttpFake
 */
class LaravelEasyHttpFake extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Pikant\LaravelEasyHttpFake\LaravelEasyHttpFake::class;
    }
}

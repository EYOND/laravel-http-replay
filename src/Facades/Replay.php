<?php

namespace EYOND\LaravelHttpReplay\Facades;

use EYOND\LaravelHttpReplay\LaravelHttpReplay;
use Illuminate\Support\Facades\Facade;

/**
 * @see LaravelHttpReplay
 */
class Replay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LaravelHttpReplay::class;
    }
}

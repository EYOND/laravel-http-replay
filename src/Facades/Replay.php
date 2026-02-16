<?php

namespace EYOND\LaravelHttpReplay\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \EYOND\LaravelHttpReplay\LaravelHttpReplay
 */
class Replay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \EYOND\LaravelHttpReplay\LaravelHttpReplay::class;
    }
}

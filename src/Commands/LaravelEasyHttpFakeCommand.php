<?php

namespace Pikant\LaravelEasyHttpFake\Commands;

use Illuminate\Console\Command;

class LaravelEasyHttpFakeCommand extends Command
{
    public $signature = 'laravel-easy-http-fake';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}

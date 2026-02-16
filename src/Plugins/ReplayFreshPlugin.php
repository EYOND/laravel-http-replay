<?php

namespace EYOND\LaravelHttpReplay\Plugins;

use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Plugins\Concerns\HandleArguments;

final class ReplayFreshPlugin implements HandlesArguments
{
    use HandleArguments;

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    public function handleArguments(array $arguments): array
    {
        if (! $this->hasArgument('--replay-fresh', $arguments)) {
            return $arguments;
        }

        $arguments = $this->popArgument('--replay-fresh', $arguments);

        $_SERVER['REPLAY_FRESH'] = 'true';
        $_ENV['REPLAY_FRESH'] = 'true';

        return $arguments;
    }
}

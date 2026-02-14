<?php

namespace Pikant\LaravelHttpReplay\Plugins;

use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Plugins\Concerns\HandleArguments;

final class ReplayBailPlugin implements HandlesArguments
{
    use HandleArguments;

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    public function handleArguments(array $arguments): array
    {
        if (! $this->hasArgument('--replay-bail', $arguments)) {
            return $arguments;
        }

        $arguments = $this->popArgument('--replay-bail', $arguments);

        $_SERVER['REPLAY_BAIL'] = 'true';
        $_ENV['REPLAY_BAIL'] = 'true';

        return $arguments;
    }
}

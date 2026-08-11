<?php

namespace EYOND\LaravelHttpReplay\Exceptions;

use RuntimeException;

class ReusableResponseMismatchException extends RuntimeException
{
    public static function forReplayKey(string $replayKey): self
    {
        return new self(
            "Http Replay received different responses for reusable replay key [{$replayKey}]. "
            .'Use sequential() for stateful responses or configure distinct matchers.'
        );
    }
}

<?php

namespace Pikant\LaravelHttpReplay\Matchers;

use Closure;
use Illuminate\Http\Client\Request;

class ClosureMatcher implements NameMatcher
{
    public function __construct(
        protected Closure $callback,
    ) {}

    /**
     * Resolve by calling the closure. The closure should return an array of filename parts.
     * We join non-empty parts with '_'.
     */
    public function resolve(Request $request): ?string
    {
        /** @var array<int, string> $parts */
        $parts = ($this->callback)($request);

        $filtered = array_filter($parts, fn ($part) => $part !== '');

        if ($filtered === []) {
            return null;
        }

        return implode('_', $filtered);
    }
}

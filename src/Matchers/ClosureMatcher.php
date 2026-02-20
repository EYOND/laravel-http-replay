<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;

class ClosureMatcher implements NameMatcher
{
    public function __construct(
        protected Closure $callback,
    ) {}

    /**
     * Resolve by calling the closure.
     *
     * The closure may return a string, int, array of strings, or Collection.
     * Multiple parts are joined with '_', empty parts are filtered out.
     */
    public function resolve(Request $request): ?string
    {
        $result = ($this->callback)($request);

        return Collection::wrap($result)
            ->flatten()
            ->map(fn (mixed $part): string => (string) $part)
            ->reject(fn (string $part): bool => $part === '')
            ->implode('_') ?: null;
    }
}

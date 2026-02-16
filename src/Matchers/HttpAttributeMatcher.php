<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;

class HttpAttributeMatcher implements NameMatcher
{
    public function __construct(
        protected string $key,
    ) {}

    public function resolve(Request $request): ?string
    {
        $value = Arr::get($request->attributes(), $this->key);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}

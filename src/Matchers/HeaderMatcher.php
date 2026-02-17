<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;

class HeaderMatcher implements NameMatcher
{
    public function __construct(
        protected string $key,
    ) {}

    public function resolve(Request $request): ?string
    {
        $headers = $request->header($this->key);

        $value = $headers[0] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}

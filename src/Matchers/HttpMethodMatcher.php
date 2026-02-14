<?php

namespace Pikant\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;

class HttpMethodMatcher implements NameMatcher
{
    public function resolve(Request $request): ?string
    {
        return strtoupper($request->method());
    }
}

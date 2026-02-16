<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;

class HostMatcher implements NameMatcher
{
    public function resolve(Request $request): ?string
    {
        $parsed = parse_url($request->url());

        return $parsed['host'] ?? null;
    }
}

<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;

class PathMatcher implements NameMatcher
{
    public function resolve(Request $request): ?string
    {
        $parsed = parse_url($request->url());
        $path = trim($parsed['path'] ?? '', '/');

        return $path !== '' ? $path : null;
    }
}

<?php

namespace Pikant\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;

class UrlMatcher implements NameMatcher
{
    public function resolve(Request $request): ?string
    {
        $parsed = parse_url($request->url());
        $host = $parsed['host'] ?? 'unknown';
        $path = trim($parsed['path'] ?? '', '/');

        return $host.($path !== '' ? '_'.$path : '');
    }
}

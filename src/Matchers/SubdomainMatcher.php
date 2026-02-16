<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;

class SubdomainMatcher implements NameMatcher
{
    public function resolve(Request $request): ?string
    {
        $parsed = parse_url($request->url());
        $host = $parsed['host'] ?? null;

        if ($host === null) {
            return null;
        }

        $parts = explode('.', $host);

        // Need at least 3 parts for a subdomain (e.g. shop.example.com)
        if (count($parts) < 3) {
            return null;
        }

        return $parts[0];
    }
}

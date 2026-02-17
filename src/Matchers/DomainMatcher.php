<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;

class DomainMatcher implements NameMatcher
{
    public function resolve(Request $request): ?string
    {
        $parsed = parse_url($request->url());
        $host = $parsed['host'] ?? null;

        if ($host === null) {
            return null;
        }

        $parts = explode('.', $host);

        // Strip subdomain if present (3+ parts, e.g. shop.myshopify.com → myshopify.com)
        if (count($parts) > 2) {
            return implode('.', array_slice($parts, 1));
        }

        return $host;
    }
}

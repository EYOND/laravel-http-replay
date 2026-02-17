<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;

class QueryParamMatcher implements NameMatcher
{
    public function __construct(
        protected string $key,
    ) {}

    public function resolve(Request $request): ?string
    {
        $parsed = parse_url($request->url());
        $queryString = $parsed['query'] ?? '';
        parse_str($queryString, $params);

        $value = Arr::get($params, $this->key);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}

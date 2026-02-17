<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;

class QueryHashMatcher implements NameMatcher
{
    /** @var list<string> */
    protected array $keys;

    /**
     * @param  list<string>  $keys  Specific query keys to hash. Empty = all params.
     */
    public function __construct(array $keys = [])
    {
        $this->keys = $keys;
    }

    public function resolve(Request $request): ?string
    {
        $parsed = parse_url($request->url());
        $queryString = $parsed['query'] ?? '';

        if ($queryString === '') {
            return null;
        }

        parse_str($queryString, $params);

        if ($this->keys !== []) {
            $subset = [];
            foreach ($this->keys as $key) {
                $subset[$key] = Arr::get($params, $key);
            }
            $params = $subset;
        }

        return substr(md5(json_encode($params) ?: ''), 0, 6);
    }
}

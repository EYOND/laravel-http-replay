<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;

class BodyFieldMatcher implements NameMatcher
{
    public function __construct(
        protected string $path,
    ) {}

    public function resolve(Request $request): ?string
    {
        $data = json_decode($request->body(), true);

        if (! is_array($data)) {
            return null;
        }

        $value = Arr::get($data, $this->path);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}

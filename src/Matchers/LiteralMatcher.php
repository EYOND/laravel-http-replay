<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;

final class LiteralMatcher implements NameMatcher
{
    public function __construct(
        protected string $value,
    ) {}

    public function resolve(Request $request): string
    {
        return $this->value;
    }
}

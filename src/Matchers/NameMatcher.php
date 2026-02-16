<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;

interface NameMatcher
{
    public function resolve(Request $request): ?string;
}

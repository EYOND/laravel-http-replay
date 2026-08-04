<?php

namespace EYOND\LaravelHttpReplay;

use EYOND\LaravelHttpReplay\Matchers\CanonicalBodyHashMatcher;
use EYOND\LaravelHttpReplay\Matchers\GraphqlOperationMatcher;
use EYOND\LaravelHttpReplay\Matchers\LiteralMatcher;

final class Matchers
{
    public static function literal(string $value): LiteralMatcher
    {
        return new LiteralMatcher($value);
    }

    public static function graphqlOperation(): GraphqlOperationMatcher
    {
        return new GraphqlOperationMatcher;
    }

    public static function canonicalBodyHash(string ...$paths): CanonicalBodyHashMatcher
    {
        return new CanonicalBodyHashMatcher($paths);
    }
}

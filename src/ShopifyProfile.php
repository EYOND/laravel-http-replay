<?php

namespace EYOND\LaravelHttpReplay;

use EYOND\LaravelHttpReplay\Matchers\NameMatcher;

enum ShopifyProfile
{
    case Semantic;
    case Strict;

    public const URL_PATTERN = '*.myshopify.com/admin/api/*/graphql.json';

    /**
     * @return list<NameMatcher>
     */
    public function matchers(): array
    {
        return [
            Matchers::literal('shopify'),
            Matchers::literal('graphql'),
            Matchers::graphqlOperation(),
            $this === self::Strict
                ? Matchers::canonicalBodyHash()
                : Matchers::canonicalBodyHash('variables'),
        ];
    }
}

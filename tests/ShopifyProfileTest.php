<?php

use EYOND\LaravelHttpReplay\Facades\Replay;
use EYOND\LaravelHttpReplay\Matchers;
use EYOND\LaravelHttpReplay\Matchers\CanonicalBodyHashMatcher;
use EYOND\LaravelHttpReplay\Matchers\GraphqlOperationMatcher;
use EYOND\LaravelHttpReplay\Matchers\LiteralMatcher;
use EYOND\LaravelHttpReplay\ReplayBuilder;
use EYOND\LaravelHttpReplay\ReplayNamer;
use EYOND\LaravelHttpReplay\ReplayStorage;
use EYOND\LaravelHttpReplay\ShopifyProfile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    $this->namer = new ReplayNamer;
});

it('exposes public matcher factories and accepts matcher objects', function () {
    $matchers = [
        Matchers::literal('shopify'),
        Matchers::graphqlOperation(),
        Matchers::canonicalBodyHash('variables'),
    ];

    expect($matchers[0])->toBeInstanceOf(LiteralMatcher::class)
        ->and($matchers[1])->toBeInstanceOf(GraphqlOperationMatcher::class)
        ->and($matchers[2])->toBeInstanceOf(CanonicalBodyHashMatcher::class)
        ->and(Replay::configure()->matchBy(...$matchers))->toBe(Replay::getConfig())
        ->and((new ReplayBuilder(new ReplayStorage(sys_get_temp_dir().'/matcher-objects-'.uniqid())))->matchBy(...$matchers))
        ->toBeInstanceOf(ReplayBuilder::class);
});

it('supports the new string shorthands', function () {
    Http::withBody('{"query":"query GetProducts { products { id } }","variables":{"b":2,"a":1}}', 'application/json')
        ->post('https://store.myshopify.com/admin/api/2026-07/graphql.json');

    $name = $this->namer->fromRequest(Http::recorded()[0][0], [
        'literal:shopify',
        'literal:graphql',
        'graphql_operation',
        'canonical_body_hash:variables',
    ]);

    expect($name)->toBe('shopify_graphql_GetProducts_744ad5.json');
});

it('configures the semantic Shopify profile by default', function () {
    Replay::configure()->shopify();

    Http::withBody('{"operationName":"GetProducts","variables":{"b":2,"a":1}}', 'application/json')
        ->post('https://store.myshopify.com/admin/api/2026-07/graphql.json');
    $request = Http::recorded()[0][0];

    $builder = new ReplayBuilder(new ReplayStorage(sys_get_temp_dir().'/shopify-default-'.uniqid()));
    $resolveMatchBy = (new ReflectionClass($builder))->getMethod('resolveMatchBy');

    $configured = Replay::getConfig()->getPerPatternMatchBy();

    expect($configured)->toHaveKey(ShopifyProfile::URL_PATTERN)
        ->and($configured[ShopifyProfile::URL_PATTERN])->toHaveCount(4)
        ->and($this->namer->fromRequest($request, $resolveMatchBy->invoke($builder, $request)))
        ->toBe('shopify_graphql_GetProducts_744ad5.json');
});

it('creates a stable semantic filename from operation and canonical variables', function () {
    Http::withBody('{"operationName":"GetProducts","query":"query GetProducts { products { id } }","variables":{"b":2,"a":1}}', 'application/json')
        ->post('https://first-shop.myshopify.com/admin/api/2026-07/graphql.json');
    Http::withBody('{"variables":{"a":1,"b":2},"query":"query GetProducts { products { title } }","operationName":"GetProducts"}', 'application/json')
        ->post('https://other-shop.myshopify.com/admin/api/2025-01/graphql.json');

    $first = $this->namer->fromRequest(Http::recorded()[0][0], ShopifyProfile::Semantic->matchers());
    $second = $this->namer->fromRequest(Http::recorded()[1][0], ShopifyProfile::Semantic->matchers());

    expect($first)->toBe('shopify_graphql_GetProducts_744ad5.json')
        ->and($second)->toBe($first);
});

it('makes the strict profile sensitive to documents and variables but not key order', function () {
    $bodies = [
        '{"operationName":"GetProducts","query":"query GetProducts { products { id } }","variables":{"b":2,"a":1}}',
        '{"variables":{"a":1,"b":2},"query":"query GetProducts { products { id } }","operationName":"GetProducts"}',
        '{"operationName":"GetProducts","query":"query GetProducts { products { title } }","variables":{"a":1,"b":2}}',
        '{"operationName":"GetProducts","query":"query GetProducts { products { id } }","variables":{"a":1,"b":3}}',
    ];

    foreach ($bodies as $body) {
        Http::withBody($body, 'application/json')
            ->post('https://shop.myshopify.com/admin/api/2026-07/graphql.json');
    }

    $names = Http::recorded()->map(
        fn (array $recorded): string => $this->namer->fromRequest($recorded[0], ShopifyProfile::Strict->matchers()),
    )->all();

    expect($names[0])->toBe('shopify_graphql_GetProducts_10bfce.json')
        ->and($names[1])->toBe($names[0])
        ->and($names[2])->not->toBe($names[0])
        ->and($names[3])->not->toBe($names[0]);
});

it('allows a per-test override of the Shopify recipe', function () {
    Replay::configure()->shopify(profile: ShopifyProfile::Strict);

    $builder = new ReplayBuilder(new ReplayStorage(sys_get_temp_dir().'/shopify-override-'.uniqid()));
    $builder->for(ShopifyProfile::URL_PATTERN)->matchBy('method', 'url');

    $property = (new ReflectionClass($builder))->getProperty('perPatternMatchBy');

    expect($property->getValue($builder)[ShopifyProfile::URL_PATTERN])->toBe(['method', 'url']);
});

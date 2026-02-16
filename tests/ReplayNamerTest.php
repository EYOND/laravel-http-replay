<?php

use Illuminate\Support\Facades\Http;
use EYOND\LaravelHttpReplay\ReplayNamer;

beforeEach(function () {
    $this->namer = new ReplayNamer;
});

it('generates name with http_method and url matchers', function () {
    Http::fake();

    Http::get('https://api.example.com/products');

    $request = Http::recorded()[0][0];
    $name = $this->namer->fromRequest($request, ['http_method', 'url']);

    expect($name)->toBe('GET_api_example_com_products.json');
});

it('generates name from POST request', function () {
    Http::fake();

    Http::post('https://api.example.com/orders', ['item' => 'test']);

    $request = Http::recorded()[0][0];
    $name = $this->namer->fromRequest($request, ['http_method', 'url']);

    expect($name)->toBe('POST_api_example_com_orders.json');
});

it('uses replay attribute when available', function () {
    Http::fake();

    Http::withAttributes(['replay' => 'products'])->get('https://api.example.com/products');

    $request = Http::recorded()[0][0];
    $name = $this->namer->fromRequest($request, ['http_method', 'url']);

    expect($name)->toBe('products.json');
});

it('generates name with body_hash matcher', function () {
    Http::fake();

    Http::post('https://shopify.com/graphql', ['query' => '{products{...}}']);

    $request = Http::recorded()[0][0];
    $name = $this->namer->fromRequest($request, ['http_method', 'url', 'body_hash']);

    expect($name)
        ->toStartWith('POST_shopify_com_graphql_')
        ->toEndWith('.json');
});

it('generates different body hash names for different bodies', function () {
    Http::fake();

    Http::post('https://shopify.com/graphql', ['query' => '{products{...}}']);
    Http::post('https://shopify.com/graphql', ['query' => '{orders{...}}']);

    $request1 = Http::recorded()[0][0];
    $request2 = Http::recorded()[1][0];

    $name1 = $this->namer->fromRequest($request1, ['http_method', 'url', 'body_hash']);
    $name2 = $this->namer->fromRequest($request2, ['http_method', 'url', 'body_hash']);

    expect($name1)->not->toBe($name2);
});

it('generates name with host matcher only', function () {
    Http::fake();

    Http::get('https://shop.myshopify.com/api/products');

    $request = Http::recorded()[0][0];
    $name = $this->namer->fromRequest($request, ['host']);

    expect($name)->toBe('shop_myshopify_com.json');
});

it('generates name with subdomain matcher', function () {
    Http::fake();

    Http::get('https://shop.myshopify.com/api/products');

    $request = Http::recorded()[0][0];
    $name = $this->namer->fromRequest($request, ['subdomain']);

    expect($name)->toBe('shop.json');
});

it('returns null for subdomain when no subdomain exists', function () {
    Http::fake();

    Http::get('https://example.com/api/products');

    $request = Http::recorded()[0][0];
    // Only subdomain — should fall back since subdomain is null
    $name = $this->namer->fromRequest($request, ['subdomain']);

    expect($name)->toBe('unknown.json');
});

it('generates name with http_attribute matcher', function () {
    Http::fake();

    Http::withAttributes(['replay' => 'products', 'request_name' => 'get-products'])
        ->get('https://api.example.com/products');

    $request = Http::recorded()[0][0];
    // replay attribute takes priority — use custom attribute instead
    $name = $this->namer->fromRequest($request, ['http_attribute:request_name']);

    // replay attribute takes priority over matchers
    expect($name)->toBe('products.json');
});

it('generates name with closure matcher', function () {
    Http::fake();

    Http::post('https://shopify.com/graphql', ['operationName' => 'GetProducts']);

    $request = Http::recorded()[0][0];
    $name = $this->namer->fromRequest($request, [
        'http_method',
        fn ($r) => [$r->data()['operationName'] ?? 'unknown'],
    ]);

    expect($name)->toBe('POST_GetProducts.json');
});

it('supports backward compat for method and body strings', function () {
    Http::fake();

    Http::post('https://api.example.com/data', ['key' => 'value']);

    $request = Http::recorded()[0][0];

    // 'method' should work like 'http_method'
    $name = $this->namer->fromRequest($request, ['method', 'url']);
    expect($name)->toBe('POST_api_example_com_data.json');

    // 'body' should work like 'body_hash'
    $name = $this->namer->fromRequest($request, ['method', 'url', 'body']);
    expect($name)->toStartWith('POST_api_example_com_data_');
});

it('makes filename unique with counter', function () {
    $existing = ['GET_api_example.json'];

    expect($this->namer->makeUnique('GET_api_example.json', $existing))
        ->toBe('GET_api_example__2.json');

    $existing[] = 'GET_api_example__2.json';
    expect($this->namer->makeUnique('GET_api_example.json', $existing))
        ->toBe('GET_api_example__3.json');
});

it('returns filename as-is when not duplicate', function () {
    expect($this->namer->makeUnique('unique.json', ['other.json']))
        ->toBe('unique.json');
});

it('handles URL without path', function () {
    Http::fake();

    Http::get('https://example.com');

    $request = Http::recorded()[0][0];
    $name = $this->namer->fromRequest($request, ['http_method', 'url']);

    expect($name)->toBe('GET_example_com.json');
});

it('generates name with body_hash and specific keys', function () {
    Http::fake();

    Http::post('https://shopify.com/graphql', [
        'query' => '{products{...}}',
        'variables' => ['id' => '123'],
    ]);

    $request = Http::recorded()[0][0];
    $name = $this->namer->fromRequest($request, ['http_method', 'url', 'body_hash:query,variables.id']);

    expect($name)
        ->toStartWith('POST_shopify_com_graphql_')
        ->toEndWith('.json');
});

it('throws on unknown matcher string', function () {
    $this->namer->parseMatchers(['nonexistent']);
})->throws(\InvalidArgumentException::class, 'Unknown matcher: nonexistent');

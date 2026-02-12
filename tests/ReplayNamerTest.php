<?php

use Illuminate\Support\Facades\Http;
use Pikant\LaravelEasyHttpFake\ReplayNamer;

beforeEach(function () {
    $this->namer = new ReplayNamer;
});

it('generates default name from GET request', function () {
    Http::fake();

    Http::get('https://api.example.com/products');

    $request = Http::recorded()[0][0];
    $name = $this->namer->defaultName($request);

    expect($name)->toBe('GET_api_example_com_products.json');
});

it('generates default name from POST request', function () {
    Http::fake();

    Http::post('https://api.example.com/orders', ['item' => 'test']);

    $request = Http::recorded()[0][0];
    $name = $this->namer->defaultName($request);

    expect($name)->toBe('POST_api_example_com_orders.json');
});

it('generates name from attribute', function () {
    expect($this->namer->fromAttribute('products'))->toBe('products.json');
    expect($this->namer->fromAttribute('my-api-call'))->toBe('my-api-call.json');
});

it('generates body hash name', function () {
    Http::fake();

    Http::post('https://shopify.com/graphql', ['query' => '{products{...}}']);

    $request = Http::recorded()[0][0];

    $namer = new ReplayNamer(['url', 'method', 'body']);
    $name = $namer->fromBodyHash($request);

    expect($name)
        ->toStartWith('POST_shopify_com_graphql__')
        ->toEndWith('.json')
        ->toContain('__');
});

it('generates different body hash names for different bodies', function () {
    Http::fake();

    Http::post('https://shopify.com/graphql', ['query' => '{products{...}}']);
    Http::post('https://shopify.com/graphql', ['query' => '{orders{...}}']);

    $request1 = Http::recorded()[0][0];
    $request2 = Http::recorded()[1][0];

    $namer = new ReplayNamer(['url', 'method', 'body']);

    expect($namer->fromBodyHash($request1))
        ->not->toBe($namer->fromBodyHash($request2));
});

it('uses attribute when available via fromRequest', function () {
    Http::fake();

    Http::withAttributes(['replay' => 'products'])->get('https://api.example.com/products');

    $request = Http::recorded()[0][0];
    $name = $this->namer->fromRequest($request);

    expect($name)->toBe('products.json');
});

it('uses body hash when matchBy includes body', function () {
    Http::fake();

    Http::post('https://shopify.com/graphql', ['query' => '{products{...}}']);

    $request = Http::recorded()[0][0];

    $namer = new ReplayNamer(['url', 'method', 'body']);
    $name = $namer->fromRequest($request);

    expect($name)->toStartWith('POST_shopify_com_graphql__');
});

it('falls back to default name', function () {
    Http::fake();

    Http::get('https://api.example.com/products');

    $request = Http::recorded()[0][0];
    $name = $this->namer->fromRequest($request);

    expect($name)->toBe('GET_api_example_com_products.json');
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
    $name = $this->namer->defaultName($request);

    expect($name)->toBe('GET_example_com.json');
});

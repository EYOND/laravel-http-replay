<?php

use EYOND\LaravelHttpReplay\Matchers\PathMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->matcher = new PathMatcher;
    Http::fake();
});

it('resolves the path without host', function () {
    Http::get('https://shop.myshopify.com/api/v1/products');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('api/v1/products');
});

it('resolves a single-segment path', function () {
    Http::get('https://example.com/products');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('products');
});

it('returns null when URL has no path', function () {
    Http::get('https://example.com');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBeNull();
});

it('strips leading and trailing slashes', function () {
    Http::get('https://example.com/api/products/');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('api/products');
});

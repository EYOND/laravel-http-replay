<?php

use EYOND\LaravelHttpReplay\Matchers\HostMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->matcher = new HostMatcher;
    Http::fake();
});

it('resolves the host', function () {
    Http::get('https://api.example.com/products');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('api.example.com');
});

it('resolves host with subdomain', function () {
    Http::get('https://shop.myshopify.com/api/products');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('shop.myshopify.com');
});

it('resolves host without subdomain', function () {
    Http::get('https://example.com/path');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('example.com');
});

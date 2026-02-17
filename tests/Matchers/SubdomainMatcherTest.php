<?php

use EYOND\LaravelHttpReplay\Matchers\SubdomainMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->matcher = new SubdomainMatcher;
    Http::fake();
});

it('resolves the subdomain', function () {
    Http::get('https://shop.myshopify.com/api/products');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('shop');
});

it('resolves first subdomain when multiple subdomains exist', function () {
    Http::get('https://deep.sub.example.com/path');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('deep');
});

it('returns null when no subdomain exists', function () {
    Http::get('https://example.com/path');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBeNull();
});

it('returns null for two-part host', function () {
    Http::get('https://localhost.test/path');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBeNull();
});

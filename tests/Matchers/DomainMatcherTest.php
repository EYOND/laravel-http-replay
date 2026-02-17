<?php

use EYOND\LaravelHttpReplay\Matchers\DomainMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->matcher = new DomainMatcher;
    Http::fake();
});

it('strips subdomain from host', function () {
    Http::get('https://shop.myshopify.com/api/products');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('myshopify.com');
});

it('strips only the first subdomain level', function () {
    Http::get('https://deep.sub.example.com/path');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('sub.example.com');
});

it('returns host as-is when no subdomain exists', function () {
    Http::get('https://example.com/path');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('example.com');
});

it('returns host as-is for two-part host', function () {
    Http::get('https://localhost.test/path');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('localhost.test');
});

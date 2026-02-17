<?php

use EYOND\LaravelHttpReplay\Matchers\UrlMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->matcher = new UrlMatcher;
    Http::fake();
});

it('resolves host and path', function () {
    Http::get('https://api.example.com/products');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('api.example.com_products');
});

it('resolves nested path', function () {
    Http::get('https://api.example.com/v1/users/42/orders');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('api.example.com_v1/users/42/orders');
});

it('resolves host only when no path', function () {
    Http::get('https://example.com');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('example.com');
});

it('strips trailing slash from path', function () {
    Http::get('https://example.com/api/');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('example.com_api');
});

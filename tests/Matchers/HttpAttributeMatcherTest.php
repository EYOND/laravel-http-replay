<?php

use EYOND\LaravelHttpReplay\Matchers\HttpAttributeMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
});

it('resolves an attribute value', function () {
    Http::withAttributes(['request_name' => 'get-products'])
        ->get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new HttpAttributeMatcher('request_name');

    expect($matcher->resolve($request))->toBe('get-products');
});

it('resolves the replay attribute', function () {
    Http::withAttributes(['replay' => 'products'])
        ->get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new HttpAttributeMatcher('replay');

    expect($matcher->resolve($request))->toBe('products');
});

it('returns null when attribute does not exist', function () {
    Http::get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new HttpAttributeMatcher('nonexistent');

    expect($matcher->resolve($request))->toBeNull();
});

it('returns null when attribute is empty string', function () {
    Http::withAttributes(['key' => ''])
        ->get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new HttpAttributeMatcher('key');

    expect($matcher->resolve($request))->toBeNull();
});

it('casts numeric attribute to string', function () {
    Http::withAttributes(['version' => 42])
        ->get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new HttpAttributeMatcher('version');

    expect($matcher->resolve($request))->toBe('42');
});

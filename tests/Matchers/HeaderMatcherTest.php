<?php

use EYOND\LaravelHttpReplay\Matchers\HeaderMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
});

it('resolves a request header value', function () {
    Http::withHeaders(['X-Api-Version' => 'v2'])->get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new HeaderMatcher('X-Api-Version');

    expect($matcher->resolve($request))->toBe('v2');
});

it('resolves the Accept header', function () {
    Http::withHeaders(['Accept' => 'application/json'])->get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new HeaderMatcher('Accept');

    expect($matcher->resolve($request))->toBe('application/json');
});

it('returns null when header is not set', function () {
    Http::get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new HeaderMatcher('X-Missing');

    expect($matcher->resolve($request))->toBeNull();
});

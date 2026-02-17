<?php

use EYOND\LaravelHttpReplay\Matchers\QueryParamMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
});

it('resolves a specific query param value', function () {
    Http::get('https://example.com/api?action=getProducts');
    $request = Http::recorded()[0][0];

    $matcher = new QueryParamMatcher('action');

    expect($matcher->resolve($request))->toBe('getProducts');
});

it('resolves a numeric query param as string', function () {
    Http::get('https://example.com/api?page=3');
    $request = Http::recorded()[0][0];

    $matcher = new QueryParamMatcher('page');

    expect($matcher->resolve($request))->toBe('3');
});

it('returns null when the key is missing', function () {
    Http::get('https://example.com/api?foo=bar');
    $request = Http::recorded()[0][0];

    $matcher = new QueryParamMatcher('missing');

    expect($matcher->resolve($request))->toBeNull();
});

it('returns null when no query params exist', function () {
    Http::get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new QueryParamMatcher('page');

    expect($matcher->resolve($request))->toBeNull();
});

it('returns null when query param value is empty string', function () {
    Http::get('https://example.com/api?key=');
    $request = Http::recorded()[0][0];

    $matcher = new QueryParamMatcher('key');

    expect($matcher->resolve($request))->toBeNull();
});

<?php

use EYOND\LaravelHttpReplay\Matchers\QueryHashMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
});

it('resolves a 6-char hash of all query params', function () {
    Http::get('https://example.com/api?page=2&limit=10');
    $request = Http::recorded()[0][0];

    $matcher = new QueryHashMatcher;

    $result = $matcher->resolve($request);
    expect($result)->toBeString()->toHaveLength(6);
});

it('returns null when no query params exist', function () {
    Http::get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new QueryHashMatcher;

    expect($matcher->resolve($request))->toBeNull();
});

it('produces different hashes for different query params', function () {
    Http::get('https://example.com/api?page=1');
    Http::get('https://example.com/api?page=2');

    $request1 = Http::recorded()[0][0];
    $request2 = Http::recorded()[1][0];

    $matcher = new QueryHashMatcher;

    expect($matcher->resolve($request1))->not->toBe($matcher->resolve($request2));
});

it('produces the same hash for identical query params', function () {
    Http::get('https://example.com/api?page=2&limit=10');
    Http::get('https://example.com/api?page=2&limit=10');

    $request1 = Http::recorded()[0][0];
    $request2 = Http::recorded()[1][0];

    $matcher = new QueryHashMatcher;

    expect($matcher->resolve($request1))->toBe($matcher->resolve($request2));
});

it('hashes only specific keys when provided', function () {
    Http::get('https://example.com/api?page=2&limit=10&timestamp=123');
    Http::get('https://example.com/api?page=2&limit=10&timestamp=456');

    $request1 = Http::recorded()[0][0];
    $request2 = Http::recorded()[1][0];

    $matcher = new QueryHashMatcher(['page', 'limit']);

    // Same hash because page and limit are identical
    expect($matcher->resolve($request1))->toBe($matcher->resolve($request2));
});

it('produces different hashes when specific keys differ', function () {
    Http::get('https://example.com/api?page=1&limit=10');
    Http::get('https://example.com/api?page=2&limit=10');

    $request1 = Http::recorded()[0][0];
    $request2 = Http::recorded()[1][0];

    $matcher = new QueryHashMatcher(['page']);

    expect($matcher->resolve($request1))->not->toBe($matcher->resolve($request2));
});

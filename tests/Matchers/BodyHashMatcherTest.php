<?php

use EYOND\LaravelHttpReplay\Matchers\BodyHashMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
});

it('resolves a 6-char hash of the entire body', function () {
    Http::post('https://example.com/api', ['query' => '{products{...}}']);
    $request = Http::recorded()[0][0];

    $matcher = new BodyHashMatcher;

    $result = $matcher->resolve($request);
    expect($result)->toBeString()->toHaveLength(6);
});

it('produces different hashes for different bodies', function () {
    Http::post('https://example.com/api', ['query' => '{products{...}}']);
    Http::post('https://example.com/api', ['query' => '{orders{...}}']);

    $request1 = Http::recorded()[0][0];
    $request2 = Http::recorded()[1][0];

    $matcher = new BodyHashMatcher;

    expect($matcher->resolve($request1))->not->toBe($matcher->resolve($request2));
});

it('produces the same hash for identical bodies', function () {
    Http::post('https://example.com/api', ['query' => '{products{...}}']);
    Http::post('https://example.com/api', ['query' => '{products{...}}']);

    $request1 = Http::recorded()[0][0];
    $request2 = Http::recorded()[1][0];

    $matcher = new BodyHashMatcher;

    expect($matcher->resolve($request1))->toBe($matcher->resolve($request2));
});

it('hashes only specific keys when provided', function () {
    Http::post('https://example.com/api', [
        'query' => '{products{...}}',
        'variables' => ['id' => '123'],
        'timestamp' => '2026-01-01',
    ]);
    Http::post('https://example.com/api', [
        'query' => '{products{...}}',
        'variables' => ['id' => '123'],
        'timestamp' => '2026-02-02',
    ]);

    $request1 = Http::recorded()[0][0];
    $request2 = Http::recorded()[1][0];

    $matcher = new BodyHashMatcher(['query', 'variables.id']);

    // Same hash because query and variables.id are identical
    expect($matcher->resolve($request1))->toBe($matcher->resolve($request2));
});

it('produces different hashes when specific keys differ', function () {
    Http::post('https://example.com/api', [
        'query' => '{products{...}}',
        'variables' => ['id' => '123'],
    ]);
    Http::post('https://example.com/api', [
        'query' => '{orders{...}}',
        'variables' => ['id' => '123'],
    ]);

    $request1 = Http::recorded()[0][0];
    $request2 = Http::recorded()[1][0];

    $matcher = new BodyHashMatcher(['query']);

    expect($matcher->resolve($request1))->not->toBe($matcher->resolve($request2));
});

it('handles non-JSON body with specific keys gracefully', function () {
    Http::withBody('plain text body', 'text/plain')
        ->post('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new BodyHashMatcher(['query']);

    $result = $matcher->resolve($request);
    // Falls back to hashing the entire body
    expect($result)->toBeString()->toHaveLength(6);
});

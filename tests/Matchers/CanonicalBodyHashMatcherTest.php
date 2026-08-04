<?php

use EYOND\LaravelHttpReplay\Matchers\CanonicalBodyHashMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
});

it('produces the same full-body hash for different object key order', function () {
    Http::withBody('{"z":2,"nested":{"b":2,"a":1},"a":1}', 'application/json')->post('https://example.com/graphql');
    Http::withBody('{"a":1,"nested":{"a":1,"b":2},"z":2}', 'application/json')->post('https://example.com/graphql');

    $matcher = new CanonicalBodyHashMatcher;

    expect($matcher->resolve(Http::recorded()[0][0]))
        ->toBe($matcher->resolve(Http::recorded()[1][0]));
});

it('changes the hash when list order changes', function () {
    Http::withBody('{"items":[1,2,3]}', 'application/json')->post('https://example.com/graphql');
    Http::withBody('{"items":[3,2,1]}', 'application/json')->post('https://example.com/graphql');

    $matcher = new CanonicalBodyHashMatcher;

    expect($matcher->resolve(Http::recorded()[0][0]))
        ->not->toBe($matcher->resolve(Http::recorded()[1][0]));
});

it('hashes selected dot-notation paths only', function () {
    Http::withBody('{"query":"query A { a }","variables":{"input":{"z":2,"a":1}},"ignored":1}', 'application/json')
        ->post('https://example.com/graphql');
    Http::withBody('{"ignored":2,"variables":{"input":{"a":1,"z":2}},"query":"query B { b }"}', 'application/json')
        ->post('https://example.com/graphql');

    $matcher = new CanonicalBodyHashMatcher(['variables.input']);

    expect($matcher->resolve(Http::recorded()[0][0]))
        ->toBe($matcher->resolve(Http::recorded()[1][0]));
});

it('supports several selected paths independent of path order', function () {
    Http::withBody('{"variables":{"b":2,"a":1},"extensions":{"context":{"z":2,"a":1}}}', 'application/json')
        ->post('https://example.com/graphql');

    $request = Http::recorded()[0][0];

    expect((new CanonicalBodyHashMatcher(['variables', 'extensions.context']))->resolve($request))
        ->toBe((new CanonicalBodyHashMatcher(['extensions.context', 'variables']))->resolve($request));
});

it('distinguishes a missing selected path from an explicit null value', function () {
    Http::withBody('{}', 'application/json')->post('https://example.com/graphql');
    Http::withBody('{"variables":null}', 'application/json')->post('https://example.com/graphql');

    $matcher = new CanonicalBodyHashMatcher(['variables']);

    expect($matcher->resolve(Http::recorded()[0][0]))->toBe('99914b')
        ->and($matcher->resolve(Http::recorded()[1][0]))->toBe('37d144');
});

it('falls back to raw body hashes for lossy integers', function () {
    Http::withBody('{"id":9223372036854775808}', 'application/json')->post('https://example.com/graphql');
    Http::withBody('{"id":9223372036854775809}', 'application/json')->post('https://example.com/graphql');
    Http::withBody('{"id":"9223372036854775808"}', 'application/json')->post('https://example.com/graphql');

    $matcher = new CanonicalBodyHashMatcher(['id']);
    $hashes = Http::recorded()->map(fn (array $recorded): string => $matcher->resolve($recorded[0]));

    expect($hashes->all())->toBe(['a5ab70', '4c7d35', '801838']);
});

it('falls back to the raw body hash for non-JSON bodies', function () {
    Http::withBody('plain text body', 'text/plain')->post('https://example.com/graphql');

    expect((new CanonicalBodyHashMatcher(['variables']))->resolve(Http::recorded()[0][0]))
        ->toBe('fb5f99');
});

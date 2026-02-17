<?php

use EYOND\LaravelHttpReplay\Matchers\BodyFieldMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
});

it('resolves a top-level JSON body field', function () {
    Http::post('https://example.com/graphql', [
        'operationName' => 'GetProducts',
        'query' => '{products{...}}',
    ]);
    $request = Http::recorded()[0][0];

    $matcher = new BodyFieldMatcher('operationName');

    expect($matcher->resolve($request))->toBe('GetProducts');
});

it('resolves a nested JSON body field via dot notation', function () {
    Http::post('https://example.com/api', [
        'variables' => ['id' => '123'],
    ]);
    $request = Http::recorded()[0][0];

    $matcher = new BodyFieldMatcher('variables.id');

    expect($matcher->resolve($request))->toBe('123');
});

it('returns null when the field is missing', function () {
    Http::post('https://example.com/api', ['foo' => 'bar']);
    $request = Http::recorded()[0][0];

    $matcher = new BodyFieldMatcher('missing');

    expect($matcher->resolve($request))->toBeNull();
});

it('returns null for non-JSON body', function () {
    Http::withBody('plain text body', 'text/plain')
        ->post('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new BodyFieldMatcher('operationName');

    expect($matcher->resolve($request))->toBeNull();
});

it('returns null when field value is empty string', function () {
    Http::post('https://example.com/api', ['name' => '']);
    $request = Http::recorded()[0][0];

    $matcher = new BodyFieldMatcher('name');

    expect($matcher->resolve($request))->toBeNull();
});

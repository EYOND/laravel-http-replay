<?php

use EYOND\LaravelHttpReplay\Matchers\ClosureMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
});

it('resolves using the closure return value', function () {
    Http::post('https://example.com/graphql', ['operationName' => 'GetProducts']);
    $request = Http::recorded()[0][0];

    $matcher = new ClosureMatcher(fn ($r) => [$r->data()['operationName']]);

    expect($matcher->resolve($request))->toBe('GetProducts');
});

it('joins multiple parts with underscore', function () {
    Http::post('https://example.com/graphql', ['operationName' => 'GetProducts']);
    $request = Http::recorded()[0][0];

    $matcher = new ClosureMatcher(fn ($r) => ['graphql', $r->data()['operationName']]);

    expect($matcher->resolve($request))->toBe('graphql_GetProducts');
});

it('filters out empty parts', function () {
    Http::post('https://example.com/api', ['key' => 'value']);
    $request = Http::recorded()[0][0];

    $matcher = new ClosureMatcher(fn ($r) => ['', 'valid', '']);

    expect($matcher->resolve($request))->toBe('valid');
});

it('returns null when all parts are empty', function () {
    Http::get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new ClosureMatcher(fn ($r) => ['', '']);

    expect($matcher->resolve($request))->toBeNull();
});

it('accepts a string return value', function () {
    Http::get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new ClosureMatcher(fn () => 'custom-name');

    expect($matcher->resolve($request))->toBe('custom-name');
});

it('accepts an integer return value', function () {
    Http::get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new ClosureMatcher(fn () => 42);

    expect($matcher->resolve($request))->toBe('42');
});

it('accepts a Collection return value', function () {
    Http::post('https://example.com/graphql', ['operationName' => 'GetProducts']);
    $request = Http::recorded()[0][0];

    $matcher = new ClosureMatcher(fn ($r) => collect(['graphql', $r->data()['operationName']]));

    expect($matcher->resolve($request))->toBe('graphql_GetProducts');
});

it('filters empty parts from a Collection', function () {
    Http::get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new ClosureMatcher(fn () => collect(['', 'valid', '']));

    expect($matcher->resolve($request))->toBe('valid');
});

it('returns null for an empty string', function () {
    Http::get('https://example.com/api');
    $request = Http::recorded()[0][0];

    $matcher = new ClosureMatcher(fn () => '');

    expect($matcher->resolve($request))->toBeNull();
});

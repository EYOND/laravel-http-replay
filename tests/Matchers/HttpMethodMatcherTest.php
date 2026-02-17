<?php

use EYOND\LaravelHttpReplay\Matchers\HttpMethodMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->matcher = new HttpMethodMatcher;
    Http::fake();
});

it('resolves GET method', function () {
    Http::get('https://example.com/api');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('GET');
});

it('resolves POST method', function () {
    Http::post('https://example.com/api', ['key' => 'value']);
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('POST');
});

it('resolves PUT method', function () {
    Http::put('https://example.com/api/1', ['key' => 'value']);
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('PUT');
});

it('resolves DELETE method', function () {
    Http::delete('https://example.com/api/1');
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('DELETE');
});

it('resolves PATCH method', function () {
    Http::patch('https://example.com/api/1', ['key' => 'updated']);
    $request = Http::recorded()[0][0];

    expect($this->matcher->resolve($request))->toBe('PATCH');
});

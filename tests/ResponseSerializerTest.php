<?php

use EYOND\LaravelHttpReplay\ResponseSerializer;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->serializer = new ResponseSerializer;
});

it('serializes a JSON response', function () {
    Http::fake([
        'api.example.com/*' => Http::response(['products' => []], 200, ['X-Custom' => 'value']),
    ]);

    Http::get('https://api.example.com/products');

    [$request, $response] = Http::recorded()[0];

    $data = $this->serializer->serialize($request, $response);

    expect($data)
        ->toHaveKey('status', 200)
        ->toHaveKey('body')
        ->toHaveKey('headers')
        ->toHaveKey('recorded_at')
        ->toHaveKey('request');

    expect($data['body'])->toBe(['products' => []]);
    expect($data['request']['method'])->toBe('GET');
    expect($data['request']['url'])->toContain('api.example.com/products');
});

it('serializes a non-JSON response as string', function () {
    Http::fake([
        'example.com/*' => Http::response('plain text body', 200),
    ]);

    Http::get('https://example.com/page');

    [$request, $response] = Http::recorded()[0];

    $data = $this->serializer->serialize($request, $response);

    expect($data['body'])->toBe('plain text body');
});

it('deserializes a stored response', function () {
    $data = [
        'status' => 201,
        'headers' => ['Content-Type' => ['application/json']],
        'body' => ['id' => 1, 'name' => 'Test'],
    ];

    $response = $this->serializer->deserialize($data);

    // The deserialized response should be a PromiseInterface (as returned by Http::response())
    expect($response)->toBeInstanceOf(\GuzzleHttp\Promise\PromiseInterface::class);
});

it('preserves request attributes in serialization', function () {
    Http::fake();

    Http::withAttributes(['replay' => 'products'])->get('https://api.example.com/products');

    [$request, $response] = Http::recorded()[0];

    $data = $this->serializer->serialize($request, $response);

    expect($data['request']['attributes'])->toHaveKey('replay', 'products');
});

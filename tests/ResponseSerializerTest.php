<?php

use EYOND\LaravelHttpReplay\ResponseSerializer;
use GuzzleHttp\Promise\PromiseInterface;
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
    expect($data['headers'])->toHaveKey('X-Custom');
    expect($data['request']['method'])->toBe('GET');
    expect($data['request']['url'])->toContain('api.example.com/products');
});

it('omits response headers when disabled while keeping a stable headers key', function () {
    Http::fake([
        'api.example.com/*' => Http::response('ok', 200, ['X-Custom' => 'value']),
    ]);

    Http::get('https://api.example.com/products');

    [$request, $response] = Http::recorded()[0];
    $data = $this->serializer->serialize($request, $response, false);

    expect($data)->toHaveKey('headers', []);
});

it('selects response headers case-insensitively', function () {
    Http::fake([
        'api.example.com/*' => Http::response('ok', 200, [
            'Content-Type' => 'text/plain',
            'X-Goog-Hash' => 'crc32c=abc',
            'X-Ignored' => 'value',
        ]),
    ]);

    Http::get('https://api.example.com/products');

    [$request, $response] = Http::recorded()[0];
    $data = $this->serializer->serialize($request, $response, ['content-type', 'X-GOOG-HASH']);

    expect($data['headers'])
        ->toHaveKeys(['Content-Type', 'X-Goog-Hash'])
        ->not->toHaveKey('X-Ignored');
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

it('keeps JSON replay bodies backward compatible', function () {
    Http::fake([
        'example.com/*' => Http::response('{"id":1,"name":"Test"}', 200),
    ]);

    Http::get('https://example.com/data');

    [$request, $response] = Http::recorded()[0];
    $data = $this->serializer->serialize($request, $response);

    expect($data['body'])->toBe(['id' => 1, 'name' => 'Test'])
        ->and($data)->not->toHaveKey('body_encoding');

    $replayed = $this->serializer->deserialize($data)->wait();

    expect(json_decode((string) $replayed->getBody(), true))
        ->toBe(['id' => 1, 'name' => 'Test']);
});

it('roundtrips UTF-8 XML byte for byte without transforming it', function () {
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<root><value>Grüezi &amp; hello</value></root>\n";

    Http::fake(['example.com/*' => Http::response($xml, 200, ['Content-Type' => 'application/xml'])]);
    Http::get('https://example.com/data.xml');

    [$request, $response] = Http::recorded()[0];
    $data = $this->serializer->serialize($request, $response);
    $replayed = $this->serializer->deserialize($data)->wait();

    expect($data['body'])->toBe($xml)
        ->and($data)->not->toHaveKey('body_encoding')
        ->and((string) $replayed->getBody())->toBe($xml);
});

it('roundtrips binary response bodies byte for byte using base64', function () {
    $binary = "\x00\xFF\x89PNG\r\n\x1A\n\x80\xFE";

    Http::fake(['example.com/*' => Http::response($binary, 200)]);
    Http::get('https://example.com/image.png');

    [$request, $response] = Http::recorded()[0];
    $data = $this->serializer->serialize($request, $response);
    $replayed = $this->serializer->deserialize($data)->wait();

    expect($data['body'])->toBe(base64_encode($binary))
        ->and($data['body_encoding'])->toBe('base64')
        ->and(json_encode($data))->not->toBeFalse()
        ->and((string) $replayed->getBody())->toBe($binary);
});

it('uses the binary fallback for non-UTF-8 XML', function () {
    $xml = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><root>Gr\xFCezi</root>";

    Http::fake(['example.com/*' => Http::response($xml, 200, ['Content-Type' => 'application/xml'])]);
    Http::get('https://example.com/legacy.xml');

    [$request, $response] = Http::recorded()[0];
    $data = $this->serializer->serialize($request, $response);
    $replayed = $this->serializer->deserialize($data)->wait();

    expect($data['body_encoding'])->toBe('base64')
        ->and((string) $replayed->getBody())->toBe($xml);
});

it('loads legacy replay bodies without an encoding marker', function () {
    $response = $this->serializer->deserialize([
        'status' => 200,
        'body' => 'legacy plain text',
    ])->wait();

    expect((string) $response->getBody())->toBe('legacy plain text');
});

it('deserializes a stored response', function () {
    $data = [
        'status' => 201,
        'headers' => ['Content-Type' => ['application/json']],
        'body' => ['id' => 1, 'name' => 'Test'],
    ];

    $response = $this->serializer->deserialize($data);

    // The deserialized response should be a PromiseInterface (as returned by Http::response())
    expect($response)->toBeInstanceOf(PromiseInterface::class);
});

it('preserves request attributes in serialization', function () {
    Http::fake();

    Http::withAttributes(['replay' => 'products'])->get('https://api.example.com/products');

    [$request, $response] = Http::recorded()[0];

    $data = $this->serializer->serialize($request, $response);

    expect($data['request']['attributes'])->toHaveKey('replay', 'products');
});

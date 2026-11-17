<?php

use EYOND\LaravelHttpReplay\ReplayStorage;
use EYOND\LaravelHttpReplay\ResponseSerializer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/http-replays-test-'.uniqid();
    $this->storage = new ReplayStorage($this->tempDir);
});

afterEach(function () {
    if (File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

it('returns the configured base path', function () {
    expect($this->storage->getBasePath())->toBe($this->tempDir);
});

it('generates shared directory path', function () {
    $dir = $this->storage->getSharedDirectory('shopify');

    expect($dir)->toBe($this->tempDir.DIRECTORY_SEPARATOR.'_shared'.DIRECTORY_SEPARATOR.'shopify');
});

it('stores a response file', function () {
    $data = [
        'status' => 200,
        'headers' => ['Content-Type' => ['application/json']],
        'body' => ['products' => []],
        'recorded_at' => now()->toIso8601String(),
    ];

    $dir = $this->tempDir.'/test';
    $this->storage->store($data, $dir, 'GET_api_example_com_products.json');

    expect(File::exists($dir.'/GET_api_example_com_products.json'))->toBeTrue();

    $stored = json_decode(File::get($dir.'/GET_api_example_com_products.json'), true);
    expect($stored['status'])->toBe(200);
    expect($stored['body'])->toBe(['products' => []]);
});

it('deletes one stored response', function () {
    $dir = $this->tempDir.'/test';
    $filename = 'GET_api_example_com_products.json';
    $this->storage->store(['status' => 200, 'body' => []], $dir, $filename);

    $this->storage->deleteResponse($dir, $filename);

    expect(File::exists($dir.'/'.$filename))->toBeFalse();
});

it('stores an empty response header list in the replay schema', function () {
    Http::fake(['example.com/*' => Http::response('ok', 200, ['X-Custom' => 'value'])]);
    Http::get('https://example.com/data');

    [$request, $response] = Http::recorded()[0];
    $data = (new ResponseSerializer)->serialize($request, $response, false);
    $dir = $this->tempDir.'/test';

    $this->storage->store($data, $dir, 'response.json');

    $stored = json_decode(File::get($dir.'/response.json'), true);

    expect($stored)->toHaveKey('headers', []);
});

it('finds stored responses in a directory', function () {
    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);

    File::put($dir.'/GET_api_example.json', json_encode([
        'status' => 200,
        'body' => ['data' => 'test'],
    ]));

    File::put($dir.'/POST_api_example.json', json_encode([
        'status' => 201,
        'body' => ['id' => 1],
    ]));

    $responses = $this->storage->findStoredResponses($dir);

    expect($responses)->toHaveCount(2);
    expect($responses)->toHaveKey('GET_api_example.json');
    expect($responses)->toHaveKey('POST_api_example.json');
});

it('returns empty array for non-existent directory', function () {
    expect($this->storage->findStoredResponses($this->tempDir.'/nonexistent'))
        ->toBe([]);
});

it('deletes a directory', function () {
    $dir = $this->tempDir.'/to-delete';
    File::ensureDirectoryExists($dir);
    File::put($dir.'/test.json', '{}');

    $this->storage->deleteDirectory($dir);

    expect(File::isDirectory($dir))->toBeFalse();
});

it('deletes files by URL pattern', function () {
    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);

    File::put($dir.'/shopify.json', json_encode([
        'request' => ['url' => 'https://shopify.com/api/products'],
    ]));

    File::put($dir.'/stripe.json', json_encode([
        'request' => ['url' => 'https://api.stripe.com/charges'],
    ]));

    $this->storage->deleteByPattern($dir, 'shopify.com/*');

    expect(File::exists($dir.'/shopify.json'))->toBeFalse();
    expect(File::exists($dir.'/stripe.json'))->toBeTrue();
});

it('detects expired responses', function () {
    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);

    $expiredFile = $dir.'/expired.json';
    File::put($expiredFile, json_encode([
        'recorded_at' => now()->subDays(10)->toIso8601String(),
    ]));

    $freshFile = $dir.'/fresh.json';
    File::put($freshFile, json_encode([
        'recorded_at' => now()->subDays(2)->toIso8601String(),
    ]));

    expect($this->storage->isExpired($expiredFile, 7))->toBeTrue();
    expect($this->storage->isExpired($freshFile, 7))->toBeFalse();
});

it('ignores non-JSON files when finding responses', function () {
    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);

    File::put($dir.'/response.json', json_encode(['status' => 200, 'body' => 'ok']));
    File::put($dir.'/readme.txt', 'not a response');

    $responses = $this->storage->findStoredResponses($dir);

    expect($responses)->toHaveCount(1);
    expect($responses)->toHaveKey('response.json');
});

it('returns a sequence of ten or more responses in recording order', function () {
    $dir = $this->tempDir.'/test';

    // Stored out of order on purpose, so the result cannot rely on creation order.
    foreach ([11, 2, 10, 1, 9, 3, 8, 4, 7, 5, 6] as $number) {
        $filename = $number === 1 ? 'operation.json' : "operation__{$number}.json";
        $this->storage->store(['status' => 200, 'body' => $number], $dir, $filename);
    }

    $responses = $this->storage->findStoredResponses($dir);

    expect(array_keys($responses))->toBe([
        'operation.json',
        'operation__2.json',
        'operation__3.json',
        'operation__4.json',
        'operation__5.json',
        'operation__6.json',
        'operation__7.json',
        'operation__8.json',
        'operation__9.json',
        'operation__10.json',
        'operation__11.json',
    ])->and(array_values(array_column($responses, 'body')))->toBe(range(1, 11));
});

it('keeps the sequences of different replay keys apart when ordering', function () {
    $dir = $this->tempDir.'/test';

    foreach (['a.json', 'a__2.json', 'a__10.json', 'a_b.json', 'a_b__2.json', 'b.json'] as $filename) {
        $this->storage->store(['status' => 200, 'body' => $filename], $dir, $filename);
    }

    $filenames = array_keys($this->storage->findStoredResponses($dir));

    $sequence = fn (string $base): array => array_values(array_filter(
        $filenames,
        fn (string $filename): bool => preg_replace('/__\d+\.json$/', '.json', $filename) === $base,
    ));

    expect($filenames)->toHaveCount(6)
        ->and($sequence('a.json'))->toBe(['a.json', 'a__2.json', 'a__10.json'])
        ->and($sequence('a_b.json'))->toBe(['a_b.json', 'a_b__2.json'])
        ->and($sequence('b.json'))->toBe(['b.json']);
});

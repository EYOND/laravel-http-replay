<?php

use EYOND\LaravelHttpReplay\ReplayBuilder;
use EYOND\LaravelHttpReplay\ReplayStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/http-replays-builder-'.uniqid();
    $this->storage = new ReplayStorage($this->tempDir);
});

afterEach(function () {
    if (File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

it('registers Http::replay() macro', function () {
    expect(Http::hasMacro('replay'))->toBeTrue();
});

it('returns a ReplayBuilder from Http::replay()', function () {
    $builder = Http::replay();

    expect($builder)->toBeInstanceOf(ReplayBuilder::class);
});

it('replays stored responses', function () {
    // Pre-populate a stored response
    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);

    File::put($dir.'/GET_api_example_com_products.json', json_encode([
        'status' => 200,
        'headers' => ['Content-Type' => ['application/json']],
        'body' => ['products' => [['id' => 1], ['id' => 2]]],
        'recorded_at' => now()->toIso8601String(),
        'request' => [
            'method' => 'GET',
            'url' => 'https://api.example.com/products',
            'attributes' => [],
        ],
    ]));

    $builder = new ReplayBuilder($this->storage);

    // Override the directory resolution for testing
    $reflection = new ReflectionClass($builder);
    $prop = $reflection->getProperty('initialized');
    $prop->setValue($builder, true);

    $loadDirs = $reflection->getProperty('loadDirectories');
    $loadDirs->setValue($builder, [$dir]);

    $saveDir = $reflection->getProperty('saveDirectory');
    $saveDir->setValue($builder, $dir);

    // Manually load stored responses
    $method = $reflection->getMethod('loadStoredResponses');
    $method->invoke($builder);

    $response = Http::get('https://api.example.com/products');

    expect($response->status())->toBe(200);
    expect($response->json('products'))->toHaveCount(2);
});

it('supports fluent matchBy configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder->matchBy('url', 'http_method', 'body_hash');

    expect($result)->toBeInstanceOf(ReplayBuilder::class);
});

it('supports fluent only configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder->only(['shopify.com/*']);

    expect($result)->toBeInstanceOf(ReplayBuilder::class);
});

it('supports fluent alsoFake configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder->alsoFake([
        'api.stripe.com/*' => Http::response(['ok' => true]),
    ]);

    expect($result)->toBeInstanceOf(ReplayBuilder::class);
});

it('supports fluent readFrom configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder->readFrom('shopify');

    expect($result)->toBeInstanceOf(ReplayBuilder::class);
});

it('supports fluent writeTo configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder->writeTo('shopify');

    expect($result)->toBeInstanceOf(ReplayBuilder::class);
});

it('supports fluent useShared configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder->useShared('shopify');

    expect($result)->toBeInstanceOf(ReplayBuilder::class);
});

it('supports fluent fresh configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder->fresh();

    expect($result)->toBeInstanceOf(ReplayBuilder::class);
});

it('supports fluent expireAfter configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder->expireAfter(days: 7);

    expect($result)->toBeInstanceOf(ReplayBuilder::class);
});

it('supports full fluent chain', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder
        ->only(['shopify.com/*'])
        ->matchBy('url', 'body_hash')
        ->expireAfter(days: 7)
        ->alsoFake([
            'api.stripe.com/*' => Http::response(['ok' => true]),
        ]);

    expect($result)->toBeInstanceOf(ReplayBuilder::class);
});

it('serves static fakes for non-replay URLs when only is set', function () {
    $builder = new ReplayBuilder($this->storage);

    $builder
        ->only(['shopify.com/*'])
        ->alsoFake([
            'api.stripe.com/*' => Http::response(['charge' => 'ok'], 200),
        ]);

    $response = Http::get('https://api.stripe.com/charges');

    expect($response->status())->toBe(200);
    expect($response->json('charge'))->toBe('ok');
});

it('writes to shared directory with useShared', function () {
    $builder = new ReplayBuilder($this->storage);
    $builder->useShared('my-shared');

    $sharedDir = $this->storage->getSharedDirectory('my-shared');

    $reflection = new ReflectionClass($builder);
    $init = $reflection->getMethod('initialize');
    $init->invoke($builder);

    $saveDir = $reflection->getProperty('saveDirectory');
    expect($saveDir->getValue($builder))->toBe($sharedDir);
});

it('loads from shared directory with readFrom()', function () {
    $builder = new ReplayBuilder($this->storage);
    $builder->readFrom('my-shared');

    $sharedDir = $this->storage->getSharedDirectory('my-shared');

    $reflection = new ReflectionClass($builder);
    $init = $reflection->getMethod('initialize');
    $init->invoke($builder);

    $loadDirs = $reflection->getProperty('loadDirectories');
    expect($loadDirs->getValue($builder))->toBe([$sharedDir]);
});

it('deletes stored responses when fresh() is used', function () {
    // Pre-populate the shared directory
    $sharedDir = $this->storage->getSharedDirectory('shopify');
    File::ensureDirectoryExists($sharedDir);
    File::put($sharedDir.'/GET_shopify.json', json_encode(['status' => 200, 'body' => 'old']));

    expect(File::exists($sharedDir.'/GET_shopify.json'))->toBeTrue();

    $builder = new ReplayBuilder($this->storage);
    $builder->readFrom('shopify')->fresh();

    // Trigger initialization which handles fresh
    $reflection = new ReflectionClass($builder);
    $init = $reflection->getMethod('initialize');
    $init->invoke($builder);

    expect(File::isDirectory($sharedDir))->toBeFalse();
});

it('deletes stored responses when REPLAY_FRESH SERVER var is set', function () {
    $_SERVER['REPLAY_FRESH'] = 'true';

    $sharedDir = $this->storage->getSharedDirectory('shopify');
    File::ensureDirectoryExists($sharedDir);
    File::put($sharedDir.'/GET_shopify.json', json_encode(['status' => 200, 'body' => 'old']));

    expect(File::exists($sharedDir.'/GET_shopify.json'))->toBeTrue();

    $builder = new ReplayBuilder($this->storage);
    $builder->readFrom('shopify');

    $reflection = new ReflectionClass($builder);
    $init = $reflection->getMethod('initialize');
    $init->invoke($builder);

    expect(File::isDirectory($sharedDir))->toBeFalse();

    unset($_SERVER['REPLAY_FRESH']);
});

it('supports for()->matchBy() per-URL configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder
        ->for('myshopify.com/*')->matchBy('url', 'http_attribute:request_name')
        ->for('reybex.com/*')->matchBy('http_method', 'url');

    expect($result)->toBeInstanceOf(ReplayBuilder::class);

    $reflection = new ReflectionClass($builder);
    $prop = $reflection->getProperty('perPatternMatchBy');

    expect($prop->getValue($builder))->toHaveCount(2);
});

it('throws ReplayBailException when bail config is active', function () {
    config()->set('http-replay.bail', true);

    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);

    $builder = new ReplayBuilder($this->storage);

    // Set up the builder as initialized with pending recordings
    $reflection = new ReflectionClass($builder);
    $reflection->getProperty('initialized')->setValue($builder, true);
    $reflection->getProperty('loadDirectories')->setValue($builder, [$dir]);
    $reflection->getProperty('saveDirectory')->setValue($builder, $dir);
    $reflection->getProperty('pendingRecordings')->setValue($builder, ['GET:https://api.example.com/products' => 1]);

    // Simulate a response being received (this is what triggers bail)
    Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);
    Http::get('https://api.example.com/products');
    [$request, $response] = Http::recorded()[0];

    $method = $reflection->getMethod('handleResponseReceived');
    $method->invoke($builder, $request, $response);
})->throws(\EYOND\LaravelHttpReplay\Exceptions\ReplayBailException::class);

it('throws ReplayBailException when --replay-bail flag sets SERVER var', function () {
    $_SERVER['REPLAY_BAIL'] = 'true';

    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);

    $builder = new ReplayBuilder($this->storage);

    $reflection = new ReflectionClass($builder);
    $reflection->getProperty('initialized')->setValue($builder, true);
    $reflection->getProperty('loadDirectories')->setValue($builder, [$dir]);
    $reflection->getProperty('saveDirectory')->setValue($builder, $dir);
    $reflection->getProperty('pendingRecordings')->setValue($builder, ['GET:https://api.example.com/products' => 1]);

    Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);
    Http::get('https://api.example.com/products');
    [$request, $response] = Http::recorded()[0];

    try {
        $method = $reflection->getMethod('handleResponseReceived');
        $method->invoke($builder, $request, $response);
    } finally {
        unset($_SERVER['REPLAY_BAIL']);
    }
})->throws(\EYOND\LaravelHttpReplay\Exceptions\ReplayBailException::class);

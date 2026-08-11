<?php

use EYOND\LaravelHttpReplay\Exceptions\ReplayBailException;
use EYOND\LaravelHttpReplay\Exceptions\ReusableResponseMismatchException;
use EYOND\LaravelHttpReplay\ReplayBuilder;
use EYOND\LaravelHttpReplay\ReplayMode;
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

it('supports fluent bail configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder->bail();

    expect($result)->toBeInstanceOf(ReplayBuilder::class);
});

it('supports reusable and sequential replay modes', function () {
    $builder = new ReplayBuilder($this->storage);
    $reflection = new ReflectionClass($builder);
    $mode = $reflection->getProperty('mode');

    expect($mode->getValue($builder))->toBe(ReplayMode::Sequential)
        ->and($builder->reusable())->toBeInstanceOf(ReplayBuilder::class)
        ->and($mode->getValue($builder))->toBe(ReplayMode::Reusable)
        ->and($builder->sequential())->toBeInstanceOf(ReplayBuilder::class)
        ->and($mode->getValue($builder))->toBe(ReplayMode::Sequential);
});

it('supports reusable and sequential modes per URL pattern', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder
        ->for('akeneo.example.com/products/*')->reusable()
        ->for('api.example.com/orders/*')->sequential();

    $modes = (new ReflectionClass($builder))->getProperty('perPatternModes')->getValue($builder);

    expect($result)->toBeInstanceOf(ReplayBuilder::class)
        ->and($modes)->toBe([
            'akeneo.example.com/products/*' => ReplayMode::Reusable,
            'api.example.com/orders/*' => ReplayMode::Sequential,
        ]);
});

it('supports fluent expireAfter configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    $result = $builder->expireAfter(days: 7);

    expect($result)->toBeInstanceOf(ReplayBuilder::class);
});

it('supports fluent response header configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    expect($builder->withResponseHeaders('Content-Type', 'x-goog-hash'))
        ->toBeInstanceOf(ReplayBuilder::class)
        ->and($builder->withoutResponseHeaders())
        ->toBeInstanceOf(ReplayBuilder::class);
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

it('throws ReplayBailException when bail() is used', function () {
    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);

    $builder = new ReplayBuilder($this->storage);
    $builder->bail();

    // Build a request object — initialized must be false so the builder's fake callback doesn't interfere
    Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);
    Http::get('https://api.example.com/products');
    [$request] = Http::recorded()[0];

    // Now set up the builder state and invoke handleRequest directly
    $reflection = new ReflectionClass($builder);
    $reflection->getProperty('initialized')->setValue($builder, true);
    $reflection->getProperty('loadDirectories')->setValue($builder, [$dir]);
    $reflection->getProperty('saveDirectory')->setValue($builder, $dir);

    $method = $reflection->getMethod('handleRequest');
    $method->invoke($builder, $request);
})->throws(ReplayBailException::class);

it('reuses one stored response for repeated requests in reusable mode', function () {
    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);
    File::put($dir.'/GET_api_example_com_products.json', json_encode([
        'status' => 200,
        'headers' => [],
        'body' => ['product' => ['id' => 1]],
    ]));

    $builder = new ReplayBuilder($this->storage);
    $builder->reusable()->bail();

    $reflection = new ReflectionClass($builder);
    $reflection->getProperty('initialized')->setValue($builder, true);
    $reflection->getProperty('loadDirectories')->setValue($builder, [$dir]);
    $reflection->getProperty('saveDirectory')->setValue($builder, $dir);
    $reflection->getMethod('loadStoredResponses')->invoke($builder);

    $responses = [
        Http::get('https://api.example.com/products'),
        Http::get('https://api.example.com/products'),
        Http::get('https://api.example.com/products'),
    ];

    expect(array_map(fn ($response) => $response->status(), $responses))->toBe([200, 200, 200])
        ->and(array_map(fn ($response) => $response->json('product.id'), $responses))->toBe([1, 1, 1])
        ->and(File::files($dir))->toHaveCount(1);
});

it('rejects different stored responses for a reusable replay key', function () {
    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);

    foreach ([
        'GET_api_example_com_orders_1.json' => 'pending',
        'GET_api_example_com_orders_1__2.json' => 'fulfilled',
    ] as $filename => $status) {
        File::put($dir.'/'.$filename, json_encode([
            'status' => 200,
            'headers' => [],
            'body' => ['status' => $status],
        ]));
    }

    $builder = new ReplayBuilder($this->storage);
    $builder->reusable();

    $reflection = new ReflectionClass($builder);
    $reflection->getProperty('initialized')->setValue($builder, true);
    $reflection->getProperty('loadDirectories')->setValue($builder, [$dir]);
    $reflection->getProperty('saveDirectory')->setValue($builder, $dir);
    $reflection->getMethod('loadStoredResponses')->invoke($builder);

    Http::get('https://api.example.com/orders/1');
})->throws(ReusableResponseMismatchException::class, 'Use sequential()');

it('stores identical recorded responses once in reusable mode', function () {
    Http::fakeSequence()
        ->push(['product' => ['id' => 1]], 200)
        ->push(['product' => ['id' => 1]], 200);

    Http::get('https://api.example.com/products');
    Http::get('https://api.example.com/products');
    [[$firstRequest, $firstResponse], [$secondRequest, $secondResponse]] = Http::recorded();

    $dir = $this->tempDir.'/test';
    $builder = new class($this->storage) extends ReplayBuilder
    {
        protected function registerSnapshotChange(string $filename): void {}
    };
    $builder->reusable();

    $reflection = new ReflectionClass($builder);
    $reflection->getProperty('initialized')->setValue($builder, true);
    $reflection->getProperty('loadDirectories')->setValue($builder, [$dir]);
    $reflection->getProperty('saveDirectory')->setValue($builder, $dir);

    $handleRequest = $reflection->getMethod('handleRequest');
    $handleResponse = $reflection->getMethod('handleResponseReceived');
    $handleRequest->invoke($builder, $firstRequest);
    $handleRequest->invoke($builder, $secondRequest);
    $handleResponse->invoke($builder, $firstRequest, $firstResponse);
    $handleResponse->invoke($builder, $secondRequest, $secondResponse);

    expect(File::files($dir))->toHaveCount(1)
        ->and(File::files($dir)[0]->getFilename())->toBe('GET_api_example_com_products.json');
});

it('rejects different recorded responses in reusable mode', function () {
    Http::fakeSequence()
        ->push(['status' => 'pending'], 200)
        ->push(['status' => 'fulfilled'], 200);

    Http::get('https://api.example.com/orders/1');
    Http::get('https://api.example.com/orders/1');
    [[$firstRequest, $firstResponse], [$secondRequest, $secondResponse]] = Http::recorded();

    $dir = $this->tempDir.'/test';
    $builder = new class($this->storage) extends ReplayBuilder
    {
        protected function registerSnapshotChange(string $filename): void {}
    };
    $builder->reusable();

    $reflection = new ReflectionClass($builder);
    $reflection->getProperty('initialized')->setValue($builder, true);
    $reflection->getProperty('loadDirectories')->setValue($builder, [$dir]);
    $reflection->getProperty('saveDirectory')->setValue($builder, $dir);

    $handleRequest = $reflection->getMethod('handleRequest');
    $handleResponse = $reflection->getMethod('handleResponseReceived');
    $handleRequest->invoke($builder, $firstRequest);
    $handleRequest->invoke($builder, $secondRequest);
    $handleResponse->invoke($builder, $firstRequest, $firstResponse);
    $handleResponse->invoke($builder, $secondRequest, $secondResponse);
})->throws(ReusableResponseMismatchException::class, 'Use sequential()');

it('throws ReplayBailException when bail config is active', function () {
    config()->set('http-replay.bail', true);

    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);

    $builder = new ReplayBuilder($this->storage);

    // Build a request object — initialized must be false so the builder's fake callback doesn't interfere
    Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);
    Http::get('https://api.example.com/products');
    [$request] = Http::recorded()[0];

    // Now set up the builder state and invoke handleRequest directly
    $reflection = new ReflectionClass($builder);
    $reflection->getProperty('initialized')->setValue($builder, true);
    $reflection->getProperty('loadDirectories')->setValue($builder, [$dir]);
    $reflection->getProperty('saveDirectory')->setValue($builder, $dir);

    $method = $reflection->getMethod('handleRequest');
    $method->invoke($builder, $request);
})->throws(ReplayBailException::class);

it('throws ReplayBailException when --replay-bail flag sets SERVER var', function () {
    $dir = $this->tempDir.'/test';
    File::ensureDirectoryExists($dir);

    // Build a request object BEFORE creating the ReplayBuilder to avoid
    // the builder's fake callback interfering with the Http::get() call
    Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);
    Http::get('https://api.example.com/products');
    [$request] = Http::recorded()[0];

    $_SERVER['REPLAY_BAIL'] = 'true';

    $builder = new ReplayBuilder($this->storage);

    // Set up the builder state and invoke handleRequest directly
    $reflection = new ReflectionClass($builder);
    $reflection->getProperty('initialized')->setValue($builder, true);
    $reflection->getProperty('loadDirectories')->setValue($builder, [$dir]);
    $reflection->getProperty('saveDirectory')->setValue($builder, $dir);

    try {
        $method = $reflection->getMethod('handleRequest');
        $method->invoke($builder, $request);
    } finally {
        unset($_SERVER['REPLAY_BAIL']);
    }
})->throws(ReplayBailException::class);

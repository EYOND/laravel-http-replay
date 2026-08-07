<?php

use EYOND\LaravelHttpReplay\Exceptions\ReplayBailException;
use EYOND\LaravelHttpReplay\ReplayBuilder;
use EYOND\LaravelHttpReplay\ReplayStorage;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/http-replays-fallback-'.uniqid();
    $this->storage = new ReplayStorage($this->tempDir);

    $this->storeReplay = function (
        string $directory,
        string $filename,
        int $status,
        string $source,
        ?string $recordedAt = null,
    ): void {
        $this->storage->store([
            'status' => $status,
            'headers' => ['Content-Type' => ['application/json']],
            'body' => ['source' => $source],
            'recorded_at' => $recordedAt ?? now()->toIso8601String(),
            'request' => [
                'method' => 'GET',
                'url' => 'https://api.example.com/'.$filename,
                'attributes' => ['replay' => pathinfo($filename, PATHINFO_FILENAME)],
            ],
        ], $directory, $filename);
    };
});

afterEach(function () {
    if (File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

it('supports fluent fallbackTo configuration', function () {
    $builder = new ReplayBuilder($this->storage);

    expect($builder->fallbackTo('project-specific', 'package-defaults'))
        ->toBeInstanceOf(ReplayBuilder::class);
});

it('loads test-local first and shared fallbacks in declared order', function () {
    $builder = new ReplayBuilder($this->storage);
    $builder->fallbackTo('project-specific', 'package-defaults');

    $reflection = new ReflectionClass($builder);
    $reflection->getMethod('initialize')->invoke($builder);

    expect($reflection->getProperty('loadDirectories')->getValue($builder))->toBe([
        $this->storage->getTestDirectory(),
        $this->storage->getSharedDirectory('project-specific'),
        $this->storage->getSharedDirectory('package-defaults'),
    ])->and($reflection->getProperty('saveDirectory')->getValue($builder))
        ->toBe($this->storage->getTestDirectory());
});

it('keeps writeTo as an explicit save-directory override', function () {
    $builder = new ReplayBuilder($this->storage);
    $builder->fallbackTo('package-defaults')->writeTo('recorded');

    $reflection = new ReflectionClass($builder);
    $reflection->getMethod('initialize')->invoke($builder);

    expect($reflection->getProperty('saveDirectory')->getValue($builder))
        ->toBe($this->storage->getSharedDirectory('recorded'));
});

it('prefers a test-local response over the same shared response', function () {
    ($this->storeReplay)($this->storage->getTestDirectory(), 'operation.json', 201, 'local');
    ($this->storeReplay)($this->storage->getSharedDirectory('defaults'), 'operation.json', 202, 'shared');

    (new ReplayBuilder($this->storage))->fallbackTo('defaults')->bail();

    $response = Http::withAttributes(['replay' => 'operation'])
        ->get('https://api.example.com/operation');

    expect($response->status())->toBe(201)
        ->and($response->json('source'))->toBe('local');
});

it('uses local-only and shared-only responses in the same activation', function () {
    ($this->storeReplay)($this->storage->getTestDirectory(), 'local-operation.json', 201, 'local');
    ($this->storeReplay)($this->storage->getSharedDirectory('defaults'), 'shared-operation.json', 202, 'shared');

    (new ReplayBuilder($this->storage))->fallbackTo('defaults')->bail();

    $local = Http::withAttributes(['replay' => 'local-operation'])
        ->get('https://api.example.com/local');
    $shared = Http::withAttributes(['replay' => 'shared-operation'])
        ->get('https://api.example.com/shared');

    expect($local->json('source'))->toBe('local')
        ->and($shared->json('source'))->toBe('shared');
});

it('keeps shared fallback order after the test-local source', function () {
    ($this->storeReplay)($this->storage->getSharedDirectory('project'), 'operation.json', 201, 'project');
    ($this->storeReplay)($this->storage->getSharedDirectory('package'), 'operation.json', 202, 'package');

    (new ReplayBuilder($this->storage))->fallbackTo('project', 'package')->bail();

    $response = Http::withAttributes(['replay' => 'operation'])
        ->get('https://api.example.com/operation');

    expect($response->json('source'))->toBe('project');
});

it('lets one source own the complete response queue', function () {
    ($this->storeReplay)($this->storage->getTestDirectory(), 'operation.json', 201, 'local-1');
    ($this->storeReplay)($this->storage->getTestDirectory(), 'operation__2.json', 202, 'local-2');
    ($this->storeReplay)($this->storage->getSharedDirectory('defaults'), 'operation.json', 203, 'shared-1');
    ($this->storeReplay)($this->storage->getSharedDirectory('defaults'), 'operation__2.json', 204, 'shared-2');
    ($this->storeReplay)($this->storage->getSharedDirectory('defaults'), 'operation__3.json', 205, 'shared-3');

    (new ReplayBuilder($this->storage))->fallbackTo('defaults')->bail();

    $first = Http::withAttributes(['replay' => 'operation'])
        ->get('https://api.example.com/operation');
    $second = Http::withAttributes(['replay' => 'operation'])
        ->get('https://api.example.com/operation');

    expect([$first->status(), $second->status()])->toBe([201, 202]);

    Http::withAttributes(['replay' => 'operation'])
        ->get('https://api.example.com/operation');
})->throws(ReplayBailException::class);

it('loads a response from the default save directory on the next activation', function () {
    $firstBuilder = new ReplayBuilder($this->storage);
    $firstBuilder->fallbackTo('defaults');

    $reflection = new ReflectionClass($firstBuilder);
    $reflection->getMethod('initialize')->invoke($firstBuilder);
    $saveDirectory = $reflection->getProperty('saveDirectory')->getValue($firstBuilder);

    ($this->storeReplay)($saveDirectory, 'recorded-locally.json', 201, 'recorded');

    $request = new Request(new PsrRequest('GET', 'https://api.example.com/recorded'));
    $request->setRequestAttributes(['replay' => 'recorded-locally']);

    $secondBuilder = (new ReplayBuilder($this->storage))->fallbackTo('defaults')->bail();
    $promise = (new ReflectionClass($secondBuilder))
        ->getMethod('handleRequest')
        ->invoke($secondBuilder, $request);
    $response = $promise->wait();

    expect(json_decode((string) $response->getBody(), true)['source'])->toBe('recorded');
});

it('fresh deletes only test-local responses and preserves shared fallbacks', function () {
    $localDirectory = $this->storage->getTestDirectory();
    $sharedDirectory = $this->storage->getSharedDirectory('defaults');

    ($this->storeReplay)($localDirectory, 'operation.json', 201, 'local');
    ($this->storeReplay)($sharedDirectory, 'operation.json', 202, 'shared');

    (new ReplayBuilder($this->storage))->fallbackTo('defaults')->fresh()->bail();

    $response = Http::withAttributes(['replay' => 'operation'])
        ->get('https://api.example.com/operation');

    expect(File::isDirectory($localDirectory))->toBeFalse()
        ->and(File::exists($sharedDirectory.'/operation.json'))->toBeTrue()
        ->and($response->json('source'))->toBe('shared');
});

it('falls back when the complete test-local queue is expired', function () {
    ($this->storeReplay)(
        $this->storage->getTestDirectory(),
        'operation.json',
        201,
        'expired-local',
        now()->subDays(30)->toIso8601String(),
    );
    ($this->storeReplay)(
        $this->storage->getSharedDirectory('defaults'),
        'operation.json',
        202,
        'fresh-shared',
    );

    (new ReplayBuilder($this->storage))->fallbackTo('defaults')->expireAfter(days: 7)->bail();

    $response = Http::withAttributes(['replay' => 'operation'])
        ->get('https://api.example.com/operation');

    expect($response->json('source'))->toBe('fresh-shared');
});

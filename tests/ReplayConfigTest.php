<?php

use EYOND\LaravelHttpReplay\Facades\Replay;
use EYOND\LaravelHttpReplay\ReplayBuilder;
use EYOND\LaravelHttpReplay\ReplayConfig;
use EYOND\LaravelHttpReplay\ReplayMode;
use EYOND\LaravelHttpReplay\ReplayStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/http-replays-config-'.uniqid();
    $this->storage = new ReplayStorage($this->tempDir);
});

afterEach(function () {
    if (File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

it('returns a ReplayConfig from Replay::configure()', function () {
    $config = Replay::configure();

    expect($config)->toBeInstanceOf(ReplayConfig::class);
});

it('returns the same ReplayConfig instance on repeated calls', function () {
    $config1 = Replay::configure();
    $config2 = Replay::configure();

    expect($config1)->toBe($config2);
});

it('stores per-pattern matchBy without registering a fake callback', function () {
    Replay::configure()
        ->for('myshopify.com/*')->matchBy('url', 'attribute:request_name');

    $config = Replay::getConfig();

    expect($config)->toBeInstanceOf(ReplayConfig::class);
    expect($config->getPerPatternMatchBy())->toHaveKey('myshopify.com/*');
    expect($config->getMatchByFields())->toBeNull();

    // No fake callback was registered — Http should still work normally
    expect(Http::recorded())->toBeEmpty();
});

it('stores global matchBy on config', function () {
    Replay::configure()->matchBy('method', 'url', 'body_hash');

    $config = Replay::getConfig();

    expect($config->getMatchByFields())->toBe(['method', 'url', 'body_hash']);
});

it('stores global response header configuration', function () {
    $config = Replay::configure()->withResponseHeaders('Content-Type', 'x-goog-hash');

    expect($config->getResponseHeaders())->toBe(['Content-Type', 'x-goog-hash']);

    $config->withoutResponseHeaders();

    expect($config->getResponseHeaders())->toBeFalse();
});

it('stores global replay mode configuration', function () {
    $config = Replay::configure()->reusable();

    expect($config->getMode())->toBe(ReplayMode::Reusable)
        ->and($config->sequential()->getMode())->toBe(ReplayMode::Sequential);
});

it('stores replay modes per URL pattern', function () {
    $config = Replay::configure()
        ->for('akeneo.example.com/products/*')->reusable()
        ->for('api.example.com/orders/*')->sequential();

    expect($config->getPerPatternModes())->toBe([
        'akeneo.example.com/products/*' => ReplayMode::Reusable,
        'api.example.com/orders/*' => ReplayMode::Sequential,
    ]);
});

it('uses sequential as the project default replay mode', function () {
    $builder = new ReplayBuilder($this->storage);

    expect((new ReflectionClass($builder))->getProperty('mode')->getValue($builder))
        ->toBe(ReplayMode::Sequential);
});

it('inherits the project replay mode', function () {
    config()->set('http-replay.mode', 'reusable');
    $builder = new ReplayBuilder($this->storage);

    expect((new ReflectionClass($builder))->getProperty('mode')->getValue($builder))
        ->toBe(ReplayMode::Reusable);
});

it('lets global replay configuration override the project default', function () {
    config()->set('http-replay.mode', 'reusable');
    Replay::configure()->sequential();
    $builder = new ReplayBuilder($this->storage);

    expect((new ReflectionClass($builder))->getProperty('mode')->getValue($builder))
        ->toBe(ReplayMode::Sequential);
});

it('lets a local replay mode override global configuration', function () {
    Replay::configure()->reusable();
    $builder = new ReplayBuilder($this->storage);
    $builder->sequential();

    expect((new ReflectionClass($builder))->getProperty('mode')->getValue($builder))
        ->toBe(ReplayMode::Sequential);
});

it('inherits replay modes per URL pattern', function () {
    Replay::configure()->for('akeneo.example.com/products/*')->reusable();
    $builder = new ReplayBuilder($this->storage);

    expect((new ReflectionClass($builder))->getProperty('perPatternModes')->getValue($builder))
        ->toBe(['akeneo.example.com/products/*' => ReplayMode::Reusable]);
});

it('lets a local per-pattern replay mode override global configuration', function () {
    Replay::configure()->for('api.example.com/orders/*')->reusable();
    $builder = new ReplayBuilder($this->storage);
    $builder->for('api.example.com/orders/*')->sequential();

    expect((new ReflectionClass($builder))->getProperty('perPatternModes')->getValue($builder))
        ->toBe(['api.example.com/orders/*' => ReplayMode::Sequential]);
});

it('uses project response header defaults and allows a local override', function () {
    config()->set('http-replay.response_headers', false);

    $builder = new ReplayBuilder($this->storage);
    $reflection = new ReflectionClass($builder);
    $property = $reflection->getProperty('responseHeaders');

    expect($property->getValue($builder))->toBeFalse();

    $builder->withResponseHeaders('Content-Type');

    expect($property->getValue($builder))->toBe(['Content-Type']);
});

it('inherits selected response headers from project configuration', function () {
    config()->set('http-replay.response_headers', ['Content-Type', 'x-goog-hash']);

    $builder = new ReplayBuilder($this->storage);
    $reflection = new ReflectionClass($builder);

    expect($reflection->getProperty('responseHeaders')->getValue($builder))
        ->toBe(['Content-Type', 'x-goog-hash']);
});

it('lets global response header configuration override the project default', function () {
    config()->set('http-replay.response_headers', false);
    Replay::configure()->withResponseHeaders();

    $builder = new ReplayBuilder($this->storage);
    $reflection = new ReflectionClass($builder);

    expect($reflection->getProperty('responseHeaders')->getValue($builder))->toBeTrue();
});

it('lets a local response header override take precedence over global configuration', function () {
    Replay::configure()->withoutResponseHeaders();

    $builder = new ReplayBuilder($this->storage);
    $reflection = new ReflectionClass($builder);
    $property = $reflection->getProperty('responseHeaders');

    expect($property->getValue($builder))->toBeFalse();

    $builder->withResponseHeaders('Content-Length');

    expect($property->getValue($builder))->toBe(['Content-Length']);
});

it('inherits per-pattern matchBy from config in ReplayBuilder', function () {
    Replay::configure()
        ->for('myshopify.com/*')->matchBy('url', 'attribute:request_name');

    $builder = new ReplayBuilder($this->storage);

    $reflection = new ReflectionClass($builder);
    $prop = $reflection->getProperty('perPatternMatchBy');

    expect($prop->getValue($builder))->toHaveKey('myshopify.com/*');
    expect($prop->getValue($builder)['myshopify.com/*'])->toBe(['url', 'attribute:request_name']);
});

it('inherits global matchBy from config in ReplayBuilder', function () {
    Replay::configure()->matchBy('url', 'body_hash');

    $builder = new ReplayBuilder($this->storage);

    $reflection = new ReflectionClass($builder);
    $prop = $reflection->getProperty('matchByFields');

    expect($prop->getValue($builder))->toBe(['url', 'body_hash']);
});

it('uses default matchBy when config has no global matchBy set', function () {
    Replay::configure()
        ->for('myshopify.com/*')->matchBy('url');

    $builder = new ReplayBuilder($this->storage);

    $reflection = new ReflectionClass($builder);
    $prop = $reflection->getProperty('matchByFields');

    expect($prop->getValue($builder))->toBe(['method', 'url']);
});

it('overrides config per-pattern matchBy with builder per-pattern matchBy for same pattern', function () {
    Replay::configure()
        ->for('myshopify.com/*')->matchBy('url', 'attribute:request_name');

    $builder = new ReplayBuilder($this->storage);
    $builder->for('myshopify.com/*')->matchBy('method', 'url');

    $reflection = new ReflectionClass($builder);
    $prop = $reflection->getProperty('perPatternMatchBy');

    expect($prop->getValue($builder)['myshopify.com/*'])->toBe(['method', 'url']);
});

it('merges config per-pattern with builder per-pattern for different patterns', function () {
    Replay::configure()
        ->for('myshopify.com/*')->matchBy('url', 'attribute:request_name');

    $builder = new ReplayBuilder($this->storage);
    $builder->for('reybex.com/*')->matchBy('method', 'url');

    $reflection = new ReflectionClass($builder);
    $prop = $reflection->getProperty('perPatternMatchBy');

    $value = $prop->getValue($builder);
    expect($value)->toHaveCount(2);
    expect($value)->toHaveKey('myshopify.com/*');
    expect($value)->toHaveKey('reybex.com/*');
});

it('overrides config global matchBy with builder matchBy', function () {
    Replay::configure()->matchBy('url', 'body_hash');

    $builder = new ReplayBuilder($this->storage);
    $builder->matchBy('method', 'url');

    $reflection = new ReflectionClass($builder);
    $prop = $reflection->getProperty('matchByFields');

    expect($prop->getValue($builder))->toBe(['method', 'url']);
});

it('does nothing when no config is set', function () {
    $builder = new ReplayBuilder($this->storage);

    $reflection = new ReflectionClass($builder);
    $matchByProp = $reflection->getProperty('matchByFields');
    $perPatternProp = $reflection->getProperty('perPatternMatchBy');

    expect($matchByProp->getValue($builder))->toBe(['method', 'url']);
    expect($perPatternProp->getValue($builder))->toBe([]);
});

it('supports chaining multiple for() calls on config', function () {
    $config = Replay::configure()
        ->for('myshopify.com/*')->matchBy('url', 'attribute:request_name')
        ->for('reybex.com/*')->matchBy('method', 'url');

    expect($config)->toBeInstanceOf(ReplayConfig::class);
    expect($config->getPerPatternMatchBy())->toHaveCount(2);
});

it('resets config between tests via fresh app container', function () {
    // This test simply verifies that getConfig() returns null on a fresh app
    // Testbench recreates the app for each test, so any previous configure() is gone
    expect(Replay::getConfig())->toBeNull();
});

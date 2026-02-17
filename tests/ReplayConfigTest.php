<?php

use EYOND\LaravelHttpReplay\Facades\Replay;
use EYOND\LaravelHttpReplay\ReplayBuilder;
use EYOND\LaravelHttpReplay\ReplayConfig;
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

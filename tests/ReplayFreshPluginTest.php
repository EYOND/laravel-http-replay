<?php

use EYOND\LaravelHttpReplay\Plugins\ReplayFreshPlugin;

beforeEach(function () {
    unset($_SERVER['REPLAY_FRESH'], $_ENV['REPLAY_FRESH']);
});

afterEach(function () {
    unset($_SERVER['REPLAY_FRESH'], $_ENV['REPLAY_FRESH']);
});

it('sets REPLAY_FRESH env when --replay-fresh flag is present', function () {
    $plugin = new ReplayFreshPlugin;

    $arguments = ['vendor/bin/pest', '--replay-fresh', 'tests/'];
    $result = $plugin->handleArguments($arguments);

    expect($result)->toBe(['vendor/bin/pest', 'tests/']);
    expect($_SERVER['REPLAY_FRESH'])->toBe('true');
    expect($_ENV['REPLAY_FRESH'])->toBe('true');
});

it('does nothing when --replay-fresh flag is absent', function () {
    $plugin = new ReplayFreshPlugin;

    $arguments = ['vendor/bin/pest', 'tests/'];
    $result = $plugin->handleArguments($arguments);

    expect($result)->toBe(['vendor/bin/pest', 'tests/']);
    expect($_SERVER['REPLAY_FRESH'] ?? null)->toBeNull();
    expect($_ENV['REPLAY_FRESH'] ?? null)->toBeNull();
});

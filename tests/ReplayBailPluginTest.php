<?php

use EYOND\LaravelHttpReplay\Plugins\ReplayBailPlugin;

beforeEach(function () {
    unset($_SERVER['REPLAY_BAIL'], $_ENV['REPLAY_BAIL']);
});

afterEach(function () {
    unset($_SERVER['REPLAY_BAIL'], $_ENV['REPLAY_BAIL']);
});

it('sets REPLAY_BAIL env when --replay-bail flag is present', function () {
    $plugin = new ReplayBailPlugin;

    $arguments = ['vendor/bin/pest', '--replay-bail', 'tests/'];
    $result = $plugin->handleArguments($arguments);

    expect($result)->toBe(['vendor/bin/pest', 'tests/']);
    expect($_SERVER['REPLAY_BAIL'])->toBe('true');
    expect($_ENV['REPLAY_BAIL'])->toBe('true');
});

it('does nothing when --replay-bail flag is absent', function () {
    $plugin = new ReplayBailPlugin;

    $arguments = ['vendor/bin/pest', 'tests/'];
    $result = $plugin->handleArguments($arguments);

    expect($result)->toBe(['vendor/bin/pest', 'tests/']);
    expect($_SERVER['REPLAY_BAIL'] ?? null)->toBeNull();
    expect($_ENV['REPLAY_BAIL'] ?? null)->toBeNull();
});

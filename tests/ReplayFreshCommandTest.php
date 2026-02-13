<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->replayDir = sys_get_temp_dir().'/http-replays-cmd-'.uniqid();
    config()->set('easy-http-fake.storage_path', $this->replayDir);
});

afterEach(function () {
    if (File::isDirectory($this->replayDir)) {
        File::deleteDirectory($this->replayDir);
    }
});

it('deletes all replay files with no options', function () {
    File::ensureDirectoryExists($this->replayDir.'/Feature/Test');
    File::put($this->replayDir.'/Feature/Test/response.json', '{}');

    $this->artisan('replay:fresh')
        ->assertSuccessful();

    expect(File::isDirectory($this->replayDir))->toBeFalse();
});

it('deletes shared fakes by name', function () {
    $sharedDir = $this->replayDir.'/_shared/shopify';
    File::ensureDirectoryExists($sharedDir);
    File::put($sharedDir.'/GET_products.json', '{}');

    $this->artisan('replay:fresh', ['--shared' => 'shopify'])
        ->assertSuccessful();

    expect(File::isDirectory($sharedDir))->toBeFalse();
});

it('warns when shared directory does not exist', function () {
    $this->artisan('replay:fresh', ['--shared' => 'nonexistent'])
        ->assertSuccessful()
        ->expectsOutputToContain('not found');
});

it('deletes fakes for a specific test file', function () {
    $dir = $this->replayDir.'/Feature/ShopifyTest';
    File::ensureDirectoryExists($dir);
    File::put($dir.'/response.json', '{}');

    $this->artisan('replay:fresh', ['--file' => 'tests/Feature/ShopifyTest.php'])
        ->assertSuccessful();

    expect(File::isDirectory($dir))->toBeFalse();
});

it('deletes fakes matching a URL pattern', function () {
    $dir = $this->replayDir.'/test';
    File::ensureDirectoryExists($dir);

    File::put($dir.'/shopify.json', json_encode([
        'request' => ['url' => 'https://shopify.com/api/products'],
    ]));

    File::put($dir.'/stripe.json', json_encode([
        'request' => ['url' => 'https://api.stripe.com/charges'],
    ]));

    $this->artisan('replay:fresh', ['--url' => 'shopify.com/*'])
        ->assertSuccessful();

    expect(File::exists($dir.'/shopify.json'))->toBeFalse();
    expect(File::exists($dir.'/stripe.json'))->toBeTrue();
});

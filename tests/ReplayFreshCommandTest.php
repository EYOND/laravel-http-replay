<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->replayDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'http-replays-cmd-'.uniqid();
    config()->set('http-replay.storage_path', $this->replayDir);
});

afterEach(function () {
    if (File::isDirectory($this->replayDir)) {
        File::deleteDirectory($this->replayDir);
    }
});

it('deletes all replay files with no options', function () {
    File::ensureDirectoryExists($this->replayDir.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'Test');
    File::put($this->replayDir.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'Test'.DIRECTORY_SEPARATOR.'response.json', '{}');

    $this->artisan('replay:prune')
        ->assertSuccessful();

    expect(File::isDirectory($this->replayDir))->toBeFalse();
});

it('deletes shared fakes by name', function () {
    $sharedDir = $this->replayDir.DIRECTORY_SEPARATOR.'_shared'.DIRECTORY_SEPARATOR.'shopify';
    File::ensureDirectoryExists($sharedDir);
    File::put($sharedDir.DIRECTORY_SEPARATOR.'GET_products.json', '{}');

    $this->artisan('replay:prune', ['--shared' => 'shopify'])
        ->assertSuccessful();

    expect(File::isDirectory($sharedDir))->toBeFalse();
});

it('warns when shared directory does not exist', function () {
    $this->artisan('replay:prune', ['--shared' => 'nonexistent'])
        ->assertSuccessful()
        ->expectsOutputToContain('not found');
});

it('deletes fakes for a specific test file', function () {
    $dir = $this->replayDir.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'ShopifyTest';
    File::ensureDirectoryExists($dir);
    File::put($dir.DIRECTORY_SEPARATOR.'response.json', '{}');

    $this->artisan('replay:prune', ['--file' => 'tests/Feature/ShopifyTest.php'])
        ->assertSuccessful();

    expect(File::isDirectory($dir))->toBeFalse();
});

it('deletes fakes matching a URL pattern', function () {
    $dir = $this->replayDir.DIRECTORY_SEPARATOR.'test';
    File::ensureDirectoryExists($dir);

    File::put($dir.DIRECTORY_SEPARATOR.'shopify.json', json_encode([
        'request' => ['url' => 'https://shopify.com/api/products'],
    ]));

    File::put($dir.DIRECTORY_SEPARATOR.'stripe.json', json_encode([
        'request' => ['url' => 'https://api.stripe.com/charges'],
    ]));

    $this->artisan('replay:prune', ['--url' => 'shopify.com/*'])
        ->assertSuccessful();

    expect(File::exists($dir.DIRECTORY_SEPARATOR.'shopify.json'))->toBeFalse();
    expect(File::exists($dir.DIRECTORY_SEPARATOR.'stripe.json'))->toBeTrue();
});

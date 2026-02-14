<?php

/**
 * End-to-end integration tests for Http::replay().
 *
 * These tests use jsonplaceholder.typicode.com as a real HTTP endpoint.
 * On the first run they make real HTTP calls and store the responses.
 * On subsequent runs they replay the stored responses (no network needed).
 *
 * The stored fakes in tests/.laravel-http-replay/Integration/ are committed to the repo.
 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

// ---------------------------------------------------------------------------
//  Basic record & replay
// ---------------------------------------------------------------------------

it('records and replays a simple GET request', function () {
    Http::replay();

    $response = Http::get('https://jsonplaceholder.typicode.com/posts/1');

    expect($response->status())->toBe(200);
    expect($response->json('id'))->toBe(1);
    expect($response->json('userId'))->toBe(1);
    expect($response->json('title'))->toBeString();
});

it('records and replays a POST request', function () {
    Http::replay();

    $response = Http::post('https://jsonplaceholder.typicode.com/posts', [
        'title' => 'foo',
        'body' => 'bar',
        'userId' => 1,
    ]);

    expect($response->status())->toBe(201);
    expect($response->json('title'))->toBe('foo');
    expect($response->json('body'))->toBe('bar');
});

it('records and replays multiple different URLs', function () {
    Http::replay();

    $post1 = Http::get('https://jsonplaceholder.typicode.com/posts/1');
    $post2 = Http::get('https://jsonplaceholder.typicode.com/posts/2');
    $user = Http::get('https://jsonplaceholder.typicode.com/users/1');

    expect($post1->json('id'))->toBe(1);
    expect($post2->json('id'))->toBe(2);
    expect($user->json('id'))->toBe(1);
    expect($user->json('email'))->toBeString();
});

// ---------------------------------------------------------------------------
//  withAttributes disambiguation
// ---------------------------------------------------------------------------

it('disambiguates same-URL requests via withAttributes', function () {
    Http::replay();

    $post1 = Http::withAttributes(['replay' => 'first_post'])
        ->get('https://jsonplaceholder.typicode.com/posts/1');

    $post2 = Http::withAttributes(['replay' => 'second_post'])
        ->get('https://jsonplaceholder.typicode.com/posts/2');

    expect($post1->json('id'))->toBe(1);
    expect($post2->json('id'))->toBe(2);

    // Verify the files are named after the attributes
    $dir = (new \Pikant\LaravelHttpReplay\ReplayStorage)->getTestDirectory();
    expect(File::exists($dir.'/first_post.json'))->toBeTrue();
    expect(File::exists($dir.'/second_post.json'))->toBeTrue();
});

// ---------------------------------------------------------------------------
//  matchBy body hash
// ---------------------------------------------------------------------------

it('disambiguates same-URL requests via matchBy body', function () {
    Http::replay()->matchBy('url', 'body_hash');

    $response1 = Http::post('https://jsonplaceholder.typicode.com/posts', [
        'title' => 'Post A',
        'body' => 'Body A',
        'userId' => 1,
    ]);

    $response2 = Http::post('https://jsonplaceholder.typicode.com/posts', [
        'title' => 'Post B',
        'body' => 'Body B',
        'userId' => 2,
    ]);

    expect($response1->json('title'))->toBe('Post A');
    expect($response2->json('title'))->toBe('Post B');

    // Verify two distinct files with body hashes exist
    $dir = (new \Pikant\LaravelHttpReplay\ReplayStorage)->getTestDirectory();
    $files = collect(File::files($dir))->map->getFilename()->all();

    expect($files)->toHaveCount(2);
    expect($files[0])->not->toBe($files[1]);
});

// ---------------------------------------------------------------------------
//  Shared fakes (storeIn / from)
// ---------------------------------------------------------------------------

it('stores fakes to a shared location with storeIn', function () {
    Http::replay()->storeIn('jsonplaceholder');

    $response = Http::get('https://jsonplaceholder.typicode.com/posts/1');

    expect($response->json('id'))->toBe(1);

    // Verify shared directory was created
    $sharedDir = (new \Pikant\LaravelHttpReplay\ReplayStorage)->getSharedDirectory('jsonplaceholder');
    expect(File::isDirectory($sharedDir))->toBeTrue();
    expect(File::files($sharedDir))->not->toBeEmpty();
});

it('loads fakes from a shared location with from()', function () {
    // This test depends on the previous test having stored shared fakes.
    // If shared fakes don't exist yet, the real HTTP call happens and succeeds.
    Http::replay()->from('jsonplaceholder');

    $response = Http::get('https://jsonplaceholder.typicode.com/posts/1');

    expect($response->status())->toBe(200);
    expect($response->json('id'))->toBe(1);
});

// ---------------------------------------------------------------------------
//  only() + fake() mix
// ---------------------------------------------------------------------------

it('mixes replay with static fakes using only()', function () {
    Http::replay()
        ->only(['jsonplaceholder.typicode.com/*'])
        ->fake([
            'api.stripe.com/*' => Http::response(['charge' => 'ok'], 200),
        ]);

    // This request is replayed (recorded/served from disk)
    $post = Http::get('https://jsonplaceholder.typicode.com/posts/1');
    expect($post->json('id'))->toBe(1);

    // This request uses the static fake
    $stripe = Http::get('https://api.stripe.com/charges');
    expect($stripe->json('charge'))->toBe('ok');
});

// ---------------------------------------------------------------------------
//  expireAfter
// ---------------------------------------------------------------------------

it('replays non-expired responses with expireAfter', function () {
    Http::replay()->expireAfter(days: 365);

    $response = Http::get('https://jsonplaceholder.typicode.com/posts/1');

    expect($response->status())->toBe(200);
    expect($response->json('id'))->toBe(1);
});

// ---------------------------------------------------------------------------
//  fresh() re-recording
// ---------------------------------------------------------------------------

it('re-records when fresh() is used', function () {
    // Use a dedicated shared location so we don't destroy other tests' fakes
    $storage = new \Pikant\LaravelHttpReplay\ReplayStorage;
    $freshDir = $storage->getSharedDirectory('fresh-test');

    // Pre-populate with a stale fake
    File::ensureDirectoryExists($freshDir);
    File::put($freshDir.'/GET_jsonplaceholder_typicode_com_posts_3.json', json_encode([
        'status' => 200,
        'headers' => ['Content-Type' => ['application/json']],
        'body' => ['id' => 3, 'title' => 'STALE DATA'],
        'recorded_at' => now()->subDays(30)->toIso8601String(),
        'request' => [
            'method' => 'GET',
            'url' => 'https://jsonplaceholder.typicode.com/posts/3',
            'attributes' => [],
        ],
    ]));

    // fresh() should delete the old fake and re-record
    Http::replay()->storeIn('fresh-test')->fresh();

    $response = Http::get('https://jsonplaceholder.typicode.com/posts/3');

    expect($response->status())->toBe(200);
    expect($response->json('id'))->toBe(3);
    // After fresh(), the stale title should be gone — the response is real or re-recorded
    expect($response->json('title'))->not->toBe('STALE DATA');

    // Clean up so re-recorded files don't cause CI diffs
    File::deleteDirectory($freshDir);
});

// ---------------------------------------------------------------------------
//  Verify stored files format
// ---------------------------------------------------------------------------

it('stores responses in the expected JSON format', function () {
    Http::replay();

    Http::get('https://jsonplaceholder.typicode.com/posts/1');

    $dir = (new \Pikant\LaravelHttpReplay\ReplayStorage)->getTestDirectory();
    $files = File::files($dir);

    expect($files)->not->toBeEmpty();

    $content = json_decode(File::get($files[0]->getPathname()), true);

    expect($content)->toHaveKey('status');
    expect($content)->toHaveKey('headers');
    expect($content)->toHaveKey('body');
    expect($content)->toHaveKey('recorded_at');
    expect($content)->toHaveKey('request');
    expect($content['request'])->toHaveKey('method');
    expect($content['request'])->toHaveKey('url');
    expect($content['request'])->toHaveKey('attributes');
});

// ---------------------------------------------------------------------------
//  preventStrayRequests() compatibility
// ---------------------------------------------------------------------------

describe('preventStrayRequests', function () {
    it('replays from shared without making real requests', function () {
        Http::preventStrayRequests();
        Http::replay()->from('jsonplaceholder');

        $response = Http::get('https://jsonplaceholder.typicode.com/posts/1');

        expect($response->status())->toBe(200);
        expect($response->json('id'))->toBe(1);
    });

    it('throws when shared fakes do not exist and stray requests are prevented', function () {
        Http::replay()->from('non-existent-shared');
        Http::preventStrayRequests();

        Http::get('https://jsonplaceholder.typicode.com/posts/1');
    })->throws(\Illuminate\Http\Client\StrayRequestException::class);

    it('throws when storeIn needs real requests but stray requests are prevented', function () {
        Http::replay()->storeIn('new-feature');
        Http::preventStrayRequests();

        Http::get('https://jsonplaceholder.typicode.com/posts/1');
    })->throws(\Illuminate\Http\Client\StrayRequestException::class);
});

// ---------------------------------------------------------------------------
//  beforeEach pattern
// ---------------------------------------------------------------------------

describe('using replay in beforeEach', function () {
    beforeEach(function () {
        Http::replay()->from('jsonplaceholder');
    });

    it('serves shared fakes in test one', function () {
        $response = Http::get('https://jsonplaceholder.typicode.com/posts/1');
        expect($response->status())->toBe(200);
    });

    it('serves shared fakes in test two', function () {
        $response = Http::get('https://jsonplaceholder.typicode.com/posts/1');
        expect($response->json('id'))->toBe(1);
    });
});

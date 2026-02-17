<?php

// config for EYOND/LaravelHttpReplay
return [

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | The directory where HTTP replay files are stored.
    | Relative paths are resolved from base_path() (your project root).
    | Absolute paths (starting with /) are used as-is.
    |
    */
    'storage_path' => 'tests/.laravel-http-replay',

    /*
    |--------------------------------------------------------------------------
    | Default Match By
    |--------------------------------------------------------------------------
    |
    | The default matchers used to generate filenames from requests.
    | Supported: 'method' (alias: 'http_method'), 'url', 'host', 'domain',
    |            'subdomain', 'path', 'attribute:key' (alias: 'http_attribute:key'),
    |            'body_hash', 'body_hash:key1,key2', 'body_field:path',
    |            'query_hash', 'query_hash:key1,key2', 'query:key',
    |            'header:key'
    |
    */
    'match_by' => ['method', 'url'],

    /*
    |--------------------------------------------------------------------------
    | Expire After (Days)
    |--------------------------------------------------------------------------
    |
    | Automatically expire stored responses after the given number of days.
    | Set to null to never expire responses automatically.
    |
    */
    'expire_after' => null,

    /*
    |--------------------------------------------------------------------------
    | Fresh Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, all stored replays are deleted and re-recorded.
    | In your app config, use: 'fresh' => env('REPLAY_FRESH', false)
    |
    */
    'fresh' => false,

    /*
    |--------------------------------------------------------------------------
    | Bail Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, tests will fail if Replay attempts to write a new file.
    | Useful in CI to ensure all replays are committed.
    | In your app config, use: 'bail' => env('REPLAY_BAIL', false)
    |
    */
    'bail' => false,

];

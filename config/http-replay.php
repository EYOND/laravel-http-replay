<?php

// config for Pikant/LaravelHttpReplay
return [

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | The directory where HTTP replay files are stored. Relative paths are
    | resolved from the project root (base_path).
    |
    */
    'storage_path' => 'tests/.laravel-http-replay',

    /*
    |--------------------------------------------------------------------------
    | Default Match By
    |--------------------------------------------------------------------------
    |
    | The default matchers used to generate filenames from requests.
    | Supported: 'http_method', 'url', 'host', 'subdomain',
    |            'http_attribute:key', 'body_hash', 'body_hash:key1,key2'
    |
    */
    'match_by' => ['http_method', 'url'],

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

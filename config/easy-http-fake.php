<?php

// config for Pikant/LaravelEasyHttpFake
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
    'storage_path' => 'tests/.http-replays',

    /*
    |--------------------------------------------------------------------------
    | Default Match By
    |--------------------------------------------------------------------------
    |
    | The default fields used to match requests to stored responses.
    | Supported values: 'url', 'method', 'body'
    |
    */
    'match_by' => ['url', 'method'],

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

];

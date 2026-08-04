<?php

use EYOND\LaravelHttpReplay\ReplayBuilder;
use EYOND\LaravelHttpReplay\ReplayNamer;
use EYOND\LaravelHttpReplay\ReplayStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    $this->namer = new ReplayNamer;
});

it('keeps legacy matcher filenames exact', function () {
    Http::withBody('{"b":2,"a":1}', 'application/json')->post('https://example.com/graphql');
    Http::withBody('{"variables":{"b":2,"a":1},"query":"query Legacy { shop { id } }"}', 'application/json')
        ->post('https://example.com/graphql');
    Http::get('https://example.com/api?page=2&limit=10');
    Http::withAttributes(['replay' => 'legacy-name'])->get('https://example.com/ignored');

    expect($this->namer->fromRequest(Http::recorded()[0][0], ['url', 'body_hash']))
        ->toBe('example_com_graphql_991596.json')
        ->and($this->namer->fromRequest(Http::recorded()[1][0], ['url', 'body_hash:variables']))
        ->toBe('example_com_graphql_7143da.json')
        ->and($this->namer->fromRequest(Http::recorded()[2][0], ['url', 'query_hash']))
        ->toBe('example_com_api_4c9dc7.json')
        ->and($this->namer->fromRequest(Http::recorded()[3][0], ['method', 'url']))
        ->toBe('legacy-name.json');
});

it('keeps per-pattern matchBy filename selection exact', function () {
    Http::get('https://api.example.com/products');
    $request = Http::recorded()[0][0];

    $builder = new ReplayBuilder(new ReplayStorage(sys_get_temp_dir().'/pattern-matchers-'.uniqid()));
    $builder->for('api.example.com/*')->matchBy('method', 'literal:pattern');

    $method = (new ReflectionClass($builder))->getMethod('resolveMatchBy');
    $matchBy = $method->invoke($builder, $request);

    expect($this->namer->fromRequest($request, $matchBy))->toBe('GET_pattern.json');
});

it('keeps duplicate response files as an ordered queue', function () {
    Http::get('https://api.example.com/products');
    $request = Http::recorded()[0][0];

    $directory = sys_get_temp_dir().'/replay-queue-'.uniqid();
    File::ensureDirectoryExists($directory);

    foreach ([
        'GET_api_example_com_products.json' => 201,
        'GET_api_example_com_products__2.json' => 202,
        'GET_api_example_com_products__3.json' => 203,
    ] as $filename => $status) {
        File::put($directory.'/'.$filename, json_encode([
            'status' => $status,
            'headers' => [],
            'body' => ['status' => $status],
        ]));
    }

    try {
        $builder = new ReplayBuilder(new ReplayStorage($directory));
        $reflection = new ReflectionClass($builder);
        $reflection->getProperty('initialized')->setValue($builder, true);
        $reflection->getProperty('loadDirectories')->setValue($builder, [$directory]);
        $reflection->getProperty('saveDirectory')->setValue($builder, $directory);
        $reflection->getMethod('loadStoredResponses')->invoke($builder);

        $handleRequest = $reflection->getMethod('handleRequest');
        $statuses = [];

        for ($call = 0; $call < 3; $call++) {
            $statuses[] = $handleRequest->invoke($builder, $request)->wait()->getStatusCode();
        }

        expect($statuses)->toBe([201, 202, 203]);
    } finally {
        File::deleteDirectory($directory);
    }
});

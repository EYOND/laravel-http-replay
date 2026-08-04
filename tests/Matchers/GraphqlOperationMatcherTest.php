<?php

use EYOND\LaravelHttpReplay\Matchers\GraphqlOperationMatcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    $this->matcher = new GraphqlOperationMatcher;
});

it('prefers operationName from the JSON body', function () {
    Http::withBody('{"operationName":"SelectedProducts","query":"query OtherProducts { products { id } }"}', 'application/json')
        ->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('SelectedProducts');
});

it('extracts a named query operation', function () {
    Http::withBody('{"query":"query GetProducts($first: Int!) { products(first: $first) { id } }"}', 'application/json')
        ->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('GetProducts');
});

it('extracts a named mutation operation', function () {
    Http::withBody('{"query":"mutation UpdateProduct { productUpdate { id } }"}', 'application/json')
        ->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('UpdateProduct');
});

it('ignores operation-like text in comments and string literals', function () {
    $document = <<<'GRAPHQL'
        # query CommentedOut { ignored }
        query RealOperation { search(query: "mutation NotAnOperation { ignored }") { id } }
        GRAPHQL;

    Http::withBody(json_encode(['query' => $document]), 'application/json')->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('RealOperation');
});

it('uses X-EYOND-Request as a fallback', function () {
    Http::withHeaders(['X-EYOND-Request' => 'InventorySync'])
        ->withBody('{"query":"{ inventoryItems { id } }"}', 'application/json')
        ->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('InventorySync');
});

it('uses deterministic fallbacks for anonymous and invalid input', function () {
    Http::withBody('{"query":"{ shop { name } }"}', 'application/json')->post('https://example.com/graphql');
    Http::withBody('{"query":"not graphql"}', 'application/json')->post('https://example.com/graphql');
    Http::withBody('not-json', 'text/plain')->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('anonymous')
        ->and($this->matcher->resolve(Http::recorded()[1][0]))->toBe('unknown')
        ->and($this->matcher->resolve(Http::recorded()[2][0]))->toBe('unknown');
});

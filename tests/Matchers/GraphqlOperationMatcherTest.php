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
        fragment SearchFragment on Query {
            search(query: "mutation NotAnOperation { ignored }") { id }
        }
        query RealOperation { ...SearchFragment }
        GRAPHQL;

    Http::withBody(json_encode(['query' => $document]), 'application/json')->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('RealOperation');
});

it('ignores unmatched quotes inside comments', function () {
    $document = <<<'GRAPHQL'
        # opening quote: "
        query RealOperation { product(id: "1") { id } }
        GRAPHQL;

    Http::withBody(json_encode(['query' => $document]), 'application/json')->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('RealOperation');
});

it('does not mistake operation keywords used as fragment names for operations', function (string $fragmentName) {
    $document = <<<GRAPHQL
        fragment {$fragmentName} on Product { id }
        query GetProduct { product { ...{$fragmentName} } }
        GRAPHQL;

    Http::withBody(json_encode(['query' => $document]), 'application/json')->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('GetProduct');
})->with(['query', 'mutation', 'subscription']);

it('recognizes an anonymous operation with a directive', function () {
    $document = 'query @inContext(country: DE) { shop { name } }';

    Http::withBody(json_encode(['query' => $document]), 'application/json')->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('anonymous');
});

it('recognizes an anonymous operation with a directive after a fragment', function () {
    $document = <<<'GRAPHQL'
        fragment Fields on Query { shop { name } }
        query @inContext(country: DE) { ...Fields }
        GRAPHQL;

    Http::withBody(json_encode(['query' => $document]), 'application/json')->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('anonymous');
});

it('ignores operation-like text after escaped triple quotes in block strings', function () {
    $document = <<<'GRAPHQL'
        {
            search(query: """
                \""" query Leaked { id }
            """)
        }
        GRAPHQL;

    Http::withBody(json_encode(['query' => $document]), 'application/json')->post('https://example.com/graphql');

    expect($this->matcher->resolve(Http::recorded()[0][0]))->toBe('anonymous');
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

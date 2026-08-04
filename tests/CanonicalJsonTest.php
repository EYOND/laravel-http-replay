<?php

use EYOND\LaravelHttpReplay\CanonicalJson;

it('sorts object keys recursively while preserving list order', function () {
    $canonicalJson = new CanonicalJson;

    $json = '{"z":2,"nested":{"z":2,"a":1},"list":[{"z":2,"a":1},2,1],"a":1}';

    expect($canonicalJson->canonicalize($json))
        ->toBe('{"a":1,"list":[{"a":1,"z":2},2,1],"nested":{"a":1,"z":2},"z":2}');
});

it('preserves scalar types and floating point fractions', function () {
    $canonicalJson = new CanonicalJson;

    expect($canonicalJson->canonicalize('{"string":"1","integer":1,"float":1.0,"boolean":true,"null":null}'))
        ->toBe('{"boolean":true,"float":1.0,"integer":1,"null":null,"string":"1"}');
});

it('preserves empty objects and empty lists', function () {
    $canonicalJson = new CanonicalJson;

    expect($canonicalJson->canonicalize('{"object":{},"list":[]}'))
        ->toBe('{"list":[],"object":{}}');
});

it('throws for invalid JSON', function () {
    (new CanonicalJson)->canonicalize('not-json');
})->throws(JsonException::class);

it('throws before a large integer can lose precision', function () {
    (new CanonicalJson)->canonicalize('{"id":9223372036854775808}');
})->throws(JsonException::class, 'JSON contains an integer outside the PHP integer range.');

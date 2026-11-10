---
name: http-replay-testing
description: Records and replays HTTP responses in Laravel/Pest tests using Http::replay(). Handles sequential versus reusable replay modes, test setup, shared fakes, matchers for filename generation, GraphQL disambiguation, renewal, and CI bail mode. Use when writing or modifying Pest tests that make HTTP calls, setting up Http::replay(), or configuring replay modes, matchers, and shared fakes.
---

# Http Replay Testing

## When to use this skill

Use this skill when:
- Writing Pest tests that make real HTTP calls and need recording/replaying
- Setting up `Http::replay()` with matchers, shared fakes, or expiry
- Choosing sequential or reusable behavior for repeated requests
- Disambiguating same-URL requests (GraphQL, webhooks)
- Configuring CI bail mode or renewal strategies

## Quick start

```php
it('fetches products', function () {
    Http::replay();

    $response = Http::get('https://api.example.com/products');

    expect($response->json('products'))->toHaveCount(10);
});
```

First run: real HTTP call, response saved to `tests/.laravel-http-replay/{TestFile}/{test_name}/`.
Subsequent runs: stored response served, no network.

## Choose the replay mode first

Replay mode controls repeated requests with the same replay key. Always make this choice deliberately when a test can send the same request more than once. Matchers determine the replay key; the mode determines how responses for that key are consumed.

### Sequential mode (current default)

Use `sequential()` when the same request can return changing state, including reads before and after a mutation, polling, and stateful retries. It records a base file followed by `__2`, `__3`, and consumes one response per call. If a test sends more requests than its queue holds, Replay records each additional response under the next free number and never overwrites a response that it loaded from the directory it writes to. With bail active, the test fails instead.

```php
Http::replay()
    ->for('api.example.com/orders/*')->sequential();

Http::get('https://api.example.com/orders/1');
Http::patch('https://api.example.com/orders/1', ['status' => 'fulfilled']);
Http::get('https://api.example.com/orders/1');
```

Sequential remains the package default for backward compatibility.

### Reusable mode

Use `reusable()` only when every request with the same replay key must receive the same response. It records one fixture and serves it any number of times.

```php
Http::replay()
    ->for('akeneo.example.com/products/*')->reusable();
```

During the initial recording run, every request occurrence still reaches the upstream service so Replay can verify that all responses are equivalent; unlimited reuse starts on subsequent runs. If reusable recording or existing numbered fixtures contain different statuses, stored headers, bodies, or body encodings, Replay throws `ReusableResponseMismatchException`. Recording metadata such as `recorded_at` is ignored and does not trigger a mismatch. A partial fixture created by that recording is removed before failure, including when further concurrent responses arrive. Switch that pattern to `sequential()` or use distinct matchers. Never use reusable mode merely to suppress fixtures for a stateful endpoint.

Modes can be global for one activation, inherited from `Replay::configure()`, or overridden per URL pattern:

```php
Http::replay()
    ->reusable()
    ->for('api.example.com/orders/*')->sequential();

Replay::configure()
    ->reusable()
    ->for('api.example.com/orders/*')->sequential();
```

## Fluent builder API

`Http::replay()` returns a `ReplayBuilder`. All methods are chainable.

```php
Http::replay()
    ->sequential()                                  // Current default: ordered response queue
    ->matchBy('method', 'url', 'body_hash')       // Filename matchers
    ->withResponseHeaders('Content-Type')          // Store selected response headers
    ->for('graphql.com/*')->matchBy('url', 'attribute:operation')  // Per-URL matchers
    ->only(['shopify.com/*'])                       // Limit which URLs are recorded
    ->alsoFake(['stripe.com/*' => Http::response(['ok' => true])])  // Static fakes
    ->readFrom('shopify')                           // Read from shared dir(s)
    ->fallbackTo('project', 'defaults')             // Test-local, then shared fallbacks
    ->writeTo('shopify-v2')                         // Write to shared dir
    ->useShared('shopify')                          // Read + write same shared dir
    ->fresh()                                       // Delete and re-record
    ->bail()                                        // Fail if no stored response
    ->expireAfter(days: 7);                         // Auto-expire after N days
```

## Matchers (matchBy)

Control how filenames are generated from requests:

| Matcher | Example output |
|---------|----------------|
| `'method'` | `GET` |
| `'url'` | `api_example_com_products` |
| `'host'` | `api_example_com` |
| `'domain'` | `myshopify_com` (host without subdomain) |
| `'subdomain'` | `shop` |
| `'path'` | `api/v1/products` (path without host) |
| `'attribute:key'` | Value from `withAttributes(['key' => 'value'])` |
| `'body_hash'` | `a1b2c3` (hash of entire body) |
| `'body_hash:query,variables.id'` | Hash of specific body fields |
| `'canonical_body_hash'` | 6-character hash of the canonical complete JSON body |
| `'canonical_body_hash:variables,extensions.context'` | Canonical hash of selected dot-notation paths |
| `'graphql_operation'` | `operationName`, named operation, `X-EYOND-Request`, `anonymous`, or `unknown` |
| `'literal:shopify'` | Fixed literal filename part |
| `'body_field:path'` | Value of JSON body field (dot notation) |
| `'query_hash'` | `a1b2c3` (hash of all query params) |
| `'query_hash:page,limit'` | Hash of specific query params |
| `'query:key'` | Value of a specific query parameter |
| `'header:key'` | Value of a specific request header |
| `fn(Request $r) => ...` | Closure returning `string`, `int`, `array`, or `Collection` |

Default: `['method', 'url']`. Aliases: `'http_method'`, `'http_attribute:key'`.

Legacy `body_hash`, keyed `body_hash`, and `query_hash` remain non-canonical for filename compatibility. Invalid JSON and JSON integers outside PHP's integer range passed to `canonical_body_hash` fall back to hashing the raw body, avoiding precision loss for large identifiers. For selected paths, missing paths are omitted while explicit `null` values are retained. Matcher objects are also accepted:

```php
use EYOND\LaravelHttpReplay\Matchers;
use EYOND\LaravelHttpReplay\Matchers\CanonicalBodyHashMatcher;
use EYOND\LaravelHttpReplay\Matchers\GraphqlOperationMatcher;
use EYOND\LaravelHttpReplay\Matchers\LiteralMatcher;

Http::replay()->matchBy(
    Matchers::literal('custom'),
    Matchers::graphqlOperation(),
    Matchers::canonicalBodyHash('variables'),
);

// The concrete matcher classes are also directly usable public API.
Http::replay()->matchBy(
    new LiteralMatcher('custom'),
    new GraphqlOperationMatcher,
    new CanonicalBodyHashMatcher(['variables']),
);
```

## Same-URL disambiguation (GraphQL)

Three approaches:

**1. withAttributes** (`replay` is a reserved key — always takes priority, no `matchBy` needed):
```php
Http::withAttributes(['replay' => 'products'])
    ->post('https://shop.com/graphql', ['query' => '{products{...}}']);
// Stored as: products.json
```

For custom attributes, use `matchBy('attribute:key')`:
```php
Http::replay()->matchBy('method', 'attribute:operation');
Http::withAttributes(['operation' => 'getProducts'])
    ->post('https://shop.com/graphql', ['query' => '{products{...}}']);
```

**2. Body hash** (automatic):
```php
Http::replay()->matchBy('url', 'body_hash');
// Different bodies produce different filenames
```

**3. Closure** (custom logic — may return `string`, `int`, `array`, or `Collection`):
```php
Http::replay()->matchBy(
    'method',
    fn(Request $r) => $r->data()['operationName'] ?? 'unknown',
);
```

## Per-URL configuration

`for()` returns a `ForPatternProxy` for a per-URL matcher or replay mode:

```php
Http::replay()
    ->for('shopify.com/graphql')->matchBy('url', 'body_hash')
    ->for('stripe.com/*')->matchBy('method', 'url')
    ->for('akeneo.com/products/*')->reusable()
    ->for('api.example.com/orders/*')->sequential();
```

## Global configuration (`Replay::configure()`)

Configure matchers globally (e.g. in `tests/Pest.php`) without activating replay:

```php
use EYOND\LaravelHttpReplay\Facades\Replay;

Replay::configure()
    ->withoutResponseHeaders()
    ->for('myshopify.com/*')->matchBy('url', 'attribute:request_name')
    ->for('reybex.com/*')->matchBy('method', 'url');
```

`Http::replay()` in each test inherits this config. Per-test overrides take precedence for the same pattern.

## Response headers and binary bodies

Response headers default to all for backward compatibility. Set `response_headers` in `config/http-replay.php` to `true`, `false`, or a case-insensitive allowlist such as `['Content-Type', 'x-goog-hash']`.

```php
Replay::configure()->withoutResponseHeaders();

Http::replay()->withResponseHeaders('Content-Type', 'Content-Length');
Http::replay()->withoutResponseHeaders();
Http::replay()->withResponseHeaders(); // All headers
```

Disabled headers are stored as `"headers": []`. UTF-8 text, XML, and JSON stay readable. Binary or non-UTF-8 bodies use a Base64 `body` plus `"body_encoding": "base64"` and are decoded transparently. Legacy replay files without the marker remain supported; XML is never parsed or reformatted.

### Shopify GraphQL profiles

Profiles are opt-in matcher recipes for `*.myshopify.com/admin/api/*/graphql.json`:

```php
use EYOND\LaravelHttpReplay\Facades\Replay;
use EYOND\LaravelHttpReplay\ShopifyProfile;

Replay::configure()->shopify(); // Semantic: shopify + graphql + operation + canonical variables hash
Replay::configure()->shopify(profile: ShopifyProfile::Strict); // Canonical complete-body hash
```

Semantic filenames look like `shopify_graphql_GetProducts_744ad5.json`. Strict reacts to GraphQL document and variable changes. Both ignore shop subdomain, API version, and JSON object-key order. Build project-specific profiles with `for(ShopifyProfile::URL_PATTERN)->matchBy(...)` and `Matchers`; per-test recipes override global configuration.

## Shared fakes

| Method | Reads from | Writes to |
|--------|-----------|-----------|
| `readFrom('a', 'b')` | shared/a, shared/b (first wins) | test-specific |
| `fallbackTo('a', 'b')` | test-specific, shared/a, shared/b (first wins per queue) | test-specific |
| `writeTo('x')` | test-specific | shared/x |
| `useShared('name')` | shared/name | shared/name |

`fallbackTo()` keeps the current test-specific directory as the primary source and
uses the named shared directories in order. One source owns the complete `__2`,
`__3`, and later response queue; queues are never merged across sources. `writeTo()`
can still select a different destination.

Load a single shared fake for `Http::fake()`:
```php
use EYOND\LaravelHttpReplay\Facades\Replay;

Http::fake([
    'foo.com/*' => Replay::getShared('shopify/GET_products.json'),
]);
```

## Renewal

```php
Http::replay()->fresh();                         // Re-record all
Http::replay()->fresh('shopify.com/*');           // Re-record matching URLs
Http::replay()->expireAfter(days: 7);            // Auto-expire after 7 days
Http::replay()->expireAfter(new DateInterval('P1M'));  // Or use DateInterval
Http::replay()->fallbackTo('defaults')->fresh(); // Delete test-local only; preserve shared
```

With `fallbackTo()`, shared fallback directories remain available after `fresh()`.
Expiry is evaluated per source, so a fully expired higher-priority queue can fall
through to the next source.

CLI: `vendor/bin/pest --replay-fresh` or `REPLAY_FRESH=true vendor/bin/pest`.
Artisan: `php artisan replay:prune`.

## Bail mode

Prevents accidental recording. Throws `ReplayBailException` if a test tries to write a new replay file.

```php
// Per-test or in beforeEach
Http::replay()->bail();
```

```bash
# CI-wide via Pest flag or env
vendor/bin/pest --replay-bail
# or: REPLAY_BAIL=true vendor/bin/pest
```

## Mixed recording + static fakes

```php
Http::replay()
    ->only(['shopify.com/*'])
    ->alsoFake([
        'stripe.com/*' => Http::response(['ok' => true]),
        'sentry.io/*' => Http::response([], 200),
    ]);
```

## Configuration

Config file: `config/http-replay.php`

```php
return [
    'storage_path' => 'tests/.laravel-http-replay',  // Relative to base_path()
    'match_by' => ['method', 'url'],
    'mode' => 'sequential',        // 'sequential' or 'reusable'
    'response_headers' => true,    // true, false, or selected header names
    'expire_after' => null,        // Days, or null
    'fresh' => false,              // env('REPLAY_FRESH', false) in app config
    'bail' => false,               // env('REPLAY_BAIL', false) in app config
];
```

## Storage structure

```
tests/.laravel-http-replay/
├── _shared/                    # Shared fakes (useShared/readFrom/fallbackTo/writeTo)
│   └── shopify/
│       └── GET_products.json
└── Feature/ShopifyTest/
    └── it_fetches_products/
        └── GET_api_products.json
```

Sequential requests with the same lookup filename use the queue convention: base filename, then `__2`, `__3`, and so on. Reusable requests store one stable response and replay it repeatedly.

## Key classes

- `EYOND\LaravelHttpReplay\ReplayBuilder` — Fluent builder returned by `Http::replay()`
- `EYOND\LaravelHttpReplay\ReplayConfig` — Config container returned by `Replay::configure()`
- `EYOND\LaravelHttpReplay\Facades\Replay` — Facade for `configure()`, `getShared()`, and config access
- `EYOND\LaravelHttpReplay\ForPatternProxy` — Proxy returned by `for()` for `matchBy()`, `reusable()`, or `sequential()`
- `EYOND\LaravelHttpReplay\ReplayMode` — `Sequential` and `Reusable` replay mode enum
- `EYOND\LaravelHttpReplay\ReplayNamer` — Generates filenames from matchers
- `EYOND\LaravelHttpReplay\Matchers\NameMatcher` — Interface for custom matchers
- `EYOND\LaravelHttpReplay\Matchers` — Factory for reusable matcher objects
- `EYOND\LaravelHttpReplay\CanonicalJson` — Deterministic recursive JSON canonicalization
- `EYOND\LaravelHttpReplay\ShopifyProfile` — Semantic and Strict Shopify matcher recipes

---
name: http-replay-testing
description: Records and replays HTTP responses in Laravel/Pest tests using Http::replay(). Handles test setup with fluent builder API, shared fakes, matchers for filename generation, GraphQL disambiguation, renewal, and CI bail mode. Use when writing or modifying Pest tests that make HTTP calls, setting up Http::replay(), or configuring replay matchers and shared fakes.
---

# Http Replay Testing

## When to use this skill

Use this skill when:
- Writing Pest tests that make real HTTP calls and need recording/replaying
- Setting up `Http::replay()` with matchers, shared fakes, or expiry
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

## Fluent builder API

`Http::replay()` returns a `ReplayBuilder`. All methods are chainable.

```php
Http::replay()
    ->matchBy('method', 'url', 'body_hash')       // Filename matchers
    ->for('graphql.com/*')->matchBy('url', 'attribute:operation')  // Per-URL matchers
    ->only(['shopify.com/*'])                       // Limit which URLs are recorded
    ->alsoFake(['stripe.com/*' => Http::response(['ok' => true])])  // Static fakes
    ->readFrom('shopify')                           // Read from shared dir(s)
    ->writeTo('shopify-v2')                         // Write to shared dir
    ->useShared('shopify')                          // Read + write same shared dir
    ->fresh()                                       // Delete and re-record
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
| `'attribute:key'` | Value from `withAttributes(['key' => 'value'])` |
| `'body_hash'` | `a1b2c3` (hash of entire body) |
| `'body_hash:query,variables.id'` | Hash of specific body fields |
| `fn(Request $r) => [...]` | Custom closure returning filename parts |

Default: `['method', 'url']`. Aliases: `'http_method'`, `'http_attribute:key'`.

## Same-URL disambiguation (GraphQL)

Three approaches:

**1. withAttributes** (explicit naming):
```php
Http::withAttributes(['replay' => 'products'])
    ->post('https://shop.com/graphql', ['query' => '{products{...}}']);
// Stored as: products.json
```

**2. Body hash** (automatic):
```php
Http::replay()->matchBy('url', 'body_hash');
// Different bodies produce different filenames
```

**3. Closure** (custom logic):
```php
Http::replay()->matchBy(
    'method',
    fn(Request $r) => [$r->data()['operationName'] ?? 'unknown'],
);
```

## Per-URL configuration

`for()` returns a `ForPatternProxy` that only exposes `matchBy()`:

```php
Http::replay()
    ->for('shopify.com/graphql')->matchBy('url', 'body_hash')
    ->for('stripe.com/*')->matchBy('method', 'url');
```

## Global configuration (`Replay::configure()`)

Configure matchers globally (e.g. in `tests/Pest.php`) without activating replay:

```php
use EYOND\LaravelHttpReplay\Facades\Replay;

Replay::configure()
    ->for('myshopify.com/*')->matchBy('url', 'attribute:request_name')
    ->for('reybex.com/*')->matchBy('method', 'url');
```

`Http::replay()` in each test inherits this config. Per-test overrides take precedence for the same pattern.

## Shared fakes

| Method | Reads from | Writes to |
|--------|-----------|-----------|
| `readFrom('a', 'b')` | shared/a, shared/b (first wins) | test-specific |
| `writeTo('x')` | test-specific | shared/x |
| `useShared('name')` | shared/name | shared/name |

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
```

CLI: `vendor/bin/pest --replay-fresh` or `REPLAY_FRESH=true vendor/bin/pest`.
Artisan: `php artisan replay:prune`.

## CI bail mode

Prevents accidental recording in CI:

```bash
vendor/bin/pest --replay-bail
# or: REPLAY_BAIL=true vendor/bin/pest
```

Throws `ReplayBailException` if a test tries to write a new replay file.

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
    'expire_after' => null,        // Days, or null
    'fresh' => false,              // env('REPLAY_FRESH', false) in app config
    'bail' => false,               // env('REPLAY_BAIL', false) in app config
];
```

## Storage structure

```
tests/.laravel-http-replay/
├── _shared/                    # Shared fakes (useShared/readFrom/writeTo)
│   └── shopify/
│       └── GET_products.json
└── Feature/ShopifyTest/
    └── it_fetches_products/
        └── GET_api_products.json
```

## Key classes

- `EYOND\LaravelHttpReplay\ReplayBuilder` — Fluent builder returned by `Http::replay()`
- `EYOND\LaravelHttpReplay\ReplayConfig` — Config container returned by `Replay::configure()`
- `EYOND\LaravelHttpReplay\Facades\Replay` — Facade for `configure()`, `getShared()`, and config access
- `EYOND\LaravelHttpReplay\ForPatternProxy` — Proxy returned by `for()`, only exposes `matchBy()`
- `EYOND\LaravelHttpReplay\ReplayNamer` — Generates filenames from matchers
- `EYOND\LaravelHttpReplay\Matchers\NameMatcher` — Interface for custom matchers

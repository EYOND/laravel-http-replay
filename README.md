# Laravel Http Replay

[![Latest Version on Packagist](https://img.shields.io/packagist/v/eyond/laravel-http-replay.svg?style=flat-square)](https://packagist.org/packages/eyond/laravel-http-replay)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/eyond/laravel-http-replay/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/eyond/laravel-http-replay/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/eyond/laravel-http-replay/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/eyond/laravel-http-replay/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/eyond/laravel-http-replay.svg?style=flat-square)](https://packagist.org/packages/eyond/laravel-http-replay)

Record and replay HTTP responses in your Laravel/Pest tests. Like snapshot testing, but for HTTP calls — responses are recorded on the first run and replayed automatically on subsequent runs.

## Installation

```bash
composer require eyond/laravel-http-replay --dev
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="laravel-http-replay-config"
```

## Quick Start

Add `Http::replay()` to your test. The first run makes real HTTP calls and stores the responses. Every subsequent run replays the stored responses — no network needed.

```php
it('fetches products', function () {
    Http::replay();

    $products = app(ShopifyService::class)->getProducts();

    expect($products)->toHaveCount(10);
});
```

Stored responses are saved as JSON in `tests/.laravel-http-replay/`, organized by test file and test name:

```
tests/.laravel-http-replay/
└── Feature/
    └── ShopifyTest/
        └── it_fetches_products/
            └── GET_shopify_com_api_products.json
```

## Usage

### Basic Replay

```php
it('fetches products', function () {
    Http::replay();

    $response = Http::get('https://api.example.com/products');

    expect($response->json('products'))->toHaveCount(10);
});
```

### Same-URL Disambiguation (GraphQL etc.)

When multiple requests go to the same URL (e.g. GraphQL endpoints), you need to disambiguate them. There are several approaches:

#### Via `withAttributes`

Give each request a unique name using Laravel's `withAttributes`:

```php
it('fetches products and orders via GraphQL', function () {
    Http::replay();

    $products = Http::withAttributes(['replay' => 'products'])
        ->post('https://shopify.com/graphql', ['query' => '{products{...}}']);

    $orders = Http::withAttributes(['replay' => 'orders'])
        ->post('https://shopify.com/graphql', ['query' => '{orders{...}}']);
});
```

This stores the responses as `products.json` and `orders.json`.

#### Via `matchBy` with Body Hash

Automatically distinguish requests by including the request body hash in the filename:

```php
it('auto-disambiguates by body', function () {
    Http::replay()->matchBy('url', 'body_hash');

    Http::post('https://shopify.com/graphql', ['query' => '{products{...}}']);
    Http::post('https://shopify.com/graphql', ['query' => '{orders{...}}']);
});
```

#### Via Closure Matcher

Use a closure for custom filename generation:

```php
Http::replay()->matchBy(
    'method',
    fn(Request $r) => [$r->data()['operationName'] ?? 'unknown'],
);
```

### Composable Matchers

The `matchBy()` method accepts any combination of built-in matchers:

| Matcher | Config String | Alias | Example Output |
|---------|--------------|-------|----------------|
| HTTP Method | `method` | `http_method` | `GET` |
| URL (host + path) | `url` | | `shop_myshopify_com_api_products` |
| Host only | `host` | | `shop_myshopify_com` |
| Subdomain | `subdomain` | | `shop` |
| HTTP Attribute | `attribute:key` | `http_attribute:key` | Value of `$request->attributes()['key']` |
| Body Hash | `body_hash` | | `a1b2c3` (6-char hash of entire body) |
| Body Hash (keys) | `body_hash:query,variables.id` | | Hash of specific body fields |
| Closure | `fn(Request $r) => [...]` | | Array of filename parts |

Default: `['method', 'url']`

### Per-URL Configuration

Configure different matchers for different URL patterns:

```php
Http::replay()
    ->for('myshopify.com/*')->matchBy('url', 'attribute:request_name')
    ->for('reybex.com/*')->matchBy('method', 'url');
```

The `for()` method returns a proxy object — you must call `matchBy()` directly on it. This prevents accidental state leaks.

### Shared Fakes

Record responses once and reuse them across multiple tests.

**Record to a shared location (read + write):**

```php
it('records shared shopify fakes', function () {
    Http::replay()->useShared('shopify');

    app(ShopifyService::class)->getProducts();
});
```

**Read from shared, write to test-local:**

```php
it('uses shared shopify fakes', function () {
    Http::replay()->readFrom('shopify');

    $products = app(ShopifyService::class)->getProducts();

    expect($products)->toHaveCount(10);
});
```

**Read from multiple shared locations (first wins):**

```php
Http::replay()->readFrom('shopify', 'shopify-fallback');
```

**Write to shared, read from test-local:**

```php
Http::replay()->writeTo('shopify');
```

**Combine read + write explicitly:**

```php
Http::replay()->readFrom('shopify')->writeTo('shopify-v2');
```

**Use shared fakes for an entire file:**

```php
beforeEach(function () {
    Http::replay()->readFrom('shopify');
});

it('test one', function () {
    // Uses shared shopify fakes
});

it('test two', function () {
    // Uses shared shopify fakes
});
```

| Method | Reads from | Writes to |
|--------|-----------|-----------|
| `readFrom('a', 'b')` | shared/a, shared/b (first wins) | test-specific |
| `writeTo('x')` | test-specific | shared/x |
| `useShared('name')` | shared/name | shared/name |
| `readFrom('a')->writeTo('x')` | shared/a | shared/x |

**Load a single shared fake in `Http::fake()`:**

```php
use EYOND\LaravelHttpReplay\Facades\Replay;

Http::fake([
    'foo.com/posts/*' => Replay::getShared('fresh-test/GET_jsonplaceholder_typicode_com_posts_3.json'),
]);
```

Shared fakes are stored in `tests/.laravel-http-replay/_shared/{name}/`.

### Mix: Recorded + Static Fakes

Combine replay recording with static `Http::fake()` stubs. Use `only()` to limit which URLs are recorded:

```php
it('mixes recorded and static fakes', function () {
    Http::replay()
        ->only(['shopify.com/*'])
        ->alsoFake([
            'api.stripe.com/*' => Http::response(['ok' => true]),
            'sentry.io/*' => Http::response([], 200),
        ]);

    // Shopify calls are recorded/replayed
    $products = Http::get('https://shopify.com/api/products');

    // Stripe and Sentry use static fakes
    $charge = Http::get('https://api.stripe.com/charges');
});
```

### Renewal / Re-Recording

#### Fluent API

```php
// Re-record everything for this test
Http::replay()->fresh();

// Re-record only matching URLs
Http::replay()->fresh('shopify.com/*');

// Auto-expire after 7 days (re-records expired responses)
Http::replay()->expireAfter(days: 7);

// Auto-expire after 1 month (accepts DateInterval)
Http::replay()->expireAfter(new DateInterval('P1M'));

// Re-record shared fakes
Http::replay()->readFrom('shopify')->fresh();
```

#### Artisan Command

```bash
# Delete all stored replays
php artisan replay:prune

# Delete replays for a specific test
php artisan replay:prune --test="it fetches products"

# Delete replays for a specific test file
php artisan replay:prune --file=tests/Feature/ShopifyTest.php

# Delete replays matching a URL pattern
php artisan replay:prune --url="shopify.com/*"

# Delete specific shared fakes
php artisan replay:prune --shared=shopify
```

#### Pest Flag

```bash
# Re-record all fakes
vendor/bin/pest --replay-fresh
```

#### Environment Variable

```bash
REPLAY_FRESH=true vendor/bin/pest
```

Or set it in your app config:

```php
// config/http-replay.php
'fresh' => env('REPLAY_FRESH', false),
```

### Bail on CI

Prevent tests from accidentally recording new fakes in CI by enabling bail mode. When active, tests will **fail** if Replay attempts to write a new file.

```bash
# Pest flag (recommended)
vendor/bin/pest --replay-bail

# Or via environment variable
REPLAY_BAIL=true vendor/bin/pest
```

You can also set it permanently in your config:

```php
// config/http-replay.php
'bail' => env('REPLAY_BAIL', false),
```

### Incomplete Test Marking

When Replay records a new response during a test, the test is automatically marked as **incomplete** (yellow) — just like Pest's snapshot testing. This makes it clear which tests recorded new data and need a re-run to verify.

### Complex Scenario

```php
it('complex shopify sync', function () {
    Http::replay()
        ->only(['shopify.com/*'])
        ->for('shopify.com/graphql')->matchBy('url', 'body_hash')
        ->expireAfter(days: 7)
        ->alsoFake([
            'api.stripe.com/*' => Http::response(['ok' => true]),
        ]);

    $products = Http::withAttributes(['replay' => 'products'])
        ->post('https://shopify.com/graphql', ['query' => '{products{...}}']);

    $charge = Http::get('https://api.stripe.com/charges');

    expect($products->json())->toHaveKey('data.products');
});
```

## File Storage Format

Each stored response is a JSON file containing the response data and metadata:

```json
{
    "status": 200,
    "headers": {
        "Content-Type": ["application/json"]
    },
    "body": {
        "products": []
    },
    "recorded_at": "2026-02-12T14:30:00+00:00",
    "request": {
        "method": "GET",
        "url": "https://shopify.com/api/products",
        "attributes": {}
    }
}
```

### Directory Structure

```
tests/.laravel-http-replay/
├── _shared/                                    # Shared fakes (via useShared/readFrom/writeTo)
│   └── shopify/
│       └── GET_shopify_com_api_products.json
├── Feature/
│   └── ShopifyTest/
│       └── it_fetches_products/                # Auto-named from Pest test
│           ├── GET_shopify_com_api_products.json
│           ├── products.json                   # Via withAttributes(['replay' => 'products'])
│           └── POST_shopify_com_graphql_a1b2c3.json  # Via matchBy('url', 'body_hash')
```

### Filename Conventions

| Scenario | Filename |
|---|---|
| Default | `GET_api_example_com_products.json` |
| `withAttributes(['replay' => 'products'])` | `products.json` |
| `matchBy('url', 'body_hash')` | `shopify_com_graphql_a1b2c3.json` |
| Duplicate URL (sequential calls) | `GET_api_example_com_products__2.json` |

## Configuration

```php
// config/http-replay.php
return [
    // Directory for stored replays
    // Relative paths are resolved from base_path() (your project root)
    // Absolute paths (starting with /) are used as-is
    'storage_path' => 'tests/.laravel-http-replay',

    // Default matchers for filename generation
    // Short forms: 'method', 'attribute:key'
    // Aliases: 'http_method', 'http_attribute:key'
    'match_by' => ['method', 'url'],

    // Auto-expire after N days (null = never)
    'expire_after' => null,

    // Force re-recording of all replays
    'fresh' => false, // Use env('REPLAY_FRESH', false) in your app

    // Fail tests if Replay attempts to write
    'bail' => false, // Use env('REPLAY_BAIL', false) in your app
];
```

## API Reference

### `Http::replay()`

Returns a `ReplayBuilder` instance with the following fluent methods:

| Method | Description |
|---|---|
| `matchBy(string\|Closure ...$fields)` | Matchers for filename generation |
| `for(string $pattern)` | Set URL pattern for per-URL matcher config (returns proxy, must chain `matchBy()`) |
| `only(array $patterns)` | Only record/replay URLs matching these patterns |
| `alsoFake(array $stubs)` | Additional static fakes for non-replayed URLs |
| `readFrom(string ...$names)` | Load stored fakes from shared location(s), first wins |
| `writeTo(string $name)` | Save recorded fakes to a shared location |
| `useShared(string $name)` | Read + write from a shared location |
| `fresh(?string $pattern)` | Delete stored fakes and re-record (optionally filtered by URL pattern) |
| `expireAfter(int\|DateInterval $days)` | Auto-expire stored fakes after N days or a DateInterval |

### `Replay::getShared(string $path)`

Load a single shared replay file for use in `Http::fake()`. Returns a `PromiseInterface`.

### `php artisan replay:prune`

| Option | Description |
|---|---|
| `--test="name"` | Delete fakes for a specific test description |
| `--file=path` | Delete fakes for a specific test file |
| `--url="pattern"` | Delete fakes matching a URL pattern |
| `--shared=name` | Delete shared fakes by name |
| *(no options)* | Delete all stored replays |

## How It Works

This package uses **only public Laravel APIs** — no internal hacks, no monkey-patching, no overriding core classes. Everything is built on top of two official extension points:

1. **`Http::fake()` with a callback** — Laravel's HTTP client supports passing a closure to `Http::fake()`. This closure receives each outgoing request and can return a response or `null` (to allow the real request). Http Replay registers a single callback that checks for stored responses and either serves them or lets the request through.

2. **`ResponseReceived` event** — Laravel dispatches this event after every HTTP response. Http Replay listens for it to capture real responses and save them to disk.

The flow:

```
Http::replay()
    │
    ├─ Registers Http::fake(callback) via Factory::macro()
    └─ Registers ResponseReceived event listener

Request comes in:
    │
    ├─ Stored response exists? → Return it (no network call)
    └─ No stored response? → Return null → Real HTTP call happens
                                                │
                                                └─ ResponseReceived event fires
                                                    → Serialize & store to disk
```

The `Http::replay()` macro itself is registered on `Illuminate\Http\Client\Factory` via Laravel's standard `macro()` method in the service provider. No classes are extended or replaced.

## Requirements

- PHP 8.3+
- Laravel 11 or 12
- Pest PHP 4

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

This package is built with **Vibe Coding** — designed and developed in collaboration with Claude Code. Despite that, the codebase follows strict quality standards: PHPStan level 5, full test coverage across PHP 8.3-8.4 and Laravel 11-12, and consistent code formatting via Pint.

**Bug fixes** — PRs with a failing test and fix are welcome.

**New features** — Please don't submit a traditional code PR. Instead, open an issue or PR that:

1. Describes the problem or use case
2. Includes a **Claude Code prompt** or a **Claude Code plan** (`.md` file) that I can use to implement the feature myself

This keeps the codebase consistent and lets me iterate on the implementation with the same AI-assisted workflow used to build the package.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

This package is an opinionated version of the original idea by [Michael Ruf](https://github.com/michiruf) in [laravel-http-automock](https://github.com/michiruf/laravel-http-automock).

- [Patrick Korber](https://github.com/pikant)
- [Michael Ruf](https://github.com/michiruf) — original idea
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

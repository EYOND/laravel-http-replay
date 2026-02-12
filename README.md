# Laravel Easy HTTP Fake

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pikant/laravel-easy-http-fake.svg?style=flat-square)](https://packagist.org/packages/pikant/laravel-easy-http-fake)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/pikant/laravel-easy-http-fake/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/pikant/laravel-easy-http-fake/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/pikant/laravel-easy-http-fake/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/pikant/laravel-easy-http-fake/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/pikant/laravel-easy-http-fake.svg?style=flat-square)](https://packagist.org/packages/pikant/laravel-easy-http-fake)

Record and replay HTTP responses in your Laravel/Pest tests. Like snapshot testing, but for HTTP calls — responses are recorded on the first run and replayed automatically on subsequent runs.

## Installation

```bash
composer require pikant/laravel-easy-http-fake --dev
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="laravel-easy-http-fake-config"
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

Stored responses are saved as JSON in `tests/.http-replays/`, organized by test file and test name:

```
tests/.http-replays/
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

When multiple requests go to the same URL (e.g. GraphQL endpoints), you need to disambiguate them. There are two approaches:

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

Automatically distinguish requests by including the request body in the filename hash:

```php
it('auto-disambiguates by body', function () {
    Http::replay()->matchBy('url', 'body');

    Http::post('https://shopify.com/graphql', ['query' => '{products{...}}']);
    Http::post('https://shopify.com/graphql', ['query' => '{orders{...}}']);
});
```

This stores responses as `POST_shopify_com_graphql__a1b2c3.json` and `POST_shopify_com_graphql__d4e5f6.json` (with unique body hashes).

### Shared Fakes

Record responses once and reuse them across multiple tests.

**Record to a shared location:**

```php
it('records shared shopify fakes', function () {
    Http::replay()->storeAs('shopify');

    app(ShopifyService::class)->getProducts();
});
```

**Use shared fakes in other tests:**

```php
it('uses shared shopify fakes', function () {
    Http::replay()->from('shopify');

    $products = app(ShopifyService::class)->getProducts();

    expect($products)->toHaveCount(10);
});
```

**Use shared fakes for an entire file:**

```php
beforeEach(function () {
    Http::replay()->from('shopify');
});

it('test one', function () {
    // Uses shared shopify fakes
});

it('test two', function () {
    // Uses shared shopify fakes
});
```

Shared fakes are stored in `tests/.http-replays/_shared/{name}/`.

### Mix: Recorded + Static Fakes

Combine replay recording with static `Http::fake()` stubs. Use `only()` to limit which URLs are recorded:

```php
it('mixes recorded and static fakes', function () {
    Http::replay()
        ->only(['shopify.com/*'])
        ->fake([
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

// Re-record shared fakes
Http::replay()->from('shopify')->fresh();
```

#### Artisan Command

```bash
# Delete all stored replays
php artisan replay:fresh

# Delete replays for a specific test
php artisan replay:fresh --test="it fetches products"

# Delete replays for a specific test file
php artisan replay:fresh --file=tests/Feature/ShopifyTest.php

# Delete replays matching a URL pattern
php artisan replay:fresh --url="shopify.com/*"

# Delete specific shared fakes
php artisan replay:fresh --shared=shopify
```

#### Environment Variable

Set `REPLAY_FRESH` in your app config to re-record all fakes (useful for CI):

```php
// config/easy-http-fake.php
'fresh' => env('REPLAY_FRESH', false),
```

```bash
REPLAY_FRESH=true vendor/bin/pest
```

### Complex Scenario

```php
it('complex shopify sync', function () {
    Http::replay()
        ->only(['shopify.com/*'])
        ->matchBy('url', 'body')
        ->expireAfter(days: 7)
        ->fake([
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
tests/.http-replays/
├── _shared/                                    # Shared fakes (via storeAs/from)
│   └── shopify/
│       └── GET_shopify_com_api_products.json
├── Feature/
│   └── ShopifyTest/
│       └── it_fetches_products/                # Auto-named from Pest test
│           ├── GET_shopify_com_api_products.json
│           ├── products.json                   # Via withAttributes(['replay' => 'products'])
│           └── POST_shopify_com_graphql__a1b2c3.json  # Via matchBy('url', 'body')
```

### Filename Conventions

| Scenario | Filename |
|---|---|
| Default | `GET_api_example_com_products.json` |
| `withAttributes(['replay' => 'products'])` | `products.json` |
| `matchBy('url', 'body')` | `POST_shopify_com_graphql__a1b2c3.json` |
| Duplicate URL (sequential calls) | `GET_api_example_com_products__2.json` |

## Configuration

```php
// config/easy-http-fake.php
return [
    // Directory for stored replays (relative to base_path)
    'storage_path' => 'tests/.http-replays',

    // Default fields for request matching: 'url', 'method', 'body'
    'match_by' => ['url', 'method'],

    // Auto-expire after N days (null = never)
    'expire_after' => null,

    // Force re-recording of all replays
    'fresh' => false, // Use env('REPLAY_FRESH', false) in your app
];
```

## API Reference

### `Http::replay()`

Returns a `ReplayBuilder` instance with the following fluent methods:

| Method | Description |
|---|---|
| `matchBy(string ...$fields)` | Fields for request matching: `'url'`, `'method'`, `'body'` |
| `only(array $patterns)` | Only record/replay URLs matching these patterns |
| `fake(array $stubs)` | Additional static fakes for non-replayed URLs |
| `from(string $name)` | Load stored fakes from a shared location |
| `storeAs(string $name)` | Save recorded fakes to a shared location |
| `fresh(?string $pattern)` | Delete stored fakes and re-record (optionally filtered by URL pattern) |
| `expireAfter(int $days)` | Auto-expire stored fakes after N days |

### `php artisan replay:fresh`

| Option | Description |
|---|---|
| `--test="name"` | Delete fakes for a specific test description |
| `--file=path` | Delete fakes for a specific test file |
| `--url="pattern"` | Delete fakes matching a URL pattern |
| `--shared=name` | Delete shared fakes by name |
| *(no options)* | Delete all stored replays |

## How It Works

1. `Http::replay()` registers a `Http::fake()` callback
2. When an HTTP request is made, the callback checks for a stored response
3. **Stored response found** — returns it immediately (no network call)
4. **No stored response** — returns `null`, allowing the real HTTP call to proceed
5. Real responses are captured via the `ResponseReceived` event and saved to disk
6. On the next test run, the stored response is found in step 3

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

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Patrick Korber](https://github.com/pikant)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

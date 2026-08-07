# Changelog

All notable changes to `laravel-http-replay` will be documented in this file.

## v0.2.3 - 2026-08-07

### What's Changed

* Add test-local replay fallbacks by @pikant in https://github.com/EYOND/laravel-http-replay/pull/9

**Full Changelog**: https://github.com/EYOND/laravel-http-replay/compare/v0.2.2...v0.2.3

## v0.2.2 - 2026-08-07

### What's Changed

* Add configurable response headers and binary-safe bodies by @pikant in https://github.com/EYOND/laravel-http-replay/pull/6

**Full Changelog**: https://github.com/EYOND/laravel-http-replay/compare/v0.2.1...v0.2.2

## v0.2.1 - 2026-08-04

### What is Changed

* Add reusable matchers and Shopify GraphQL profiles by @pikant in https://github.com/EYOND/laravel-http-replay/pull/5

**Full Changelog**: https://github.com/EYOND/laravel-http-replay/compare/v0.2.0...v0.2.1

## Unreleased (compatible with the 0.2 release line)

### Added

- Public reusable matcher objects and the `Matchers` factory, including literals, GraphQL operation names, and canonical JSON body hashes
- `literal:*`, `graphql_operation`, and `canonical_body_hash[:paths]` string shorthands
- Opt-in Semantic and Strict Shopify GraphQL profiles through `Replay::configure()->shopify()`
- Precision-safe raw-body fallback for canonical hashes containing JSON integers outside PHP's integer range
- Configurable response-header storage through `response_headers`, `withResponseHeaders()`, and `withoutResponseHeaders()`
- Lossless Base64 storage and transparent replay for binary and non-UTF-8 response bodies
- Test-local response priority with ordered shared fallbacks through `fallbackTo()`

### Compatibility

- Existing default, legacy matcher filenames, replay schemas without `body_encoding`, reserved `replay` attributes, and repeated-request queue behavior remain unchanged
- Existing `readFrom()`, `writeTo()`, and `useShared()` source-selection behavior remains unchanged
- All response headers remain the package default; disabling them still stores a stable empty `headers` list
- The additive API is intended for a future 0.2.x release, accepted by Composer constraints `^0.2` and `^0.2.0`

## v0.2.0 - 2026-03-19

### Added

- Fluent `bail()` method on ReplayBuilder to abort the test before sending the request when no replay is found

### Changed

- Bail now triggers before the HTTP request instead of after the response
- Upgrade to Laravel 13 support (drop Laravel 11/12)
- Require PHP 8.4+ (drop PHP 8.3)
- Update Orchestra Testbench to ^11.0

## v0.1.2 - 2026-02-25

### Documentation

- Clarify that `replay` is a reserved attribute key in `withAttributes` docs
- Add example for custom attributes with `matchBy('attribute:key')`

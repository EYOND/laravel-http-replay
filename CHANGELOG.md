# Changelog

All notable changes to `laravel-http-replay` will be documented in this file.

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

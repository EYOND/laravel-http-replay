# Changelog

All notable changes to `laravel-http-replay` will be documented in this file.

## v0.1.3 - 2026-03-12

### Added

- Fluent `bail()` method on `ReplayBuilder` for failing tests when unrecorded HTTP requests are detected

### Changed

- Bail now triggers before the request is sent instead of after the response is received

## v0.1.2 - 2026-02-25

### Documentation

- Clarify that `replay` is a reserved attribute key in `withAttributes` docs
- Add example for custom attributes with `matchBy('attribute:key')`

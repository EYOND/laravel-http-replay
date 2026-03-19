# Upgrade Package to Laravel 13 (Drop L11/L12)

## Context

The `eyond/laravel-http-replay` package currently supports Laravel 11 and 12. Laravel 13 has been released (Q1 2026, PHP 8.3-8.5). This upgrade drops Laravel 11/12 support and targets Laravel 13 only.

**Good news:** After reviewing all L13 breaking changes against the package source code, **no source code changes are needed**. The package doesn't extend or override any classes affected by L13 breaking changes.

## Baseline

- All 159 tests pass on current codebase
- `composer install` works cleanly

## Changes

### 1. `composer.json`
- `php`: `^8.3` → `^8.4` (align with new matrix)
- `illuminate/contracts`: `^11.0||^12.0` → `^13.0`
- `orchestra/testbench`: `^10.0.0||^9.0.0` → `^11.0.0`
- Review other dev deps for compatibility (collision, larastan, etc.)

### 2. `.github/workflows/run-tests.yml`
Replace matrix to only test Laravel 13 with PHP 8.4/8.5:
```yaml
matrix:
  os: [ubuntu-latest, windows-latest]
  php: [8.5, 8.4]
  laravel: [13.*]
  stability: [prefer-lowest, prefer-stable]
  include:
    - laravel: 13.*
      testbench: 11.*
```

### 3. `README.md` (2 locations)
- Requirements section: `PHP 8.3+` → `PHP 8.4+`, `Laravel 11 or 12` → `Laravel 13`
- Contributing section: `PHP 8.3-8.4 and Laravel 11-12` → `PHP 8.4-8.5 and Laravel 13`

### 4. `CLAUDE.md`
- Update package overview: `Supports Laravel 11/12 and PHP 8.3/8.4` → `Supports Laravel 13 and PHP 8.4/8.5`
- Update CI matrix description: `PHP 8.3-8.4 x Laravel 11-12` → `PHP 8.4-8.5 x Laravel 13`

### 5. `CHANGELOG.md`
Add entry for the new version.

## Verification

1. Update `composer.json` constraints
2. Run `composer update` to resolve new dependencies
3. Run `composer test` — all 159 tests must still pass
4. Run `composer analyse` — PHPStan must pass at level 5
5. Run `composer format` — Pint formatting check

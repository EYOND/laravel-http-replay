# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

Laravel package (`pikant/laravel-easy-http-fake`) for storing, using, and renewing HTTP fakes in Laravel. Built with Spatie's Laravel Package Tools. Supports Laravel 11/12 and PHP 8.3/8.4.

## Commands

```bash
composer test                # Run Pest tests
composer test-coverage       # Run tests with coverage
composer analyse             # Run PHPStan (level 5)
composer format              # Run Laravel Pint code formatter

# Run a single test file
./vendor/bin/pest tests/ExampleTest.php

# Run a single test by name
./vendor/bin/pest --filter="test name here"
```

## Architecture

- **Namespace:** `Pikant\LaravelEasyHttpFake`
- **Service Provider:** `LaravelEasyHttpFakeServiceProvider` — registers config, views, migrations, and commands using Spatie's `PackageServiceProvider`
- **Facade:** `Pikant\LaravelEasyHttpFake\Facades\LaravelEasyHttpFake` — resolves to the main `LaravelEasyHttpFake` class
- **Testing:** Uses Pest PHP 4 with Orchestra Testbench. Base `TestCase` configures SQLite in-memory DB and auto-registers the service provider
- **Architecture tests** in `tests/ArchTest.php` enforce no `dd`, `dump`, or `ray` calls in source code

## Code Quality

- **PHPStan** at level 5 with Larastan, Octane compatibility, and model property checks enabled
- **Pint** for code formatting (Laravel preset)
- CI runs tests across PHP 8.3–8.4 × Laravel 11–12 matrix on Ubuntu and Windows

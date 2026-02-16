# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

Laravel package (`eyond/laravel-http-replay`) for recording and replaying HTTP responses in Laravel/Pest tests. Built with Spatie's Laravel Package Tools. Supports Laravel 11/12 and PHP 8.3/8.4.

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

- **Namespace:** `EYOND\LaravelHttpReplay`
- **Service Provider:** `LaravelHttpReplayServiceProvider` — registers config, views, migrations, and commands using Spatie's `PackageServiceProvider`
- **Facade:** `EYOND\LaravelHttpReplay\Facades\Replay` — resolves to the main `LaravelHttpReplay` class
- **Testing:** Uses Pest PHP 4 with Orchestra Testbench. Base `TestCase` configures SQLite in-memory DB and auto-registers the service provider
- **Architecture tests** in `tests/ArchTest.php` enforce no `dd`, `dump`, or `ray` calls in source code

## Documentation

- **README.md** is the user-facing documentation. Any change to the package's public API, configuration, CLI flags, or usage patterns **must** be reflected in `README.md`.
- **`resources/boost/skills/http-replay-testing/SKILL.md`** is the Laravel Boost skill for AI agents. It must be kept in sync with `README.md` — any API, config, or usage change that updates the README must also update the skill.

## Workflow

After completing a change, always print a ready-to-use commit snippet for the user:

```
git add . && git commit -m "Concise commit message describing the change"
```

Do not run the commit yourself — just print the snippet so the user can review and execute it.

## Code Quality

- **PHPStan** at level 5 with Larastan, Octane compatibility, and model property checks enabled
- **Pint** for code formatting (Laravel preset)
- CI runs tests across PHP 8.3-8.4 x Laravel 11-12 matrix on Ubuntu and Windows

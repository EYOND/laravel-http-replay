# Add fluent `bail()` method to ReplayBuilder

## Context

Bail mode can currently only be activated globally via config (`http-replay.bail`) or CLI flag (`--replay-bail`). There's no way to activate it per-test or in a `beforeEach`. The `fresh()` feature already has this three-tier pattern (fluent + config + env), but bail is missing the fluent layer.

## Changes

### 1. `src/ReplayBuilder.php`

Follow the exact `fresh()` / `shouldFresh()` pattern:

- Add property `protected bool $isBail = false;`
- Add fluent method `bail(): self` that sets `$this->isBail = true`
- Update `shouldBail()` to include `$this->isBail`:
  ```php
  return $this->isBail
      || config('http-replay.bail', false)
      || filter_var($_SERVER['REPLAY_BAIL'] ?? false, FILTER_VALIDATE_BOOLEAN);
  ```

### 2. `tests/ReplayBuilderTest.php`

Add a test for the fluent bail method (matching the style of other fluent tests like `fresh()`).

### 3. `README.md` + `resources/boost/skills/http-replay-testing/SKILL.md`

Add `bail()` to the fluent API docs alongside the existing config/CLI documentation.

## Verification

```bash
composer test
composer analyse
composer format
```

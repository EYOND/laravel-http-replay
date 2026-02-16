# Plan: Namespace Pikant → EYOND

## Context

Das Package soll von `Pikant\LaravelHttpReplay` auf `EYOND\LaravelHttpReplay` umgestellt werden, analog zur Konvention in `eyond/laravel-shopify`. GitHub-URLs werden ebenfalls auf `eyond/` umgestellt. Author-Credits bleiben bei Patrick Korber.

## Globale Ersetzungen

| Alt | Neu | Scope |
|-----|-----|-------|
| `Pikant\LaravelHttpReplay` | `EYOND\LaravelHttpReplay` | PHP namespace (alle src/ + tests/) |
| `Pikant\\LaravelHttpReplay` | `EYOND\\LaravelHttpReplay` | composer.json (escaped backslashes) |
| `pikant/laravel-http-replay` | `eyond/laravel-http-replay` | Composer name, README install, Badges |
| `Pikant/LaravelHttpReplay` | `EYOND/LaravelHttpReplay` | Config comment |
| `Pikant Test Suite` | `EYOND Test Suite` | phpunit.xml.dist |
| `github.com/pikant/laravel-http-replay` | `github.com/eyond/laravel-http-replay` | Alle GitHub-URLs |
| `github: pikant` | `github: eyond` | FUNDING.yml |

**Nicht ändern:**
- `[Patrick Korber](https://github.com/pikant)` in README Credits → bleibt (Author-Link)
- LICENSE.md Copyright-Inhaber → bleibt `pikant <p.koerber@eyond.io>`

## Betroffene Dateien

### Source (namespace)
Alle 20 Dateien in `src/` — nur Zeile 3 (`namespace`) und `use`-Statements:
- `src/LaravelHttpReplay.php`
- `src/LaravelHttpReplayServiceProvider.php`
- `src/ReplayBuilder.php`
- `src/ReplayNamer.php`
- `src/ReplayStorage.php`
- `src/ResponseSerializer.php`
- `src/ForPatternProxy.php`
- `src/Facades/Replay.php`
- `src/Commands/ReplayPruneCommand.php`
- `src/Exceptions/ReplayBailException.php`
- `src/Plugins/ReplayBailPlugin.php`
- `src/Plugins/ReplayFreshPlugin.php`
- `src/Matchers/NameMatcher.php`
- `src/Matchers/BodyHashMatcher.php`
- `src/Matchers/ClosureMatcher.php`
- `src/Matchers/HostMatcher.php`
- `src/Matchers/HttpAttributeMatcher.php`
- `src/Matchers/HttpMethodMatcher.php`
- `src/Matchers/SubdomainMatcher.php`
- `src/Matchers/UrlMatcher.php`

### Tests (namespace + use)
- `tests/TestCase.php`
- `tests/Pest.php`
- `tests/ReplayBuilderTest.php`
- `tests/ReplayNamerTest.php`
- `tests/ReplayStorageTest.php`
- `tests/ResponseSerializerTest.php`
- `tests/ReplayBailPluginTest.php`
- `tests/ReplayFreshPluginTest.php`
- `tests/Integration/ReplayIntegrationTest.php`

### Config + Build
- `composer.json` — name, autoload, autoload-dev, extra.laravel, extra.pest
- `config/http-replay.php` — Kommentar Zeile 3
- `phpunit.xml.dist` — testsuite name

### Dokumentation
- `README.md` — Badges, Install-Befehl, Code-Beispiele, URLs
- `CLAUDE.md` — Package-Name, Namespace, Facade
- `resources/boost/skills/http-replay-testing/SKILL.md` — Code-Beispiele, Key Classes

### GitHub Config
- `.github/FUNDING.yml` — `github: pikant` → `github: eyond`
- `.github/ISSUE_TEMPLATE/config.yml` — GitHub-URLs

## Vorgehen

1. `composer.json` — alle `Pikant`/`pikant` Referenzen ersetzen
2. Alle `src/**/*.php` — `Pikant\LaravelHttpReplay` → `EYOND\LaravelHttpReplay`
3. Alle `tests/**/*.php` — gleiche Ersetzung
4. `config/http-replay.php` — Kommentar
5. `phpunit.xml.dist` — Testsuite-Name
6. `README.md`, `CLAUDE.md`, `SKILL.md` — Namespace + URLs
7. `.github/` — FUNDING + Issue Templates
8. `composer dumpautoload` zum Testen
9. `composer test` + `composer analyse`

## Verifikation

```bash
composer dumpautoload
composer test
composer analyse
# Prüfen dass kein "Pikant" mehr vorkommt (außer Author-Credits):
grep -r "Pikant" --include="*.php" --include="*.json" --include="*.md" --include="*.yml" --include="*.xml" .
```

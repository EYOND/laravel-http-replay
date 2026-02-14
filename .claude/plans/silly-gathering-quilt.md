# Plan: Feedback-Runde — Laravel Http Replay

## Context

Basierend auf dem Praxis-Feedback in `feedback-replay.md` stehen 8 Änderungen an:
1. Package umbenennen (laravel-easy-http-fake → laravel-http-replay)
2. API-Naming anpassen (storeAs→storeIn, replay:fresh→replay:prune)
3. ReplayNamer komplett neu — komposierbare Matcher
4. Per-URL Konfiguration via `->for()->matchBy()`
5. `Replay::get()` für einzelne shared Fakes in `Http::fake()`
6. Bail on CI (Tests failen wenn Replay schreibt)
7. Test als incomplete markieren wenn Replay schreibt
8. Storage-Pfad → `tests/.laravel-http-replay`

---

## Phase 1: Package Rename

Namespace `Pikant\LaravelEasyHttpFake` → `Pikant\LaravelHttpReplay`. Facade wird zu `Replay`.

### Datei-Umbenennungen

| Alt | Neu |
|-----|-----|
| `src/LaravelEasyHttpFake.php` | `src/LaravelHttpReplay.php` |
| `src/LaravelEasyHttpFakeServiceProvider.php` | `src/LaravelHttpReplayServiceProvider.php` |
| `src/Facades/LaravelEasyHttpFake.php` | `src/Facades/Replay.php` |
| `config/easy-http-fake.php` | `config/http-replay.php` |

### Globale Ersetzungen

| Alt | Neu |
|-----|-----|
| `Pikant\LaravelEasyHttpFake` | `Pikant\LaravelHttpReplay` |
| `LaravelEasyHttpFakeServiceProvider` | `LaravelHttpReplayServiceProvider` |
| `LaravelEasyHttpFake` (Klasse) | `LaravelHttpReplay` |
| `LaravelEasyHttpFake` (Facade) | `Replay` |
| `config('easy-http-fake.` | `config('http-replay.` |
| `'laravel-easy-http-fake'` (Package-Name) | `'laravel-http-replay'` |
| `pikant/laravel-easy-http-fake` (Composer) | `pikant/laravel-http-replay` |

### Betroffene Dateien (~20)
- `composer.json` (name, autoload, extra.laravel)
- Alle `src/*.php` (Namespace)
- `src/Facades/Replay.php` (Klasse + Accessor)
- `config/http-replay.php`
- Alle `tests/*.php` (use-Statements, config-Keys)
- `tests/Integration/ReplayIntegrationTest.php` (FQCN-Referenzen)
- `README.md`, `CHANGELOG.md`, `CLAUDE.md`
- `.github/ISSUE_TEMPLATE/config.yml`

### Storage-Pfad
- Default: `tests/.http-replays` → `tests/.laravel-http-replay`
- Bestehende Replay-Dateien im Repo müssen verschoben werden
- `.gitignore`-Eintrag anpassen falls vorhanden

---

## Phase 2: API-Naming

Kleine Umbenennungen:

| Alt | Neu | Dateien |
|-----|-----|---------|
| `->storeAs('name')` | `->storeIn('name')` | `ReplayBuilder.php`, Tests |
| `replay:fresh` | `replay:prune` | `ReplayFreshCommand.php` → `ReplayPruneCommand.php`, ServiceProvider |

---

## Phase 3: ReplayNamer — Komposierbare Matcher

### Neue Architektur

Statt der aktuellen if-Leiter wird der Namer aus einzelnen Matchern zusammengesetzt. Jeder Matcher löst einen Teil des Dateinamens auf.

**Neues Interface:** `src/Matchers/NameMatcher.php`
```php
interface NameMatcher
{
    public function resolve(Request $request): ?string;
}
```

**Neue Matcher-Klassen** in `src/Matchers/`:

| Matcher | Config-String | Beispiel-Output |
|---------|--------------|-----------------|
| `HttpMethodMatcher` | `http_method` | `GET` |
| `SubdomainMatcher` | `subdomain` | `shop` (von shop.myshopify.com) |
| `HostMatcher` | `host` | `shop_myshopify_com` |
| `UrlMatcher` | `url` | `shop_myshopify_com_api_products` (ohne Schema) |
| `HttpAttributeMatcher` | `http_attribute:key` | Wert von `$request->attributes()['key']`. Dot-Notation: `http_attribute:object.slug` |
| `BodyHashMatcher` | `body_hash`, `body_hash:query`, `body_hash:query,variables.id` | `a1b2c3` (6-Zeichen Hash). Ohne Key = ganzer Body. Mit Keys = nur diese Felder. |
| `ClosureMatcher` | `Closure` | Was die Closure zurückgibt (Array von Teilen) |

### Matcher-Parsing in ReplayNamer

```php
// matchBy-Config: ['http_method', 'url', 'http_attribute:request_name']
// → [HttpMethodMatcher, UrlMatcher, HttpAttributeMatcher('request_name')]

public function fromRequest(Request $request, array $matchBy): string
{
    $parts = [];
    foreach ($this->parseMatchers($matchBy) as $matcher) {
        $resolved = $matcher->resolve($request);
        if ($resolved !== null && $resolved !== '') {
            $parts[] = $this->sanitize($resolved);
        }
    }
    return implode('_', $parts) . '.json';
}
```

**Leere Werte:** `resolve()` gibt `null` zurück → wird übersprungen → keine `___` im Dateinamen.

### Default-Matcher

Config `match_by` Default bleibt `['http_method', 'url']` (vorher `['url', 'method']` → umbenennen für Konsistenz mit neuen Matcher-Namen).

### Closure-Matcher Signatur

```php
Http::replay()->matchBy(
    'http_method',
    fn(Request $r): array => [
        $r->data()['operationName'] ?? 'unknown',
        $r->data()['variables']['id'] ?? '',
    ],
);
```

Die Closure gibt ein `array` von Filename-Teilen zurück. Leere Strings werden übersprungen.

### Backward Compatibility

Die alten String-Werte `'url'`, `'method'`, `'body'` werden auf die neuen Matcher gemappt:
- `'url'` → `UrlMatcher`
- `'method'` → `HttpMethodMatcher`
- `'body'` → `BodyHashMatcher` (ganzer Body)

### Anpassungen in ReplayBuilder

- `recordingKey()` muss die gleiche Matcher-Logik verwenden wie der Namer (damit pending recordings korrekt zugeordnet werden)
- `getBaseFilename()` für Counter-Stripping bleibt unverändert

---

## Phase 4: Per-URL Konfiguration via `->for()->matchBy()`

### API

```php
Http::replay()
    ->for('myshopify.com/*')->matchBy('url', 'http_attribute:request_name', 'http_attribute:request_id')
    ->for('reybex.com/*')->matchBy('http_method', 'url');
```

### Implementierung in ReplayBuilder

```php
protected ?string $currentForPattern = null;

/** @var array<string, list<string|Closure>> */
protected array $perPatternMatchBy = [];

public function for(string $pattern): self
{
    $this->currentForPattern = $pattern;
    return $this;
}

public function matchBy(string|Closure ...$fields): self
{
    if ($this->currentForPattern !== null) {
        $this->perPatternMatchBy[$this->currentForPattern] = array_values($fields);
        $this->currentForPattern = null;
    } else {
        $this->matchByFields = array_values($fields);
    }
    return $this;
}
```

### Matcher-Auflösung pro Request

In `handleRequest()` und `handleResponseReceived()`:
```php
protected function resolveMatchBy(Request $request): array
{
    foreach ($this->perPatternMatchBy as $pattern => $matchBy) {
        if (Str::is(Str::start($pattern, '*'), $request->url())) {
            return $matchBy;
        }
    }
    return $this->matchByFields; // Global default
}
```

Der `ReplayNamer` bekommt die aufgelösten `matchBy`-Felder pro Request statt sie im Constructor zu setzen.

---

## Phase 5: `Replay::get()` Facade

### API

```php
Http::fake([
    'foo.com/posts/*' => Replay::get('fresh-test/GET_jsonplaceholder_typicode_com_posts_3.json'),
]);
```

### Implementierung

Die `Replay`-Facade (umbenannte `LaravelEasyHttpFake`) bekommt eine `get()`-Methode auf der Hauptklasse `LaravelHttpReplay`:

```php
// src/LaravelHttpReplay.php
public function get(string $path): \GuzzleHttp\Promise\PromiseInterface
{
    $fullPath = $this->getStoragePath()
        . DIRECTORY_SEPARATOR . '_shared'
        . DIRECTORY_SEPARATOR . $path;

    $content = File::get($fullPath);
    $data = json_decode($content, true);

    return (new ResponseSerializer)->deserialize($data);
}
```

Sucht in `{storage_path}/_shared/{path}`. Gibt ein `PromiseInterface` zurück (kompatibel mit `Http::fake()`).

---

## Phase 6: Bail on CI

### Konzept

Wenn `REPLAY_BAIL=true` gesetzt ist, soll der Test **failen** wenn Replay versucht eine Datei zu schreiben. Das verhindert, dass in CI versehentlich neue Fakes angelegt werden.

### Implementierung

Drei Wege zum Aktivieren — Pest CLI Flag, ENV oder Config:

**1. Pest Plugin** (`src/Plugins/ReplayBailPlugin.php`):

Pest erlaubt custom CLI-Flags über das `HandlesArguments`-Interface. Plugin-Registrierung via `composer.json`:

```php
class ReplayBailPlugin implements HandlesArguments
{
    use HandleArguments;

    public function handleArguments(array $arguments): array
    {
        if ($this->hasArgument('--replay-bail', $arguments)) {
            $arguments = $this->popArgument('--replay-bail', $arguments);
            config()->set('http-replay.bail', true);
        }
        return $arguments;
    }
}
```

```json
// composer.json
"extra": {
    "pest": {
        "plugins": ["Pikant\\LaravelHttpReplay\\Plugins\\ReplayBailPlugin"]
    }
}
```

**2. Config** (`config/http-replay.php`):
```php
'bail' => env('REPLAY_BAIL', false),
```

**3. In ReplayBuilder.handleResponseReceived():**
```php
if (config('http-replay.bail', false)) {
    throw new ReplayBailException(
        "Http Replay attempted to write [$filename] but bail mode is active. "
        . "Run tests locally to record new fakes."
    );
}
```

**Neue Exception:** `src/Exceptions/ReplayBailException.php` (extends `\RuntimeException`)

**Anwendung in CI (alle drei Varianten):**
```bash
# Pest Flag (bevorzugt)
vendor/bin/pest --replay-bail

# Environment Variable
REPLAY_BAIL=true vendor/bin/pest

# phpunit.xml
<php><env name="REPLAY_BAIL" value="true"/></php>
```

---

## Phase 7: Test als Incomplete markieren

### Konzept

Wenn Replay innerhalb eines Tests neue Daten schreibt, soll der Test als "incomplete" (gelb) markiert werden — genau wie bei `expect()->toMatchSnapshot()`.

### Mechanismus (von Pest übernommen)

Pest nutzt `TestSuite::getInstance()->registerSnapshotChange($message)`. Diese public Methode fügt eine Nachricht zum `$__snapshotChanges`-Array des Tests hinzu. Ein `#[PostCondition]`-Hook in Pest's `Testable`-Trait ruft danach `markTestIncomplete()` auf.

**Wir piggybacen auf diesem Mechanismus.** In `ReplayBuilder.handleResponseReceived()` nach dem Schreiben:

```php
use Pest\TestSuite;

// Nach erfolgreichem Speichern:
if (class_exists(TestSuite::class)) {
    TestSuite::getInstance()->registerSnapshotChange(
        "Http replay recorded at [{$this->saveDirectory}/{$filename}]"
    );
}
```

Das ist alles — Pest's bestehender PostCondition-Hook erledigt den Rest. Der Test wird gelb mit der Nachricht wo die Datei gespeichert wurde.

---

## Phase 8: Config-Datei aktualisieren

```php
// config/http-replay.php
return [
    'storage_path' => 'tests/.laravel-http-replay',
    'match_by' => ['http_method', 'url'],
    'expire_after' => null,
    'fresh' => false,
    'bail' => false,
];
```

---

## Implementierungs-Reihenfolge

1. **Package Rename** (Phase 1 + 2) — Sauberer Schnitt zuerst
2. **Matcher-Architektur** (Phase 3) — NameMatcher Interface + alle Matcher-Klassen + ReplayNamer Refactoring
3. **Per-URL Config** (Phase 4) — `for()->matchBy()` auf ReplayBuilder
4. **Replay::get()** (Phase 5) — Methode auf Hauptklasse
5. **Bail + Incomplete** (Phase 6 + 7) — ReplayBuilder-Hooks
6. **Tests** für alle Phasen

## Dateiübersicht

### Neue Dateien
- `src/Matchers/NameMatcher.php` (Interface)
- `src/Matchers/HttpMethodMatcher.php`
- `src/Matchers/SubdomainMatcher.php`
- `src/Matchers/HostMatcher.php`
- `src/Matchers/UrlMatcher.php`
- `src/Matchers/HttpAttributeMatcher.php`
- `src/Matchers/BodyHashMatcher.php`
- `src/Matchers/ClosureMatcher.php`
- `src/Plugins/ReplayBailPlugin.php` (Pest Plugin für --replay-bail)
- `src/Exceptions/ReplayBailException.php`
- Tests für alle neuen Matcher

### Umbenannte Dateien
- `src/LaravelEasyHttpFake.php` → `src/LaravelHttpReplay.php`
- `src/LaravelEasyHttpFakeServiceProvider.php` → `src/LaravelHttpReplayServiceProvider.php`
- `src/Facades/LaravelEasyHttpFake.php` → `src/Facades/Replay.php`
- `src/Commands/ReplayFreshCommand.php` → `src/Commands/ReplayPruneCommand.php`
- `config/easy-http-fake.php` → `config/http-replay.php`
- `tests/.http-replays/` → `tests/.laravel-http-replay/`

### Geänderte Dateien
- `composer.json` (name, namespaces, aliases)
- `src/ReplayBuilder.php` (for/matchBy, bail, incomplete, storeAs→storeIn)
- `src/ReplayNamer.php` (komplett refactored — Matcher-basiert)
- `src/ReplayStorage.php` (Namespace)
- `src/ResponseSerializer.php` (Namespace)
- Alle Test-Dateien (Namespaces, Config-Keys, API-Änderungen)
- `README.md`, `CLAUDE.md`, `CHANGELOG.md`

---

## Verifikation

```bash
# Nach jedem Schritt:
composer test                # Alle Tests grün
composer analyse             # PHPStan level 5
composer format              # Pint Formatting

# Integration testen:
# 1. Tests laufen lassen → Replay-Dateien in tests/.laravel-http-replay/
# 2. Nochmal laufen → kein Netzwerk, Dateien unverändert (git diff leer)
# 3. REPLAY_BAIL=true vendor/bin/pest → Test failt wenn geschrieben wird
# 4. Frischen Test schreiben → gelb/incomplete markiert
```

# Plan: API-Verbesserungen (TODO.md)

## Context

Nach dem API-Review in `TODO.md` stehen 8 freigegebene Verbesserungen an. Das Package ist funktional komplett (rename, matcher, bail, incomplete — alles umgesetzt). Diese Runde poliert die öffentliche API für Konsistenz und Klarheit.

---

## 1. REPLAY_FRESH ENV-Parity mit REPLAY_BAIL

**Problem:** `REPLAY_BAIL` kann via `$_SERVER`, Config und `--replay-bail` Flag gesetzt werden, aber `REPLAY_FRESH` nur über Config.

### Änderungen

**`src/ReplayBuilder.php`** — Neue `shouldFresh()` Methode (analog zu `shouldBail()`):
```php
protected function shouldFresh(): bool
{
    return $this->isFresh
        || config('http-replay.fresh', false)
        || filter_var($_SERVER['REPLAY_FRESH'] ?? false, FILTER_VALIDATE_BOOLEAN);
}
```

`handleFreshAndExpiry()` Zeile 188 ändern: `$this->isFresh || config(...)` → `$this->shouldFresh()`.

**`src/Plugins/ReplayFreshPlugin.php`** — Neues Pest Plugin (Kopie von ReplayBailPlugin):
```php
final class ReplayFreshPlugin implements HandlesArguments
{
    use HandleArguments;

    public function handleArguments(array $arguments): array
    {
        if (! $this->hasArgument('--replay-fresh', $arguments)) {
            return $arguments;
        }
        $arguments = $this->popArgument('--replay-fresh', $arguments);
        $_SERVER['REPLAY_FRESH'] = 'true';
        $_ENV['REPLAY_FRESH'] = 'true';
        return $arguments;
    }
}
```

**`composer.json`** — Plugin registrieren in `extra.pest.plugins`.

**Tests:** `tests/ReplayFreshPluginTest.php` (analog zu `tests/ReplayBailPluginTest.php`).

**README.md** — Abschnitt "Renewal / Re-Recording" erweitern um `--replay-fresh` und `REPLAY_FRESH=true`.

---

## 2. Kürzere Matcher-Namen

**Problem:** `http_method` und `http_attribute:key` sind redundant lang im HTTP-Kontext.

### Änderungen

**`src/ReplayNamer.php`** `parseMatchers()` — Neue Kurzformen als primär, lange als Alias:

```php
$field === 'method' => new HttpMethodMatcher,       // primary (kurz)
$field === 'http_method' => new HttpMethodMatcher,   // alias (lang)
str_starts_with($field, 'attribute:') => new HttpAttributeMatcher(
    substr($field, strlen('attribute:'))
),                                                    // primary (kurz)
str_starts_with($field, 'http_attribute:') => new HttpAttributeMatcher(
    substr($field, strlen('http_attribute:'))
),                                                    // alias (lang)
```

Reihenfolge der match-Arms beachten: `'method'` muss vor `str_starts_with($field, 'method')` stehen (kein Konflikt, da kein prefix-Match auf 'method' existiert).

**`src/ReplayBuilder.php`** `recordingKey()` Zeile 358 — `'body'` Alias bleibt, `'body_hash'` bleibt. Kein Handlungsbedarf (kein `http_method`/`http_attribute` Check in recordingKey).

**`config/http-replay.php`** — Default `match_by` bleibt `['http_method', 'url']`. Dokumentation erweitern um Kurzformen.

**README.md** — Matcher-Tabelle aktualisieren: Kurzformen als primär, `http_method`/`http_attribute` als "(alias)".

---

## 3. `for()` Proxy-Objekt gegen State-Leak

**Problem:** `for('x')` setzt `$currentForPattern`. Wenn danach kein `matchBy()` folgt, leakt der State zum nächsten Aufruf.

### Änderungen

**Neue Datei `src/ForPatternProxy.php`:**
```php
class ForPatternProxy
{
    public function __construct(
        protected ReplayBuilder $builder,
        protected string $pattern,
    ) {}

    /** @param string|Closure ...$fields */
    public function matchBy(string|Closure ...$fields): ReplayBuilder
    {
        $this->builder->addPerPatternMatchBy($this->pattern, array_values($fields));
        return $this->builder;
    }
}
```

**`src/ReplayBuilder.php`:**
- `for()` gibt `ForPatternProxy` zurück statt `$this`:
  ```php
  public function for(string $pattern): ForPatternProxy
  {
      return new ForPatternProxy($this, $pattern);
  }
  ```
- `$currentForPattern` Property entfernen
- Neuer public Helper für den Proxy:
  ```php
  /** @param list<string|Closure> $fields */
  public function addPerPatternMatchBy(string $pattern, array $fields): void
  {
      $this->perPatternMatchBy[$pattern] = $fields;
  }
  ```
- `matchBy()` vereinfacht — kein `$currentForPattern`-Check mehr:
  ```php
  public function matchBy(string|Closure ...$fields): self
  {
      $this->matchByFields = array_values($fields);
      return $this;
  }
  ```

**Tests:** Bestehende `for()->matchBy()` Tests funktionieren weiter. Neuer Test: `for('x')->only(...)` ist Compile-Error (Methode existiert nicht auf Proxy).

---

## 4. `readFrom()` / `writeTo()` / `useShared()`

**Problem:** `from()` und `storeIn()` sind asymmetrisch. `from()` liest shared, schreibt test-lokal. `storeIn()` liest+schreibt shared. Nicht intuitiv.

### Neue API

| Methode | Liest aus | Schreibt nach |
|---------|-----------|---------------|
| `readFrom('a', 'b')` | shared/a, shared/b (first wins) | test-spezifisch |
| `writeTo('x')` | test-spezifisch | shared/x |
| `useShared('name')` | shared/name | shared/name |
| `readFrom('a')->writeTo('x')` | shared/a | shared/x |

### Änderungen

**`src/ReplayBuilder.php`:**
- Properties ersetzen:
  ```php
  // Alt:
  protected ?string $fromName = null;
  protected ?string $storeInName = null;

  // Neu:
  /** @var list<string> */
  protected array $readFromNames = [];
  protected ?string $writeToName = null;
  ```

- Neue Methoden:
  ```php
  public function readFrom(string ...$names): self
  {
      $this->readFromNames = array_values($names);
      return $this;
  }

  public function writeTo(string $name): self
  {
      $this->writeToName = $name;
      return $this;
  }

  public function useShared(string $name): self
  {
      $this->readFromNames = [$name];
      $this->writeToName = $name;
      return $this;
  }
  ```

- `from()` und `storeIn()` entfernen (kein Backward-Compat nötig — Package ist noch nicht stable released).

- `resolveDirectories()` refactoren:
  ```php
  protected function resolveDirectories(): void
  {
      // Load directories (can be multiple for readFrom)
      if ($this->readFromNames !== []) {
          $this->loadDirectories = array_map(
              fn (string $name) => $this->storage->getSharedDirectory($name),
              $this->readFromNames,
          );
      } else {
          $this->loadDirectories = [$this->storage->getTestDirectory()];
      }

      // Save directory
      if ($this->writeToName !== null) {
          $this->saveDirectory = $this->storage->getSharedDirectory($this->writeToName);
      } else {
          $this->saveDirectory = $this->storage->getTestDirectory();
      }
  }
  ```

- Property `loadDirectory` (string) → `loadDirectories` (array):
  ```php
  /** @var list<string> */
  protected array $loadDirectories = [];
  ```

- `loadStoredResponses()` über mehrere Directories iterieren (first wins):
  ```php
  protected function loadStoredResponses(): void
  {
      // Track which dir first provided a baseFilename
      $seenFromDir = [];

      foreach ($this->loadDirectories as $dir) {
          $stored = $this->storage->findStoredResponses($dir);
          foreach ($stored as $filename => $data) {
              if ($this->expireDays !== null) {
                  $filepath = $dir . DIRECTORY_SEPARATOR . $filename;
                  if ($this->storage->isExpired($filepath, $this->expireDays)) {
                      continue;
                  }
              }

              $baseFilename = $this->getBaseFilename($filename);

              // First wins: skip if a previous directory already provided this base filename
              // (but allow __2, __3 etc from the same directory for sequential calls)
              if (isset($seenFromDir[$baseFilename]) && $seenFromDir[$baseFilename] !== $dir) {
                  continue;
              }
              $seenFromDir[$baseFilename] = $dir;

              if (! isset($this->responseQueues[$baseFilename])) {
                  $this->responseQueues[$baseFilename] = [];
              }
              $this->responseQueues[$baseFilename][] = $data;
          }
      }
  }
  ```

- `handleFreshAndExpiry()` muss über `$this->loadDirectories` iterieren:
  ```php
  protected function handleFreshAndExpiry(): void
  {
      if (! $this->shouldFresh()) {
          return;
      }
      foreach ($this->loadDirectories as $dir) {
          if ($this->freshPattern !== null) {
              $this->storage->deleteByPattern($dir, $this->freshPattern);
          } else {
              $this->storage->deleteDirectory($dir);
          }
      }
  }
  ```

**Tests:** Bestehende `from()`/`storeIn()` Tests auf neue API umschreiben. Neue Tests für `readFrom()` mit mehreren Directories und "first wins" Verhalten.

**README.md** — Shared Fakes Abschnitt komplett aktualisieren.

---

## 5. `fake()` → `alsoFake()`

**Problem:** `fake()` auf ReplayBuilder kollidiert konzeptionell mit `Http::fake()`.

### Änderungen

**`src/ReplayBuilder.php`:**
- Methode umbenennen: `fake()` → `alsoFake()`
- Property bleibt `$additionalFakes` (intern unverändert)

**Tests + README.md** — Alle `->fake([...])` → `->alsoFake([...])`.

---

## 6. `expireAfter()` mit DateInterval

**Problem:** Nur `int $days` — nicht Laravel-typisch.

### Änderungen

**`src/ReplayBuilder.php`:**
```php
public function expireAfter(int|DateInterval $ttl): self
{
    if ($ttl instanceof DateInterval) {
        // Convert to total days (rounded up)
        $now = new DateTimeImmutable();
        $this->expireDays = (int) ceil($now->add($ttl)->getTimestamp() - $now->getTimestamp()) / 86400);
    } else {
        $this->expireDays = $ttl;
    }
    return $this;
}
```

Import: `use DateInterval; use DateTimeImmutable;`

Named parameter `days:` funktioniert weiter: `expireAfter(days: 7)`.

**README.md** — Beispiel ergänzen: `expireAfter(new DateInterval('P1M'))` oder `expireAfter(CarbonInterval::month())`.

---

## 7. Config `storage_path` Dokumentation

**Problem:** Unklar, dass relative Pfade von `base_path()` aufgelöst werden.

### Änderungen

**`config/http-replay.php`** — Kommentar erweitern:
```php
/*
|--------------------------------------------------------------------------
| Storage Path
|--------------------------------------------------------------------------
|
| The directory where HTTP replay files are stored.
| Relative paths are resolved from base_path() (your project root).
| Absolute paths (starting with /) are used as-is.
|
*/
'storage_path' => 'tests/.laravel-http-replay',
```

**README.md** — Im Configuration-Abschnitt Hinweis ergänzen.

---

## 8. `Replay::get()` → `Replay::getShared()`

**Problem:** `get()` funktioniert nur für shared Fakes — der Name suggeriert Allgemeinheit.

### Änderungen

**`src/LaravelHttpReplay.php`:**
- `get()` → `getShared()` umbenennen

**`src/Facades/Replay.php`** — Keine Änderung nötig (Facade proxied dynamisch).

**Tests + README.md** — Alle `Replay::get(...)` → `Replay::getShared(...)`.

---

## Implementierungs-Reihenfolge

1. **Matcher-Namen** (#2) — Schnell, kein Refactoring
2. **`alsoFake()`** (#5) — Einfaches Rename
3. **`Replay::getShared()`** (#8) — Einfaches Rename
4. **REPLAY_FRESH Parity** (#1) — Plugin + shouldFresh()
5. **`for()` Proxy** (#3) — Neue Klasse, Builder-Refactoring
6. **`readFrom`/`writeTo`/`useShared`** (#4) — Größtes Refactoring
7. **`expireAfter` DateInterval** (#6) — Kleine Erweiterung
8. **Config-Doku** (#7) — Nur Kommentare/README
9. **README.md** — Alle API-Änderungen dokumentieren

## Betroffene Dateien

| Datei | Änderungen |
|-------|-----------|
| `src/ReplayBuilder.php` | #1 shouldFresh, #3 for() Proxy, #4 readFrom/writeTo/useShared, #5 alsoFake, #6 DateInterval |
| `src/ReplayNamer.php` | #2 Kurzformen |
| `src/LaravelHttpReplay.php` | #8 getShared |
| `src/ForPatternProxy.php` | #3 **NEU** |
| `src/Plugins/ReplayFreshPlugin.php` | #1 **NEU** |
| `config/http-replay.php` | #2 Matcher-Doku, #7 storage_path Doku |
| `composer.json` | #1 Plugin registrieren |
| `tests/ReplayBuilderTest.php` | #1, #3, #4, #5, #6 |
| `tests/ReplayFreshPluginTest.php` | #1 **NEU** |
| `tests/Integration/ReplayIntegrationTest.php` | #4, #5 |
| `README.md` | Alle Punkte |

## Verifikation

```bash
# Nach jedem Schritt:
composer test                # Alle Tests grün
composer analyse             # PHPStan level 5
composer format              # Pint Formatting
```

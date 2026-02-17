# Plan: `Replay::configure()` — Konfiguration ohne Aktivierung

## Context

Aktuell aktiviert `Http::replay()` sofort Fake-Callback + Event-Listener im Constructor. Es gibt keine Möglichkeit, Replay global zu konfigurieren (z.B. per-URL Matcher in `Pest.php`) ohne es gleichzeitig für jeden Test zu aktivieren. Ruft man `Http::replay()` in `beforeEach` UND im Test auf, entstehen zwei Builder mit je eigenem Listener → doppelte Dateien.

**Ziel:** `Replay::configure()` speichert Konfiguration (per-URL Matcher etc.) ohne Listener zu registrieren. `Http::replay()` im Test aktiviert Replay und erbt die Config. Per-Test Override bleibt möglich.

```php
// tests/Pest.php — konfiguriert, aktiviert NICHT
Replay::configure()
    ->for('myshopify.com/*')->matchBy('url', fn (...) => [...]);

// Test — aktiviert und erbt Config
it('replays shopify', function () {
    Http::replay();
    Shopify::make(shop())->send(...);
});

// Test — überschreibt Config für diesen Test
it('special test', function () {
    Http::replay()
        ->for('myshopify.com/*')->matchBy('method', 'url');
    // ...
});
```

## Neue Klasse: `ReplayConfig` (`src/ReplayConfig.php`)

Einfacher Config-Container. Kein Fake-Callback, kein Event-Listener.

```php
class ReplayConfig
{
    /** @var list<string|Closure>|null */
    protected ?array $matchByFields = null;

    /** @var array<string, list<string|Closure>> */
    protected array $perPatternMatchBy = [];

    public function matchBy(string|Closure ...$fields): self
    {
        $this->matchByFields = array_values($fields);
        return $this;
    }

    public function for(string $pattern): ForPatternProxy
    {
        return new ForPatternProxy($this, $pattern);
    }

    public function addPerPatternMatchBy(string $pattern, array $fields): void
    {
        $this->perPatternMatchBy[$pattern] = $fields;
    }

    /** @return list<string|Closure>|null */
    public function getMatchByFields(): ?array { return $this->matchByFields; }

    /** @return array<string, list<string|Closure>> */
    public function getPerPatternMatchBy(): array { return $this->perPatternMatchBy; }
}
```

`matchByFields` ist `null` wenn nicht gesetzt → ReplayBuilder behält seinen eigenen Default (`['method', 'url']` bzw. Config-Wert).

## Änderung: `ForPatternProxy` (`src/ForPatternProxy.php`)

Union-Type im Constructor, damit derselbe Proxy für `ReplayBuilder` und `ReplayConfig` funktioniert:

```php
class ForPatternProxy
{
    public function __construct(
        protected ReplayBuilder|ReplayConfig $parent,
        protected string $pattern,
    ) {}

    public function matchBy(string|Closure ...$fields): ReplayBuilder|ReplayConfig
    {
        $this->parent->addPerPatternMatchBy($this->pattern, array_values($fields));
        return $this->parent;
    }
}
```

Kein Duplikat-Proxy nötig. `addPerPatternMatchBy()` existiert bereits auf `ReplayBuilder` und wird identisch auf `ReplayConfig` implementiert.

## Änderung: `LaravelHttpReplay` (`src/LaravelHttpReplay.php`)

```php
protected ?ReplayConfig $config = null;

public function configure(): ReplayConfig
{
    return $this->config ??= new ReplayConfig;
}

public function getConfig(): ?ReplayConfig
{
    return $this->config;
}
```

Da die Facade per Container aufgelöst wird und Testbench die App pro Test neu erstellt, wird die Config automatisch zwischen Tests zurückgesetzt.

## Änderung: `ReplayBuilder` Constructor (`src/ReplayBuilder.php`)

Nach Initialisierung der eigenen Properties, Config mergen:

```php
public function __construct(
    ?ReplayStorage $storage = null,
    ?ResponseSerializer $serializer = null,
) {
    $this->storage = $storage ?? new ReplayStorage;
    $this->serializer = $serializer ?? new ResponseSerializer;
    $this->namer = new ReplayNamer;

    $this->applyConfig();

    $this->registerFakeCallback();
    $this->registerResponseListener();
}

protected function applyConfig(): void
{
    $config = app(LaravelHttpReplay::class)->getConfig();

    if (! $config) {
        return;
    }

    if ($config->getMatchByFields() !== null) {
        $this->matchByFields = $config->getMatchByFields();
    }

    $this->perPatternMatchBy = array_merge(
        $config->getPerPatternMatchBy(),
        $this->perPatternMatchBy,
    );
}
```

Merge-Semantik: Per-Pattern Matcher aus Config werden von Builder überschrieben bei gleichem Pattern. Globale `matchByFields` aus Config überschreiben den Hardcoded-Default nur wenn explizit gesetzt.

## Betroffene Dateien

| Datei | Änderung |
|-------|----------|
| `src/ReplayConfig.php` | **NEU** — Config-Container |
| `src/ForPatternProxy.php` | Union-Type `ReplayBuilder\|ReplayConfig` |
| `src/LaravelHttpReplay.php` | `configure()` + `getConfig()` |
| `src/ReplayBuilder.php` | `applyConfig()` im Constructor |
| `tests/ReplayConfigTest.php` | **NEU** — Tests für Config |
| `README.md` | Doku für `Replay::configure()` |
| `resources/boost/skills/http-replay-testing/SKILL.md` | Doku sync |

## Verifikation

```bash
composer test
composer analyse
```

Tests prüfen:
1. `Replay::configure()->for('x/*')->matchBy(...)` speichert Config ohne Listener zu registrieren
2. `Http::replay()` erbt per-pattern Matcher aus Config
3. `Http::replay()->for('x/*')->matchBy(...)` überschreibt Config-Matcher für gleichen Pattern
4. `Http::replay()->matchBy(...)` überschreibt globale matchBy aus Config
5. Ohne `Http::replay()` im Test passiert nichts (kein Fake-Callback aktiv)
6. Config wird zwischen Tests zurückgesetzt (frische App)

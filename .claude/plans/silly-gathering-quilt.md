# Implementierungsplan — `pikant/laravel-easy-http-fake`

## Context

Das Package vereinfacht HTTP Faking in Laravel/Pest Tests: Responses von echten Calls auf Disk speichern, bei folgenden Runs automatisch wiederverwenden, bei Bedarf erneuern. Ähnlich wie Pest's `toMatchSnapshot()`, aber für HTTP-Calls.

**Entscheidungen:**
- Entry Point: `Http::replay()` (Macro auf Factory)
- Stil: Fluent Builder
- Attribute Key: `replay` (`Http::withAttributes(['replay' => 'products'])`)
- Nur Pest-Support
- Same-URL: `matchBy()` + `withAttributes()` beide unterstützt

---

## Definitive API

### Basics

```php
// Simpelster Fall — records on first run, replays after
it('fetches products', function () {
    Http::replay();

    $products = app(ShopifyService::class)->getProducts();
    expect($products)->toHaveCount(10);
});
```

### Same-URL Disambiguation (GraphQL etc.)

```php
it('fetches products and orders via GraphQL', function () {
    Http::replay();

    // Explizit per withAttributes
    $products = Http::withAttributes(['replay' => 'products'])
        ->post('https://shopify.com/graphql', ['query' => '{products{...}}']);
    $orders = Http::withAttributes(['replay' => 'orders'])
        ->post('https://shopify.com/graphql', ['query' => '{orders{...}}']);
});

// Oder: automatisch per Body-Hash (kein withAttributes nötig)
it('auto-disambiguates by body', function () {
    Http::replay()->matchBy('url', 'body');

    Http::post('https://shopify.com/graphql', ['query' => '{products{...}}']);
    Http::post('https://shopify.com/graphql', ['query' => '{orders{...}}']);
});
```

### Shared Fakes

```php
// Aufnehmen in shared Ordner
it('records shared shopify fakes', function () {
    Http::replay()->storeAs('shopify');
    app(ShopifyService::class)->getProducts();
});

// Verwenden aus shared Ordner
it('uses shared shopify fakes', function () {
    Http::replay()->from('shopify');
    $products = app(ShopifyService::class)->getProducts();
    expect($products)->toHaveCount(10);
});

// In beforeEach für ganze Datei
beforeEach(function () {
    Http::replay()->from('shopify');
});
```

### Mix: Recorded + statische Fakes

```php
Http::replay()
    ->only(['shopify.com/*'])
    ->fake([
        'api.stripe.com/*' => Http::response(['ok' => true]),
        'sentry.io/*' => Http::response([], 200),
    ]);
```

### Renewal / Erneuerung

```php
// Fluent
Http::replay()->fresh();                    // Alles neu für diesen Test
Http::replay()->fresh('shopify.com/*');     // Nur bestimmte URLs
Http::replay()->expireAfter(days: 7);      // Auto-Expire
Http::replay()->from('shopify')->fresh();  // Shared Fakes erneuern

// Artisan
// php artisan replay:fresh
// php artisan replay:fresh --test="it fetches products"
// php artisan replay:fresh --file=tests/Feature/ShopifyTest.php
// php artisan replay:fresh --url="shopify.com/*"
// php artisan replay:fresh --shared=shopify

// ENV für CI
// REPLAY_FRESH=true vendor/bin/pest
```

### Komplexes Szenario

```php
it('complex shopify sync', function () {
    Http::replay()
        ->only(['shopify.com/*'])
        ->matchBy('url', 'body')
        ->expireAfter(days: 7)
        ->fake([
            'api.stripe.com/*' => Http::response(['ok' => true]),
        ]);

    $products = Http::withAttributes(['replay' => 'products'])
        ->post('https://shopify.com/graphql', ['query' => '{products{...}}']);
    $charge = app(StripeService::class)->charge(100);

    expect($products->json())->toHaveKey('data.products');
});
```

---

## File Storage

```
tests/.http-replays/
├── _shared/                                    # Geteilte Fakes
│   └── shopify/
│       └── GET_shopify.com_api_products.json
├── Feature/
│   └── ShopifyTest/
│       └── it_fetches_products/                # Auto-Name aus Pest Test
│           ├── GET_shopify.com_api_products.json
│           ├── products.json                   # via withAttributes(['replay' => 'products'])
│           └── POST_shopify.com_graphql__a1b2c3.json  # via matchBy body hash
```

Response-Datei Format:
```json
{
    "status": 200,
    "headers": {"Content-Type": "application/json"},
    "body": {"products": []},
    "recorded_at": "2026-02-12T14:30:00Z",
    "request": {
        "method": "GET",
        "url": "https://shopify.com/api/products",
        "attributes": {"replay": "products"}
    }
}
```

---

## Technische Implementierung

### Neue Klassen

#### 1. `src/ReplayBuilder.php`
Fluent Builder, zurückgegeben von `Http::replay()`. Sammelt Konfiguration und aktiviert Record/Replay.

```
Methoden:
- matchBy(string ...$fields): self          # 'url', 'method', 'body', 'headers'
- only(array $patterns): self               # Nur bestimmte URLs recorden
- fake(array $stubs): self                  # Statische Fakes zusätzlich
- from(string $name): self                  # Shared Fakes laden
- storeAs(string $name): self               # In shared Ordner speichern
- fresh(?string $pattern = null): self      # Fakes erneuern
- expireAfter(int $days): self              # Auto-Expire
```

Am Ende der Konfiguration (via `__destruct` oder explizitem `->start()`) registriert der Builder:
1. Vorhandene Fakes als `Http::fake()` Stubs
2. Einen Recording-Callback für unbekannte Requests
3. Einen `afterResponse`-Hook um neue Responses auf Disk zu schreiben

#### 2. `src/ReplayStorage.php`
Liest/schreibt Response-Dateien auf Disk.

```
Methoden:
- getTestDirectory(): string                # Leitet Pfad aus Pest TestSuite ab
- getSharedDirectory(string $name): string  # _shared/{name}/
- findStoredResponses(string $dir): array   # Alle gespeicherten Responses laden
- store(Request $request, Response $response, string $dir): void
- deleteByPattern(string $pattern): void    # Für fresh/renewal
- isExpired(string $file, int $days): bool  # Für expireAfter
```

#### 3. `src/ReplayNamer.php`
Erzeugt Dateinamen aus Request-Daten.

```
Methoden:
- fromRequest(Request $request): string     # GET_shopify.com_api_products.json
- fromAttribute(string $name): string       # products.json
- fromBodyHash(Request $request): string    # POST_shopify.com_graphql__a1b2c3.json
```

Naming-Strategie:
- Hat der Request ein `replay` Attribute? → `{attribute_value}.json`
- Ist `matchBy` mit 'body' aktiv? → `{METHOD}_{host}_{path}__{bodyHash}.json`
- Default: `{METHOD}_{host}_{path}.json`
- Bei Duplikaten: Counter wie Pest (`__2`, `__3`, etc.)

#### 4. `src/ResponseSerializer.php`
Serialisiert Laravel Response-Objekte zu/von JSON.

```
Methoden:
- serialize(Request $request, Response $response): array
- deserialize(array $data): Response        # Gibt Http::response() zurück
```

#### 5. `src/Commands/ReplayFreshCommand.php`
Artisan Command zum Löschen von gespeicherten Fakes.

```
Optionen: --test, --file, --url, --shared
Logik: Delegiert an ReplayStorage::deleteByPattern()
```

### Zu ändernde bestehende Dateien

#### `src/LaravelEasyHttpFakeServiceProvider.php`
- `boot()`: Macro `Http::replay()` auf `Factory` registrieren
- Config, Migration, Views entfernen (nicht benötigt)
- Command registrieren: `ReplayFreshCommand`

#### `src/LaravelEasyHttpFake.php`
- Wird zum Container/Facade-Accessor für Storage-Konfiguration (base path etc.)

#### `src/Facades/LaravelEasyHttpFake.php`
- Bleibt als Facade, ggf. umbenennen zu `Replay`

#### `config/easy-http-fake.php`
```php
return [
    'storage_path' => 'tests/.http-replays',
    'match_by' => ['url', 'method'],           // Default matching
    'expire_after' => null,                     // Tage, null = nie
];
```

#### `database/migrations/` → Löschen (kein DB nötig)

### Kern-Mechanismus: Record vs. Replay

```
Http::replay() aufgerufen
    │
    ├─ ReplayStorage: Gibt es gespeicherte Fakes für diesen Test?
    │   ├─ JA → Http::fake() mit gespeicherten Responses
    │   │       Unbekannte Requests → echter Call → auf Disk speichern
    │   └─ NEIN → Alle Requests durchlassen → aufnehmen → auf Disk speichern
    │
    └─ Nach dem Test: Neue Recordings auf Disk persistieren
```

Technisch nutzt der Builder `Http::fake($callback)` mit einer Closure die:
1. Prüft ob ein gespeicherter Fake zum Request passt
2. Wenn ja → Fake-Response zurückgibt
3. Wenn nein → `null` zurückgibt (Laravel macht den echten Call)
4. Per `Http::globalMiddleware()` oder Recording-Hook den echten Response abfängt und speichert

### Test-Name Auflösung (Pest)

Nutzt `Pest\TestSuite::getInstance()`:
```php
$filename = TestSuite::getInstance()->getFilename();    // /abs/path/tests/Feature/ShopifyTest.php
$description = TestSuite::getInstance()->getDescription(); // it_fetches_products
```

Daraus wird: `tests/.http-replays/Feature/ShopifyTest/it_fetches_products/`

---

## Implementierungs-Reihenfolge

1. **ReplayStorage + ReplayNamer** — File I/O und Naming-Logik
2. **ResponseSerializer** — Response zu/von JSON
3. **ReplayBuilder** — Fluent API + Http::fake() Integration
4. **Service Provider** — Macro registrieren
5. **ReplayFreshCommand** — Artisan Command
6. **Config** — Konfigurationsdatei
7. **Tests** — Für jeden Schritt

---

## Verifikation

```bash
# Unit Tests für Storage, Namer, Serializer
composer test

# Integration Test: Echter HTTP Call wird recorded
# 1. Test mit Http::replay() und echtem Endpoint schreiben
# 2. Erster Run: prüfen dass .http-replays/ Datei erstellt wird
# 3. Zweiter Run: prüfen dass kein Netzwerk-Call stattfindet

# Artisan Command testen
php artisan replay:fresh --test="it fetches products"

# PHPStan
composer analyse
```

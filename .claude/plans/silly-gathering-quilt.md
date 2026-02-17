# Plan: 5 neue Matcher hinzufügen

## Context

Aktuell gibt es 8 Matcher-Klassen (HttpMethod, Url, Host, Domain, Subdomain, HttpAttribute, BodyHash, Closure). Es fehlen Matcher für häufige Anwendungsfälle: reiner Pfad ohne Host, Query-Parameter (Hash und Einzelwert), Request-Header, und JSON-Body-Feldwerte ohne Hashing.

## Neue Matcher

### 1. `PathMatcher` — Config-String: `path`

Nur der Pfad ohne Host. Komplement zu `host`.

```php
class PathMatcher implements NameMatcher
{
    public function resolve(Request $request): ?string
    {
        $parsed = parse_url($request->url());
        $path = trim($parsed['path'] ?? '', '/');

        return $path !== '' ? $path : null;
    }
}
```

| Input | Output |
|---|---|
| `https://shop.myshopify.com/api/v1/products` | `api/v1/products` |
| `https://example.com` | `null` |

---

### 2. `QueryHashMatcher` — Config-Strings: `query_hash`, `query_hash:key1,key2`

Hash der Query-Parameter. Pendant zu `body_hash` für GET-Requests.

```php
class QueryHashMatcher implements NameMatcher
{
    /** @var list<string> */
    protected array $keys;

    public function __construct(array $keys = []) { $this->keys = $keys; }

    public function resolve(Request $request): ?string
    {
        $parsed = parse_url($request->url());
        $queryString = $parsed['query'] ?? '';

        if ($queryString === '') {
            return null;
        }

        parse_str($queryString, $params);

        if ($this->keys !== []) {
            $subset = [];
            foreach ($this->keys as $key) {
                $subset[$key] = Arr::get($params, $key);
            }
            $params = $subset;
        }

        return substr(md5(json_encode($params) ?: ''), 0, 6);
    }
}
```

| Config | Input | Output |
|---|---|---|
| `query_hash` | `?page=2&limit=10` | `a3f1b2` |
| `query_hash:page` | `?page=2&limit=10` | `b4c2d1` (nur page) |
| — | keine Query-Params | `null` |

---

### 3. `QueryParamMatcher` — Config-String: `query:key`

Einzelnen Query-Parameter-Wert extrahieren.

```php
class QueryParamMatcher implements NameMatcher
{
    public function __construct(protected string $key) {}

    public function resolve(Request $request): ?string
    {
        $parsed = parse_url($request->url());
        $queryString = $parsed['query'] ?? '';
        parse_str($queryString, $params);

        $value = Arr::get($params, $this->key);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
```

| Config | Input | Output |
|---|---|---|
| `query:action` | `?action=getProducts` | `getProducts` |
| `query:page` | `?page=3` | `3` |
| `query:missing` | `?foo=bar` | `null` |

---

### 4. `HeaderMatcher` — Config-String: `header:key`

Wert eines bestimmten Request-Headers.

```php
class HeaderMatcher implements NameMatcher
{
    public function __construct(protected string $key) {}

    public function resolve(Request $request): ?string
    {
        $value = $request->header($this->key);

        if ($value === '') {
            return null;
        }

        return $value;
    }
}
```

`Request::header(string)` gibt den String-Wert zurück oder `''` wenn nicht vorhanden.

| Config | Input | Output |
|---|---|---|
| `header:X-Api-Version` | `X-Api-Version: v2` | `v2` |
| `header:Accept` | `Accept: application/json` | `application/json` |
| `header:Missing` | (nicht gesetzt) | `null` |

---

### 5. `BodyFieldMatcher` — Config-String: `body_field:path`

Konkreten Wert aus dem JSON-Body per Dot-Notation extrahieren (nicht hashen).

```php
class BodyFieldMatcher implements NameMatcher
{
    public function __construct(protected string $path) {}

    public function resolve(Request $request): ?string
    {
        $data = json_decode($request->body(), true);

        if (! is_array($data)) {
            return null;
        }

        $value = Arr::get($data, $this->path);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
```

| Config | Input Body | Output |
|---|---|---|
| `body_field:operationName` | `{"operationName": "GetProducts", ...}` | `GetProducts` |
| `body_field:variables.id` | `{"variables": {"id": "123"}}` | `123` |
| `body_field:missing` | `{"foo": "bar"}` | `null` |
| `body_field:op` | `plain text` | `null` |

## Änderungen in `ReplayNamer::parseMatchers()`

Neue Cases in der `match(true)` Expression:

```php
$field === 'path' => new PathMatcher,
$field === 'query_hash' => new QueryHashMatcher,
str_starts_with($field, 'query_hash:') => new QueryHashMatcher(
    explode(',', substr($field, strlen('query_hash:')))
),
str_starts_with($field, 'query:') => new QueryParamMatcher(
    substr($field, strlen('query:'))
),
str_starts_with($field, 'header:') => new HeaderMatcher(
    substr($field, strlen('header:'))
),
str_starts_with($field, 'body_field:') => new BodyFieldMatcher(
    substr($field, strlen('body_field:'))
),
```

## Betroffene Dateien

| Datei | Änderung |
|---|---|
| `src/Matchers/PathMatcher.php` | **NEU** |
| `src/Matchers/QueryHashMatcher.php` | **NEU** |
| `src/Matchers/QueryParamMatcher.php` | **NEU** |
| `src/Matchers/HeaderMatcher.php` | **NEU** |
| `src/Matchers/BodyFieldMatcher.php` | **NEU** |
| `src/ReplayNamer.php` | 5 neue Cases in `parseMatchers()` + Imports |
| `tests/Matchers/PathMatcherTest.php` | **NEU** |
| `tests/Matchers/QueryHashMatcherTest.php` | **NEU** |
| `tests/Matchers/QueryParamMatcherTest.php` | **NEU** |
| `tests/Matchers/HeaderMatcherTest.php` | **NEU** |
| `tests/Matchers/BodyFieldMatcherTest.php` | **NEU** |
| `tests/ReplayNamerTest.php` | Tests für neue Config-Strings |
| `config/http-replay.php` | Kommentar bei `match_by` erweitern |
| `README.md` | Matcher-Tabelle erweitern |
| `resources/boost/skills/http-replay-testing/SKILL.md` | Matcher-Tabelle erweitern |

## Verifikation

```bash
composer test
composer analyse
```

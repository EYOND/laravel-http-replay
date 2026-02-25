# Plan: withAttributes-Doku in README und SKILL.md präzisieren

## Context

Die README-Sektion "Via `withAttributes`" (Zeile 64–80) zeigt `withAttributes(['replay' => 'products'])` ohne zu erklären, dass `replay` ein **reserviertes Attribut** ist, das im `ReplayNamer` (Zeile 24–29) hardcoded Priorität über alle Matcher hat. Ein Leser könnte denken, jedes beliebige Attribut funktioniert automatisch — braucht aber für andere Keys `matchBy('attribute:key')`.

## Änderungen

### 1. `README.md` (Zeile 64–80)

Die `withAttributes`-Sektion erweitern:
- Erklären, dass `replay` ein reserviertes Attribut ist, das alle Matcher umgeht
- Ein zweites Beispiel ergänzen, das zeigt, wie man eigene Attribute mit `matchBy('attribute:key')` nutzt

**Vorher:**
```markdown
#### Via `withAttributes`

Give each request a unique name using Laravel's `withAttributes`:

\```php
it('fetches products and orders via GraphQL', function () {
    Http::replay();

    $products = Http::withAttributes(['replay' => 'products'])
        ->post('https://shopify.com/graphql', ['query' => '{products{...}}']);

    $orders = Http::withAttributes(['replay' => 'orders'])
        ->post('https://shopify.com/graphql', ['query' => '{orders{...}}']);
});
\```

This stores the responses as `products.json` and `orders.json`.
```

**Nachher:**
```markdown
#### Via `withAttributes`

The `replay` attribute is a **reserved key** that always takes priority over all matchers — no `matchBy` configuration needed:

\```php
it('fetches products and orders via GraphQL', function () {
    Http::replay();

    $products = Http::withAttributes(['replay' => 'products'])
        ->post('https://shopify.com/graphql', ['query' => '{products{...}}']);

    $orders = Http::withAttributes(['replay' => 'orders'])
        ->post('https://shopify.com/graphql', ['query' => '{orders{...}}']);
});
\```

This stores the responses as `products.json` and `orders.json`.

For custom attribute keys, use `matchBy('attribute:key')`:

\```php
it('uses a custom attribute for naming', function () {
    Http::replay()->matchBy('method', 'attribute:operation');

    Http::withAttributes(['operation' => 'getProducts'])
        ->post('https://shopify.com/graphql', ['query' => '{products{...}}']);
});
\```
```

### 2. `resources/boost/skills/http-replay-testing/SKILL.md` (Zeile 76–81)

Analog anpassen — `replay` als reserviertes Attribut kennzeichnen:

**Vorher:**
```markdown
**1. withAttributes** (explicit naming):
\```php
Http::withAttributes(['replay' => 'products'])
    ->post('https://shop.com/graphql', ['query' => '{products{...}}']);
// Stored as: products.json
\```
```

**Nachher:**
```markdown
**1. withAttributes** (`replay` is a reserved key — always takes priority, no `matchBy` needed):
\```php
Http::withAttributes(['replay' => 'products'])
    ->post('https://shop.com/graphql', ['query' => '{products{...}}']);
// Stored as: products.json
\```

For custom attributes, use `matchBy('attribute:key')`:
\```php
Http::replay()->matchBy('method', 'attribute:operation');
Http::withAttributes(['operation' => 'getProducts'])
    ->post('https://shop.com/graphql', ['query' => '{products{...}}']);
\```
```

## Betroffene Dateien

| Datei | Änderung |
|---|---|
| `README.md` | withAttributes-Sektion erweitern (Zeile 64–80) |
| `resources/boost/skills/http-replay-testing/SKILL.md` | withAttributes-Sektion erweitern (Zeile 76–81) |

## Verifikation

Reine Doku-Änderung — kein Code betroffen. Kurze Prüfung:
- README lesen und sicherstellen, dass die Beispiele korrekt sind
- SKILL.md lesen und Konsistenz mit README prüfen

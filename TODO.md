# API Review — Verbesserungsvorschläge

## Freigegeben: umsetzen

### 1. `REPLAY_FRESH` ENV-Parity mit `REPLAY_BAIL`
`REPLAY_BAIL` prüft `$_SERVER` via `shouldBail()`, aber `REPLAY_FRESH` funktioniert nur über Config. Inkonsistenz — beide sollten gleich funktionieren.
Feedback: Guter Vorschlag

### 2. Kürzere Matcher-Namen
`http_method` → `method`, `http_attribute:key` → `attribute:key`. Das `http_`-Prefix ist redundant. Backward-Compat-Alias für `method` existiert bereits.
Feedback: Guter Vorschlag

### 5. `for()` leakt State
Wenn kein `matchBy()` nach `for()` folgt, hängt `$currentForPattern` am nächsten Call. Optionen:
- Proxy-Objekt zurückgeben das nur `matchBy()` erlaubt
- `for('pattern', matchBy: [...])` als Alternative
Feedback: Ich denke, das Proxy Objekt wäre die beste Lösung, falls das nicht zu kompliziert ist.

### 7. `from()` vs `storeIn()` asymmetrisch
Könnte zu einem `storeIn('shopify')` vereinheitlicht werden (liest wenn vorhanden, schreibt wenn nicht). Nur relevant wenn der Edge-Case "laden aus shared, speichern in test-dir" selten ist.
Feedback: Wichtiger Punkt fürmich. Dieser Punkt ist mir ebenfalls aufgefallen. Ich denke, wir sollten mit `readFrom($sharedPath1, $sharedPath1)` und `writeTo($sharedPath)` klarer unterschiedbar machen, was im Lese- und Schreibefall passieren soll. Man soll auch auf jeden Fall aus mehreren Directories lesen können. Wenn man für beides das selbe Verzeichnis will, würde ich `directory($sharedPath)` oder `useShared($sharedPath)` empfehlen. 

### 8. `fake()` → `withFakes()` oder `alsoFake()`
`fake()` auf dem ReplayBuilder kollidiert konzeptionell mit `Http::fake()`. Ein deutlicherer Name macht klar, dass es zusätzliche Stubs sind.
Feedback: `alsoFake()` finde ich gut.

### 10. `expireAfter(int $days)` nur Tage
Kein Problem aktuell, aber `DateInterval`-Support wäre Laravel-typischer für die Zukunft.
Feedback: Guter Vorschlag

### 11. Config `storage_path` Dokumentation
Klarer dokumentieren, dass relative Pfade von `base_path()` aufgelöst werden.
Feedback: Guter Vorschlag

### 12. `Replay::get()` nur für shared
Test-spezifische Replays können nicht geladen werden. Dokumentieren oder Parameter hinzufügen.
Feedback: In `Replay::getShared()` umbennen und klar dokumentieren.

## Nicht freigegeben: zurückgestellt

### 3. `Http::replayAs()` Macro
`withAttributes(['replay' => 'products'])` ist schwer entdeckbar. Ein Shortcut-Macro wäre besser:
```php
Http::replayAs('products')->post('https://shopify.com/graphql', ['query' => '...']);
```
Feedback: Ich will hier keinen Default wie `replay` vorgeben.

### 4. `only()` variadic statt Array
`only('shopify.com/*', 'stripe.com/*')` wäre konsistenter mit `matchBy()` und Laravel-Konventionen wie `$query->select('a', 'b')`.
Feedback: ich finde only hier sehr gut vom Naming

### 6. `Replay::get()` Pfad ist fragil
Benutzer muss internen Dateinamen kennen. Optionen:
- `Replay::from('shopify')->get('filename.json')`
- Zwei-Argument-Form: `Replay::get('shopify', 'filename.json')`
Feedback: Es ist in diesem Fall so gewollt, dass der Nutzer den exakten Pfad verwendet.

### 9. `fresh()` ist überladen
Gleichzeitig Toggle und Pattern-Filter. Könnte ein reiner Toggle sein, Pattern-basiertes Löschen nur via Artisan.

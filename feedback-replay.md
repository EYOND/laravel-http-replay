### Naming verbessern
Hier einige naming verbesserungen
* `Http::replay()->storeAs('shopify')` -> `Http::replay()->storeIn('shopify')`
* Delete all stored replays: `php artisan replay:fresh` -> `php artisan replay:prune`

### Rename to Laravel Replay
Ich möchte das Package generell zu Laravel Http Replay umbenennen. Hier ein paar Hinweise ohne Anspruch auf Vollständigkeit:
* LaravelEasyHttpFake.php -> LaravelHttpReplay.php
* LaravelEasyHttpFakeServiceProvider -> LaravelEasyHttpFakeServiceProvider
* "name": "pikant/laravel-easy-http-fake" -> "name": "pikant/laravel-http-replay"
* Rename default config `'storage_path' => 'tests/.http-replays'` -> `'storage_path' => 'tests/.laravel-http-replay'`

Http::replay() finde ich gut, das soll beibehalten werden.

### ReplayNamer / ->matchBy weiter ausbauen
Ich möchte hier generell viele einzelne Name Matcher bereitstellen, aus denen der Anwender ausschen kann
* default (ich denke, Http Methode + URL ist ein guter Default)
* http_method
* subdomain
* host
* url (ohne https usw.)
* http_attribute (Anwendung `http_attribute:laravel_replay` oder `http_attribute:object.slug`)
* body_hash (Anwendung `body_hash`, `body_hash:query`, `body_hash:query,variables.id` -> bei mehreren keys, ein hash für alles; wenn man mehrere Hashes will, kann man body_hash mehrfach kofigurieren)
* closure (wichtig)

Leere Werte sollen jeweils sauber gehandhabt werden, damit wir keine unnötigen `___` einbauen.

### Read from shared
Möglichkeit, möglichst einfach einzelnen geteilte Fakes zu verwenden:
```php
Http::fake([
    'foo.com/posts/*' => Replay::get('fresh-test/GET_jsonplaceholder_typicode_com_posts_3.json') // -> tests/.http-replays/_shared/fresh-test/GET_jsonplaceholder_typicode_com_posts_3.json
])
```

### Konfiguration pro URL/Host
* Kern-Anforderung: Man will für verschiedene APIs auch verschiedene Naming / Resolver Konfigurationen 
* Beispiel:
  * Shopify: url + http_attribute:request_name + http_attribute:request_id
  * Reybex: http_method + url
* Die Konfigraution sollte am besten innerhalb der Pest.php passieren, nicht in einer Config, damit man die Kongiuratuion auch innerhalb einzelnen Tests überschreiben kann
* Konfiguration pro URL, ähnlich wie bei `Http::fake(['myshopify.com/api/2025-01/*' => ..., 'myshopify.com/api/2026-01/*' => ...])`
* Startpunkt: Http::replay()->configure('...')

### Bail on CI
Innerhalb der CI pipeline wollen wir naürlich keine neuen Fakes anlegen oder verändern. Deshalb will ich eine Option, den ich an `vendor/bin/pest` anhängen kann, die den Test failen lässt, wenn Replay versucht zu schreiben.

### Make Test incorrect when something was written
Ähnlich wie bei `expect('foo')->toMatchSnapshot();` soll der Test als gelb / incomplete markiert werden, wenn Replay innerhalb dieses Tests Daten verändert bzw. hinzugefügt hat. Das ist nützlich als Feedback für den Entwickler damit er sieht, wenn Replay Dinge verändert.

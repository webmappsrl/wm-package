> Ticket: oc:8681

# Nome delle tracce nella lingua dell'utente nei risultati di ricerca — piano di implementazione

> **Per chi esegue:** usare superpowers:subagent-driven-development o superpowers:executing-plans,
> task per task. Gli step usano le checkbox (`- [ ]`). **Nessun `git add`/`git commit`/`git push`
> durante l'esecuzione**: gli step «Commit» sono istruzioni per il dev, da eseguire solo dopo la sua
> approvazione.

**Goal:** l'endpoint di ricerca di wm-package restituisce `name` delle tracce con tutte le lingue,
così l'app mostra nelle card il nome nella lingua dell'utente.

**Architecture:** `EcTrack::toSearchableArray()` aggiunge all'indice un campo nuovo,
`name_translations`, lasciando `name` com'è (stringa italiana, mapping e ordinamento invariati).
`ElasticsearchController::index()`, prima di rispondere, mette `name_translations` al posto di
`name` in ogni risultato che lo ha non vuoto e toglie `name_translations` da tutti i risultati.

**Tech Stack:** PHP 8.4, Laravel 12, Laravel Scout con driver Matchish (Elasticsearch 8),
spatie/laravel-translatable, Pest/PHPUnit.

**Spec:** [overview.md](overview.md)

## Global Constraints

- Tutto il codice in **wm-package**; in maphub nessun file (il bump del submodule lo fanno i dev).
- `name` nell'indice resta `getTranslation('name', 'it')`; `orderBy('name.keyword', 'asc')` invariato.
- `name_translations` dichiarato nel mapping come `object`, **senza** `enabled: false`.
- La risposta dell'API contiene gli stessi campi di oggi: nessun `name_translations` nei risultati.
- Nessuna modifica a wm-core, webmapp-app, wm-types.
- Commenti e documentazione in italiano, termini tecnici in inglese.
- Test del package: si lanciano da maphub (`vendor/bin/pest wm-package/tests/...`), con
  `Wm\WmPackage\Tests\TestCase`; i record usati vanno creati nel test (`.claude/rules/test.md`).

## Review Focus

1. **Traccia senza nome o con nome vuoto** (`name` null o `{}`): `name_translations` deve essere un
   array vuoto e il risultato dell'API deve mantenere il `name` di oggi → test in Task 1 e Task 2.
2. **Lingue vuote nel nome** (`{"it":"…","en":""}`): non devono finire in `name_translations` →
   test in Task 1.
3. **Indice misto** (alcuni risultati con `name_translations`, altri senza, altri con `[]`): ogni
   risultato va trattato da solo → test in Task 2.
4. **`name_translations` non array** (es. `null` o stringa, da un documento anomalo): va trattato
   come vuoto, senza errori → test in Task 2.
5. **Risposta senza `hits`** (nessun risultato): la trasformazione non deve fallire → test in Task 2.

---

### Task 1: `name_translations` nell'indice e nel mapping

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#comando-dei-test)
> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-costante-e-test-del-mapping)

**Files:**
- Modify: `src/Models/EcTrack.php` (`toSearchableArray()`, accanto a `'name' =>` alla riga 748)
- Modify: `config/wm-elasticsearch.php` (`indices.mappings.default.properties`, dopo il blocco `name`)
- Test: `tests/Unit/Models/EcTrackSearchableArrayTest.php`

**Interfaces:**
- Produces: chiave `name_translations` nell'array di `EcTrack::toSearchableArray()`, di tipo
  `array<string, string>` (lingua → nome, solo lingue valorizzate); chiave di config
  `wm-elasticsearch.indices.mappings.default.properties.name_translations` = `['type' => 'object']`.

- [x] **Step 1: Scrivere i test che falliscono** (metodi nella classe esistente, stesso stile)

```php
public function test_name_translations_contains_all_filled_languages()
{
    $track = EcTrack::factory()->createQuietly([
        'osmid' => null,
        'name' => ['it' => 'V6 – Anello di Vecchiano', 'en' => 'V6 - Loop of Vecchiano', 'fr' => ''],
    ]);

    $array = $track->toSearchableArray();

    $this->assertSame(
        ['it' => 'V6 – Anello di Vecchiano', 'en' => 'V6 - Loop of Vecchiano'],
        $array['name_translations']
    );
    $this->assertSame('V6 – Anello di Vecchiano', $array['name']);
}

public function test_name_translations_is_empty_when_the_name_is_empty()
{
    $track = EcTrack::factory()->createQuietly(['osmid' => null, 'name' => []]);

    $this->assertSame([], $track->toSearchableArray()['name_translations']);
}

public function test_mapping_declares_name_translations_as_object()
{
    $this->assertSame(
        ['type' => 'object'],
        config('wm-elasticsearch.indices.mappings.default.properties.name_translations')
    );
}
```

- [x] **Step 2: Lanciare i test e verificare che falliscano**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Unit/Models/EcTrackSearchableArrayTest.php`
Expected: FAIL sui tre test nuovi (`Undefined array key "name_translations"` e config `null`); i tre
test esistenti su `ascent` passano.

- [x] **Step 3: Implementare**

In `toSearchableArray()` aggiungere `'name_translations' => $this->getTranslations('name'),` subito
dopo `'name'` (`getTranslations()` scarta già le lingue nulle o vuote). In
`config/wm-elasticsearch.php` aggiungere `'name_translations' => ['type' => 'object'],` con un
commento: il campo esiste per mostrare il nome nella lingua dell'utente (oc:8681); niente
`enabled: false`, così è uguale negli indici vecchi, dove nasce in modo dinamico, e in quelli
ricreati.

- [x] **Step 4: Lanciare i test e verificare che passino**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Unit/Models/EcTrackSearchableArrayTest.php wm-package/tests/Feature/EcTrackToSearchableArrayFromToTest.php`
Expected: PASS su tutti.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add src/Models/EcTrack.php config/wm-elasticsearch.php tests/Unit/Models/EcTrackSearchableArrayTest.php
git commit -m "fix(oc:8681): salva nell'indice il nome delle tracce in tutte le lingue"
```

---

### Task 2: l'API restituisce `name` con tutte le lingue

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-chiamata-in-index)
> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#comando-dei-test)

**Files:**
- Modify: `src/Http/Controllers/Api/ElasticsearchController.php` (`index()`, prima del `return $resultsArray;` alla riga ~249; nuovo metodo statico)
- Test: `tests/Unit/Http/ElasticsearchControllerLocalizeHitNamesTest.php` (nuovo)

**Interfaces:**
- Consumes: chiave `name_translations` nei documenti dell'indice (Task 1).
- Produces: `public static function localizeHitNames(array $hits): array` in
  `ElasticsearchController`: per ogni hit, se `name_translations` è un array non vuoto lo mette in
  `name`; in ogni caso toglie `name_translations`. Non tocca le altre chiavi.

- [x] **Step 1: Scrivere i test che falliscono** (classe `Wm\WmPackage\Tests\Unit\Http\ElasticsearchControllerLocalizeHitNamesTest extends Wm\WmPackage\Tests\TestCase`)

```php
public function test_mixed_hits_are_localized_one_by_one()
{
    $hits = [
        ['id' => 57, 'name' => 'V6 – Anello di Vecchiano',
            'name_translations' => ['it' => 'V6 – Anello di Vecchiano', 'en' => 'V6 - Loop of Vecchiano']],
        ['id' => 38, 'name' => 'Percorso 2 – Circuit Haut Estéron'],
        ['id' => 76, 'name' => 'Boucle 1', 'name_translations' => []],
        ['id' => 99, 'name' => 'Senza traduzioni', 'name_translations' => null],
    ];

    $result = ElasticsearchController::localizeHitNames($hits);

    $this->assertSame(['it' => 'V6 – Anello di Vecchiano', 'en' => 'V6 - Loop of Vecchiano'], $result[0]['name']);
    $this->assertSame('Percorso 2 – Circuit Haut Estéron', $result[1]['name']);
    $this->assertSame('Boucle 1', $result[2]['name']);
    $this->assertSame('Senza traduzioni', $result[3]['name']);
    foreach ($result as $hit) {
        $this->assertArrayNotHasKey('name_translations', $hit);
    }
    $this->assertSame(57, $result[0]['id']);
}

public function test_empty_hits_return_empty()
{
    $this->assertSame([], ElasticsearchController::localizeHitNames([]));
}
```

- [x] **Step 2: Lanciare i test e verificare che falliscano**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Unit/Http/ElasticsearchControllerLocalizeHitNamesTest.php`
Expected: FAIL con `Call to undefined method …::localizeHitNames()`.

- [x] **Step 3: Implementare `localizeHitNames(array $hits): array`** e chiamarlo in `index()`:
`$resultsArray['hits'] = self::localizeHitNames($resultsArray['hits'] ?? []);` prima del return.
Commento sul metodo: `name` nell'indice resta la stringa italiana, su cui si ordina
(`name.keyword`); il frontend (wm-core `search-box`, pipe `wmtrans`) mostra `name` e con un oggetto
sceglie la lingua dell'utente, come con Geohub. La differenza fra indice e risposta è voluta
(oc:8681); il ripiego sul `name` di oggi serve agli indici non ancora reindicizzati.

- [x] **Step 4: Lanciare i test e verificare che passino**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Unit/Http/ElasticsearchControllerLocalizeHitNamesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add src/Http/Controllers/Api/ElasticsearchController.php tests/Unit/Http/ElasticsearchControllerLocalizeHitNamesTest.php
git commit -m "fix(oc:8681): l'API di ricerca restituisce il nome delle tracce in tutte le lingue"
```

---

### Task 3: verifiche finali e documentazione

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-analisi-statica)

**Files:**
- Create: `docs/knowledge/<argomento>.md` (pagina sul nome delle tracce nella ricerca; nome e
  contenuto decisi in `update-context` di wm-plan, con `wm-context-guard`)
- Modify: `CLAUDE.md` di wm-package (riga nell'indice `## Conoscenza`)

- [x] **Step 1: Analisi statica**

Run (da maphub): `docker exec php-maphub vendor/bin/phpstan analyse`
Expected: nessun errore sui file toccati.

- [x] **Step 2: Formattazione limitata ai file toccati**

Run: `docker exec php-maphub vendor/bin/pint wm-package/src/Models/EcTrack.php wm-package/src/Http/Controllers/Api/ElasticsearchController.php wm-package/config/wm-elasticsearch.php wm-package/tests/Unit/Models/EcTrackSearchableArrayTest.php wm-package/tests/Unit/Http/ElasticsearchControllerLocalizeHitNamesTest.php`
Expected: nessun file fuori dal lavoro modificato (`git status`).

- [x] **Step 3: Pagina di conoscenza** con: come funziona oggi (`name` stringa italiana per
ordinamento, `name_translations` per la lingua, sostituzione nell'API con ripiego), perché così
(campo nuovo accettato dagli indici esistenti senza ricrearli, salvataggi sempre funzionanti), come
ci siamo arrivati (`name` come oggetto scartato: cambio di mapping, eccezione al salvataggio fino a
`scout:import`); dopo un bump basta «Reindicizza Scout» per app.

- [ ] **Step 4: Verifica manuale su maphub dev** (dopo merge e bump, a cura del dev): «Reindicizza
Scout» sull'app 3; con l'app in inglese su https://3.maphubdev.maphub.it/ la ricerca «Loop» mostra
nella card della traccia 57 «V6 - Loop of Vecchiano». Controllo da API:
`https://dev.maphub.it/api/v2/elasticsearch?app=geohub_app_3&query=Loop` → la 57 ha `name` oggetto
con `it` ed `en`, e nessun risultato ha `name_translations`.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add docs/ CLAUDE.md
git commit -m "docs(oc:8681): nome delle tracce nella ricerca"
```

> Ticket: oc:8588

# Mostrare solo la regione nel dettaglio tappa — Piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** riportare `properties.taxonomy_where` al formato vecchio `{id: {<lingue>, _admin_level, _source}}` in tutti gli scrittori di wm-package e aggiungere un'opzione per App (select multipla) che filtra, solo nelle uscite pubbliche, le località mostrate per categoria (livello amministrativo o sorgente).

**Architecture:** un servizio nuovo, `TaxonomyWhereDisplayService` (registrato `scoped`, quindi azzerato a ogni richiesta e a ogni job), concentra tutta la logica pura: categoria di una voce, normalizzazione alla forma vecchia, filtro, ordinamento, lettura dell'opzione App. `GeometryModel` espone un solo metodo, `applyTaxonomyWhereDisplay(array $properties): array`, chiamato **solo** dai punti che producono l'uscita pubblica; `GeoJsonService::getModelAsGeojson()` resta invariato. Un servizio di riallineamento (`TaxonomyWhereResyncService`) e un comando artisan riallineano i dati in modo conservativo e rigenerano le uscite in due fasi (prima i sync di tutti i record, poi json statici, Elasticsearch e `pois.geojson`).

**Tech Stack:** Laravel (10–13), Nova 5, PostgreSQL/PostGIS, Laravel Scout + Elasticsearch, Pest/PHPUnit, code Horizon.

**Spec:** [overview.md](overview.md)

## Global Constraints

- Tutto il codice è nel submodule **wm-package**; nessuna modifica a wm-core, webmapp-app, wp-geohub; in camminiditalia solo il bump del submodule e il valore dell'opzione in Nova.
- PHP minimo `>8.1`: **mai `const` dentro un trait** (le costanti vanno sulle classi).
- Le geometrie PostGIS passano sempre da SQL puro, mai dall'ORM (le scritture di `taxonomy_where` sono `UPDATE` SQL, come oggi).
- Traduzioni in `resources/lang/{it,en,de,es,fr}.json` (non in `lang/`), testo base in inglese come le altre label Nova del package.
- Documentazione, commenti e messaggi di commit in italiano; commit `feat(oc:8588): …`.
- **Nessun commit, `git add`, `git push` o branch eseguito dall'agente**: gli step "Commit" sono istruzioni per il dev, che committa dopo aver letto il diff.
- Un test che crea un'App con `native_app_deep_link_enabled = true` deve fakare `Bus` e il disco `well_known_registry` (regola in cima a `wm-package/CLAUDE.md`). Nessun test di questo piano abilita quel toggle; i test che salvano un'App fakano comunque `Bus`.
- I test del package estendono `Tests\TestCase` del consumer: si eseguono **dal root di camminiditalia**, che vede il codice del branch e usa il DB separato `camminiditalia_testing`. Comando: `docker exec laravel-camminiditalia php artisan test wm-package/<file>`. (`vendor/bin/pest` lanciato dentro `wm-package/` non trova `Tests\TestCase`; `php-maphub` monta un'altra copia del package, `maphub/wm-package`.)
- Chiave dell'opzione App: `properties->taxonomy_where_display`, array di stringhe (categorie). Array vuoto o chiave assente = nessun filtro.
- Formato categoria: `"<N>"` (livello amministrativo come stringa di cifre), `"source:<valore>"`, oppure `null`.
- Chiavi lingua riconosciute: chiavi che rispettano `^[a-z]{2,3}$` con valore stringa non vuota.
- Postgres riordina le chiavi `jsonb` (per lunghezza, poi alfabetico): nei test, per gli array associativi letti dal DB, usare `toEqual`/`assertEquals` e non `toBe`/`assertSame`, che controllano anche l'ordine.

## Review Focus

1. **Record salvato nel formato oc:8487 e nessuna categoria selezionata**: l'uscita deve essere nella forma vecchia (altrimenti wm-core e wp-geohub non mostrano nulla). Test in Task 1 (`normalizes an oc:8487 entry to the legacy shape`) e in Task 3 (`applyTaxonomyWhereDisplay` senza opzione).
2. **`_taxonomy_where_backup` scritto dal riallineamento**: non deve mai comparire nelle uscite pubbliche, perché `GeoJsonService` copia tutto `properties`. Test in Task 3 (`strips the backup key from public output`).
3. **`_admin_level` salvato come stringa `"4"` e categoria selezionata `"4"`** (e viceversa intero): il filtro deve funzionare. Test in Task 1 (`computes the same category for int and string levels`).
4. **Worker Horizon a lunga vita**: l'opzione letta per un'App non deve restare in memoria fra un job e il successivo, dopo una modifica in Nova. Coperto dalla registrazione `scoped` (Task 1) e dal test `reads the option again after the scoped instance is flushed`.
5. **osmfeatures risponde vuoto durante il riallineamento**: il valore esistente non deve essere toccato. Test in Task 6 (`leaves the current value untouched when nothing is found`).

---

## Mappa dei file

| File | Responsabilità |
|---|---|
| `src/Services/TaxonomyWhereDisplayService.php` (nuovo) | categoria, normalizzazione, filtro, ordinamento, opzione App, categorie disponibili, etichette, classificazione del formato |
| `src/WmPackageServiceProvider.php` | registrazione `scoped` del servizio, registrazione del comando |
| `src/Services/GeometryComputationService.php` | SQL di sync nella forma vecchia; nuovo `computeTaxonomyWhere()` (calcolo senza scrittura) |
| `src/Jobs/TaxonomyWhere/SyncModelTaxonomyWhereJob.php` | fallback osmfeatures mappato nella forma vecchia |
| `src/Jobs/UpdateModelWithGeometryTaxonomyWhere.php` | aggiunta di `_source` (UGC) |
| `src/Models/Abstracts/GeometryModel.php` | `applyTaxonomyWhereDisplay()`, `getOrderedTaxonomyWheres()` delegato al servizio |
| `src/Http/Resources/EcTrackResource.php`, `src/Http/Resources/EcPoiResource.php` | filtro sul json statico della traccia e sui `related_pois` |
| `src/Models/App.php` | filtro in `getAllPoisGeojson()` (`pois.geojson`) |
| `src/Http/Controllers/Api/EcTrackController.php` | filtro sui due rami che servono `getGeojson()` dal vivo |
| `src/Http/Controllers/Api/Abstracts/UgcController.php` | filtro nella FeatureCollection UGC |
| `src/Models/EcTrack.php` | `taxonomyWheres` filtrato in `toSearchableArray()` |
| `src/Nova/App.php` | campo `MultiSelect` |
| `src/Observers/AppObserver.php` | rigenerazione delle uscite quando l'opzione cambia |
| `src/Services/TaxonomyWhereResyncService.php` (nuovo) | riallineamento conservativo di un record, conteggi del dry-run, avvio delle due fasi |
| `src/Jobs/TaxonomyWhere/ConservativeSyncTaxonomyWhereJob.php` (nuovo) | job `Batchable` per un record |
| `src/Jobs/TaxonomyWhere/RegenerateTaxonomyWhereOutputsJob.php` (nuovo) | fase 2: json statici, Elasticsearch, `pois.geojson` |
| `src/Commands/WmResyncTaxonomyWhereCommand.php` (nuovo) | `wm:resync-taxonomy-where` |
| `resources/lang/{it,en,de,es,fr}.json` | etichette |
| `tests/Unit/Services/TaxonomyWhereDisplayServiceTest.php` (nuovo) | logica pura |
| `tests/Feature/TaxonomyWhere/*` (nuovi) | uscite, Nova, observer, riallineamento, comando, regressioni |
| `tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php`, `tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php` | asserzioni di oc:8487 sul formato, aggiornate |
| `docs/resources/TaxonomyWhere.md`, `docs/features/8487-…/notes.md` | documentazione del formato e del rovesciamento di oc:8487 |

---

### Task 0: Prerequisito ambiente di test

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-0-ambiente-di-test)

- [ ] **Step 1: avviare il container dei test del package**

Il container `php-maphub` è fermo. Chiedere al dev di avviarlo (può andare in conflitto di porte con camminiditalia: la decisione è sua, non dell'agente):

```bash
cd ~/Documents/BackEnd/maphub && docker compose -f local.compose.yml up -d
```

- [ ] **Step 2: verificare che la suite esistente di oc:8487 passi prima di toccare nulla**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php wm-package/tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php`
Expected: PASS. Se fallisce, fermarsi e segnalarlo: è lo stato di partenza.

---

### Task 1: `TaxonomyWhereDisplayService` — logica pura e opzione App

**Files:**
- Create: `src/Services/TaxonomyWhereDisplayService.php`
- Modify: `src/WmPackageServiceProvider.php` (metodo `register()`, dopo `$this->app->bind(HitsIteratorAggregate::class, …)`)
- Test: `tests/Unit/Services/TaxonomyWhereDisplayServiceTest.php`

**Interfaces:**
- Produces:
  - `TaxonomyWhereDisplayService::SOURCE_PREFIX = 'source:'`
  - `categoryOf(array $entry): ?string`
  - `toLegacyEntry(array $entry): array` — forma `{<lingue>, _admin_level?, _source?}`
  - `normalize(array $taxonomyWhere): array` — mappa intera nella forma vecchia, scarta voci senza nomi
  - `filter(array $taxonomyWhere, array $categories): array` — normalizza e filtra; `$categories` vuoto = nessun filtro
  - `orderedNames(array $taxonomyWhere): array` — nomi ordinati per livello crescente (null prima), come `getOrderedTaxonomyWheres()` di oggi
  - `selectedCategoriesForApp(?int $appId): array`
  - `fromOsmfeatures(array $wheres): array` — mappa l'output di `OsmfeaturesClient::getWheresByGeojson()` nella forma vecchia con `_source = 'osmfeatures'`
  - `classifyFormat(mixed $taxonomyWhere): string` — `'empty' | 'oldest' | 'oc8487' | 'legacy'`

- [ ] **Step 1: scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

use Tests\TestCase;
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;

uses(TestCase::class);

function displayService(): TaxonomyWhereDisplayService
{
    return app(TaxonomyWhereDisplayService::class);
}

it('computes the same category for int and string levels', function () {
    expect(displayService()->categoryOf(['it' => 'Lazio', '_admin_level' => 4]))->toBe('4');
    expect(displayService()->categoryOf(['it' => 'Lazio', '_admin_level' => '4']))->toBe('4');
    expect(displayService()->categoryOf(['name' => ['it' => 'Lazio'], 'admin_level' => 4]))->toBe('4');
});

it('falls back to the source when the level is missing, null or empty', function () {
    expect(displayService()->categoryOf(['it' => 'X', '_admin_level' => null, '_source' => 'geohub']))->toBe('source:geohub');
    expect(displayService()->categoryOf(['it' => 'X', '_admin_level' => '', '_source' => 'geohub']))->toBe('source:geohub');
    expect(displayService()->categoryOf(['name' => ['it' => 'X'], 'admin_level' => null, 'source' => 'geohub']))->toBe('source:geohub');
});

it('returns a null category for the oldest shape', function () {
    expect(displayService()->categoryOf(['it' => 'Esperia', 'en' => 'Esperia']))->toBeNull();
    expect(displayService()->categoryOf(['it' => 'X', '_admin_level' => 'abc']))->toBeNull();
});

it('normalizes an oc:8487 entry to the legacy shape keeping only language keys', function () {
    $entry = ['name' => ['it' => 'Toscana', 'en' => 'Tuscany', 'wikidata' => 'Q1273'], 'admin_level' => 4, 'source' => 'osmfeatures'];

    expect(displayService()->toLegacyEntry($entry))
        ->toBe(['it' => 'Toscana', 'en' => 'Tuscany', '_admin_level' => 4, '_source' => 'osmfeatures']);
});

it('accepts a plain or json string name', function () {
    expect(displayService()->toLegacyEntry(['name' => 'Corsica', 'admin_level' => 4]))
        ->toBe(['it' => 'Corsica', 'en' => 'Corsica', '_admin_level' => 4]);
    expect(displayService()->toLegacyEntry(['name' => '{"it":"Corsica"}']))->toBe(['it' => 'Corsica']);
});

it('keeps a legacy entry as it is', function () {
    $entry = ['it' => 'Lazio', 'en' => 'Lazio', '_admin_level' => 4];

    expect(displayService()->toLegacyEntry($entry))->toBe($entry);
});

it('drops entries without any name when normalizing', function () {
    $normalized = displayService()->normalize([
        'R1' => ['_admin_level' => 8],
        'R2' => ['it' => 'Lazio', '_admin_level' => 4],
    ]);

    expect(array_keys($normalized))->toBe(['R2']);
});

it('filters by one or more categories and keeps everything when none is selected', function () {
    $where = [
        'R40784' => ['it' => 'Lazio', '_admin_level' => 4],
        'R41241' => ['it' => 'Esperia', '_admin_level' => 8],
        'G1' => ['it' => 'Parco', '_source' => 'geohub'],
        'OLD' => ['it' => 'Ausonia'],
    ];

    expect(array_keys(displayService()->filter($where, ['4'])))->toBe(['R40784']);
    expect(array_keys(displayService()->filter($where, ['4', 'source:geohub'])))->toBe(['R40784', 'G1']);
    expect(array_keys(displayService()->filter($where, [])))->toBe(['R40784', 'R41241', 'G1', 'OLD']);
    expect(displayService()->filter($where, ['6']))->toBe([]);
});

it('orders names by ascending level with nulls first', function () {
    $names = displayService()->orderedNames([
        'R41241' => ['it' => 'Esperia', '_admin_level' => 8],
        'R40784' => ['it' => 'Lazio', '_admin_level' => 4],
        'OLD' => ['it' => 'Ausonia'],
    ]);

    expect($names)->toBe(['Ausonia', 'Lazio', 'Esperia']);
});

it('maps osmfeatures output to the legacy shape with the osmfeatures source', function () {
    $mapped = displayService()->fromOsmfeatures([
        'R617447' => ['it' => 'Toscana', 'en' => 'Tuscany', '_admin_level' => 4],
        'R1' => ['_admin_level' => 8],
    ]);

    expect($mapped)->toBe(['R617447' => ['it' => 'Toscana', 'en' => 'Tuscany', '_admin_level' => 4, '_source' => 'osmfeatures']]);
});

it('classifies the stored format', function () {
    expect(displayService()->classifyFormat(null))->toBe('empty');
    expect(displayService()->classifyFormat([]))->toBe('empty');
    expect(displayService()->classifyFormat(['R1' => ['it' => 'Esperia']]))->toBe('oldest');
    expect(displayService()->classifyFormat(['R1' => ['name' => ['it' => 'X'], 'admin_level' => 4]]))->toBe('oc8487');
    expect(displayService()->classifyFormat(['R1' => ['it' => 'Lazio', '_admin_level' => 4]]))->toBe('legacy');
});
```

- [ ] **Step 2: eseguire il test e verificare che fallisce**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Unit/Services/TaxonomyWhereDisplayServiceTest.php`
Expected: FAIL con `Class "Wm\WmPackage\Services\TaxonomyWhereDisplayService" not found`.

- [ ] **Step 3: implementare il servizio**

```php
<?php

namespace Wm\WmPackage\Services;

use Wm\WmPackage\Models\App;

/**
 * Logica di visualizzazione di `properties.taxonomy_where` (oc:8588).
 *
 * Il dato salvato può essere in tre forme (più vecchia senza livello, forma vecchia con
 * `_admin_level`, forma oc:8487 con `name/admin_level/source`); in uscita si produce sempre
 * la forma vecchia, l'unica che wm-core (`<wm-txn-where>`) e wp-geohub sanno leggere.
 *
 * Registrato `scoped`: la cache dell'opzione App vive per una sola richiesta o un solo job,
 * quindi un worker Horizon a lunga vita rilegge l'opzione dopo una modifica in Nova.
 */
class TaxonomyWhereDisplayService extends BaseService
{
    public const SOURCE_PREFIX = 'source:';

    public const OSMFEATURES_SOURCE = 'osmfeatures';

    public const APP_OPTION = 'taxonomy_where_display';

    private const LANGUAGE_KEY_PATTERN = '/^[a-z]{2,3}$/';

    /** @var array<int, string[]> */
    private array $appCategories = [];

    public function categoryOf(array $entry): ?string
    {
        $level = $entry['_admin_level'] ?? $entry['admin_level'] ?? null;
        if (is_int($level) || (is_string($level) && ctype_digit($level))) {
            return (string) (int) $level;
        }

        $source = $entry['_source'] ?? $entry['source'] ?? null;
        if (is_string($source) && $source !== '') {
            return self::SOURCE_PREFIX.$source;
        }

        return null;
    }

    public function toLegacyEntry(array $entry): array
    {
        $names = $entry['name'] ?? $entry;
        if (is_string($names)) {
            // Nome stringa semplice o JSON serializzato, come già accettava getValidName().
            $trimmed = trim($names);
            $decoded = str_starts_with($trimmed, '{') ? json_decode($trimmed, true) : null;
            $names = is_array($decoded) ? $decoded : ($trimmed !== '' ? ['it' => $trimmed, 'en' => $trimmed] : []);
        }
        if (! is_array($names)) {
            $names = [];
        }

        $legacy = [];
        foreach ($names as $key => $value) {
            if (is_string($key) && preg_match(self::LANGUAGE_KEY_PATTERN, $key) && is_string($value) && trim($value) !== '') {
                $legacy[$key] = $value;
            }
        }

        $level = $entry['_admin_level'] ?? $entry['admin_level'] ?? null;
        if (is_int($level) || (is_string($level) && ctype_digit($level))) {
            $legacy['_admin_level'] = (int) $level;
        }

        $source = $entry['_source'] ?? $entry['source'] ?? null;
        if (is_string($source) && $source !== '') {
            $legacy['_source'] = $source;
        }

        return $legacy;
    }

    public function normalize(array $taxonomyWhere): array
    {
        $normalized = [];
        foreach ($taxonomyWhere as $id => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $legacy = $this->toLegacyEntry($entry);
            if ($this->hasName($legacy)) {
                $normalized[$id] = $legacy;
            }
        }

        return $normalized;
    }

    public function filter(array $taxonomyWhere, array $categories): array
    {
        $normalized = $this->normalize($taxonomyWhere);
        if ($categories === []) {
            return $normalized;
        }

        $categories = array_map('strval', $categories);

        return array_filter(
            $normalized,
            fn (array $entry) => in_array($this->categoryOf($entry), $categories, true)
        );
    }

    /** @return string[] */
    public function orderedNames(array $taxonomyWhere): array
    {
        $entries = [];
        $idx = 0;
        foreach ($this->normalize($taxonomyWhere) as $entry) {
            $entries[] = [
                'name' => $entry['it'] ?? $entry['en'] ?? $this->firstName($entry),
                'level' => $entry['_admin_level'] ?? null,
                'idx' => $idx++,
            ];
        }

        usort($entries, function (array $a, array $b): int {
            if (($a['level'] === null) !== ($b['level'] === null)) {
                return $a['level'] === null ? -1 : 1;
            }

            return [$a['level'], $a['idx']] <=> [$b['level'], $b['idx']];
        });

        return array_map(static fn (array $e) => trim($e['name']), $entries);
    }

    /** @return string[] */
    public function selectedCategoriesForApp(?int $appId): array
    {
        if ($appId === null) {
            return [];
        }

        if (! array_key_exists($appId, $this->appCategories)) {
            $properties = App::query()->whereKey($appId)->value('properties');
            $properties = is_array($properties) ? $properties : (json_decode((string) $properties, true) ?: []);
            $selected = $properties[self::APP_OPTION] ?? [];
            $this->appCategories[$appId] = is_array($selected) ? array_values(array_map('strval', $selected)) : [];
        }

        return $this->appCategories[$appId];
    }

    public function fromOsmfeatures(array $wheres): array
    {
        $mapped = [];
        foreach ($wheres as $id => $where) {
            if (! is_array($where)) {
                continue;
            }
            $legacy = $this->toLegacyEntry($where);
            if (! $this->hasName($legacy)) {
                continue;
            }
            $legacy['_source'] = self::OSMFEATURES_SOURCE;
            $mapped[$id] = $legacy;
        }

        return $mapped;
    }

    public function classifyFormat(mixed $taxonomyWhere): string
    {
        if (! is_array($taxonomyWhere) || $taxonomyWhere === []) {
            return 'empty';
        }

        $entries = array_filter($taxonomyWhere, 'is_array');
        foreach ($entries as $entry) {
            if (is_array($entry['name'] ?? null)) {
                return 'oc8487';
            }
        }
        foreach ($entries as $entry) {
            if (array_key_exists('_admin_level', $entry) || array_key_exists('_source', $entry)) {
                return 'legacy';
            }
        }

        return 'oldest';
    }

    private function hasName(array $legacy): bool
    {
        return $this->firstName($legacy) !== '';
    }

    private function firstName(array $legacy): string
    {
        foreach ($legacy as $key => $value) {
            if (! str_starts_with((string) $key, '_') && is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }
}
```

In `src/WmPackageServiceProvider.php`, dentro `register()`:

```php
        $this->app->scoped(\Wm\WmPackage\Services\TaxonomyWhereDisplayService::class);
```

- [ ] **Step 4: aggiungere il test sulla registrazione `scoped` (Review Focus 4)**

In coda al file di test:

```php
it('reads the option again after the scoped instance is flushed', function () {
    $app = \Wm\WmPackage\Models\App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    expect(displayService()->selectedCategoriesForApp($app->id))->toBe(['4']);

    \Illuminate\Support\Facades\DB::table('apps')->where('id', $app->id)
        ->update(['properties' => json_encode(['taxonomy_where_display' => ['8']])]);
    app()->forgetScopedInstances();

    expect(displayService()->selectedCategoriesForApp($app->id))->toBe(['8']);
})->uses(\Illuminate\Foundation\Testing\DatabaseTransactions::class);
```

- [ ] **Step 5: eseguire i test e verificare che passano**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Unit/Services/TaxonomyWhereDisplayServiceTest.php`
Expected: PASS (12 test).

- [ ] **Step 6: commit (istruzione per il dev)**

```bash
git add src/Services/TaxonomyWhereDisplayService.php src/WmPackageServiceProvider.php tests/Unit/Services/TaxonomyWhereDisplayServiceTest.php
git commit -m "feat(oc:8588): servizio di visualizzazione taxonomy_where (categorie, forma vecchia, filtro)"
```

---

### Task 2: tutti gli scrittori nella forma vecchia

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-scrittori-nella-forma-vecchia)

**Files:**
- Modify: `src/Services/GeometryComputationService.php` (SQL in `syncTaxonomyWhere()`, docblock di `writeTaxonomyWhereIfEmpty()`; nuovo `computeTaxonomyWhere()`)
- Modify: `src/Jobs/TaxonomyWhere/SyncModelTaxonomyWhereJob.php` (`mapOsmfeaturesWheres()` → `TaxonomyWhereDisplayService::fromOsmfeatures()`)
- Modify: `src/Jobs/UpdateModelWithGeometryTaxonomyWhere.php` (aggiunta di `_source`)
- Modify test: `tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php:145-163`, `tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php:110-115`
- Test: `tests/Feature/TaxonomyWhere/UgcTaxonomyWhereSourceTest.php`

**Interfaces:**
- Consumes: `TaxonomyWhereDisplayService::fromOsmfeatures()` (Task 1)
- Produces: `GeometryComputationService::computeTaxonomyWhere(GeometryModel $model): array` — la mappa che il SQL scriverebbe per quel record, senza scriverla (usata dal Task 6)

**Nota:** `OsmfeaturesClient::getWheresByGeojson()` **non** si tocca. In camminiditalia `LayerAttributesService.php:483-485` toglie solo `_admin_level` e tratta il resto della voce come traduzioni del nome: un `_source` aggiunto nel client comparirebbe come lingua nei filtri del layer. `_source` si aggiunge quindi nei job, a valle del client.

- [ ] **Step 1: aggiornare le asserzioni di oc:8487 (devono fallire)**

In `tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php`, sostituire il test `it('writes a unified taxonomy_where shape with name/admin_level/source keys', …)` con:

```php
it('writes the legacy taxonomy_where shape with language keys, _admin_level and _source', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class, $poiId);

    $entry = collect(EcPoi::find($poiId)->properties['taxonomy_where'])->first();
    expect($entry)->toEqual(['it' => 'Corsica', 'en' => 'Corsica', '_admin_level' => 4, '_source' => 'geohub']);
});

it('computes the taxonomy_where of a record without writing it', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $computed = GeometryComputationService::make()->computeTaxonomyWhere(EcPoi::find($poiId));

    expect(collect($computed)->first())->toEqual(['it' => 'Corsica', 'en' => 'Corsica', '_admin_level' => 4, '_source' => 'geohub']);
    expect(EcPoi::find($poiId)->properties['taxonomy_where'] ?? null)->toBeNull();
});
```

Nei test `preserves an existing taxonomy_where …` dello stesso file (righe ~165-235), il valore preesistente `'R999999' => ['name' => ['it' => 'Regione Precedente'], 'admin_level' => 4, 'source' => 'osmfeatures']` resta com'è (è un dato preesistente nel formato oc:8487, e deve continuare a essere preservato); l'asserzione `$properties['taxonomy_where']['R999999']['name']['it']` resta valida.

In `tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php`, righe 110-115, sostituire le tre asserzioni sul formato con:

```php
        $this->assertEquals(
            ['it' => 'Toscana', 'en' => 'Tuscany', '_admin_level' => 4, '_source' => SyncModelTaxonomyWhereJob::SOURCE_OSMFEATURES],
            $taxonomyWhere['R617447']
        );
```

(`assertEquals` e non `assertSame`: il valore è riletto da una colonna `jsonb`, che riordina le chiavi. Verificare sulla risposta fake del test quali chiavi lingua produce `OsmfeaturesClient`: se non c'è `en`, toglierla dall'atteso.)

- [ ] **Step 2: scrivere il test UGC che fallisce**

`tests/Feature/TaxonomyWhere/UgcTaxonomyWhereSourceTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\UgcPoi;

uses(TestCase::class, DatabaseTransactions::class);

it('adds the osmfeatures source to UGC taxonomy_where', function () {
    $ugc = UgcPoi::factory()->create();

    $client = Mockery::mock(OsmfeaturesClient::class);
    $client->shouldReceive('getWheresByGeojson')->andReturn([
        'R40784' => ['it' => 'Lazio', 'en' => 'Lazio', '_admin_level' => 4],
    ]);

    (new UpdateModelWithGeometryTaxonomyWhere($ugc))->handle($client);

    expect($ugc->fresh()->properties['taxonomy_where']['R40784'])
        ->toEqual(['it' => 'Lazio', 'en' => 'Lazio', '_admin_level' => 4, '_source' => 'osmfeatures']);
});
```

(Se `UgcPoi::factory()` non produce una geometria valida, creare il record con `DB::table('ugc_pois')->insertGetId([...])` sullo stesso schema dei test di oc:8487.)

- [ ] **Step 3: eseguire i test e verificare che falliscono**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php wm-package/tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php wm-package/tests/Feature/TaxonomyWhere/UgcTaxonomyWhereSourceTest.php`
Expected: FAIL sui tre test nuovi o aggiornati (forma `name/admin_level/source`, `computeTaxonomyWhere` inesistente, `_source` assente).

- [ ] **Step 4: implementare**

In `GeometryComputationService`, estrarre l'aggregato in un metodo privato e usarlo sia in `syncTaxonomyWhere()` sia nel nuovo `computeTaxonomyWhere()`:

```php
    /**
     * Espressione SQL che aggrega le taxonomy_wheres intersecate da `{$tableName}.geometry`
     * nella forma vecchia `{id: {<lingue>, _admin_level, _source}}` (oc:8588): lingue appiattite
     * al primo livello (solo chiavi `^[a-z]{2,3}$` con valore stringa), `_admin_level` e
     * `_source` omessi se nulli.
     */
    private function taxonomyWhereAggregateSql(string $tableName): string
    {
        return "
            SELECT jsonb_object_agg(
                COALESCE(tw.properties->>'osmfeatures_id', (tw.properties->>'osm2cai_id'), tw.id::text),
                (
                    SELECT COALESCE(jsonb_object_agg(n.key, n.value), '{}'::jsonb)
                    FROM jsonb_each(
                        CASE
                            WHEN tw.name IS NULL OR btrim(tw.name) = '' THEN '{}'::jsonb
                            WHEN left(ltrim(tw.name), 1) = '{' THEN tw.name::jsonb
                            ELSE jsonb_build_object('it', tw.name, 'en', tw.name)
                        END
                    ) AS n(key, value)
                    WHERE n.key ~ '^[a-z]{2,3}$' AND jsonb_typeof(n.value) = 'string'
                )
                || jsonb_strip_nulls(jsonb_build_object(
                    '_admin_level', (tw.properties->>'admin_level')::int,
                    '_source', tw.properties->>'source'
                ))
            )
            FROM taxonomy_wheres tw
            WHERE tw.geometry IS NOT NULL
              AND ST_Intersects({$tableName}.geometry::geometry, tw.geometry::geometry)
        ";
    }

    /**
     * Calcola, senza scriverla, la taxonomy_where che il sync SQL assegnerebbe a un record
     * (usata dal riallineamento conservativo, oc:8588).
     *
     * @return array<string, array<string, mixed>>
     */
    public function computeTaxonomyWhere(GeometryModel $model): array
    {
        $tableName = $model->getTable();
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }

        $row = DB::selectOne("
            SELECT ({$this->taxonomyWhereAggregateSql($tableName)}) AS tw
            FROM {$tableName}
            WHERE id = ? AND geometry IS NOT NULL
        ", [$model->id]);

        return json_decode($row->tw ?? 'null', true) ?: [];
    }
```

In `syncTaxonomyWhere()`, sostituire il blocco `( SELECT jsonb_object_agg( … ) FROM taxonomy_wheres tw WHERE … )` dentro il `COALESCE(` con `({$this->taxonomyWhereAggregateSql($tableName)})`, lasciando invariati `{$noMatchFallback}`, `{$idCondition}` e il conteggio finale.

Aggiornare il docblock di `writeTaxonomyWhereIfEmpty()`: `@param array<string, array<string, mixed>> $mapped` nella forma `{<lingue>, _admin_level?, _source}` (oc:8588).

In `SyncModelTaxonomyWhereJob`, sostituire la chiamata e cancellare `mapOsmfeaturesWheres()`:

```php
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;
// …
        if ($geojson !== null) {
            $mapped = app(TaxonomyWhereDisplayService::class)
                ->fromOsmfeatures($osmfeaturesClient->getWheresByGeojson($geojson));
        }
```

Lasciare `SOURCE_OSMFEATURES = 'osmfeatures'` (è referenziata dai test) e aggiornare il commento del job: la forma scritta è quella vecchia (oc:8588).

In `UpdateModelWithGeometryTaxonomyWhere::handle()`:

```php
        $properties = $this->model->properties;
        $properties['taxonomy_where'] = app(TaxonomyWhereDisplayService::class)->fromOsmfeatures($wheres);
        $this->model->properties = $properties;
        $this->model->saveQuietly();
```

(Se `fromOsmfeatures()` scarta tutte le voci perché senza nome, il job scrive `[]`: comportamento coerente con il job EC, che già scarta le voci senza nome.)

- [ ] **Step 5: eseguire i test e verificare che passano**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php wm-package/tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php wm-package/tests/Feature/Jobs/SyncTaxonomyWhereJobTest.php wm-package/tests/Feature/TaxonomyWhere/UgcTaxonomyWhereSourceTest.php wm-package/tests/Feature/Nova/EcTrackRegenerateTaxonomyWhereActionTest.php`
Expected: PASS.

- [ ] **Step 6: commit (istruzione per il dev)**

```bash
git add src/Services/GeometryComputationService.php src/Jobs/TaxonomyWhere/SyncModelTaxonomyWhereJob.php src/Jobs/UpdateModelWithGeometryTaxonomyWhere.php tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php tests/Feature/TaxonomyWhere/UgcTaxonomyWhereSourceTest.php
git commit -m "feat(oc:8588): taxonomy_where scritta nella forma vecchia da tutti gli scrittori"
```

---

### Task 3: filtro nelle uscite pubbliche (json statico, `pois.geojson`, endpoint, UGC)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-filtro-nelle-uscite-pubbliche)

**Files:**
- Modify: `src/Models/Abstracts/GeometryModel.php` (nuovo `applyTaxonomyWhereDisplay()`; `getOrderedTaxonomyWheres()` delega a `orderedNames()`)
- Modify: `src/Http/Resources/EcTrackResource.php` (`toArray()`, dopo `applyDemFields`)
- Modify: `src/Http/Resources/EcPoiResource.php` (`toArray()`; copre anche `RelatedEcPoiResource`, che chiama `parent::toArray()`)
- Modify: `src/Models/App.php` (`getAllPoisGeojson()`, subito dopo `$item = $poi->getGeojson(false, $this->id);`)
- Modify: `src/Http/Controllers/Api/EcTrackController.php` (riga 32, ramo senza file statico; riga 71, FeatureCollection per id)
- Modify: `src/Http/Controllers/Api/Abstracts/UgcController.php` (`getFeatureCollection()`, dopo il controllo di validità)
- Test: `tests/Feature/TaxonomyWhere/TaxonomyWhereDisplayOutputTest.php`

**Interfaces:**
- Consumes: `TaxonomyWhereDisplayService::filter()`, `orderedNames()`, `selectedCategoriesForApp()` (Task 1)
- Produces: `GeometryModel::applyTaxonomyWhereDisplay(array $properties): array` — restituisce `$properties` con `taxonomy_where` filtrato nella forma vecchia, `taxonomyWheres` ricalcolato sui soli elementi filtrati, e senza `_taxonomy_where_backup`

- [ ] **Step 1: scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;

uses(TestCase::class, DatabaseTransactions::class);

function wheresFixture(): array
{
    return [
        'R40784' => ['it' => 'Lazio', 'en' => 'Lazio', '_admin_level' => 4],
        'R41241' => ['name' => ['it' => 'Esperia'], 'admin_level' => 8, 'source' => 'osmfeatures'],
    ];
}

function poiWithWheres(App $app, array $properties): EcPoi
{
    $id = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[13.7,41.4,0]}')"),
        'properties' => json_encode($properties),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return EcPoi::find($id);
}

it('keeps every entry, in the legacy shape, when no category is selected', function () {
    $app = App::factory()->create();
    $poi = poiWithWheres($app, ['taxonomy_where' => wheresFixture()]);

    $out = $poi->applyTaxonomyWhereDisplay($poi->getGeojson()['properties']);

    expect($out['taxonomy_where'])->toEqual([
        'R40784' => ['it' => 'Lazio', 'en' => 'Lazio', '_admin_level' => 4],
        'R41241' => ['it' => 'Esperia', '_admin_level' => 8, '_source' => 'osmfeatures'],
    ]);
    expect($out['taxonomyWheres'])->toBe(['Lazio', 'Esperia']);
});

it('shows only the selected categories', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    $poi = poiWithWheres($app, ['taxonomy_where' => wheresFixture()]);

    $out = $poi->applyTaxonomyWhereDisplay($poi->getGeojson()['properties']);

    expect(array_keys($out['taxonomy_where']))->toBe(['R40784']);
    expect($out['taxonomyWheres'])->toBe(['Lazio']);
});

it('returns empty lists when no entry matches the selected categories', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    $poi = poiWithWheres($app, ['taxonomy_where' => ['OLD' => ['it' => 'Ausonia']]]);

    $out = $poi->applyTaxonomyWhereDisplay($poi->getGeojson()['properties']);

    expect($out['taxonomy_where'])->toBe([]);
    expect($out['taxonomyWheres'])->toBe([]);
});

it('strips the backup key from public output', function () {
    $app = App::factory()->create();
    $poi = poiWithWheres($app, ['taxonomy_where' => wheresFixture(), '_taxonomy_where_backup' => ['X' => ['it' => 'Vecchio']]]);

    $out = $poi->applyTaxonomyWhereDisplay($poi->getGeojson()['properties']);

    expect($out)->not->toHaveKey('_taxonomy_where_backup');
});

it('does not filter getModelAsGeojson, used by internal jobs', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    $poi = poiWithWheres($app, ['taxonomy_where' => wheresFixture()]);

    expect(array_keys($poi->getGeojson()['properties']['taxonomy_where']))->toEqualCanonicalizing(['R40784', 'R41241']);
});

it('filters pois.geojson of the app', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    $poi = poiWithWheres($app, ['taxonomy_where' => wheresFixture()]);

    $feature = collect($app->getAllPoisGeojson())->firstWhere('properties.id', $poi->id);

    expect(array_keys($feature['properties']['taxonomy_where']))->toBe(['R40784']);
});

it('keeps the full-text searchable string unfiltered', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    $trackId = DB::table('ec_tracks')->insertGetId([
        'name' => json_encode(['it' => 'Tappa']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[13.7,41.4,0],[13.8,41.5,0]]]}')"),
        'properties' => json_encode(['taxonomy_where' => wheresFixture()]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(EcTrack::find($trackId)->getSearchableString())->toContain('Esperia');
});
```

(Se `getAllPoisGeojson()` filtra per la colonna `global`, inserire `'global' => true` nel record quando la colonna esiste. Se `App::factory()` non accetta `properties`, aggiornarle con `DB::table('apps')->update(...)` dopo la creazione.)

- [ ] **Step 2: eseguire il test e verificare che fallisce**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/TaxonomyWhere/TaxonomyWhereDisplayOutputTest.php`
Expected: FAIL con `Call to undefined method … applyTaxonomyWhereDisplay()`.

- [ ] **Step 3: implementare**

In `GeometryModel`:

```php
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;
// …
    /**
     * Applica l'opzione "Località mostrate" dell'App proprietaria alle proprietà di
     * un'uscita pubblica (json statico, pois.geojson, API, Elasticsearch — oc:8588).
     * Non va usata da job interni che rileggono o risalvano `properties`.
     */
    public function applyTaxonomyWhereDisplay(array $properties): array
    {
        unset($properties['_taxonomy_where_backup']);

        if (! isset($properties['taxonomy_where']) || ! is_array($properties['taxonomy_where'])) {
            return $properties;
        }

        $service = app(TaxonomyWhereDisplayService::class);
        $filtered = $service->filter(
            $properties['taxonomy_where'],
            $service->selectedCategoriesForApp($this->app_id)
        );

        $properties['taxonomy_where'] = $filtered;
        $properties['taxonomyWheres'] = $service->orderedNames($filtered);

        return $properties;
    }
```

E in `getOrderedTaxonomyWheres()`, sostituire il corpo con la delega (stesso comportamento, una sola implementazione):

```php
    public function getOrderedTaxonomyWheres(): array
    {
        $wheres = $this->properties['taxonomy_where'] ?? [];

        return is_array($wheres) ? app(TaxonomyWhereDisplayService::class)->orderedNames($wheres) : [];
    }
```

`orderedNames()` accetta tutte le forme accettate da `getValidName()`, compreso il nome stringa semplice o JSON serializzato (Task 1). `getValidName()` resta (è `public`, potrebbe avere chiamanti nei consumer).

In `EcTrackResource::toArray()`, subito dopo `$properties = $this->applyDemFields($properties, $this->resource);`:

```php
        $properties = $this->resource->applyTaxonomyWhereDisplay($properties);
```

In `EcPoiResource::toArray()`:

```php
        $geojson['properties'] = $this->resource->applyTaxonomyWhereDisplay([
            ...GeoJsonService::make()->removeInvalidProperties($geojson['properties']),
            'name' => $this->getTranslations('name'),
            'feature_image' => new MediaResource($this->getMedia()->first()),
            'image_gallery' => MediaResource::collection($this->getMedia()),
        ]);
```

In `App::getAllPoisGeojson()`, subito dopo `$item = $poi->getGeojson(false, $this->id);`:

```php
                        if (isset($item['properties'])) {
                            $item['properties'] = $poi->applyTaxonomyWhereDisplay($item['properties']);
                        }
```

In `EcTrackController`, riga 32:

```php
        $geojson = $ecTrack->getGeojson();
        if (isset($geojson['properties'])) {
            $geojson['properties'] = $ecTrack->applyTaxonomyWhereDisplay($geojson['properties']);
        }

        return response()->json($geojson, 200, $headers);
```

e riga 71:

```php
                        $feature = $track->getGeojson();
                        if (isset($feature['properties'])) {
                            $feature['properties'] = $track->applyTaxonomyWhereDisplay($feature['properties']);
                        }
                        $featureCollection['features'][] = $feature;
```

In `UgcController::getFeatureCollection()`, dopo il blocco `continue` sui geojson non validi:

```php
                $geojson['properties'] = $feature->applyTaxonomyWhereDisplay($geojson['properties']);
```

- [ ] **Step 4: verificare che nessun altro punto di uscita pubblica sia rimasto fuori**

Run: `grep -rn "getGeojson()" src/Http src/Models/App.php src/Services/Models/EcPoiService.php src/Services/Models/EcTrackService.php`
Per ogni riga, decidere: uscita pubblica (va filtrata) o uso interno (no). Riportare l'esito in `notes.md` (sezione `## Decisioni`), con l'elenco dei punti esclusi e il motivo. Candidati noti da valutare: `EcPoiService.php:59` (FeatureCollection POI), `EcTrackService.php:425`, `EditorialContentController.php:97`.

- [ ] **Step 5: eseguire i test e verificare che passano**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/TaxonomyWhere/TaxonomyWhereDisplayOutputTest.php wm-package/tests/Unit/Services/TaxonomyWhereDisplayServiceTest.php wm-package/tests/Feature/EcTrackToSearchableArrayFromToTest.php`
Expected: PASS.

- [ ] **Step 6: commit (istruzione per il dev)**

```bash
git add src/Models/Abstracts/GeometryModel.php src/Http/Resources/EcTrackResource.php src/Http/Resources/EcPoiResource.php src/Models/App.php src/Http/Controllers/Api/EcTrackController.php src/Http/Controllers/Api/Abstracts/UgcController.php tests/Feature/TaxonomyWhere/TaxonomyWhereDisplayOutputTest.php
git commit -m "feat(oc:8588): filtro delle località mostrate nelle uscite pubbliche"
```

---

### Task 4: Elasticsearch (`toSearchableArray`)

**Files:**
- Modify: `src/Models/EcTrack.php:738` (`'taxonomyWheres' => $this->getOrderedTaxonomyWheres(),`)
- Test: `tests/Feature/TaxonomyWhere/EcTrackSearchableTaxonomyWheresTest.php`

**Interfaces:**
- Consumes: `GeometryModel::applyTaxonomyWhereDisplay()` (Task 3)

- [ ] **Step 1: scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

uses(TestCase::class, DatabaseTransactions::class);

function trackForSearch(App $app): EcTrack
{
    $id = DB::table('ec_tracks')->insertGetId([
        'name' => json_encode(['it' => 'Tappa']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[13.7,41.4,0],[13.8,41.5,0]]]}')"),
        'properties' => json_encode(['taxonomy_where' => [
            'R40784' => ['it' => 'Lazio', '_admin_level' => 4],
            'R41241' => ['it' => 'Esperia', '_admin_level' => 8],
        ]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return EcTrack::find($id);
}

it('indexes only the selected categories in taxonomyWheres', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);

    expect(trackForSearch($app)->toSearchableArray()['taxonomyWheres'])->toBe(['Lazio']);
});

it('indexes every entry when no category is selected', function () {
    $app = App::factory()->create();

    expect(trackForSearch($app)->toSearchableArray()['taxonomyWheres'])->toBe(['Lazio', 'Esperia']);
});
```

Nota: `ElasticsearchController.php:158` (`TermQuery` su `taxonomyWheres`) e `:184-185` (aggregazione) leggono questo stesso campo indicizzato, quindi il test sul documento indicizzato copre anche filtro e faccette. Un test d'integrazione contro un Elasticsearch reale non è previsto (non c'è un harness ES nella suite del package): scriverlo in `notes.md` come copertura indiretta.

- [ ] **Step 2: eseguire il test e verificare che fallisce**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/TaxonomyWhere/EcTrackSearchableTaxonomyWheresTest.php`
Expected: FAIL sul primo test (`['Lazio', 'Esperia']` invece di `['Lazio']`).

- [ ] **Step 3: implementare**

In `EcTrack::toSearchableArray()`, riga 738:

```php
            'taxonomyWheres' => $this->applyTaxonomyWhereDisplay([
                'taxonomy_where' => $this->properties['taxonomy_where'] ?? [],
            ])['taxonomyWheres'] ?? [],
```

`getSearchableString()` (riga ~797) resta su `getOrderedTaxonomyWheres()`, non filtrato.

- [ ] **Step 4: eseguire i test e verificare che passano**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/TaxonomyWhere/EcTrackSearchableTaxonomyWheresTest.php wm-package/tests/Feature/EcTrackToSearchableArrayFromToTest.php`
Expected: PASS.

- [ ] **Step 5: commit (istruzione per il dev)**

```bash
git add src/Models/EcTrack.php tests/Feature/TaxonomyWhere/EcTrackSearchableTaxonomyWheresTest.php
git commit -m "feat(oc:8588): taxonomyWheres filtrato anche nell'indice Elasticsearch"
```

---

### Task 5: opzione App in Nova e rigenerazione al cambio

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-opzione-app-in-nova-e-rigenerazione)

**Files:**
- Modify: `src/Services/TaxonomyWhereDisplayService.php` (`availableCategories()`, `labelFor()`)
- Create: `src/Jobs/TaxonomyWhere/RegenerateTaxonomyWhereOutputsJob.php`
- Modify: `src/Nova/App.php` (import `Laravel\Nova\Fields\MultiSelect`; campo in fondo a `app_tab()`)
- Modify: `src/Observers/AppObserver.php` (`saved()`)
- Modify: `resources/lang/{it,en,de,es,fr}.json`
- Test: `tests/Feature/TaxonomyWhere/TaxonomyWhereDisplayOptionTest.php`

**Interfaces:**
- Consumes: `TaxonomyWhereDisplayService::categoryOf()`, `APP_OPTION` (Task 1)
- Produces:
  - `TaxonomyWhereDisplayService::availableCategories(int $appId, array $alreadySelected = []): array<string, string>` (categoria → etichetta)
  - `TaxonomyWhereDisplayService::labelFor(string $category): string`
  - `RegenerateTaxonomyWhereOutputsJob::__construct(int $appId)` (usato anche dal Task 6)

- [ ] **Step 1: scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;

uses(TestCase::class, DatabaseTransactions::class);

it('lists the categories present in the app data plus the already selected ones', function () {
    $app = App::factory()->create();
    DB::table('ec_pois')->insert([
        'name' => json_encode(['it' => 'Poi']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[13.7,41.4,0]}')"),
        'properties' => json_encode(['taxonomy_where' => [
            'R1' => ['it' => 'Lazio', '_admin_level' => 4],
            'R2' => ['name' => ['it' => 'Esperia'], 'admin_level' => '8'],
            'G1' => ['it' => 'Parco', '_source' => 'geohub'],
            'OLD' => ['it' => 'Ausonia'],
        ]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $options = app(TaxonomyWhereDisplayService::class)->availableCategories($app->id, ['6']);

    expect(array_keys($options))->toEqualCanonicalizing(['4', '8', 'source:geohub', '6']);
    expect($options['4'])->toBe(__('Region'));
    expect($options['source:geohub'])->toBe(__('Source: :source', ['source' => 'geohub']));
});

it('queues the outputs regeneration when the option changes', function () {
    Bus::fake();
    $app = App::factory()->create();

    $properties = $app->properties ?? [];
    $properties['taxonomy_where_display'] = ['4'];
    $app->properties = $properties;
    $app->save();

    Bus::assertDispatched(RegenerateTaxonomyWhereOutputsJob::class, fn ($job) => $job->appId === $app->id);
});

it('does not queue the regeneration when the option is unchanged', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    Bus::fake();

    $app->name = $app->name.' bis';
    $app->save();

    Bus::assertNotDispatched(RegenerateTaxonomyWhereOutputsJob::class);
});
```

- [ ] **Step 2: eseguire il test e verificare che fallisce**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/TaxonomyWhere/TaxonomyWhereDisplayOptionTest.php`
Expected: FAIL (`availableCategories` inesistente, classe job inesistente).

- [ ] **Step 3: implementare**

In `TaxonomyWhereDisplayService`:

```php
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
// …
    /**
     * Categorie presenti nei taxonomy_where di EcTrack, EcPoi, UgcPoi e UgcTrack dell'App, più
     * quelle già salvate (restano selezionabili anche se nessun record le contiene più).
     *
     * @return array<string, string>
     */
    public function availableCategories(int $appId, array $alreadySelected = []): array
    {
        $trackModel = config('wm-package.ec_track_model');
        $tables = [(new $trackModel)->getTable(), (new EcPoi)->getTable(), (new UgcPoi)->getTable(), (new UgcTrack)->getTable()];

        $categories = [];
        foreach ($tables as $table) {
            $rows = DB::select("
                SELECT DISTINCT e.value AS entry
                FROM {$table} t,
                     jsonb_each(CASE WHEN jsonb_typeof(t.properties->'taxonomy_where') = 'object'
                                     THEN t.properties->'taxonomy_where' ELSE '{}'::jsonb END) e
                WHERE t.app_id = ?
            ", [$appId]);

            foreach ($rows as $row) {
                $category = $this->categoryOf(json_decode($row->entry, true) ?: []);
                if ($category !== null) {
                    $categories[$category] = true;
                }
            }
        }

        foreach ($alreadySelected as $category) {
            $categories[(string) $category] = true;
        }

        $options = [];
        foreach (array_keys($categories) as $category) {
            $options[(string) $category] = $this->labelFor((string) $category);
        }
        ksort($options, SORT_NATURAL);

        return $options;
    }

    public function labelFor(string $category): string
    {
        if (str_starts_with($category, self::SOURCE_PREFIX)) {
            return __('Source: :source', ['source' => substr($category, strlen(self::SOURCE_PREFIX))]);
        }

        return match ($category) {
            '4' => __('Region'),
            '6' => __('Province'),
            '8' => __('Municipality'),
            default => __('Admin level :level', ['level' => $category]),
        };
    }
```

(`SELECT DISTINCT e.value` sul jsonb intero può restituire molte righe diverse per nome: se la misura supera ~200 ms sul DB di camminiditalia, sostituire con `SELECT DISTINCT COALESCE(e.value->>'_admin_level', e.value->>'admin_level') AS lvl, COALESCE(e.value->>'_source', e.value->>'source') AS src` e costruire la voce `['_admin_level' => lvl, '_source' => src]` in PHP.)

`src/Jobs/TaxonomyWhere/RegenerateTaxonomyWhereOutputsJob.php`:

```php
<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;

/**
 * Rigenera le uscite pubbliche che dipendono da taxonomy_where per un'App (oc:8588):
 * json statico di ogni EcTrack (contiene anche i related_pois), documento Elasticsearch,
 * pois.geojson dell'App. Non chiama osmfeatures e non modifica il dato salvato.
 */
class RegenerateTaxonomyWhereOutputsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $appId) {}

    public function uniqueId(): string
    {
        return 'regenerate-taxonomy-where-outputs-'.$this->appId;
    }

    public function handle(): void
    {
        $trackModel = config('wm-package.ec_track_model');

        $trackModel::query()
            ->where('app_id', $this->appId)
            ->whereNotNull('geometry')
            ->chunkById(200, function ($tracks) {
                foreach ($tracks as $track) {
                    UpdateEcTrackAwsJob::dispatch($track);
                }
                $tracks->searchable();
            });

        BuildAppPoisGeojsonJob::dispatch($this->appId);
    }
}
```

In `AppObserver::saved()`, dopo la sync del registro well-known:

```php
        $this->regenerateTaxonomyWhereOutputsIfChanged($app);
```

e il metodo:

```php
    /**
     * Se cambia l'opzione "Località mostrate" (oc:8588), le uscite già generate (json statici,
     * Elasticsearch, pois.geojson) vanno rigenerate: altrimenti il cambio in Nova non si vede.
     */
    private function regenerateTaxonomyWhereOutputsIfChanged(App $app): void
    {
        if ($app->wasRecentlyCreated || ! $app->wasChanged('properties')) {
            return;
        }

        $original = $app->getOriginal('properties');
        $original = is_array($original) ? $original : (json_decode($original ?? '{}', true) ?: []);

        $before = array_map('strval', (array) ($original[TaxonomyWhereDisplayService::APP_OPTION] ?? []));
        $after = array_map('strval', (array) ($app->properties[TaxonomyWhereDisplayService::APP_OPTION] ?? []));
        sort($before);
        sort($after);

        if ($before !== $after) {
            RegenerateTaxonomyWhereOutputsJob::dispatch($app->id);
        }
    }
```

(con gli `use` di `RegenerateTaxonomyWhereOutputsJob` e `TaxonomyWhereDisplayService`).

I PBF restano fuori dalla rigenerazione: verificato scrivendo il piano che nessun file in `src/Jobs/Pbf/` legge `taxonomy_where` o `taxonomyWheres` (`grep -rln "taxonomy_where\|taxonomyWheres" src/Jobs/Pbf` → nessun risultato). È il rischio "PBF" dell'overview, ora chiuso.

In `src/Nova/App.php`, `use Laravel\Nova\Fields\MultiSelect;` e in fondo all'array di `app_tab()`:

```php
            MultiSelect::make(__('Displayed locations'), 'properties->taxonomy_where_display')
                ->options(fn () => $this->resource->id
                    ? TaxonomyWhereDisplayService::make()->availableCategories(
                        $this->resource->id,
                        (array) ($this->resource->properties['taxonomy_where_display'] ?? [])
                    )
                    : [])
                ->hideFromIndex()
                ->help(__('Location categories shown in the app and on the WordPress sites (track and POI detail, cards, search filters). Leave empty to show them all. The app detail only shows Region, Province and Municipality; cards show the last selected level. After saving, published files and the search index are regenerated in the background.')),
```

(Verificare con Nova in locale che `MultiSelect` su `properties->…` salvi un array JSON e lo rilegga selezionato; se lo salva come stringa JSON, adattare la lettura in `selectedCategoriesForApp()` con un `json_decode` quando il valore è una stringa, e aggiungere quel caso al test del Task 1.)

Traduzioni, in tutti e cinque i file `resources/lang/*.json` (chiavi in inglese; valori):

| Chiave | it | en | de | es | fr |
|---|---|---|---|---|---|
| `Displayed locations` | Località mostrate | Displayed locations | Angezeigte Orte | Localidades mostradas | Localités affichées |
| `Region` | Regione | Region | Region | Región | Région |
| `Province` | Provincia | Province | Provinz | Provincia | Province |
| `Municipality` | Comune | Municipality | Gemeinde | Municipio | Commune |
| `Admin level :level` | Livello amministrativo :level | Admin level :level | Verwaltungsebene :level | Nivel administrativo :level | Niveau administratif :level |
| `Source: :source` | Sorgente: :source | Source: :source | Quelle: :source | Fuente: :source | Source : :source |
| testo dell'help (chiave = frase inglese sopra) | Categorie di località mostrate nell'app e nei siti WordPress (dettaglio di tappe e POI, card, filtri di ricerca). Lascia vuoto per mostrarle tutte. Il dettaglio dell'app mostra solo Regione, Provincia e Comune; le card mostrano l'ultimo livello selezionato. Dopo il salvataggio, i file pubblicati e l'indice di ricerca vengono rigenerati in background. | (uguale alla chiave) | Ortskategorien, die in der App und auf den WordPress-Seiten angezeigt werden (Detail von Etappen und POIs, Karten, Suchfilter). Leer lassen, um alle anzuzeigen. Das App-Detail zeigt nur Region, Provinz und Gemeinde; Karten zeigen die letzte ausgewählte Ebene. Nach dem Speichern werden veröffentlichte Dateien und Suchindex im Hintergrund neu erzeugt. | Categorías de localidades mostradas en la app y en los sitios WordPress (detalle de etapas y POI, tarjetas, filtros de búsqueda). Déjalo vacío para mostrarlas todas. El detalle de la app solo muestra Región, Provincia y Municipio; las tarjetas muestran el último nivel seleccionado. Tras guardar, los archivos publicados y el índice de búsqueda se regeneran en segundo plano. | Catégories de localités affichées dans l'app et sur les sites WordPress (détail des étapes et des POI, cartes, filtres de recherche). Laisser vide pour toutes les afficher. Le détail de l'app n'affiche que Région, Province et Commune ; les cartes affichent le dernier niveau sélectionné. Après l'enregistrement, les fichiers publiés et l'index de recherche sont régénérés en arrière-plan. |

Prima di aggiungere una chiave, verificare con `grep` che non esista già in quel file (per esempio `Region` o `Province`): se esiste, non duplicarla.

- [ ] **Step 4: eseguire i test e verificare che passano**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/TaxonomyWhere/TaxonomyWhereDisplayOptionTest.php`
Expected: PASS. Poi controllare che i JSON di lingua siano validi: `for f in resources/lang/*.json; do python3 -m json.tool "$f" >/dev/null || echo "INVALIDO $f"; done` (nessun output atteso).

- [ ] **Step 5: verifica manuale in Nova (dev)**

Aprire l'App 1 di camminiditalia in Nova, tab "frontend": il campo "Località mostrate" elenca Regione e Comune (i livelli presenti nei dati locali). Selezionare Regione e salvare; controllare in Horizon che parta `RegenerateTaxonomyWhereOutputsJob`.

- [ ] **Step 6: commit (istruzione per il dev)**

```bash
git add src/Services/TaxonomyWhereDisplayService.php src/Jobs/TaxonomyWhere/RegenerateTaxonomyWhereOutputsJob.php src/Nova/App.php src/Observers/AppObserver.php resources/lang/*.json tests/Feature/TaxonomyWhere/TaxonomyWhereDisplayOptionTest.php
git commit -m "feat(oc:8588): opzione App \"Località mostrate\" e rigenerazione delle uscite al cambio"
```

---

### Task 6: riallineamento conservativo e comando `wm:resync-taxonomy-where`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-riallineamento-conservativo-e-comando)

**Files:**
- Create: `src/Services/TaxonomyWhereResyncService.php`
- Create: `src/Jobs/TaxonomyWhere/ConservativeSyncTaxonomyWhereJob.php`
- Create: `src/Commands/WmResyncTaxonomyWhereCommand.php`
- Modify: `src/WmPackageServiceProvider.php` (`hasCommands([...])`: aggiungere `WmResyncTaxonomyWhereCommand::class` e il relativo `use`)
- Test: `tests/Feature/TaxonomyWhere/TaxonomyWhereResyncTest.php`

**Interfaces:**
- Consumes: `GeometryComputationService::computeTaxonomyWhere()` (Task 2), `TaxonomyWhereDisplayService::fromOsmfeatures()`, `classifyFormat()`, `filter()`, `selectedCategoriesForApp()` (Task 1), `RegenerateTaxonomyWhereOutputsJob` (Task 5)
- Produces:
  - `TaxonomyWhereResyncService::resyncRecord(string $modelClass, int $id): bool` — `true` se ha scritto
  - `TaxonomyWhereResyncService::modelClasses(): array<string, class-string>` (`ec_tracks`, `ec_pois`, `ugc_pois`, `ugc_tracks`)
  - `TaxonomyWhereResyncService::dryRunReport(int $appId): array<string, array<string, int>>`
  - `TaxonomyWhereResyncService::dispatch(int $appId, bool $onlyLegacy): int` — numero di record accodati

Scelta di progetto: la rigenerazione è in **due fasi** e non in una catena per record. La fase 1 è un `Bus::batch` di `ConservativeSyncTaxonomyWhereJob`, uno per record; il suo `->finally()` accoda `RegenerateTaxonomyWhereOutputsJob` (fase 2). Motivo: il json statico di una traccia incorpora i `related_pois`, quindi va rigenerato dopo che anche i POI sono stati riallineati. Con una catena per record, la traccia potrebbe essere rigenerata con POI non ancora aggiornati. L'ordine richiesto dall'overview ("la rigenerazione parte dopo il sync") resta garantito, a livello di intero batch.

- [ ] **Step 1: scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\ConservativeSyncTaxonomyWhereJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\TaxonomyWhereResyncService;

uses(TestCase::class, DatabaseTransactions::class);

function resyncPoi(App $app, array $properties): int
{
    return DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        // punto in mezzo al mare, fuori da qualunque taxonomy_where locale di test
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[11.0,39.0,0]}')"),
        'properties' => json_encode($properties),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function fakeOsmfeatures(array $wheres): void
{
    $client = Mockery::mock(OsmfeaturesClient::class);
    $client->shouldReceive('getWheresByGeojson')->andReturn($wheres);
    app()->instance(OsmfeaturesClient::class, $client);
}

it('writes the osmfeatures result and keeps a backup of the previous value', function () {
    $app = App::factory()->create();
    $id = resyncPoi($app, ['taxonomy_where' => ['R41241' => ['it' => 'Esperia']]]);
    fakeOsmfeatures(['R40784' => ['it' => 'Lazio', '_admin_level' => 4]]);

    $written = app(TaxonomyWhereResyncService::class)->resyncRecord(EcPoi::class, $id);

    $properties = EcPoi::find($id)->properties;
    expect($written)->toBeTrue();
    expect($properties['taxonomy_where'])->toEqual(['R40784' => ['it' => 'Lazio', '_admin_level' => 4, '_source' => 'osmfeatures']]);
    expect($properties['_taxonomy_where_backup'])->toEqual(['R41241' => ['it' => 'Esperia']]);
});

it('does not overwrite an existing backup on a second run', function () {
    $app = App::factory()->create();
    $id = resyncPoi($app, [
        'taxonomy_where' => ['R40784' => ['it' => 'Lazio', '_admin_level' => 4, '_source' => 'osmfeatures']],
        '_taxonomy_where_backup' => ['R41241' => ['it' => 'Esperia']],
    ]);
    fakeOsmfeatures(['R40784' => ['it' => 'Lazio', '_admin_level' => 4]]);

    app(TaxonomyWhereResyncService::class)->resyncRecord(EcPoi::class, $id);

    expect(EcPoi::find($id)->properties['_taxonomy_where_backup'])->toEqual(['R41241' => ['it' => 'Esperia']]);
});

it('leaves the current value untouched when nothing is found', function () {
    $app = App::factory()->create();
    $id = resyncPoi($app, ['taxonomy_where' => ['R41241' => ['it' => 'Esperia']]]);
    fakeOsmfeatures([]);

    $written = app(TaxonomyWhereResyncService::class)->resyncRecord(EcPoi::class, $id);

    $properties = EcPoi::find($id)->properties;
    expect($written)->toBeFalse();
    expect($properties['taxonomy_where'])->toEqual(['R41241' => ['it' => 'Esperia']]);
    expect($properties)->not->toHaveKey('_taxonomy_where_backup');
});

it('lets an osmfeatures failure bubble up without touching the record', function () {
    $app = App::factory()->create();
    $id = resyncPoi($app, ['taxonomy_where' => ['R41241' => ['it' => 'Esperia']]]);
    $client = Mockery::mock(OsmfeaturesClient::class);
    $client->shouldReceive('getWheresByGeojson')->andThrow(new RuntimeException('timeout'));
    app()->instance(OsmfeaturesClient::class, $client);

    expect(fn () => app(TaxonomyWhereResyncService::class)->resyncRecord(EcPoi::class, $id))
        ->toThrow(RuntimeException::class);
    expect(EcPoi::find($id)->properties['taxonomy_where'])->toEqual(['R41241' => ['it' => 'Esperia']]);
});

it('counts records by format and missing categories in the dry run, without writing', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    resyncPoi($app, ['taxonomy_where' => ['R1' => ['it' => 'Esperia']]]);
    resyncPoi($app, ['taxonomy_where' => ['R2' => ['it' => 'Lazio', '_admin_level' => 4]]]);
    resyncPoi($app, ['taxonomy_where' => ['R3' => ['name' => ['it' => 'Comune'], 'admin_level' => 8]]]);
    $before = DB::table('ec_pois')->where('app_id', $app->id)->pluck('properties')->all();

    $report = app(TaxonomyWhereResyncService::class)->dryRunReport($app->id);

    expect($report['ec_pois'])->toMatchArray(['oldest' => 1, 'legacy' => 1, 'oc8487' => 1, 'empty' => 0, 'without_selected_categories' => 2]);
    expect(DB::table('ec_pois')->where('app_id', $app->id)->pluck('properties')->all())->toBe($before);
});

it('queues one conservative job per record, only legacy ones when asked', function () {
    Bus::fake();
    $app = App::factory()->create();
    resyncPoi($app, ['taxonomy_where' => ['R1' => ['it' => 'Esperia']]]);
    resyncPoi($app, ['taxonomy_where' => ['R2' => ['it' => 'Lazio', '_admin_level' => 4]]]);

    $count = app(TaxonomyWhereResyncService::class)->dispatch($app->id, onlyLegacy: true);

    expect($count)->toBe(1);
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1
        && $batch->jobs->first() instanceof ConservativeSyncTaxonomyWhereJob);
});

it('runs the command in dry-run mode without queueing anything', function () {
    Bus::fake();
    $app = App::factory()->create();
    resyncPoi($app, ['taxonomy_where' => ['R1' => ['it' => 'Esperia']]]);

    $this->artisan('wm:resync-taxonomy-where', ['--app' => $app->id, '--dry-run' => true])
        ->expectsOutputToContain('ec_pois')
        ->assertSuccessful();

    Bus::assertNothingBatched();
    Bus::assertNothingDispatched();
});
```

- [ ] **Step 2: eseguire il test e verificare che fallisce**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/TaxonomyWhere/TaxonomyWhereResyncTest.php`
Expected: FAIL (`TaxonomyWhereResyncService` inesistente).

- [ ] **Step 3: implementare il servizio**

```php
<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\ConservativeSyncTaxonomyWhereJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;

/**
 * Riallineamento di properties.taxonomy_where alla forma vecchia (oc:8588).
 *
 * A differenza del job automatico di oc:8487, non azzera mai: calcola prima (SQL locale, poi
 * osmfeatures) e scrive solo se ha un risultato non vuoto, salvando il valore precedente in
 * properties._taxonomy_where_backup (una volta sola: le esecuzioni successive non lo toccano).
 */
class TaxonomyWhereResyncService extends BaseService
{
    public function __construct(
        private GeometryComputationService $geometry,
        private TaxonomyWhereDisplayService $display,
        private OsmfeaturesClient $osmfeatures,
    ) {}

    /** @return array<string, class-string<GeometryModel>> */
    public function modelClasses(): array
    {
        $trackModel = config('wm-package.ec_track_model');

        $classes = [];
        foreach ([$trackModel, EcPoi::class, UgcPoi::class, UgcTrack::class] as $class) {
            $classes[(new $class)->getTable()] = $class;
        }

        return $classes;
    }

    public function resyncRecord(string $modelClass, int $id): bool
    {
        /** @var GeometryModel|null $model */
        $model = $modelClass::find($id);
        if ($model === null || $model->geometry === null) {
            return false;
        }

        $computed = $this->geometry->computeTaxonomyWhere($model);

        if ($computed === []) {
            $geojson = $model->getGeojson();
            if ($geojson !== null) {
                $computed = $this->display->fromOsmfeatures($this->osmfeatures->getWheresByGeojson($geojson));
            }
        }

        if ($computed === []) {
            Log::warning('oc:8588 taxonomy_where non riallineata: nessun risultato locale né da osmfeatures', [
                'table' => $model->getTable(),
                'id' => $id,
            ]);

            return false;
        }

        $table = $model->getTable();
        DB::statement("
            UPDATE {$table}
            SET properties = jsonb_set(
                CASE
                    WHEN COALESCE(properties, '{}'::jsonb) ? '_taxonomy_where_backup' THEN COALESCE(properties, '{}'::jsonb)
                    ELSE jsonb_set(COALESCE(properties, '{}'::jsonb), '{_taxonomy_where_backup}', COALESCE(properties->'taxonomy_where', 'null'::jsonb))
                END,
                '{taxonomy_where}',
                ?::jsonb
            )
            WHERE id = ?
        ", [json_encode((object) $computed), $id]);

        return true;
    }

    /** @return array<string, array<string, int>> */
    public function dryRunReport(int $appId): array
    {
        $selected = $this->display->selectedCategoriesForApp($appId);
        $report = [];

        foreach ($this->modelClasses() as $table => $class) {
            $counts = ['empty' => 0, 'oldest' => 0, 'legacy' => 0, 'oc8487' => 0, 'without_selected_categories' => 0];

            DB::table($table)->where('app_id', $appId)->whereNotNull('geometry')->select(['id', 'properties'])
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$counts, $selected) {
                    foreach ($rows as $row) {
                        $where = (json_decode($row->properties ?? '{}', true) ?: [])['taxonomy_where'] ?? null;
                        $counts[$this->display->classifyFormat($where)]++;
                        if ($selected !== [] && $this->display->filter(is_array($where) ? $where : [], $selected) === []) {
                            $counts['without_selected_categories']++;
                        }
                    }
                });

            $report[$table] = $counts;
        }

        return $report;
    }

    public function dispatch(int $appId, bool $onlyLegacy): int
    {
        $jobs = [];

        foreach ($this->modelClasses() as $table => $class) {
            DB::table($table)->where('app_id', $appId)->whereNotNull('geometry')->select(['id', 'properties'])
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$jobs, $class, $onlyLegacy) {
                    foreach ($rows as $row) {
                        $where = (json_decode($row->properties ?? '{}', true) ?: [])['taxonomy_where'] ?? null;
                        if ($onlyLegacy && $this->display->classifyFormat($where) === 'legacy') {
                            continue;
                        }
                        $jobs[] = new ConservativeSyncTaxonomyWhereJob($class, (int) $row->id);
                    }
                });
        }

        if ($jobs === []) {
            RegenerateTaxonomyWhereOutputsJob::dispatch($appId);

            return 0;
        }

        Bus::batch($jobs)
            ->name("oc8588-resync-taxonomy-where-app-{$appId}")
            ->onQueue('geometric-computations')
            ->allowFailures()
            ->finally(fn () => RegenerateTaxonomyWhereOutputsJob::dispatch($appId))
            ->dispatch();

        return count($jobs);
    }
}
```

(Verificare che `properties` delle quattro tabelle sia `jsonb`: se una è `json`, l'operatore `?` e `jsonb_set` richiedono un cast `properties::jsonb` in lettura e `::json` in scrittura, come già fa lo stesso file di oc:8487 per quel caso. Controllare con `\d ugc_pois` nel DB di camminiditalia.)

`src/Jobs/TaxonomyWhere/ConservativeSyncTaxonomyWhereJob.php`:

```php
<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\TaxonomyWhereResyncService;

class ConservativeSyncTaxonomyWhereJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public string $modelClass, public int $modelId) {}

    public function handle(TaxonomyWhereResyncService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $service->resyncRecord($this->modelClass, $this->modelId);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('oc:8588 ConservativeSyncTaxonomyWhereJob fallito dopo tutti i tentativi: valore lasciato invariato', [
            'model' => $this->modelClass,
            'model_id' => $this->modelId,
            'error' => $e->getMessage(),
        ]);
    }
}
```

`src/Commands/WmResyncTaxonomyWhereCommand.php`:

```php
<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Services\TaxonomyWhereResyncService;

class WmResyncTaxonomyWhereCommand extends Command
{
    protected $signature = 'wm:resync-taxonomy-where
                            {--app= : ID dell\'App (obbligatorio)}
                            {--only-legacy : Solo i record senza _admin_level o nel formato oc:8487}
                            {--outputs-only : Non ricalcola, rigenera solo json statici, Elasticsearch e pois.geojson}
                            {--dry-run : Conta i record per formato senza scrivere né chiamare osmfeatures}';

    protected $description = 'Riallinea properties.taxonomy_where alla forma vecchia in modo conservativo e rigenera le uscite pubbliche (oc:8588).';

    public function handle(TaxonomyWhereResyncService $service): int
    {
        $appId = (int) $this->option('app');
        if ($appId <= 0) {
            $this->error('Opzione --app obbligatoria.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $rows = [];
            foreach ($service->dryRunReport($appId) as $table => $counts) {
                $rows[] = [$table, ...array_values($counts)];
            }
            $this->table(['tabella', 'vuoti', 'formato più vecchio', 'forma vecchia', 'formato oc:8487', 'senza categorie scelte'], $rows);

            return self::SUCCESS;
        }

        if ($this->option('outputs-only')) {
            RegenerateTaxonomyWhereOutputsJob::dispatch($appId);
            $this->info('Rigenerazione delle uscite accodata.');

            return self::SUCCESS;
        }

        $count = $service->dispatch($appId, (bool) $this->option('only-legacy'));
        $this->info("Record accodati: {$count}. La rigenerazione delle uscite parte a fine batch. Rilanciare con --dry-run per vedere cosa resta da riallineare.");

        return self::SUCCESS;
    }
}
```

In `WmPackageServiceProvider`: `use Wm\WmPackage\Commands\WmResyncTaxonomyWhereCommand;` e aggiungere `WmResyncTaxonomyWhereCommand::class,` dopo `WmSyncUgcTaxonomyWhereCommand::class,` in `hasCommands([...])`.

- [ ] **Step 4: eseguire i test e verificare che passano**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/TaxonomyWhere/TaxonomyWhereResyncTest.php`
Expected: PASS (7 test).

- [ ] **Step 5: prova reale in sola lettura sul DB di sviluppo di camminiditalia (dev)**

Run: `docker exec laravel-camminiditalia php artisan wm:resync-taxonomy-where --app=1 --dry-run`
Expected: la tabella di `ec_tracks` con circa `oldest=1040`, `legacy≈240`, `empty=4` (i numeri misurati in analisi). Nessuna scrittura.

- [ ] **Step 6: commit (istruzione per il dev)**

```bash
git add src/Services/TaxonomyWhereResyncService.php src/Jobs/TaxonomyWhere/ConservativeSyncTaxonomyWhereJob.php src/Commands/WmResyncTaxonomyWhereCommand.php src/WmPackageServiceProvider.php tests/Feature/TaxonomyWhere/TaxonomyWhereResyncTest.php
git commit -m "feat(oc:8588): comando di riallineamento conservativo wm:resync-taxonomy-where"
```

---

### Task 7: regressioni sui lettori delle chiavi e documentazione

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7-regressioni-e-documentazione)

**Files:**
- Test: `tests/Feature/TaxonomyWhere/TaxonomyWhereKeyReadersRegressionTest.php`
- Modify: `docs/resources/TaxonomyWhere.md` (sezione sul formato di `properties.taxonomy_where` e sull'opzione App)
- Modify: `docs/features/8487-generalizzare-sync-taxonomy-where-ecpoi/notes.md` (nota in fondo: il formato unificato è stato rovesciato da oc:8588, con il motivo)

- [ ] **Step 1: scrivere il test di regressione**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Nova\Filters\EcPoiRegionFilter;

uses(TestCase::class, DatabaseTransactions::class);

function legacyPoi(App $app): int
{
    return DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[13.7,41.4,0]}')"),
        'properties' => json_encode(['taxonomy_where' => [
            'R40784' => ['it' => 'Lazio', '_admin_level' => 4, '_source' => 'osmfeatures'],
        ]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('EcPoiRegionFilter still finds a POI stored in the legacy shape', function () {
    $id = legacyPoi(App::factory()->create());

    $query = (new EcPoiRegionFilter)->apply(app(NovaRequest::class), EcPoi::query(), 'R40784');

    expect($query->pluck('id')->all())->toContain($id);
});

it('scopeByWhereProperty still matches a POI stored in the legacy shape', function () {
    $id = legacyPoi(App::factory()->create());

    $ids = EcPoi::query()->byWhereProperty(['taxonomy_where' => ['R40784' => []]])->pluck('id')->all();

    expect($ids)->toContain($id);
});
```

(Se `EcPoi` non usa il trait `TaxonomyWhereAbleModel`, usare il modello che lo usa: `grep -rn "TaxonomyWhereAbleModel" src/Models`.)

- [ ] **Step 2: eseguire il test**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/TaxonomyWhere/TaxonomyWhereKeyReadersRegressionTest.php`
Expected: PASS (è un test di regressione: se fallisce, le chiavi o la forma rompono un lettore esistente e va capito prima di proseguire).

- [ ] **Step 3: documentazione**

In `docs/resources/TaxonomyWhere.md` aggiungere una sezione "Formato di `properties.taxonomy_where` e località mostrate" con:
- la forma `{id: {<lingue>, _admin_level, _source}}` e il motivo (wm-core `<wm-txn-where>` e wp-geohub `single_track.php` leggono solo quella);
- le categorie (`"<N>"`, `"source:<valore>"`, `null`) e l'opzione App `properties->taxonomy_where_display`;
- dove si applica il filtro (uscite pubbliche) e dove no (`getModelAsGeojson()`, `getSearchableString()`);
- l'ordine di rilascio: `pg_dump` di `ec_tracks`, `ec_pois`, `ugc_pois`, `ugc_tracks` → deploy → riavvio Horizon → `wm:resync-taxonomy-where --app=<id> --dry-run` → comando senza `--dry-run` → impostazione dell'opzione in Nova → di nuovo `--dry-run` per vedere cosa resta non riallineato.

In `docs/features/8487-generalizzare-sync-taxonomy-where-ecpoi/notes.md`, in fondo (il cantiere non si riscrive, si annota):

```markdown
## Nota successiva (oc:8588, 2026-09-23)

Il formato unificato `{name, admin_level, source}` introdotto qui è stato rovesciato da oc:8588:
tutti gli scrittori tornano alla forma `{<lingue>, _admin_level, _source}`, perché wm-core
(`<wm-txn-where>`) e wp-geohub (`single_track.php`) leggono solo quella e non mostravano più la
sezione "Dove". Dettaglio in `docs/features/8588-mostrare-solo-la-regione-non-il-comune-nel-dettaglio-tappa/`.
```

- [ ] **Step 4: suite completa del package e PHPStan**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests`
Expected: nessun test rosso nuovo rispetto allo stato del Task 0.
Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && composer analyse"`
Expected: nessun errore nuovo sui file toccati.

- [ ] **Step 5: commit (istruzione per il dev)**

```bash
git add tests/Feature/TaxonomyWhere/TaxonomyWhereKeyReadersRegressionTest.php docs/resources/TaxonomyWhere.md docs/features/8487-generalizzare-sync-taxonomy-where-ecpoi/notes.md docs/features/8588-mostrare-solo-la-regione-non-il-comune-nel-dettaglio-tappa/
git commit -m "feat(oc:8588): test di regressione sui lettori delle chiavi e documentazione del formato"
```

---

### Task 8: consumer camminiditalia (dopo il merge in wm-package)

Da fare **solo dopo** che il lavoro è stato mergiato in wm-package (regola di `wm-package/CLAUDE.md`: il package si merge prima, il consumer bumpa dopo).

- [ ] **Step 1: bump del submodule in camminiditalia** (dev), seguendo la procedura migration di `wm-package/docs/howto/migration-wm-package.md` (questo lavoro non aggiunge migration, ma la procedura va eseguita comunque a ogni bump).
- [ ] **Step 2: verifica locale su camminiditalia (dev)**: `--dry-run`, poi il comando reale sull'App 1, poi l'opzione "Località mostrate" = Regione in Nova; aprire il json statico di una tappa del formato più vecchio (per esempio la EcTrack 705) e controllare che `taxonomy_where` contenga solo la regione, con `_admin_level: 4`.
- [ ] **Step 3: riga in `CLAUDE.md` di camminiditalia** (tabella `## Feature disponibili`), da preparare nella fase di aggiornamento del contesto.

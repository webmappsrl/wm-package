> Ticket: oc:8488

# Config app: allineamento a GeoHub post-import — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dopo l'import one-off di un'app da GeoHub, il `config.json` prodotto da Maphub coincide con quello di GeoHub senza alcun intervento manuale.

**Architecture:** Tre cause, non sei sintomi. **Causa 1** (18 divergenze osservate, 25 latenti): `ImportAppJob::transformData()` copia solo colonne omonime e scarta il resto in silenzio — un contratto scaduto da quando la configurazione si è spostata dentro `properties`, una pivot, o da nessuna parte. Fix: una mappa dichiarativa (`ImportedAppProperties`) sorgente unica di quali chiavi `properties` l'import scrive e Nova rende editabili; `AppConfigService` legge tutto tramite un solo helper col criterio `! is_null()`. **Causa 2** (2 divergenze): il remap della HOME e la scrittura del config sono agganciati al momento sbagliato — dispatch invece di completamento. Fix: un `->finally()` sul **solo batch layer** (non un batch padre di tutta l'entità dell'import) che rimappa e scrive il config. **Causa 4** (0 osservate oggi, 47 al primo re-import): `fill()` sostituisce `properties` invece di fonderle — distruggerebbe silenziosamente il fix delle altre due cause.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 2 + Orchestra Testbench, PostgreSQL/PostGIS, Laravel Nova 5, Horizon/Redis.

**Spec:** `docs/features/8488-config-app-allineamento-geohub-post-import/overview.md` (questo repo, organizzato per causa) e `../maphub/docs/features/8488-config-app-allineamento-geohub-post-import/overview.md` (perimetro Maphub). Il piano argomenta dalla spec: leggere entrambi.

## Global Constraints

- **Nessuna migration.** Nessuna colonna viene aggiunta a `apps`. Se un task sembra richiederne una, è il task a essere sbagliato.
- **Criterio di omissione: `! is_null()`.** Mai `empty()`, mai `is_string()`. `false` e `0` sono valori da emettere.
- **Nessun batch padre.** Il fix della causa 2 si aggancia al solo batch dell'entità `layer`, non a un batch che aggrega tutte le entità. Gli altri batch (ec_poi, ec_track, taxonomy, ec_media, ugc_*) restano esattamente come sono oggi.
- **Nessun fix a `persistQuietly()`.** Verificato sui 35 job falliti del test di import reale: zero sono falliti dentro `saveQuietly()`. Il fix scelto non dipende da quel comportamento. Fuori scope.
- **Nessun import eseguito da Claude.** `php artisan wm:import-from-geohub` è un'azione del dev, su un'app che sceglie il dev. Le query in **sola lettura** sulla connessione `geohub` sono ammesse.
- **Nessun `git clean` / `checkout -f` / `stash -u`** nel working tree di maphub: contiene due migration non tracciate già applicate al DB.
- **Nessun commit eseguito da Claude.** I blocchi `git commit` di questo piano sono **istruzioni testuali per l'utente**. Non eseguirli.
- **Test:** namespace `Wm\WmPackage\Tests\TestCase` (mai `Tests\TestCase`, pattern rotto documentato in `CLAUDE.md`). Ogni file apre con `declare(strict_types=1);` e `uses(DatabaseTransactions::class);` — **mai** `uses(TestCase::class, DatabaseTransactions::class);`: `tests/Pest.php` applica già `uses(TestCase::class)->in(__DIR__)` a tutta la cartella, e ridichiarare la stessa classe nel file fa lanciare `TestCaseAlreadyInUse` (verificato: il file preesistente `AppConfigServiceThemeTest.php`, di oc:8367, usa il pattern vecchio e infatti non è eseguibile con questa versione di Pest — non è nel perimetro di questo ticket, non toccarlo). Factory: `App::factory()->createQuietly([...])`.
- **Ambiente Docker:** eseguire `vendor/bin/pest`/`composer` con `docker exec -w /var/www/html/maphub/wm-package php-maphub <comando>` — **non** `docker compose -f local.compose.yml exec ... laravel`, il cui mount di `/var/www/html/wm-package` punta a una directory host vuota e obsoleta (verificato coi bind mount reali del container). `php-maphub` monta l'intero repo `maphub` (sottomodulo incluso) sul path corretto.
- **`vendor/bin/pest` senza filtro FALLISCE** su 19 file preesistenti (debito noto). Eseguire sempre per singolo file.
- **Convenzione commit:** `fix(oc:8488): ...`, `test(oc:8488): ...`, `refactor(oc:8488): ...`, `docs(oc:8488): ...`.
- **Formattazione:** `vendor/bin/pint` prima di ogni commit.
- **Branch:** `feature/oc-8488-config-app-allineamento-geohub-post-import`, già creato.

## File Structure

| File | Responsabilità |
|---|---|
| `src/Support/ImportedAppProperties.php` | **Nuovo.** Sorgente unica: mappa `properties`-key → tipo. Due consumer: import e Nova |
| `src/Jobs/Import/ImportAppJob.php` | `transformData()`: merge `properties`, scrittura theme + 37 campi, pivot `app_tile`. `queueEntityImport()`: `allowFailures()` + `finally()` sul solo batch layer |
| `src/Services/Import/GeohubImportService.php` | Nuovo `resolveTile()`. `persistQuietly()` **non toccato** |
| `src/Services/Models/App/AppConfigService.php` | Nuovo helper `prop()`/`setProp()` con criterio `! is_null()`; 36 siti di lettura migrati; 4 nuove esposizioni |
| `src/Nova/App.php` | Generazione dei campi dalla mappa; `options()`/guard della Select layer |
| `src/Jobs/UpdateAppConfigJob.php` | `ShouldBeUnique` |
| `src/Jobs/UpdateAppConfigHomeLayerIdsJob.php` | Rimozione attesa a finestra fissa; remap idempotente |
| `src/Nova/Flexible/Resolvers/ConfigHomeResolver.php` | Guard contro la riassegnazione silenziosa |
| `src/Http/Controllers/Api/AppController.php` | `config()`: response object, fallback funzionante, storage-first invariato |
| `resources/lang/{it,en}.json` | Stringhe `app.prop.<key>` e `app.prop.<key>.help` |

---

## Task 0: Rendere eseguibile la suite di test del package

Prerequisito assoluto: `wm-package/vendor/` contiene **una sola voce**, `vendor/bin/pest` non esiste, e il DB `wm_package` che `phpunit.xml.dist` si aspetta non esiste. Senza questo task nessuno step TDD dei task successivi è eseguibile.

**Files:** nessuno (setup ambiente).

**Interfaces:**
- Consumes: niente
- Produces: `vendor/bin/pest` eseguibile dal container, DB `wm_package` con PostGIS

- [ ] **Step 1: Creare il database di test con PostGIS**

```bash
cd /Users/rubensgarofalo/Sites/Webmapp/Laravel/maphub
docker compose -f local.compose.yml exec -T db psql -U maphub -c "CREATE DATABASE wm_package;"
docker compose -f local.compose.yml exec -T db psql -U maphub -d wm_package -c "CREATE EXTENSION IF NOT EXISTS postgis;"
```

- [ ] **Step 2: Installare le dipendenze di sviluppo del package**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub composer install
```

Se `post-install-cmd` (`npm install`) fallisce per `npm` non disponibile nel container: `composer install --no-scripts` poi `composer run prepare` (il solo script necessario ai test, invoca `testbench package:discover`).

- [ ] **Step 3: Verificare l'ambiente con un test esistente**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/AppConfigServiceThemeTest.php
```

Expected: PASS. Questo file è di oc:8367, usa il namespace corretto: la sua riuscita conferma che DB, testbench e Pest funzionano.

- [ ] **Step 4: Nessun commit**

`vendor/` è in `.gitignore`. Non c'è nulla da committare.

---

## Task 1: `properties` fuse invece di sostituite, con precedenza a GeoHub sui null locali (causa 4)

`importData()` fa `$model->fill($transformedData)` e `transformData()` costruisce `$transformedData['properties']` da zero. `properties` è castato `array` con `$guarded = []`: ogni re-import azzera tutto ciò che Nova ha configurato. Provato in sola lettura su app 3: 28 chiavi → 2.

Serve anche la regola di precedenza: `properties.theme` su app 3 contiene 4 chiavi a `null` scritte da un salvataggio Nova (il primo salvataggio materializza i default del campo). Un merge naïf le farebbe sopravvivere, e `config_section_theme()` le scarterebbe.

**Files:**
- Modify: `src/Jobs/Import/ImportAppJob.php` (`transformData()`)
- Test: `tests/Feature/Import/ImportAppJobPropertiesMergeTest.php` (nuovo)

**Interfaces:**
- Consumes: niente
- Produces: `ImportAppJob::mergeProperties(?Model $existing, array $incoming): array` (protected)

- [ ] **Step 1: Scrivere il test che falla**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;

uses(DatabaseTransactions::class);

it('preserves Nova-configured properties keys across a re-import', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'geohub_id' => 999,
            'min_app_version' => '3.2.1',
            'analytics_app_enabled' => true,
        ],
    ]);

    $merged = mergePropertiesForTest($app, ['geohub_id' => 999, 'geohub_synced_at' => 'now']);

    expect($merged)->toHaveKeys(['min_app_version', 'analytics_app_enabled'])
        ->and($merged['min_app_version'])->toBe('3.2.1');
});

it('lets a non-null Geohub value overwrite an explicit local null under theme', function () {
    $app = App::factory()->createQuietly([
        'properties' => ['theme' => ['primary_color' => null, 'font_family_header' => null]],
    ]);

    $merged = mergePropertiesForTest($app, [
        'theme' => ['primary_color' => '#0055aa', 'font_family_header' => 'Montserrat'],
    ]);

    expect($merged['theme']['primary_color'])->toBe('#0055aa')
        ->and($merged['theme']['font_family_header'])->toBe('Montserrat');
});

it('keeps a local value when Geohub has nothing to say about that key', function () {
    $app = App::factory()->createQuietly([
        'properties' => ['theme' => ['secondary_color' => '#abcdef']],
    ]);

    $merged = mergePropertiesForTest($app, ['theme' => ['primary_color' => '#0055aa']]);

    expect($merged['theme']['secondary_color'])->toBe('#abcdef')
        ->and($merged['theme']['primary_color'])->toBe('#0055aa');
});
```

Helper via reflection (verificare prima la firma reale del costruttore):

```bash
grep -n "function __construct" -A 8 src/Jobs/Import/BaseImportJob.php
```

```php
function mergePropertiesForTest(App $app, array $incoming): array
{
    $job = new \Wm\WmPackage\Jobs\Import\ImportAppJob('app', 999, []);
    $method = new ReflectionMethod($job, 'mergeProperties');
    $method->setAccessible(true);

    return $method->invoke($job, $app, $incoming);
}
```

- [ ] **Step 2: Eseguire il test e verificare che falla**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Import/ImportAppJobPropertiesMergeTest.php
```

Expected: FAIL con `ReflectionException: Method ... mergeProperties() does not exist`.

- [ ] **Step 3: Implementare il merge**

```php
/**
 * Fonde le properties in arrivo da Geohub con quelle già presenti sull'App locale.
 *
 * Necessario perché importData() fa $model->fill($transformedData) e `properties` è
 * castato `array`: assegnarlo sostituisce l'intera colonna, azzerando tutto ciò che
 * un admin ha configurato da Nova (theme, analytics, min_app_version, wp_*).
 *
 * Precedenza: un valore Geohub non-null vince sempre, anche su un null locale esplicito
 * (properties.theme contiene 4 chiavi a null appena qualcuno apre il tab Theme in Nova).
 * Un valore Geohub assente lascia intatto quello locale.
 *
 * Scrittura monotona crescente: una chiave che Geohub non emette più resta in properties.
 * Scelta consapevole, nessun percorso di rimozione in questo ciclo. Vedi oc:8488.
 */
protected function mergeProperties(?Model $existing, array $incoming): array
{
    $current = $existing?->properties ?? [];

    if (! is_array($current)) {
        $current = [];
    }

    foreach ($incoming as $key => $value) {
        if (is_array($value) && is_array($current[$key] ?? null)) {
            $current[$key] = array_merge($current[$key], array_filter(
                $value,
                static fn ($v) => ! is_null($v)
            ));

            continue;
        }

        if (! is_null($value)) {
            $current[$key] = $value;
        }
    }

    return $current;
}

protected function findExistingApp(int $geohubId): ?Model
{
    return \Wm\WmPackage\Models\App::where('properties->geohub_id', $geohubId)->first();
}
```

In `transformData()`, sostituire le assegnazioni dirette:

```php
// PRIMA
$transformedData['properties']['geohub_id'] = $data['id'];
$transformedData['properties']['geohub_synced_at'] = now();

// DOPO
$existing = $this->findExistingApp($data['id']);
$transformedData['properties'] = $this->mergeProperties($existing, [
    'geohub_id' => $data['id'],
    'geohub_synced_at' => now(),
]);
```

Verificare che l'identificatore combaci con `GeohubImportService::getIdentifier('app', $id)`:

```bash
grep -n "function getIdentifier" -A 12 src/Services/Import/GeohubImportService.php
```

- [ ] **Step 4: Eseguire il test e verificare che passa**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Import/ImportAppJobPropertiesMergeTest.php
```

Expected: PASS, 3 test.

- [ ] **Step 5: Commit** *(istruzione per l'utente — non eseguire)*

```bash
vendor/bin/pint src/Jobs/Import/ImportAppJob.php tests/Feature/Import/ImportAppJobPropertiesMergeTest.php
git add src/Jobs/Import/ImportAppJob.php tests/Feature/Import/ImportAppJobPropertiesMergeTest.php
git commit -m "fix(oc:8488): merge app properties on import instead of replacing them"
```

---

## Task 2: La mappa dichiarativa `ImportedAppProperties` (causa 1)

Sorgente unica di **quali** chiavi `properties` l'import scrive e Nova rende editabili. Non contiene la struttura delle sezioni di config: sono irregolari (`TABLES.details.hide_ascent` è la negazione di `table_details_show_ascent`, `OPTIONS.show_scale` non è derivabile dal nome, 5 campi vanno in due sezioni). Restano esplicite in `AppConfigService`.

**Files:**
- Create: `src/Support/ImportedAppProperties.php`
- Test: `tests/Feature/Support/ImportedAppPropertiesTest.php` (nuovo)

**Interfaces:**
- Consumes: niente
- Produces:
  - `ImportedAppProperties::MAP` — `array<string, array{type: string, geohub?: string, nova?: bool}>`
  - `ImportedAppProperties::keys(): array`
  - `ImportedAppProperties::geohubColumns(): array` — colonna GeoHub → chiave `properties` locale
  - `ImportedAppProperties::type(string $key): string`
  - `ImportedAppProperties::novaKeys(): array` — 31 chiavi (esclude il gruppo A, che ha già il campo)

- [ ] **Step 1: Scrivere il test che falla**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Support\ImportedAppProperties;

uses(DatabaseTransactions::class);

it('declares exactly the 37 properties keys imported from Geohub', function () {
    expect(ImportedAppProperties::keys())->toHaveCount(37);
});

it('maps the one Geohub column whose local key differs', function () {
    expect(ImportedAppProperties::geohubColumns())
        ->toHaveKey('show_download_tiles_button')
        ->and(ImportedAppProperties::geohubColumns()['show_download_tiles_button'])
        ->toBe('show_download_tiles');
});

it('resolves every Geohub column to a distinct local key', function () {
    $locals = array_values(ImportedAppProperties::geohubColumns());

    expect($locals)->toHaveCount(count(array_unique($locals)));
});

it('gives every key a supported type', function () {
    foreach (ImportedAppProperties::keys() as $key) {
        expect(ImportedAppProperties::type($key))->toBeIn(['bool', 'text', 'int']);
    }
});

it('excludes the group A keys from Nova generation, because those fields already exist', function () {
    expect(ImportedAppProperties::novaKeys())->toHaveCount(31)
        ->and(ImportedAppProperties::novaKeys())->not->toContain('show_travel_mode')
        ->and(ImportedAppProperties::novaKeys())->toContain('start_url');
});

it('does not declare any key that is already a real apps column', function () {
    // Se una chiave esistesse anche come colonna, transformData la copierebbe due volte
    // in posti diversi e riapriremmo la doppia sorgente che questo ticket chiude.
    $columns = \Illuminate\Support\Facades\Schema::getColumnListing('apps');

    expect(array_intersect(ImportedAppProperties::keys(), $columns))->toBe([]);
});
```

- [ ] **Step 2: Eseguire il test e verificare che falla**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Support/ImportedAppPropertiesTest.php
```

Expected: FAIL con `Class "Wm\WmPackage\Support\ImportedAppProperties" not found`.

- [ ] **Step 3: Scrivere la mappa**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Support;

/**
 * Sorgente unica delle chiavi `apps.properties` popolate dall'import Geohub (oc:8488).
 *
 * Due consumer, che DEVONO concordare sul nome della chiave:
 *   - ImportAppJob::transformData()  scrive properties[$key] leggendo la colonna Geohub
 *   - Nova\App                       genera il campo editabile per ogni voce con nova=>true
 *
 * La struttura delle sezioni di config NON sta qui: `TABLES.details.hide_ascent` è la
 * negazione di `table_details_show_ascent`, `OPTIONS.show_scale` non è derivabile dal
 * nome, e 5 campi sono letti in due sezioni con chiavi diverse. Quelle restano esplicite
 * in AppConfigService, che le legge tutte tramite l'unico helper prop()/setProp().
 *
 * Le 4 chiavi theme (primary_color, default_feature_color, font_family_header,
 * font_family_content) NON sono qui: vivono sotto properties.theme e passano da
 * AppConfigService::THEME_KEY_MAP e da Nova\App::theme_tab(), entrambi di oc:8367.
 */
final class ImportedAppProperties
{
    /**
     * @var array<string, array{type: string, geohub?: string, nova?: bool}>
     */
    public const MAP = [
        // --- Gruppo A: destinazione già letta da AppConfigService, campo Nova già presente.
        'show_travel_mode' => ['type' => 'bool', 'nova' => false],
        'show_features_in_viewport' => ['type' => 'bool', 'nova' => false],
        'min_zoom_features_in_viewport' => ['type' => 'int', 'nova' => false],
        'max_zoom_features_in_viewport' => ['type' => 'int', 'nova' => false],
        'show_track_direction_arrow' => ['type' => 'bool', 'nova' => false],
        'show_download_tiles' => ['type' => 'bool', 'nova' => false, 'geohub' => 'show_download_tiles_button'],

        // --- Gruppo B: lette da AppConfigService come attributo inesistente, nessun campo Nova.
        'start_url' => ['type' => 'text'],
        'show_edit_link' => ['type' => 'bool'],
        'skip_route_index_download' => ['type' => 'bool'],
        'show_favorites' => ['type' => 'bool'],
        'enable_routing' => ['type' => 'bool'],
        'offline_enable' => ['type' => 'bool'],
        'offline_force_auth' => ['type' => 'bool'],
        'tracks_on_payment' => ['type' => 'bool'],
        'table_details_show_gpx_download' => ['type' => 'bool'],
        'table_details_show_kml_download' => ['type' => 'bool'],
        'table_details_show_geojson_download' => ['type' => 'bool'],
        'table_details_show_shapefile_download' => ['type' => 'bool'],
        'table_details_show_scale' => ['type' => 'bool'],
        'table_details_show_related_poi' => ['type' => 'bool'],
        'table_details_show_duration_forward' => ['type' => 'bool'],
        'table_details_show_duration_backward' => ['type' => 'bool'],
        'table_details_show_distance' => ['type' => 'bool'],
        'table_details_show_ascent' => ['type' => 'bool'],
        'table_details_show_descent' => ['type' => 'bool'],
        'table_details_show_ele_max' => ['type' => 'bool'],
        'table_details_show_ele_min' => ['type' => 'bool'],
        'table_details_show_ele_from' => ['type' => 'bool'],
        'table_details_show_ele_to' => ['type' => 'bool'],
        'table_details_show_cai_scale' => ['type' => 'bool'],
        'table_details_show_mtb_scale' => ['type' => 'bool'],
        'table_details_show_ref' => ['type' => 'bool'],
        'table_details_show_surface' => ['type' => 'bool'],

        // --- Gruppo C-attivi: su Geohub, mai lette in Maphub. Nuova esposizione nel config.
        'draw_poi_show' => ['type' => 'bool'],
        'show_embedded_html' => ['type' => 'bool'],
        'show_get_directions' => ['type' => 'bool'],
        'show_media_name' => ['type' => 'bool'],
    ];

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Colonna Geohub → chiave properties locale. Coincidono tranne per il rename dichiarato.
     *
     * @return array<string, string>
     */
    public static function geohubColumns(): array
    {
        $out = [];

        foreach (self::MAP as $key => $spec) {
            $out[$spec['geohub'] ?? $key] = $key;
        }

        return $out;
    }

    public static function type(string $key): string
    {
        return self::MAP[$key]['type'] ?? 'text';
    }

    /**
     * Chiavi per cui Nova deve generare un campo. Il gruppo A è escluso: il campo esiste già.
     *
     * @return array<int, string>
     */
    public static function novaKeys(): array
    {
        return array_keys(array_filter(
            self::MAP,
            static fn (array $spec) => ($spec['nova'] ?? true) === true
        ));
    }
}
```

Contare le voci: 6 (A) + 27 (B) + 4 (C) = **37**. `novaKeys()` ne restituisce **31**.

- [ ] **Step 4: Eseguire il test e verificare che passa**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Support/ImportedAppPropertiesTest.php
```

Expected: PASS, 6 test.

- [ ] **Step 5: Commit** *(istruzione per l'utente — non eseguire)*

```bash
vendor/bin/pint src/Support/ImportedAppProperties.php tests/Feature/Support/ImportedAppPropertiesTest.php
git add src/Support/ImportedAppProperties.php tests/Feature/Support/ImportedAppPropertiesTest.php
git commit -m "feat(oc:8488): add ImportedAppProperties as the single source for imported properties keys"
```

---

## Task 3: L'import scrive `properties.theme.*` e le 37 chiavi (causa 1a-1d)

**Files:**
- Modify: `src/Jobs/Import/ImportAppJob.php` (`transformData()`)
- Test: `tests/Feature/Import/ImportAppJobWritesPropertiesTest.php` (nuovo)

**Interfaces:**
- Consumes: `ImportedAppProperties::geohubColumns()`, `ImportAppJob::mergeProperties()` (Task 1)
- Produces: `$transformedData['properties']` contiene `theme.*` e le 37 chiavi

- [ ] **Step 1: Scrivere il test che falla**

I valori sono scelti per **non poter coincidere con i default della migration** (`create_apps_table`: `primary_color` `#de1b0d`, `font_family_header` `'Roboto Slab'`, `font_family_content` `'Roboto'`, `default_feature_color` `#de1b0d'`).

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function geohubAppRow(array $overrides = []): array
{
    return array_merge([
        'id' => 999,
        'user_id' => 1,
        'name' => 'Synthetic App',
        'sku' => 'it.webmapp.synthetic',
        'primary_color' => '#0055aa',
        'default_feature_color' => '#00aa55',
        'font_family_header' => 'Montserrat',
        'font_family_content' => 'Inter',
        'start_url' => '/main/explore',
        'show_favorites' => true,
        'show_edit_link' => false,
        'skip_route_index_download' => true,
        'draw_poi_show' => false,
        'show_download_tiles_button' => true,
        'min_zoom_features_in_viewport' => 11,
    ], $overrides);
}

it('writes the four theme values under properties.theme, not to the dead columns', function () {
    $result = transformGeohubRow(geohubAppRow());

    expect($result['properties']['theme'])->toMatchArray([
        'primary_color' => '#0055aa',
        'default_feature_color' => '#00aa55',
        'font_family_header' => 'Montserrat',
        'font_family_content' => 'Inter',
    ]);
});

it('writes every mapped Geohub column into properties under its local key', function () {
    $result = transformGeohubRow(geohubAppRow());

    expect($result['properties'])->toMatchArray([
        'start_url' => '/main/explore',
        'show_favorites' => true,
        'skip_route_index_download' => true,
        'min_zoom_features_in_viewport' => 11,
    ]);
});

it('preserves false as false, never dropping it', function () {
    $result = transformGeohubRow(geohubAppRow());

    expect($result['properties'])->toHaveKey('show_edit_link')
        ->and($result['properties']['show_edit_link'])->toBeFalse()
        ->and($result['properties'])->toHaveKey('draw_poi_show')
        ->and($result['properties']['draw_poi_show'])->toBeFalse();
});

it('applies the one declared rename', function () {
    $result = transformGeohubRow(geohubAppRow());

    expect($result['properties'])->toHaveKey('show_download_tiles')
        ->and($result['properties']['show_download_tiles'])->toBeTrue()
        ->and($result['properties'])->not->toHaveKey('show_download_tiles_button');
});

it('no longer writes the four dead theme columns', function () {
    $result = transformGeohubRow(geohubAppRow());

    expect($result)->not->toHaveKey('primary_color')
        ->and($result)->not->toHaveKey('font_family_header')
        ->and($result)->not->toHaveKey('font_family_content')
        ->and($result)->not->toHaveKey('default_feature_color');
});

function transformGeohubRow(array $row): array
{
    $job = new \Wm\WmPackage\Jobs\Import\ImportAppJob('app', $row['id'], []);
    $method = new ReflectionMethod($job, 'transformData');
    $method->setAccessible(true);

    $prop = new ReflectionProperty($job, 'geohubImportService');
    $prop->setAccessible(true);
    $prop->setValue($job, app(\Wm\WmPackage\Services\Import\GeohubImportService::class));

    return $method->invoke($job, $row);
}
```

Verificare il nome reale della proprietà del service prima di scrivere il helper:

```bash
grep -n "geohubImportService" src/Jobs/Import/BaseImportJob.php | head -3
```

- [ ] **Step 2: Eseguire il test e verificare che falla**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Import/ImportAppJobWritesPropertiesTest.php
```

Expected: FAIL — `properties.theme` non esiste, `properties` non contiene `start_url`.

- [ ] **Step 3: Implementare la scrittura**

```php
use Wm\WmPackage\Support\ImportedAppProperties;

private const THEME_COLUMNS = [
    'primary_color',
    'default_feature_color',
    'font_family_header',
    'font_family_content',
];

/**
 * Chiavi Geohub che vivono sotto properties.theme in Maphub.
 *
 * Le colonne omonime esistono anche su apps ma oc:8367 le ha dichiarate morte: legge
 * solo properties.theme.*, e il filtro schema-driven di transformData le copierebbe
 * nel posto sbagliato. Vengono quindi escluse dal payload colonnare ed emesse qui.
 */
protected function buildImportedProperties(array $data): array
{
    $properties = [];

    $theme = [];
    foreach (self::THEME_COLUMNS as $column) {
        if (array_key_exists($column, $data)) {
            $theme[$column] = $data[$column];
        }
    }
    if ($theme !== []) {
        $properties['theme'] = $theme;
    }

    foreach (ImportedAppProperties::geohubColumns() as $geohubColumn => $localKey) {
        if (array_key_exists($geohubColumn, $data)) {
            $properties[$localKey] = $data[$geohubColumn];
        }
    }

    return $properties;
}
```

In `transformData()`:

```php
$diff = array_diff(array_keys($data), Schema::getColumnListing('apps'));
$transformedData = array_diff_key($data, array_flip($diff));

// Le colonne theme non vanno più scritte come colonne: oc:8367 le legge da properties.
$transformedData = array_diff_key($transformedData, array_flip(self::THEME_COLUMNS));

$existing = $this->findExistingApp($data['id']);
$transformedData['properties'] = $this->mergeProperties($existing, array_merge(
    $this->buildImportedProperties($data),
    ['geohub_id' => $data['id'], 'geohub_synced_at' => now()],
));
```

- [ ] **Step 4: Eseguire il test e verificare che passa**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Import/ImportAppJobWritesPropertiesTest.php
```

Expected: PASS, 5 test.

- [ ] **Step 5: Commit** *(istruzione per l'utente — non eseguire)*

```bash
vendor/bin/pint src/Jobs/Import/ImportAppJob.php tests/Feature/Import/ImportAppJobWritesPropertiesTest.php
git add src/Jobs/Import/ImportAppJob.php tests/Feature/Import/ImportAppJobWritesPropertiesTest.php
git commit -m "fix(oc:8488): import Geohub app config into properties instead of dead columns"
```

---

## Task 4: `AppConfigService` legge da `properties` con il criterio `! is_null()` (causa 1c, 1d)

36 siti di lettura su 31 campi. Cinque campi sono letti in due sezioni con guard diversi: `table_details_show_{scale, gpx_download, kml_download, geojson_download, shapefile_download}` sono in `config_section_tables()` (dentro `api == 'elbrus'`) e in `config_section_options()` (**senza** guard).

**Files:**
- Modify: `src/Services/Models/App/AppConfigService.php`
- Test: `tests/Feature/AppConfigServiceImportedPropertiesTest.php` (nuovo)

**Interfaces:**
- Consumes: `ImportedAppProperties`
- Produces: `AppConfigService::prop(string $key, mixed $default = null): mixed` (protected), `AppConfigService::setProp(array &$target, string $key, string $configKey): void` (private)

- [ ] **Step 1: Scrivere il test che falla**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

uses(DatabaseTransactions::class);

it('emits OPTIONS keys from properties instead of non-existent columns', function () {
    $app = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => [
            'start_url' => '/main/explore',
            'show_edit_link' => false,
            'skip_route_index_download' => true,
            'show_favorites' => true,
            'table_details_show_scale' => true,
            'table_details_show_gpx_download' => false,
            'table_details_show_kml_download' => false,
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS'])->toMatchArray([
        'startUrl' => '/main/explore',
        'showEditLink' => false,
        'skipRouteIndexDownload' => true,
        'show_favorites' => true,
        'show_scale' => true,
        'showGpxDownload' => false,
        'showKmlDownload' => false,
    ]);
});

it('omits a key only when the source value is null, never when it is false or zero', function () {
    $app = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => ['show_edit_link' => false, 'min_zoom_features_in_viewport' => 0],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS'])->toHaveKey('showEditLink')
        ->and($config['OPTIONS']['showEditLink'])->toBeFalse()
        ->and($config['OPTIONS']['minZoomFeaturesInViewport'])->toBe(0)
        ->and($config['OPTIONS'])->not->toHaveKey('startUrl');
});

it('exposes the four previously unread Geohub fields', function () {
    $app = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => [
            'show_embedded_html' => false,
            'show_get_directions' => false,
            'show_media_name' => false,
            'draw_poi_show' => false,
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS'])->toMatchArray([
        'showEmbeddedHtml' => false,
        'showGetDirections' => false,
        'showMediaName' => false,
    ])->and($config['WEBAPP']['draw_poi_show'])->toBeFalse();
});

it('reads the five dual-site fields in OPTIONS even for a non-elbrus app', function () {
    $app = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => [
            'table_details_show_geojson_download' => true,
            'table_details_show_shapefile_download' => true,
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS']['showGeojsonDownload'])->toBeTrue()
        ->and($config['OPTIONS']['showShapefileDownload'])->toBeTrue();
});

it('still gates the TABLES section behind api elbrus', function () {
    $webmapp = App::factory()->createQuietly([
        'api' => 'webmapp', 'properties' => ['table_details_show_ascent' => true],
    ]);
    $elbrus = App::factory()->createQuietly([
        'api' => 'elbrus', 'properties' => ['table_details_show_ascent' => true],
    ]);

    expect((new AppConfigService($webmapp))->config())->not->toHaveKey('TABLES')
        ->and((new AppConfigService($elbrus))->config()['TABLES']['details']['hide_ascent'])->toBeFalse();
});

it('does not hide a table detail that has no value configured', function () {
    // ! null è true: senza default esplicito, un campo non configurato risulterebbe
    // nascosto invece di mostrato — comportamento invariato rispetto a prima del fix.
    $app = App::factory()->createQuietly(['api' => 'elbrus', 'properties' => []]);

    expect((new AppConfigService($app))->config()['TABLES']['details']['hide_ascent'])->toBeFalse();
});

it('emits OFFLINE from properties', function () {
    $app = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => ['offline_enable' => true, 'tracks_on_payment' => true],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['OFFLINE'])->toMatchArray([
        'enable' => true, 'forceAuth' => false, 'tracksOnPayment' => true,
    ]);
});
```

- [ ] **Step 2: Eseguire il test e verificare che falla**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/AppConfigServiceImportedPropertiesTest.php
```

Expected: FAIL — `startUrl` è `null` invece di `/main/explore`, `showEmbeddedHtml` non esiste.

- [ ] **Step 3: Aggiungere i due helper**

```php
use Wm\WmPackage\Support\ImportedAppProperties;

/**
 * Legge una chiave importata da apps.properties (oc:8488).
 */
protected function prop(string $key, mixed $default = null): mixed
{
    $properties = $this->app->properties ?? [];

    if (! is_array($properties)) {
        return $default;
    }

    return $properties[$key] ?? $default;
}

/**
 * Scrive $configKey in $target solo se il valore sorgente non è null.
 *
 * Criterio `! is_null()`, NON `empty()` né `is_string()`: `false` e `0` sono valori
 * legittimi. Il frontend fa `OPTIONS: {...state.OPTIONS, ...conf.OPTIONS}` (wm-core,
 * conf.reducer.ts): una chiave assente lascia vincere il default, una chiave a null lo
 * sovrascrive. Omettere è giusto solo per null.
 */
private function setProp(array &$target, string $key, string $configKey): void
{
    $value = $this->prop($key);

    if (is_null($value)) {
        return;
    }

    $target[$configKey] = $value;
}
```

- [ ] **Step 4: Migrare `config_section_options()`**

```php
// PRIMA
$data['OPTIONS']['startUrl'] = $this->app->start_url;
$data['OPTIONS']['showEditLink'] = $this->app->show_edit_link;
$data['OPTIONS']['skipRouteIndexDownload'] = $this->app->skip_route_index_download;
// showTrackRefLabel/download_track_enable/print_track_enable/show_searchbar restano invariati: colonne reali
$data['OPTIONS']['show_favorites'] = $this->app->show_favorites;
$data['OPTIONS']['show_scale'] = $this->app->table_details_show_scale;
$data['OPTIONS']['showGpxDownload'] = $this->app->table_details_show_gpx_download;
$data['OPTIONS']['showKmlDownload'] = $this->app->table_details_show_kml_download;
$data['OPTIONS']['showGeojsonDownload'] = (bool) $this->app->table_details_show_geojson_download;
$data['OPTIONS']['showShapefileDownload'] = (bool) $this->app->table_details_show_shapefile_download;

// DOPO
$this->setProp($data['OPTIONS'], 'start_url', 'startUrl');
$this->setProp($data['OPTIONS'], 'show_edit_link', 'showEditLink');
$this->setProp($data['OPTIONS'], 'skip_route_index_download', 'skipRouteIndexDownload');
$data['OPTIONS']['showTrackRefLabel'] = $this->app->show_track_ref_label;
$data['OPTIONS']['download_track_enable'] = $this->app->download_track_enable;
$data['OPTIONS']['print_track_enable'] = $this->app->print_track_enable;
$data['OPTIONS']['show_searchbar'] = $this->app->show_search;
$this->setProp($data['OPTIONS'], 'show_favorites', 'show_favorites');
$this->setProp($data['OPTIONS'], 'table_details_show_scale', 'show_scale');
$this->setProp($data['OPTIONS'], 'table_details_show_gpx_download', 'showGpxDownload');
$this->setProp($data['OPTIONS'], 'table_details_show_kml_download', 'showKmlDownload');
$this->setProp($data['OPTIONS'], 'table_details_show_geojson_download', 'showGeojsonDownload');
$this->setProp($data['OPTIONS'], 'table_details_show_shapefile_download', 'showShapefileDownload');

// Gruppo C-attivi: nuova esposizione, chiavi identiche a Geohub
$this->setProp($data['OPTIONS'], 'show_embedded_html', 'showEmbeddedHtml');
$this->setProp($data['OPTIONS'], 'show_get_directions', 'showGetDirections');
$this->setProp($data['OPTIONS'], 'show_media_name', 'showMediaName');
```

Il blocco finale (`show_travel_mode`, `show_features_in_viewport`, `min/max_zoom_features_in_viewport`) legge già `properties` con default hardcoded: **non toccarlo**.

- [ ] **Step 5: Migrare `config_section_tables()`, `config_section_routing()`, `config_section_offline()`**

`TABLES` resta interamente dentro `in_array($this->app->api, ['elbrus'])`. Le negazioni vanno rese null-safe con **default esplicito**: `! null` è `true`, quindi un porting meccanico nasconderebbe il dato per un'app non configurata.

```php
// PRIMA
$data['TABLES']['details']['hide_ascent'] = ! $this->app->table_details_show_ascent;

// DOPO — il default true preserva "mostra se non configurato"
$data['TABLES']['details']['hide_ascent'] = ! $this->prop('table_details_show_ascent', true);
```

Stesso schema per: `hide_duration:forward`, `hide_duration:backward`, `hide_distance`, `hide_ascent`, `hide_descent`, `hide_ele:max`, `hide_ele:min`, `hide_ele:from`, `hide_ele:to`, `hide_scale`, `hide_cai_scale`, `hide_mtb_scale`, `hide_ref`, `hide_surface`. Per i tre `(bool)`:

```php
$data['TABLES']['details']['showGpxDownload'] = (bool) $this->prop('table_details_show_gpx_download');
$data['TABLES']['details']['showKmlDownload'] = (bool) $this->prop('table_details_show_kml_download');
$data['TABLES']['details']['showRelatedPoi'] = (bool) $this->prop('table_details_show_related_poi');
$data['TABLES']['details']['showGeojsonDownload'] = (bool) $this->prop('table_details_show_geojson_download');
$data['TABLES']['details']['showShapefileDownload'] = (bool) $this->prop('table_details_show_shapefile_download');
```

`ROUTING` (stesso guard elbrus): `$data['ROUTING']['enable'] = $this->prop('enable_routing');`

`OFFLINE`:

```php
$data['OFFLINE']['enable'] = (bool) $this->prop('offline_enable');
$data['OFFLINE']['forceAuth'] = (bool) $this->prop('offline_force_auth');
$data['OFFLINE']['tracksOnPayment'] = (bool) $this->prop('tracks_on_payment');
```

- [ ] **Step 6: Migrare `config_section_webapp()` per `draw_poi_show`**

`draw_track_show`, `editing_inline_show`, `splash_screen_show` sono colonne reali: **non toccarle**.

```php
$data['WEBAPP']['draw_track_show'] = $this->app->draw_track_show;
$data['WEBAPP']['editing_inline_show'] = $this->app->editing_inline_show;
$data['WEBAPP']['splash_screen_show'] = $this->app->splash_screen_show;
$this->setProp($data['WEBAPP'], 'draw_poi_show', 'draw_poi_show');
```

- [ ] **Step 7: Test di sincronia, ed eseguire tutto**

```php
it('only reads properties keys that ImportedAppProperties declares', function () {
    $source = file_get_contents(__DIR__.'/../../src/Services/Models/App/AppConfigService.php');
    preg_match_all("/(?:prop|setProp)\(\s*(?:\\\$[a-zA-Z]+,\s*)?'([a-z_]+)'/", $source, $m);

    expect(array_values(array_unique(array_diff($m[1], \Wm\WmPackage\Support\ImportedAppProperties::keys()))))->toBe([]);
});
```

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/AppConfigServiceImportedPropertiesTest.php
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/AppConfigServiceThemeTest.php
```

Expected: PASS entrambi. Il secondo (oc:8367) non deve regredire.

- [ ] **Step 8: Commit** *(istruzione per l'utente — non eseguire)*

```bash
vendor/bin/pint src/Services/Models/App/AppConfigService.php tests/Feature/AppConfigServiceImportedPropertiesTest.php
git add src/Services/Models/App/AppConfigService.php tests/Feature/AppConfigServiceImportedPropertiesTest.php
git commit -m "fix(oc:8488): read imported app config from properties, omit only null keys"
```

---

## Task 5: Campi Nova generati dalla mappa (causa 1)

31 campi, generati, non scritti a mano: il disallineamento mappa↔Nova diventa **irrappresentabile**, e sparisce l'allowlist che servirebbe altrimenti (in `Nova/App.php` ci sono già 31 campi `properties->*` che non sono chiavi di questa mappa: `analytics_*`, `wp_*`, `min_app_version`, `theme->*`).

**Files:**
- Modify: `src/Nova/App.php`
- Modify: `resources/lang/it.json`, `resources/lang/en.json`
- Test: `tests/Feature/Nova/AppImportedPropertiesFieldsTest.php` (nuovo)

**Interfaces:**
- Consumes: `ImportedAppProperties::novaKeys()`, `::type()`
- Produces: `App::imported_properties_tab(): array`

- [ ] **Step 1: Scrivere il test che falla**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Support\ImportedAppProperties;

uses(DatabaseTransactions::class);

it('exposes a Nova field for every key that needs one', function () {
    $attributes = novaAppFieldAttributes();

    foreach (ImportedAppProperties::novaKeys() as $key) {
        expect($attributes)->toContain("properties->{$key}");
    }
});

it('does not duplicate the group A fields that already existed', function () {
    $attributes = novaAppFieldAttributes();

    expect(array_count_values($attributes)['properties->show_travel_mode'] ?? 0)->toBe(1);
});

it('has an it and en translation for every generated label and help', function () {
    $it = json_decode(file_get_contents(__DIR__.'/../../../resources/lang/it.json'), true);
    $en = json_decode(file_get_contents(__DIR__.'/../../../resources/lang/en.json'), true);

    foreach (ImportedAppProperties::novaKeys() as $key) {
        expect($it)->toHaveKey("app.prop.{$key}")->and($it)->toHaveKey("app.prop.{$key}.help")
            ->and($en)->toHaveKey("app.prop.{$key}")->and($en)->toHaveKey("app.prop.{$key}.help");
    }
});

function novaAppFieldAttributes(): array
{
    $resource = new \Wm\WmPackage\Nova\App(new \Wm\WmPackage\Models\App);
    $request = \Laravel\Nova\Http\Requests\NovaRequest::create('/', 'GET');

    $flatten = function ($fields) use (&$flatten) {
        $out = [];
        foreach ($fields as $field) {
            if (method_exists($field, 'data') && is_iterable($field->data ?? null)) {
                $out = array_merge($out, $flatten($field->data));
                continue;
            }
            if (property_exists($field, 'attribute')) {
                $out[] = $field->attribute;
            }
        }
        return $out;
    };

    return $flatten($resource->fields($request));
}
```

Se l'appiattimento dei `Tab` non funziona con la versione di Nova installata, verificare il pattern su un test esistente:

```bash
grep -rln "fields(" tests/Feature/Nova/ | head -3
```

- [ ] **Step 2: Eseguire il test e verificare che falla**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Nova/AppImportedPropertiesFieldsTest.php
```

Expected: FAIL — nessuno dei 31 attributi presente.

- [ ] **Step 3: Generare i campi**

```php
use Wm\WmPackage\Support\ImportedAppProperties;

/**
 * Campi delle chiavi properties importate da Geohub (oc:8488).
 *
 * GENERATI dalla mappa ImportedAppProperties, non scritti a mano: una chiave nuova è una
 * riga in un posto solo, e un campo Nova senza voce nella mappa (o viceversa) è
 * irrappresentabile. I 4 campi theme di oc:8367 restano scritti a mano in theme_tab(),
 * con label e help propri: NON uniformare, la divergenza è deliberata.
 */
protected function imported_properties_tab(): array
{
    $fields = [];

    foreach (ImportedAppProperties::novaKeys() as $key) {
        $label = __("app.prop.{$key}");
        $help = __("app.prop.{$key}.help");
        $attribute = "properties->{$key}";

        $fields[] = match (ImportedAppProperties::type($key)) {
            'bool' => Boolean::make($label, $attribute)->hideFromIndex()->help($help),
            'int' => Number::make($label, $attribute)->nullable()->hideFromIndex()->help($help),
            default => Text::make($label, $attribute)->nullable()->hideFromIndex()->help($help),
        };
    }

    return $fields;
}
```

Registrare `Tab::make(__('Imported config'), $this->imported_properties_tab())` accanto agli altri Tab. Aggiungere `use Laravel\Nova\Fields\Number;` se assente.

**NOTA POST-REVIEW: questa tab dedicata è stata rimossa su richiesta esplicita del dev, dopo l'esecuzione di questo task.** "L'import trasferisce un'app su Maphub, l'app deve comparire nelle tab esistenti come qualsiasi altra, non in una tab dedicata ai campi importati." `imported_properties_tab()` è stato sostituito da un helper per-chiave (`importedPropertyField(string $key)`), riusato dalle tab esistenti in base alla sezione di config che ogni chiave alimenta davvero (`app_tab`/Frontend per OPTIONS + le 19 `TABLES.details`, `mobile_tab` per OFFLINE, `map_settings_tab` per ROUTING, `webapp_tab` per WEBAPP). Le 19 chiavi `TABLES.details` sono state verificate contro l'admin reale di GeoHub: 9 hanno un equivalente lì ma su una colonna diversa (`track_technical_details->show_*`, mai esposta prima in Nova) — aggiunta anche questa, tenuta volutamente separata (stesso nome, output di config diverso). Dettaglio completo in `notes.md` → "Redesign UI Nova".

- [ ] **Step 4: Aggiungere le stringhe di lingua**

62 chiavi per file. Esempio della forma esatta, da replicare per le 31 chiavi di `novaKeys()`:

```json
"app.prop.start_url": "Start URL",
"app.prop.start_url.help": "Route the app opens on at startup (e.g. /main/explore)",
"app.prop.show_edit_link": "Show edit link",
"app.prop.show_edit_link.help": "Shows the content edit link inside the app",
"app.prop.draw_poi_show": "Show POI drawing",
"app.prop.draw_poi_show.help": "Enables the POI creation flow in the webapp",
"app.prop.table_details_show_scale": "Show scale",
"app.prop.table_details_show_scale.help": "Shows the difficulty scale in track details"
```

Per i 19 `table_details_show_*` la label naturale è il nome del dato; l'help dei 14 che vivono solo sotto `TABLES` può dichiarare il guard: `"Only used by apps with api = elbrus"`.

- [ ] **Step 5: Eseguire il test e verificare che passa**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Nova/AppImportedPropertiesFieldsTest.php
```

Expected: PASS, 3 test.

- [ ] **Step 6: Commit** *(istruzione per l'utente — non eseguire)*

```bash
vendor/bin/pint src/Nova/App.php tests/Feature/Nova/AppImportedPropertiesFieldsTest.php
git add src/Nova/App.php resources/lang/it.json resources/lang/en.json tests/Feature/Nova/AppImportedPropertiesFieldsTest.php
git commit -m "feat(oc:8488): generate Nova fields for imported app properties from the shared map"
```

---

## Task 6: Tiles — parser doppio-decode, pivot `app_tile`, `Tile` mancanti (causa 1e)

La forma reale di `apps.tiles` su GeoHub è **doppiamente codificata**: un array che contiene una *stringa* JSON. Verificato su GeoHub app 28: `'["{\"webmapp\":\"https://api.webmapp.it/tiles/{z}/{x}/{y}.png\"}"]'`.

Su GeoHub si usano 9 `attribution` distinte; in Maphub la tabella `tiles` ne ha 3. Mancano `CyclOSM`, `OSM`, `notile`, `GOMBITELLI`, `Humanitarian`, `CARG`.

**Files:**
- Modify: `src/Jobs/Import/ImportAppJob.php`
- Modify: `src/Services/Import/GeohubImportService.php` (nuovo `resolveTile()`)
- Test: `tests/Feature/Import/ImportAppJobTilesTest.php` (nuovo)

**Interfaces:**
- Consumes: `Wm\WmPackage\Models\Tile`
- Produces:
  - `ImportAppJob::parseGeohubTiles(mixed $raw): array<int, array{attribution: string, server_xyz: string}>`
  - `ImportAppJob::syncTiles(Model $app, array $parsed): void`
  - `GeohubImportService::resolveTile(string $attribution, string $serverXyz): Tile`

- [ ] **Step 1: Scrivere il test che falla**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Tile;

uses(DatabaseTransactions::class);

it('parses the real double-encoded Geohub tiles column', function () {
    $raw = '["{\"webmapp\":\"https:\/\/api.webmapp.it\/tiles\/{z}\/{x}\/{y}.png\"}"]';

    expect(parseTilesForTest($raw))->toBe([
        ['attribution' => 'webmapp', 'server_xyz' => 'https://api.webmapp.it/tiles/{z}/{x}/{y}.png'],
    ]);
});

it('also parses a plain array of objects, in case Geohub stops double-encoding', function () {
    $raw = '[{"satellite":"https://example.test/{z}/{x}/{y}.jpg"}]';

    expect(parseTilesForTest($raw))->toBe([
        ['attribution' => 'satellite', 'server_xyz' => 'https://example.test/{z}/{x}/{y}.jpg'],
    ]);
});

it('returns an empty array for null, empty string or malformed json', function () {
    expect(parseTilesForTest(null))->toBe([])
        ->and(parseTilesForTest(''))->toBe([])
        ->and(parseTilesForTest('not json'))->toBe([]);
});

it('attaches existing tiles to the pivot preserving Geohub order', function () {
    $app = App::factory()->createQuietly();
    Tile::firstOrCreate(['attribution' => 'webmapp'], tileAttrs('webmapp'));
    Tile::firstOrCreate(['attribution' => 'satellite'], tileAttrs('satellite'));

    syncTilesForTest($app, [
        ['attribution' => 'satellite', 'server_xyz' => 'x'],
        ['attribution' => 'webmapp', 'server_xyz' => 'y'],
    ]);

    expect($app->fresh()->tiles()->pluck('attribution')->all())->toBe(['satellite', 'webmapp']);
});

it('creates a missing Tile with a translations array label, never a bare string', function () {
    $app = App::factory()->createQuietly();

    syncTilesForTest($app, [
        ['attribution' => 'GOMBITELLI', 'server_xyz' => 'https://example.test/g/{z}/{x}/{y}.png'],
    ]);

    $tile = Tile::where('attribution', 'GOMBITELLI')->firstOrFail();

    // label è json NOT NULL con HasTranslations: una stringa nuda produrrebbe JSON invalido
    expect($tile->getTranslations('label'))->toBe(['it' => 'GOMBITELLI', 'en' => 'GOMBITELLI'])
        ->and($tile->server_xyz)->toBe('https://example.test/g/{z}/{x}/{y}.png')
        ->and($tile->icon)->toBeNull();
});

it('never modifies an existing Tile, even when Geohub disagrees on the url', function () {
    $app = App::factory()->createQuietly();
    $existing = Tile::firstOrCreate(['attribution' => 'webmapp'], tileAttrs('webmapp'));
    $originalUrl = $existing->server_xyz;
    $originalUpdatedAt = $existing->updated_at;

    syncTilesForTest($app, [
        ['attribution' => 'webmapp', 'server_xyz' => 'https://a-different-server.test/{z}/{x}/{y}.png'],
    ]);

    $existing->refresh();

    // TileObserver::saved() dispatcha UpdateAppConfigJob per OGNI app collegata al tile:
    // modificare un tile condiviso durante l'import di una app riscriverebbe il config
    // di app non correlate. La creazione è sicura (apps() vuota -> early return).
    expect($existing->server_xyz)->toBe($originalUrl)
        ->and($existing->updated_at->eq($originalUpdatedAt))->toBeTrue();
});

it('is idempotent across a re-import', function () {
    $app = App::factory()->createQuietly();
    Tile::firstOrCreate(['attribution' => 'webmapp'], tileAttrs('webmapp'));

    $parsed = [['attribution' => 'webmapp', 'server_xyz' => 'y']];
    syncTilesForTest($app, $parsed);
    syncTilesForTest($app, $parsed);

    expect($app->fresh()->tiles()->count())->toBe(1);
});

function tileAttrs(string $attribution): array
{
    return [
        'label' => ['it' => $attribution, 'en' => $attribution],
        'server_xyz' => "https://original.test/{$attribution}/{z}/{x}/{y}.png",
    ];
}

function parseTilesForTest(mixed $raw): array
{
    $job = new \Wm\WmPackage\Jobs\Import\ImportAppJob('app', 999, []);
    $m = new ReflectionMethod($job, 'parseGeohubTiles');
    $m->setAccessible(true);

    return $m->invoke($job, $raw);
}

function syncTilesForTest(App $app, array $parsed): void
{
    $job = new \Wm\WmPackage\Jobs\Import\ImportAppJob('app', 999, []);
    $prop = new ReflectionProperty($job, 'geohubImportService');
    $prop->setAccessible(true);
    $prop->setValue($job, app(\Wm\WmPackage\Services\Import\GeohubImportService::class));

    $m = new ReflectionMethod($job, 'syncTiles');
    $m->setAccessible(true);
    $m->invoke($job, $app, $parsed);
}
```

- [ ] **Step 2: Eseguire il test e verificare che falla**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Import/ImportAppJobTilesTest.php
```

Expected: FAIL con `ReflectionException: Method ... parseGeohubTiles() does not exist`.

- [ ] **Step 3: Implementare il parser**

```php
/**
 * Interpreta la colonna Geohub `apps.tiles`.
 *
 * La forma reale è DOPPIAMENTE CODIFICATA: un array che contiene una stringa JSON.
 * Verificato su Geohub app 28. Coincide col default della migration create_apps_table,
 * che ha la stessa genealogia. Il ramo "array di oggetti" tollera un futuro cambio.
 *
 * @return array<int, array{attribution: string, server_xyz: string}>
 */
protected function parseGeohubTiles(mixed $raw): array
{
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }

    if (! is_array($raw)) {
        return [];
    }

    $out = [];

    foreach ($raw as $entry) {
        if (is_string($entry)) {
            $entry = json_decode($entry, true);
        }

        if (! is_array($entry)) {
            continue;
        }

        foreach ($entry as $attribution => $serverXyz) {
            if (! is_string($attribution) || $attribution === '' || ! is_string($serverXyz) || $serverXyz === '') {
                continue;
            }

            $out[] = ['attribution' => $attribution, 'server_xyz' => $serverXyz];
        }
    }

    return $out;
}
```

- [ ] **Step 4: Implementare `resolveTile()` e `syncTiles()`**

In `GeohubImportService`:

```php
/**
 * Trova il Tile per attribution, o lo crea se manca.
 *
 * MAI aggiornare un Tile esistente: `tiles` è globale, e TileObserver::saved() dispatcha
 * UpdateAppConfigJob per OGNI app collegata al tile. Un update durante l'import di una
 * app riscriverebbe il config di app non correlate. La creazione è sicura (apps() vuota).
 *
 * `label` è json NOT NULL con HasTranslations: va scritta come array di traduzioni, mai
 * come stringa nuda.
 *
 * Il match ignora server_xyz: se un Tile locale omonimo punta altrove, l'app viene
 * agganciata a quel basemap. Limite noto, vedi overview → Rischi.
 */
public function resolveTile(string $attribution, string $serverXyz): Tile
{
    $tile = Tile::where('attribution', $attribution)->first();

    if ($tile) {
        return $tile;
    }

    $this->logger->info("Tile '{$attribution}' assente in locale: creato dall'import Geohub con label grezza e senza icona", [
        'attribution' => $attribution,
        'server_xyz' => $serverXyz,
    ]);

    return Tile::create([
        'attribution' => $attribution,
        'label' => ['it' => $attribution, 'en' => $attribution],
        'server_xyz' => $serverXyz,
        'icon' => null,
        'link' => null,
    ]);
}
```

In `ImportAppJob`:

```php
/**
 * Popola la pivot app_tile dall'ordine dichiarato da Geohub.
 *
 * App::tiles() ordina per app_tile.sort_order, quindi il primo elemento dell'array
 * Geohub resta il basemap di default in app. sync() invece di attach(): idempotente.
 */
protected function syncTiles(Model $app, array $parsed): void
{
    if ($parsed === []) {
        return;
    }

    $pivot = [];

    foreach (array_values($parsed) as $index => $entry) {
        $tile = $this->geohubImportService->resolveTile($entry['attribution'], $entry['server_xyz']);
        $pivot[$tile->id] = ['sort_order' => $index];
    }

    $app->tiles()->sync($pivot);
}
```

Aggiungere `use Wm\WmPackage\Models\Tile;` in `GeohubImportService` se assente.

- [ ] **Step 5: Agganciare `syncTiles()` all'import**

In `processDependencies()`, che riceve `$data` grezzo (non `$transformedData`):

```php
protected function processDependencies(array $data, Model $model): void
{
    $this->syncTiles($model, $this->parseGeohubTiles($data['tiles'] ?? null));

    // ... resto invariato
}
```

- [ ] **Step 6: Eseguire i test e verificare che passano**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Import/ImportAppJobTilesTest.php
```

Expected: PASS, 7 test.

- [ ] **Step 7: Commit** *(istruzione per l'utente — non eseguire)*

```bash
vendor/bin/pint src/Jobs/Import/ImportAppJob.php src/Services/Import/GeohubImportService.php tests/Feature/Import/ImportAppJobTilesTest.php
git add src/Jobs/Import/ImportAppJob.php src/Services/Import/GeohubImportService.php tests/Feature/Import/ImportAppJobTilesTest.php
git commit -m "fix(oc:8488): populate app_tile pivot from the double-encoded Geohub tiles column"
```

---

## Task 7: `UpdateAppConfigJob` diventa unico (causa 2)

`implements ShouldQueue` e nulla più: `uniqueId()` è **codice morto**, perché Laravel lo consulta solo con `ShouldBeUnique`. Cinque altri job del package lo fanno correttamente. Senza, il dispatch del Task 9 si somma a quelli di `TileObserver`, `LayerObserver`, `FeatureCollection*`: N ricalcoli concorrenti che scrivono la stessa chiave S3 senza lock.

**Files:**
- Modify: `src/Jobs/UpdateAppConfigJob.php`
- Test: `tests/Unit/Jobs/UpdateAppConfigJobUniquenessTest.php` (nuovo)

**Interfaces:**
- Consumes: niente
- Produces: `UpdateAppConfigJob implements ShouldQueue, ShouldBeUnique`

- [ ] **Step 1: Scrivere il test che falla**

```php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;

it('is declared unique, so that uniqueId() is actually honoured', function () {
    expect(new UpdateAppConfigJob(1))->toBeInstanceOf(ShouldBeUnique::class);
});

it('scopes uniqueness per app', function () {
    expect((new UpdateAppConfigJob(1))->uniqueId())->toBe('update-app-config-1')
        ->and((new UpdateAppConfigJob(2))->uniqueId())->toBe('update-app-config-2');
});
```

Confermare il pattern su un job già corretto del package:

```bash
grep -n "ShouldBeUnique\|uniqueId\|uniqueFor" src/Jobs/BuildAppPoisGeojsonJob.php
```

- [ ] **Step 2: Eseguire il test e verificare che falla**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Unit/Jobs/UpdateAppConfigJobUniquenessTest.php
```

Expected: il primo FAIL, il secondo PASS.

- [ ] **Step 3: Implementare**

```php
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * ShouldBeUnique necessario perché uniqueId() sia effettivo: Laravel lo consulta solo
 * con questa interfaccia. Senza, il dispatch dal finally() del batch layer si sommava a
 * quelli di TileObserver/LayerObserver/FeatureCollection*, N ricalcoli concorrenti che
 * scrivono la stessa chiave S3 senza lock. Vedi oc:8488.
 */
class UpdateAppConfigJob implements ShouldBeUnique, ShouldQueue
{
    // ...

    public function uniqueFor(): int
    {
        return 600;
    }
```

- [ ] **Step 4: Eseguire il test e verificare che passa**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Unit/Jobs/UpdateAppConfigJobUniquenessTest.php
```

Expected: PASS, 2 test.

- [ ] **Step 5: Commit** *(istruzione per l'utente — non eseguire)*

```bash
vendor/bin/pint src/Jobs/UpdateAppConfigJob.php tests/Unit/Jobs/UpdateAppConfigJobUniquenessTest.php
git add src/Jobs/UpdateAppConfigJob.php tests/Unit/Jobs/UpdateAppConfigJobUniquenessTest.php
git commit -m "fix(oc:8488): make UpdateAppConfigJob actually unique per app"
```

---

## Task 8: Remap HOME idempotente, senza finestra temporale (causa 2)

`UpdateAppConfigHomeLayerIdsJob` attende `isQueueEmpty()` con 5 tentativi × 2 min = 10 minuti contro un import di ~30. E `firstWhere('properties.geohub_id', $layerId)` con `$layerId` già locale può trovare un layer diverso.

**Files:**
- Modify: `src/Jobs/UpdateAppConfigHomeLayerIdsJob.php`
- Test: `tests/Feature/Jobs/UpdateAppConfigHomeLayerIdsJobTest.php` (nuovo)

**Interfaces:**
- Consumes: niente
- Produces: `UpdateAppConfigHomeLayerIdsJob::handle(): void` — non rilascia più il job, non consulta la coda, non rimappa un id già locale

- [ ] **Step 1: Scrivere il test che falla**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\UpdateAppConfigHomeLayerIdsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

uses(DatabaseTransactions::class);

it('remaps Geohub layer ids to local ids preserving order', function () {
    $app = App::factory()->createQuietly();
    $a = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);
    $b = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 137]]);

    setConfigHome($app, [133, 137]);
    (new UpdateAppConfigHomeLayerIdsJob($app->id))->handle();

    expect(homeLayerIds($app))->toBe([$a->id, $b->id]);
});

it('does not remap an id that is already a local layer of this app', function () {
    $app = App::factory()->createQuietly();
    $local = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 999]]);

    setConfigHome($app, [$local->id]);
    (new UpdateAppConfigHomeLayerIdsJob($app->id))->handle();

    expect(homeLayerIds($app))->toBe([$local->id]);
});

it('is idempotent when run twice', function () {
    $app = App::factory()->createQuietly();
    $a = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);

    setConfigHome($app, [133]);
    (new UpdateAppConfigHomeLayerIdsJob($app->id))->handle();
    (new UpdateAppConfigHomeLayerIdsJob($app->id))->handle();

    expect(homeLayerIds($app))->toBe([$a->id]);
});

it('leaves an unresolvable id untouched instead of guessing', function () {
    $app = App::factory()->createQuietly();
    setConfigHome($app, [424242]);
    (new UpdateAppConfigHomeLayerIdsJob($app->id))->handle();

    expect(homeLayerIds($app))->toBe([424242]);
});

it('does not consult the queue nor release itself', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Jobs/UpdateAppConfigHomeLayerIdsJob.php');

    expect($source)->not->toContain('isQueueEmpty')
        ->and($source)->not->toContain('release(')
        ->and($source)->not->toContain('maxAttempts');
});

function setConfigHome(App $app, array $layerIds): void
{
    $home = array_map(
        static fn (int $id) => ['box_type' => 'layer', 'layer' => $id, 'title' => ['it' => 'x']],
        $layerIds
    );

    DB::table('apps')->where('id', $app->id)->update(['config_home' => json_encode(['HOME' => $home])]);
}

function homeLayerIds(App $app): array
{
    $raw = DB::table('apps')->where('id', $app->id)->value('config_home');

    return array_column(json_decode($raw, true)['HOME'], 'layer');
}
```

Verificare che esista una factory per `Layer`:

```bash
ls database/factories/ | grep -i layer
```

- [ ] **Step 2: Eseguire il test e verificare che falla**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Jobs/UpdateAppConfigHomeLayerIdsJobTest.php
```

Expected: FAIL sul test "does not consult the queue".

- [ ] **Step 3: Riscrivere `handle()`**

```php
public function handle(): void
{
    $app = App::find($this->appId);

    if (! $app) {
        Log::warning("App non trovata per app id {$this->appId}");
        return;
    }

    $configHome = $app->getRawOriginal('config_home');

    if (empty($configHome)) {
        return;
    }

    if (is_string($configHome)) {
        $configHome = json_decode($configHome, true);
    } elseif (is_object($configHome) && method_exists($configHome, 'toArray')) {
        $configHome = $configHome->toArray();
    }

    if (! is_array($configHome) || ! isset($configHome['HOME'])) {
        return;
    }

    $allLayers = $app->layers()->get()->concat($app->associatedLayers()->get());
    $localIds = $allLayers->pluck('id')->all();

    $homeElements = $configHome['HOME'] ?? [];
    $updated = false;

    foreach ($homeElements as $index => $element) {
        if (($element['box_type'] ?? null) !== 'layer') {
            continue;
        }

        $layerId = $element['layer'] ?? null;

        if (is_null($layerId)) {
            continue;
        }

        // Idempotenza: un valore già id locale di questa app non va rimappato.
        // Limite noto: id Geohub e id locali condividono lo spazio di interi, quindi un
        // id Geohub che sia ANCHE un id locale valido è indistinguibile da un valore già
        // rimappato. Oggi non collide (id locali 1-9, geohub_id 131-137) ma gli id locali
        // crescono. Servirebbe un marker persistito. Vedi overview → Rischi.
        if (in_array((int) $layerId, $localIds, true)) {
            continue;
        }

        $layer = $allLayers->firstWhere('properties.geohub_id', $layerId);

        if (! $layer) {
            continue;
        }

        $homeElements[$index]['layer'] = $layer->id;
        $updated = true;
    }

    if (! $updated) {
        return;
    }

    $configHome['HOME'] = array_values($homeElements);

    DB::table('apps')->where('id', $app->id)->update(['config_home' => json_encode($configHome)]);

    Log::info("Config home: id layer rimappati per App ID {$app->id}");
}
```

Rimuovere `$maxAttempts` e gli import non più usati (`GeohubImportService`, `Redis`).

- [ ] **Step 4: Eseguire il test e verificare che passa**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Jobs/UpdateAppConfigHomeLayerIdsJobTest.php
```

Expected: PASS, 5 test.

- [ ] **Step 5: Commit** *(istruzione per l'utente — non eseguire)*

```bash
vendor/bin/pint src/Jobs/UpdateAppConfigHomeLayerIdsJob.php tests/Feature/Jobs/UpdateAppConfigHomeLayerIdsJobTest.php
git add src/Jobs/UpdateAppConfigHomeLayerIdsJob.php tests/Feature/Jobs/UpdateAppConfigHomeLayerIdsJobTest.php
git commit -m "fix(oc:8488): make HOME layer id remap idempotent and drop the fixed wait window"
```

---

## Task 9: Nova non può più distruggere un id layer non risolto (causa 2)

Le `options()` della Select coprono i soli layer **diretti** (`Layer::where('app_id', ...)`), mentre il job risolve su `layers()->concat(associatedLayers())`. Un valore fuori dalle options non viene preservato: il browser posta la prima opzione, e `ConfigHomeResolver::buildLayerElement()` riscrive anche il `title` — corruzione indistinguibile da una configurazione voluta.

**Files:**
- Modify: `src/Nova/App.php` (`layer_layout()`)
- Modify: `src/Nova/Flexible/Resolvers/ConfigHomeResolver.php` (`buildLayerElement()`)
- Test: `tests/Feature/Nova/ConfigHomeLayerSelectGuardTest.php` (nuovo)

**Interfaces:**
- Consumes: `App::layers()`, `App::associatedLayers()`
- Produces: `ConfigHomeResolver::buildLayerElement()` preserva un `layer` non risolvibile invece di riassegnarlo

- [ ] **Step 1: Scrivere il test che falla**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

uses(DatabaseTransactions::class);

it('preserves a layer id that is not among the options instead of reassigning it', function () {
    $app = App::factory()->createQuietly();
    Layer::factory()->createQuietly(['app_id' => $app->id]);

    // 133 è un id Geohub residuo: non è tra le options
    $element = buildLayerElementForTest($app, ['layer' => 133, 'title' => ['it' => 'Toscana']]);

    expect($element['layer'])->toBe(133)
        ->and($element['title'])->toBe(['it' => 'Toscana']);
});

it('still resolves the title from the layer when the id is valid', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);

    $element = buildLayerElementForTest($app, ['layer' => $layer->id]);

    expect($element['layer'])->toBe($layer->id)
        ->and($element['title'])->not->toBeEmpty();
});
```

I helper vanno scritti dopo aver letto le firme reali:

```bash
grep -n "function layer_layout" -A 30 src/Nova/App.php
grep -n "function buildLayerElement" -A 25 src/Nova/Flexible/Resolvers/ConfigHomeResolver.php
```

- [ ] **Step 2: Eseguire il test e verificare che falla**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Nova/ConfigHomeLayerSelectGuardTest.php
```

Expected: FAIL sul primo test — l'id 133 viene perso.

- [ ] **Step 3: Estendere le `options()` della Select**

```php
Select::make('Layer', 'layer')
    ->options(function () {
        $app = $this->model();

        // Allineato a UpdateAppConfigHomeLayerIdsJob, che risolve su layers +
        // associatedLayers: se le options coprissero solo i layer diretti, un remap
        // corretto potrebbe produrre un id valido ma non offerto, e il guard lo
        // renderebbe non editabile in silenzio.
        $layers = $app->layers()->get()->concat($app->associatedLayers()->get())->unique('id');

        return $layers->map(function ($layer) {
            $title = $layer->getStringName();
            if (is_array($title)) {
                $title = $title['it'] ?? $title['en'] ?? ('Layer #'.$layer->id);
            } elseif (is_null($title)) {
                $title = 'Layer #'.$layer->id;
            }

            return ['id' => $layer->id, 'title' => $title];
        })->sortBy('title')->pluck('title', 'id')->all();
    })
```

- [ ] **Step 4: Aggiungere il guard nel resolver**

```php
private function buildLayerElement(Layout $layout): array
{
    $element = ['box_type' => 'layer'];

    foreach ($layout->getAttributes() as $key => $val) {
        if ($key === 'layer' && $val) {
            $element[$key] = (int) $val;
        } elseif (! is_null($val) && $val !== '') {
            $element[$key] = $val;
        }
    }

    if (! isset($element['layer'])) {
        return $element;
    }

    $layer = Layer::find($element['layer']);

    if (! $layer) {
        // Id non risolvibile: PRESERVARLO invece di riassegnarlo. Le options() della
        // Select contengono solo id locali: un id Geohub residuo in config_home (finestra
        // tra import e remap) non ha corrispondenza, e riscrivere anche il title
        // renderebbe la corruzione indistinguibile da una configurazione voluta. oc:8488.
        return $element;
    }

    $element['title'] = $layer->getStringName() ?: 'Layer #'.$layer->id;

    return $element;
}
```

Se il test mostra che il browser sostituisce comunque l'id con uno valido (cioè il guard lato server non basta perché la corruzione avviene già lato client), serve un `readonly` sul campo quando il valore non è tra le options: aggiungerlo allora, e registrare la deviazione in `notes.md`.

- [ ] **Step 5: Eseguire il test e verificare che passa**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Nova/ConfigHomeLayerSelectGuardTest.php
```

Expected: PASS, 2 test.

- [ ] **Step 6: Commit** *(istruzione per l'utente — non eseguire)*

```bash
vendor/bin/pint src/Nova/App.php src/Nova/Flexible/Resolvers/ConfigHomeResolver.php tests/Feature/Nova/ConfigHomeLayerSelectGuardTest.php
git add src/Nova/App.php src/Nova/Flexible/Resolvers/ConfigHomeResolver.php tests/Feature/Nova/ConfigHomeLayerSelectGuardTest.php
git commit -m "fix(oc:8488): stop Nova from silently reassigning unresolved HOME layer ids"
```

---

## Task 10: Agganciare remap e scrittura config al completamento del solo batch layer (causa 2)

**Nessun batch padre.** Il fix precedente a questa revisione del piano aggregava tutte le entità in un unico batch — non serve. La scrittura del config dipende solo dai dati già sulla riga `apps` (theme, tiles, OPTIONS: tutti corretti dai Task 1-6, indipendentemente da ec_poi/ec_track/ugc/taxonomy) e dai layer per il remap HOME. Aggancia il lavoro **al solo batch dell'entità `layer`**.

Oggi il codice dispatcha il remap subito dopo il batch, senza aspettarlo:

```php
$batch = Bus::batch($jobs)->name("app-dependencies-{$entityModelKey}-import-batch")->onQueue(...);
$batch->dispatch();
if ($entityModelKey === 'layer') {
    dispatch((new UpdateAppConfigHomeLayerIdsJob($appId))->onQueue(...));
}
```

**Files:**
- Modify: `src/Jobs/Import/ImportAppJob.php` (`queueEntityImport()`, `processDependencies()`)
- Test: `tests/Feature/Import/ImportAppJobFinalizeTest.php` (nuovo)

**Interfaces:**
- Consumes: `UpdateAppConfigHomeLayerIdsJob` (Task 8), `UpdateAppConfigJob` (Task 7)
- Produces: `ImportAppJob::finalizeAppImport(int $appId, ?Batch $batch): void` (public, per essere testabile e utilizzabile come callback di `finally()`)

- [ ] **Step 1: Scrivere il test che falla**

Data la difficoltà di testare `finally()` in modo affidabile con `Bus::fake()`, la logica di completamento va estratta in un metodo pubblico testabile direttamente, e `finally()` lo invoca:

```php
<?php

declare(strict_types=1);

use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

uses(DatabaseTransactions::class);

it('remaps HOME and writes the config when there is no batch (--skip-dependencies path)', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);
    DB_setConfigHome($app, [133]);

    $job = new ImportAppJob('app', 999, []);
    ImportAppJob::finalizeAppImport($app->id, null);

    expect(DB_homeLayerIds($app))->toBe([$layer->id]);
});

it('remaps HOME and writes the config when the layer batch completed without failures', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);
    DB_setConfigHome($app, [133]);

    $job = new ImportAppJob('app', 999, []);
    ImportAppJob::finalizeAppImport($app->id, fakeBatch(hasFailures: false, cancelled: false));

    expect(DB_homeLayerIds($app))->toBe([$layer->id]);
});

it('still remaps HOME but skips the config write when the layer batch has failures, and logs a warning', function () {
    // Fix post-review (Finding 2, vedi notes.md "Fix post-review finale"): il batch con
    // failures NON blocca più il remap HOME, solo la scrittura del config — un Layer con
    // geohub_id=133 esiste quindi il remap trova un match reale.
    Log::spy();

    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);
    DB_setConfigHome($app, [133]);

    $job = new ImportAppJob('app', 999, []);
    ImportAppJob::finalizeAppImport($app->id, fakeBatch(hasFailures: true, cancelled: false));

    expect(DB_homeLayerIds($app))->toBe([$layer->id]); // rimappato, non più invariato
    Log::shouldHaveReceived('warning')->once();
});

it('still remaps HOME but skips the config write when the layer batch was cancelled', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);
    DB_setConfigHome($app, [133]);

    $job = new ImportAppJob('app', 999, []);
    ImportAppJob::finalizeAppImport($app->id, fakeBatch(hasFailures: false, cancelled: true));

    expect(DB_homeLayerIds($app))->toBe([$layer->id]);
});

function fakeBatch(bool $hasFailures, bool $cancelled): Batch
{
    return Mockery::mock(Batch::class, [
        'hasFailures' => $hasFailures,
        'cancelled' => $cancelled,
        'failedJobs' => $hasFailures ? 3 : 0,
    ]);
}

function DB_setConfigHome(App $app, array $layerIds): void
{
    $home = array_map(
        static fn (int $id) => ['box_type' => 'layer', 'layer' => $id, 'title' => ['it' => 'x']],
        $layerIds
    );
    \Illuminate\Support\Facades\DB::table('apps')->where('id', $app->id)->update(['config_home' => json_encode(['HOME' => $home])]);
}

function DB_homeLayerIds(App $app): array
{
    $raw = \Illuminate\Support\Facades\DB::table('apps')->where('id', $app->id)->value('config_home');

    return array_column(json_decode($raw, true)['HOME'], 'layer');
}
```

Verificare che `Illuminate\Bus\Batch` sia mockabile così nella versione Laravel installata; se `Mockery::mock(Batch::class, [...])` non permette di mockare i tre metodi come proprietà/metodi misti, sostituire con un mock esplicito:

```bash
grep -n "class Batch" vendor/laravel/framework/src/Illuminate/Bus/Batch.php | head -1
grep -n "public function hasFailures\|public function cancelled\|public \$failedJobs" vendor/laravel/framework/src/Illuminate/Bus/Batch.php
```

- [ ] **Step 2: Eseguire il test e verificare che falla**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Import/ImportAppJobFinalizeTest.php
```

Expected: FAIL — `finalizeAppImport()` non esiste.

- [ ] **Step 3: Implementare `finalizeAppImport()`**

```php
use Illuminate\Bus\Batch;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;

/**
 * Punto di completamento del pezzo di import da cui dipende la HOME e il config.
 *
 * NOTA POST-REVIEW (Finding 2, vedi notes.md "Fix post-review finale"): la stesura sotto
 * era la prima versione, che gatava ENTRAMBI i passi (remap + scrittura config) sulla
 * stessa condizione — contraddiceva il commento al punto di chiamata (poco più sotto in
 * questo stesso file) che già descriveva un "remap parziale, non un danno" su failure.
 * La versione shippata rimappa SEMPRE la HOME (un remap parziale non è un danno, solo
 * incompleto — comunque meglio di id GeoHub mai rimappati) e gate solo la scrittura del
 * config sull'integrità del batch. `static`, non un metodo d'istanza: vedi Finding 1
 * (bug critico di serializzazione, separato da questo finding).
 *
 * $batch è null sul percorso senza dipendenze (--skip-dependencies, o layer non tra le
 * allowed_dependencies): in quel caso le entità arrivano dal DB, non da un batch appena
 * dispatchato, quindi si procede sempre (remap + config).
 */
public static function finalizeAppImport(int $appId, ?Batch $batch): void
{
    UpdateAppConfigHomeLayerIdsJob::dispatchSync($appId);

    if ($batch && ($batch->hasFailures() || $batch->cancelled())) {
        Log::channel('wm-package-failed-jobs')->warning(
            'Import layer incompleto: HOME rimappata, config non riscritto',
            [
                'app_id' => $appId,
                'failed_jobs' => $batch->failedJobs,
                'cancelled' => $batch->cancelled(),
                'hint' => 'GET /api/app/webmapp/'.$appId.'/base-config.json per forzare la riscrittura',
            ]
        );

        return;
    }

    (new UpdateAppConfigJob($appId))->handle();
}
```

`dispatchSync()`/chiamata diretta invece di `dispatch()` in coda: dentro un `finally()` che già gira su un worker, non serve un ulteriore giro di coda per due operazioni brevi (remap + scrittura file). Verificare che `UpdateAppConfigHomeLayerIdsJob` supporti `dispatchSync()` (usa il trait `Dispatchable` di Laravel, quindi sì) e che `UpdateAppConfigJob::handle()` sia chiamabile direttamente (è `public function handle()`, sì).

- [ ] **Step 4: Agganciare `finalizeAppImport()` al batch layer e al percorso senza dipendenze**

In `queueEntityImport()`, quando l'entità è `layer`:

```php
$batch = Bus::batch($jobs)
    ->name("app-dependencies-{$entityModelKey}-import-batch")
    ->onQueue(config('wm-geohub-import.queue.queue', 'geohub-import'));

if ($entityModelKey === 'layer') {
    $batch->allowFailures()->finally(
        static fn (Batch $batch) => \Wm\WmPackage\Jobs\Import\ImportAppJob::finalizeAppImport($appId, $batch)
    );
}

$batch->dispatch();
```

**NOTA POST-REVIEW (Finding 1, critico):** la prima stesura sopra usava `fn (Batch $batch) => $this->finalizeAppImport($appId, $batch)` — un closure non-static che cattura implicitamente `$this` (l'istanza `ImportAppJob`, che porta dietro `GeohubImportService` con una `Connection` PDO e un `Logger`, entrambi non serializzabili). Il closure passato a `finally()` viene serializzato da `BatchRepository::store()` quando `$batch->dispatch()` gira: sul percorso reale (layer tra le dipendenze e almeno un id da importare — cioè ogni import GeoHub normale) questo faceva fallire `serialize()` con `Exception: Serialization of 'Pdo\Pgsql' is not allowed`, e l'intero `ImportAppJob` falliva — **nessun batch layer veniva mai dispatchato**. Bug trovato in review, non durante l'esecuzione originale di questo task (i test con `Bus::fake()` non attraversano mai `BatchRepository::store()`, quindi non lo intercettavano). Fix: closure `static`, che chiama il metodo (ora anch'esso `static`, vedi sopra) per nome di classe qualificato. Vedi `notes.md` → "Fix post-review finale" per il dettaglio e il test di regressione aggiunto.

`allowFailures()`: se un layer fallisce, gli altri completano e `finally()` scatta comunque — il remap HOME gira sempre (un remap parziale non è un danno, solo incompleto), solo la scrittura del config viene saltata se il batch non è integro (Finding 2, vedi sopra).

**NOTA POST-REVIEW (Finding 4/5/6, non pianificate in questo task — emerse tutte dalla review finale whole-branch, vedi `notes.md` → "Re-review scoped della fix wave" e "Risoluzione dei 2 Important residui").** Questa stesura del task copriva solo il batch `layer`. `config_section_map()` legge anche dati alimentati da altri batch indipendenti (`ec_poi`/`ec_media`/`ec_track`/`taxonomy_activity`/`taxonomy_poi_types`, tutti in `ImportAppJob::CONFIG_DEPENDENT_BATCHES`), senza garanzia di completamento relativa al batch layer — un config scritto al completamento dei soli layer poteva restare temporaneamente privo di quelle sezioni, senza che nulla lo correggesse più tardi (`persistQuietly()` silenzia gli observer per tutto l'import). Anche questi 5 batch usano `allowFailures()->finally()` — non più "solo su questo batch" — per accodare (in coda, non sincrono) un refresh best-effort di `UpdateAppConfigJob`, con lo stesso vincolo di serializzazione (closure `static`, nessun `$this` catturato) e **gated** su `ImportAppJob::layerBatchIsPublishReady()`: senza questo gate, il refresh scriveva incondizionatamente, ignorando lo stato del batch layer e annullando il gate di `finalizeAppImport()` sopra in quasi ogni import normale. Dettaglio completo (meccanismo del sentinel in cache, test) in `notes.md`.

E, per il caso in cui `layer` non è tra le `allowed_dependencies` (incluso `--skip-dependencies`), in `processDependencies()`, dopo il blocco che gestisce le dipendenze:

```php
if (! in_array('layer', $allowedDependencies, true)) {
    // Nessun batch layer dispatchato in questo import: se ci sono già layer sul DB
    // (es. da un import precedente), rimappa e scrivi comunque, sincrono.
    self::finalizeAppImport($model->id, null);
}
```

Verificare che questo branch non duplichi la chiamata quando `layer` **è** tra le dipendenze ma non produce job (nessun id da importare): in quel caso `queueEntityImport()` va comunque a chiamare `finalizeAppImport(null)` se non dispatcha un batch. Aggiungere:

```php
// dentro queueEntityImport(), per l'entità 'layer', se non ci sono job da importare
if ($entityModelKey === 'layer' && $jobs === []) {
    self::finalizeAppImport($appId, null);
}
```

- [ ] **Step 5: Eseguire i test e verificare che passano**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Import/ImportAppJobFinalizeTest.php
```

Expected: PASS, 4 test.

- [ ] **Step 6: Verificare che nessun altro job dipenda dal proprio batch**

```bash
grep -rn '\$this->batch()' src/Jobs/
```

Expected: nessun risultato.

- [ ] **Step 7: Commit** *(istruzione per l'utente — non eseguire)*

```bash
vendor/bin/pint src/Jobs/Import/ImportAppJob.php tests/Feature/Import/ImportAppJobFinalizeTest.php
git add src/Jobs/Import/ImportAppJob.php tests/Feature/Import/ImportAppJobFinalizeTest.php
git commit -m "fix(oc:8488): remap HOME and write app config when the layer batch completes"
```

---

## Task 11: `AppController::config` risponde object e non muore su storage vuoto (Parte 4)

Due difetti: `getAppConfigJson()` ritorna `?string` e `response()->json()` la ri-encoda (`jq type` → `string`); e `?? $app->BuildConfJson($app->id)` chiama un metodo **inesistente** → 500 su storage vuoto.

**Non causano nessuna divergenza dal config di GeoHub** — restano in questo ticket per decisione esplicita del dev, perché sono lo strumento che ha prodotto una diagnosi errata dentro questo stesso ticket (causa 2).

Lo storage-first **resta**: il frontend legge il file da S3/CDN, non questa rotta.

**Files:**
- Modify: `src/Http/Controllers/Api/AppController.php` (`config()`)
- Test: `tests/Feature/Api/AppConfigEndpointTest.php` (nuovo)

**Interfaces:**
- Consumes: `StorageService::getAppConfigJson()`, `AppConfigService::writeAppConfigOnAws()`
- Produces: `AppController::config(App $app): JsonResponse` — object, con fallback che ricalcola una volta e scrive

- [ ] **Step 0: Censire i consumer prima di cambiare la response**

```bash
for d in ~/Sites/Webmapp/wm-webapp ~/Sites/Webmapp/webmapp-app ~/Sites/Webmapp/wm-core; do
  echo "--- $d"
  grep -rn "api/app/[a-z]*/[^/]*/config" "$d/src" 2>/dev/null | grep -v node_modules | head -5
done
grep -rln "config.json" ~/Sites/Webmapp/"Wordpress plugin" 2>/dev/null | head -5
```

`wm-core` e `webmapp-app` non usano questa rotta (leggono `${awsApi}/${appId}/config.json`). Se emerge un consumer che fa doppio parse su `wm-webapp` o WordPress, **fermarsi** e riportarlo all'utente prima di procedere. Registrare l'esito in `notes.md`.

- [ ] **Step 1: Scrivere il test che falla**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;

uses(DatabaseTransactions::class);

it('responds with a JSON object, not a JSON string', function () {
    $app = App::factory()->createQuietly(['api' => 'webmapp']);
    (new \Wm\WmPackage\Services\Models\App\AppConfigService($app))->writeAppConfigOnAws();

    $response = $this->getJson("/api/app/webmapp/{$app->id}/config.json");

    $response->assertOk();
    expect($response->json())->toBeArray()
        ->and($response->json('APP.name'))->not->toBeNull();
});

it('recomputes and stores the config once when storage is empty, instead of throwing 500', function () {
    $app = App::factory()->createQuietly(['api' => 'webmapp']);

    $response = $this->getJson("/api/app/webmapp/{$app->id}/config.json");

    $response->assertOk();
    expect($response->json())->toBeArray();
});

it('does not serve a 200 with a null body when the stored file is corrupted', function () {
    $app = App::factory()->createQuietly(['api' => 'webmapp']);
    \Wm\WmPackage\Services\StorageService::make()->storeAppConfig($app->id, '{"APP": tronc');

    $response = $this->getJson("/api/app/webmapp/{$app->id}/config.json");

    expect($response->json())->not->toBeNull();
});
```

Verificare come i test del package configurano i dischi:

```bash
grep -rn "Storage::fake\|storeAppConfig" tests/ | head -5
```

- [ ] **Step 2: Eseguire il test e verificare che falla**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Api/AppConfigEndpointTest.php
```

Expected: il primo FAIL (`toBeArray()` su una stringa), il secondo FAIL con `BadMethodCallException`.

- [ ] **Step 3: Riscrivere `config()`**

```php
/**
 * Serve il config.json già scritto su storage.
 *
 * Storage-first deliberato: il frontend legge il file direttamente da S3/CDN, non
 * questa rotta, quindi ricalcolare a ogni richiesta non porterebbe alcun beneficio.
 *
 * Il fallback sostituisce il ramo morto `?? $app->BuildConfJson($app->id)`, metodo
 * inesistente: 500 su storage vuoto, mai osservato perché MinIO locale sopravvive ai
 * ripristini del DB. Ricalcola UNA volta e scrive.
 */
public function config(App $app)
{
    $raw = StorageService::make()->getAppConfigJson($app->id);

    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return response()->json($decoded);
        }

        Log::warning('Config su storage non decodificabile, ricalcolo', ['app_id' => $app->id]);
    }

    return response()->json((new AppConfigService($app))->writeAppConfigOnAws());
}
```

Aggiungere `use Illuminate\Support\Facades\Log;` e `use Wm\WmPackage\Services\Models\App\AppConfigService;` se assenti. **Non toccare `baseConfig()`**.

- [ ] **Step 4: Eseguire il test e verificare che passa**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub \
  vendor/bin/pest tests/Feature/Api/AppConfigEndpointTest.php
```

Expected: PASS, 3 test.

- [ ] **Step 5: Verificare che `BuildConfJson` non sia più referenziato**

```bash
grep -rn "BuildConfJson" src/ app/ 2>/dev/null
```

Expected: nessun risultato.

- [ ] **Step 6: Commit** *(istruzione per l'utente — non eseguire)*

```bash
vendor/bin/pint src/Http/Controllers/Api/AppController.php tests/Feature/Api/AppConfigEndpointTest.php
git add src/Http/Controllers/Api/AppController.php tests/Feature/Api/AppConfigEndpointTest.php
git commit -m "fix(oc:8488): serve app config as a JSON object and replace the dead fallback branch"
```

---

## Task 12: PHPStan, documentazione, e chiusura

**Files:**
- Modify: `CLAUDE.md`
- Create: `docs/features/8488-config-app-allineamento-geohub-post-import/notes.md`

- [ ] **Step 1: Eseguire PHPStan**

```bash
docker exec -w /var/www/html/maphub/wm-package php-maphub vendor/bin/phpstan analyse
```

Expected: nessun errore nuovo sui file toccati.

- [ ] **Step 2: Rieseguire tutti i test dei task, uno per file**

```bash
cd /Users/rubensgarofalo/Sites/Webmapp/Laravel/maphub
for f in \
  tests/Feature/Import/ImportAppJobPropertiesMergeTest.php \
  tests/Feature/Support/ImportedAppPropertiesTest.php \
  tests/Feature/Import/ImportAppJobWritesPropertiesTest.php \
  tests/Feature/AppConfigServiceImportedPropertiesTest.php \
  tests/Feature/Nova/AppImportedPropertiesFieldsTest.php \
  tests/Feature/Import/ImportAppJobTilesTest.php \
  tests/Unit/Jobs/UpdateAppConfigJobUniquenessTest.php \
  tests/Feature/Jobs/UpdateAppConfigHomeLayerIdsJobTest.php \
  tests/Feature/Nova/ConfigHomeLayerSelectGuardTest.php \
  tests/Feature/Import/ImportAppJobFinalizeTest.php \
  tests/Feature/Api/AppConfigEndpointTest.php \
  tests/Feature/AppConfigServiceThemeTest.php \
  tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php ; do
  echo "=== $f"
  docker exec -w /var/www/html/maphub/wm-package php-maphub vendor/bin/pest "$f" || echo "FAIL: $f"
done
```

Gli ultimi due sono di oc:8367 e non devono regredire.

- [ ] **Step 3: Compilare `notes.md`**

Sezioni: Deviazioni dal piano, Bug trovati, Decisioni, Follow-up. Registrare almeno:
- l'esito del censimento consumer del Task 11 Step 0
- se il guard del Task 9 ha richiesto anche un `readonly` sul campo
- che `persistQuietly()` è stato escluso dallo scope dopo verifica sui 35 job falliti reali (0 dentro `saveQuietly()`), e che resta un difetto noto non corretto in questo ciclo

- [ ] **Step 4: Aggiornare `CLAUDE.md`**

Decisioni che un futuro Claude deve conoscere:
- `properties` è la sorgente per la config importata, **non** colonne: nessuna migration
- `ImportedAppProperties` è sorgente unica, e i campi Nova sono **generati**
- criterio di omissione `! is_null()`
- i default dello stub `create_apps_table` coincidono coi valori GeoHub di app 28: mai usare app 28 come prova di un fix sull'import
- `merge-base --is-ancestor` inganna dopo un merge di integrazione: verificare per contenuto
- il fix della causa 2 (remap + config write) è agganciato al **solo batch layer**, non a un batch padre — deliberato, non una semplificazione provvisoria
- `persistQuietly()` **non è stato toccato**: verificato che non causa nessuna divergenza osservabile, nonostante il difetto teorico sia reale

- [ ] **Step 5: Commit** *(istruzione per l'utente — non eseguire)*

```bash
git add CLAUDE.md docs/features/8488-config-app-allineamento-geohub-post-import/
git commit -m "docs(oc:8488): record notes and architectural decisions"
```

---

## Self-Review

**Copertura della spec.** Criterio `! is_null()` → Task 4; THEME → Task 3; tiles → Task 6; 37 campi/36 siti → Task 3-4; guard elbrus preservato con default → Task 4 Step 5; mappa unica e campi generati → Task 2, 5; HOME (idempotenza, options, guard) → Task 8-9; scrittura del config agganciata al solo batch layer, `allowFailures()`, `finally()` condizionato → Task 10; `ShouldBeUnique` → Task 7; merge `properties` → Task 1; endpoint → Task 11; i18n → Task 5 Step 4; censimento consumer → Task 11 Step 0.

**Rispetto al design precedente, cosa è stato tolto.** Nessun batch padre che aggrega tutte le entità (Task 10 si aggancia solo al batch `layer`). Nessun fix a `persistQuietly()` (verificato: 0 dei 35 job falliti reali sono falliti dentro `saveQuietly()`). Nessun requisito di scrittura "solo se l'intero import è integro" — solo il batch layer deve essere integro, perché è l'unico da cui il fix di questa causa dipende.

**Coerenza dei tipi.** `mergeProperties(?Model, array): array` (Task 1) usato dal Task 3. `ImportedAppProperties::{keys, geohubColumns, type, novaKeys}` (Task 2) usati da Task 3, 4, 5. `prop`/`setProp` (Task 4) usati nei Step 4-6 dello stesso task. `parseGeohubTiles`/`syncTiles` (Task 6) usati dal Task 10 Step 4. `finalizeAppImport(int, ?Batch): void` (Task 10) è **public**, non protected, perché deve essere chiamabile sia come callback di `finally()` sia direttamente dai test e dal percorso senza dipendenze.

**Punti dove il piano chiede di verificare prima di scrivere**: firma del costruttore di `BaseImportJob` (Task 1), unicità di `sku` (non più necessaria, rimossa col Task persistQuietly), identificatore di `getIdentifier` (Task 1), nome della proprietà del service (Task 3), appiattimento dei Tab Nova (Task 5), factory di `Layer` (Task 8), firme di `layer_layout`/`buildLayerElement` (Task 9), mockabilità di `Illuminate\Bus\Batch` (Task 10), dischi di storage nei test (Task 11).

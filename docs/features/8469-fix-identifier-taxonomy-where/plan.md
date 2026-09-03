> Ticket: oc:8469

# Fix identifier TaxonomyWhere — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sbloccare l'import di `TaxonomyWhere` da Nova, oggi interrotto da
`SQLSTATE[42703]: column "identifier" does not exist`, dotando `taxonomy_wheres`
di un `identifier` derivato dalla sorgente del dato invece che dal nome.

**Architecture:** La derivazione dell'identifier diventa un metodo pubblico
sovrascrivibile su `Taxonomy` (default: slug del nome, comportamento odierno
invariato per tutte le altre taxonomy). `TaxonomyObserver` smette di conoscere la
regola e si limita a invocarla. `TaxonomyWhere` la sovrascrive derivando da
`properties['source']` + id della sorgente — valori stabili e univoci per
costruzione — e valorizza `source` con il nome della piattaforma per i record
creati a mano.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL + PostGIS, Nova 5,
Orchestra Testbench + Pest (suite del package).

**Spec:** `docs/features/8469-fix-identifier-taxonomy-where/overview.md`

## Global Constraints

- **Repo:** tutte le modifiche in `wm-package`, branch base **`develop`**.
  Nessuna modifica al repo `forestas` a parte il bump del submodule (Task 6).
- **Commit convention:** `fix(oc:8469): <descrizione>`.
- **⚠️ Nessun commit automatico.** Gli step "Commit" di questo piano sono
  istruzioni testuali per il developer. Non eseguire `git add`, `git commit`,
  `git push` o creare branch senza conferma esplicita, nemmeno a fine task.
- **Regola di derivazione (autoritativa, da `overview.md`):**
  ```
  import      → "{source}-{id_sorgente}"        osmfeatures-r276369 · osm2cai-142
  legacy      → "{source}-{slug(name)}"         geohub-conf-32-area-f-nord
  manuale     → "{source}-{slug(name)}"         forestas-area-nuova
                 (+ contatore progressivo sulle omonimie: -2, -3, ...)
                 (source = Str::slug(config('app.name')), scritto alla creazione)
  ```
- **Il guard `if (empty($taxonomy->identifier))` di `TaxonomyObserver` non va
  rimosso.** Rimuoverlo farebbe ricalcolare l'identifier di `TaxonomyActivity` e
  `TaxonomyPoiType` a ogni rename, rompendo i riferimenti persistiti nella config
  App (`src/Nova/App.php:923-941`).
- **`identifier` NON va aggiunto a `$fillable` di `TaxonomyWhere`:** lo scrive
  l'observer, non il mass-assignment.
- **Backfill in SQL puro, mai via Eloquent.** `TaxonomyWhere` estende `Polygon`:
  risalvare i modelli farebbe transitare la geometria PostGIS attraverso l'ORM,
  mentre nel package viene scritta solo con `DB::statement` + `ST_GeomFromGeoJSON`.
- **Lingua di default del repo:** `en`. Ogni stringa utente nuova passa da `__()`
  con chiavi in `resources/lang/en.json` **e** `resources/lang/it.json`.
- **Isolamento DB dei test (obbligatorio prima di lanciare la suite):**
  `phpunit.xml.dist` del package usa `DB_DATABASE=wm_package`, separato dal DB
  `forestas`. Verificare questo valore prima di eseguire i test. Se il DB di test
  non è isolato, non lanciare i test e chiedere all'utente.

## File Structure

| File | Responsabilità | Azione |
|---|---|---|
| `src/Models/Abstracts/Taxonomy.php` | Ospita la derivazione di default dell'identifier come metodo pubblico sovrascrivibile | Modificare |
| `src/Observers/TaxonomyObserver.php` | Invoca la derivazione del modello; slug + check unicità con confronto strict | Modificare |
| `src/Models/TaxonomyWhere.php` | Override della derivazione (source + id sorgente); default `source`; suffisso id post-insert | Modificare |
| `database/migrations/zz_2026_09_03_000001_add_identifier_to_taxonomy_wheres_table.php.stub` | Colonna + backfill SQL + indice unique | Creare |
| `src/Nova/Actions/ImportTaxonomyWhere.php` | Skip + conteggio dei record in collisione, senza interrompere l'import | Modificare |
| `resources/lang/en.json`, `resources/lang/it.json` | Stringa utente del contatore collisioni | Modificare |
| `tests/Unit/Observers/TaxonomyObserverIdentifierTest.php` | Comportamento observer: delega, slug, strict compare, guard | Creare |
| `tests/Feature/TaxonomyWhereIdentifierTest.php` | Derivazione per sorgente, record manuale, unicità | Creare |

---

### Task 1: Derivazione sovrascrivibile + fix del confronto loose

Sposta la regola di derivazione dall'observer al modello e corregge due difetti
verificati dell'observer: `'' != null` è `false` (quindi un identifier vuoto salta
il check di unicità e finirebbe in una colonna unique), e uno slug che si riduce a
stringa vuota veniva scritto come `''` invece che lasciato `null`.

**Files:**
- Modify: `src/Models/Abstracts/Taxonomy.php`
- Modify: `src/Observers/TaxonomyObserver.php:17-78`
- Test: `tests/Unit/Observers/TaxonomyObserverIdentifierTest.php`

**Interfaces:**
- Produces: `Taxonomy::generateIdentifier(): ?string` — metodo pubblico,
  sovrascritto da `TaxonomyWhere` nel Task 3. Ritorna `null` quando non è
  derivabile alcun identifier.
- Consumes: niente da task precedenti.

- [ ] **Step 1: Scrivere il test che fallisce**

Creare `tests/Unit/Observers/TaxonomyObserverIdentifierTest.php`:

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Models\TaxonomyActivity;

it('derives the identifier from the name by default', function () {
    $activity = TaxonomyActivity::create(['name' => ['it' => 'Escursionismo']]);

    expect($activity->identifier)->toBe('escursionismo');
});

it('leaves the identifier null when the name produces an empty slug', function () {
    $activity = TaxonomyActivity::create(['name' => ['it' => '올리아스트라']]);

    expect($activity->identifier)->toBeNull();
});

it('does not skip the uniqueness check for a name with an empty slug', function () {
    TaxonomyActivity::create(['name' => ['it' => '올리아스트라']]);
    TaxonomyActivity::create(['name' => ['it' => '세컨드']]);

    expect(TaxonomyActivity::whereNull('identifier')->count())->toBe(2);
});

it('keeps an explicitly provided identifier instead of deriving one', function () {
    $activity = TaxonomyActivity::create([
        'name' => ['it' => 'Escursionismo'],
        'identifier' => 'custom-id',
    ]);

    expect($activity->identifier)->toBe('custom-id');
});

it('does not recompute the identifier when the name changes', function () {
    $activity = TaxonomyActivity::create(['name' => ['it' => 'Escursionismo']]);

    $activity->update(['name' => ['it' => 'Trekking']]);

    expect($activity->fresh()->identifier)->toBe('escursionismo');
});
```

- [ ] **Step 2: Lanciare il test e verificare che fallisca**

Prima verificare l'isolamento del DB:

```bash
grep -n 'DB_DATABASE' wm-package/phpunit.xml.dist
```

Atteso: `wm_package` (diverso da `forestas`). Se non lo è, fermarsi e chiedere.

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Unit/Observers/TaxonomyObserverIdentifierTest.php"
```

Atteso: FAIL sui casi con slug vuoto — l'identifier risulta `''` invece di `null`,
perché `if ($taxonomy->identifier != null)` è `false` con stringa vuota.

- [ ] **Step 3: Spostare la derivazione di default sul modello**

In `src/Models/Abstracts/Taxonomy.php`, aggiungere `use Illuminate\Support\Str;`
in testa e questo metodo pubblico (il corpo è la logica oggi `private static` in
`TaxonomyObserver::generateIdentifierFromName()`):

```php
    /**
     * Genera l'identifier del modello. Il default usa la prima traduzione
     * disponibile del nome (preferenza: locale corrente, poi 'it', poi 'en').
     * I modelli figli possono sovrascriverlo con una regola propria.
     */
    public function generateIdentifier(): ?string
    {
        if (! method_exists($this, 'getTranslations')) {
            return null;
        }

        $translations = $this->getTranslations('name');

        foreach ([app()->getLocale(), 'it', 'en'] as $locale) {
            if (! empty($translations[$locale])) {
                return $translations[$locale];
            }
        }

        foreach ($translations as $value) {
            if (! empty($value)) {
                return $value;
            }
        }

        return null;
    }
```

- [ ] **Step 4: Far delegare l'observer al modello e correggere il confronto**

In `src/Observers/TaxonomyObserver.php` sostituire i metodi `creating()` e
`updating()` e rimuovere `generateIdentifierFromName()` (ora vive su `Taxonomy`):

```php
    public function creating(Model $taxonomy)
    {
        $this->assignIdentifier($taxonomy);

        if ($taxonomy->identifier === null) {
            return;
        }

        $existing = $taxonomy::where('identifier', $taxonomy->identifier)->first();
        if ($existing !== null) {
            self::validationError("The inserted 'identifier' field already exists.");
        }
    }

    public function updating(Model $taxonomy)
    {
        $this->assignIdentifier($taxonomy);
    }

    /**
     * Deriva (se assente) e normalizza l'identifier. Uno slug che si riduce a
     * stringa vuota diventa null: la colonna e' nullable e PostgreSQL ammette
     * piu' NULL su un indice unique.
     */
    private function assignIdentifier(Model $taxonomy): void
    {
        if (empty($taxonomy->identifier)) {
            $taxonomy->identifier = method_exists($taxonomy, 'generateIdentifier')
                ? $taxonomy->generateIdentifier()
                : null;
        }

        if ($taxonomy->identifier === null) {
            return;
        }

        $slug = Str::slug((string) $taxonomy->identifier, '-');
        $taxonomy->identifier = $slug !== '' ? $slug : null;
    }
```

- [ ] **Step 5: Lanciare il test e verificare che passi**

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Unit/Observers/TaxonomyObserverIdentifierTest.php"
```

Atteso: PASS su tutti e cinque i casi.

- [ ] **Step 6: Verificare che le altre taxonomy non abbiano regressioni**

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest --filter=Taxonomy"
```

Atteso: PASS. Nessun test esistente deve rompersi: il comportamento di default è
invariato tranne che per il caso "slug vuoto", che prima produceva `''`.

- [ ] **Step 7: Commit** *(istruzione per il developer — non eseguire autonomamente)*

```bash
git add src/Models/Abstracts/Taxonomy.php src/Observers/TaxonomyObserver.php tests/Unit/Observers/TaxonomyObserverIdentifierTest.php
git commit -m "fix(oc:8469): make taxonomy identifier derivation overridable by the model"
```

---

### Task 2: Migration — colonna identifier, backfill, indice unique

**Files:**
- Create: `database/migrations/zz_2026_09_03_000001_add_identifier_to_taxonomy_wheres_table.php.stub`
- Test: coperto dalla suite del package, che pubblica ed esegue gli stub in
  `TestCase::defineDatabaseMigrations()`

**Interfaces:**
- Produces: colonna `taxonomy_wheres.identifier` (`text`, nullable, unique).
  I Task 3 e 4 la presuppongono esistente.
- Consumes: niente.

- [ ] **Step 1: Scrivere lo stub della migration**

Il nome del file segue la convenzione degli altri ALTER del package
(`zz_YYYY_MM_DD_NNNNNN_...`). Il backfill è in SQL puro: nessun modello Eloquent
viene istanziato, quindi la colonna `geometry` PostGIS non viene mai riscritta.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('taxonomy_wheres')) {
            return;
        }

        if (! Schema::hasColumn('taxonomy_wheres', 'identifier')) {
            Schema::table('taxonomy_wheres', function (Blueprint $table) {
                $table->text('identifier')->nullable();
            });
        }

        // Backfill in SQL puro: mai via Eloquent, per non far transitare la
        // geometria PostGIS attraverso l'ORM.
        //
        // Regola (vedi overview.md):
        //   osmfeatures_id presente -> "{source}-{osmfeatures_id}"
        //   osm2cai_id presente     -> "{source}-{osm2cai_id}"
        //   altrimenti              -> "{source}-{slug(name)}"
        DB::statement(<<<'SQL'
            UPDATE taxonomy_wheres
            SET identifier = NULLIF(
                btrim(
                    regexp_replace(
                        lower(
                            COALESCE(properties->>'source', '')
                            || '-'
                            || COALESCE(
                                properties->>'osmfeatures_id',
                                properties->>'osm2cai_id',
                                CASE
                                    WHEN name IS NULL OR btrim(name) = '' THEN ''
                                    WHEN left(btrim(name), 1) = '{' THEN COALESCE(
                                        name::jsonb->>'it',
                                        name::jsonb->>'en',
                                        ''
                                    )
                                    ELSE name
                                END
                            )
                        ),
                        '[^a-z0-9]+', '-', 'g'
                    ),
                    '-'
                ),
                ''
            )
            WHERE identifier IS NULL
        SQL);

        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS taxonomy_wheres_identifier_unique
            ON taxonomy_wheres (identifier)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS taxonomy_wheres_identifier_unique');

        if (Schema::hasColumn('taxonomy_wheres', 'identifier')) {
            Schema::table('taxonomy_wheres', function (Blueprint $table) {
                $table->dropColumn('identifier');
            });
        }
    }
};
```

- [ ] **Step 2: Pubblicare ed eseguire la migration in forestas**

```bash
docker exec -it php-forestas php artisan vendor:publish --tag=wm-package-migrations
docker exec -it php-forestas php artisan migrate
```

Atteso: la migration viene applicata senza errori.

- [ ] **Step 3: Verificare colonna, backfill e unicità sui 15 record reali**

```bash
docker exec -i postgres-forestas psql -U forestas -d forestas -c \
  "select id, identifier, properties->>'source' src from taxonomy_wheres order by id;"
```

Atteso, sui record oggi presenti:
- id 1-7 (`geohub_conf_32`) → `geohub-conf-32-area-f-nord` e simili
- id 8-15 (`osmfeatures`) → `osmfeatures-r19622159`, `osmfeatures-r276369`, …
- nessun `identifier` `NULL`, nessun duplicato

```bash
docker exec -i postgres-forestas psql -U forestas -d forestas -c \
  "select identifier, count(*) from taxonomy_wheres group by identifier having count(*) > 1;"
```

Atteso: 0 righe.

- [ ] **Step 4: Verificare il rollback**

```bash
docker exec -it php-forestas php artisan migrate:rollback --step=1
docker exec -it php-forestas php artisan migrate
```

Atteso: rollback e riapplicazione puliti, backfill di nuovo corretto.

- [ ] **Step 5: Commit** *(istruzione per il developer)*

```bash
git add database/migrations/zz_2026_09_03_000001_add_identifier_to_taxonomy_wheres_table.php.stub
git commit -m "fix(oc:8469): add identifier column to taxonomy_wheres with source-based backfill"
```

---

### Task 3: Derivazione specifica di TaxonomyWhere

**Files:**
- Modify: `src/Models/TaxonomyWhere.php`
- Test: `tests/Feature/TaxonomyWhereIdentifierTest.php`

**Interfaces:**
- Consumes: `Taxonomy::generateIdentifier(): ?string` (Task 1), colonna
  `identifier` (Task 2).
- Produces: `TaxonomyWhere::generateIdentifier(): ?string` con la regola
  source-based; `properties['source']` sempre valorizzato dopo la creazione.

- [ ] **Step 1: Scrivere il test che fallisce**

Creare `tests/Feature/TaxonomyWhereIdentifierTest.php`:

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Models\TaxonomyWhere;

it('derives the identifier from source and osmfeatures id', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => 'Cagliari'],
        'properties' => [
            'source' => 'osmfeatures',
            'osmfeatures_id' => 'R276369',
            'admin_level' => 6,
        ],
    ]);

    expect($where->identifier)->toBe('osmfeatures-r276369');
});

it('ignores the name when a source id is available', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => '올리아스트라'],
        'properties' => [
            'source' => 'osmfeatures',
            'osmfeatures_id' => 'R19621461',
        ],
    ]);

    expect($where->identifier)->toBe('osmfeatures-r19621461');
});

it('derives the identifier from source and osm2cai id', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => 'Settore Gennargentu'],
        'properties' => ['source' => 'osm2cai', 'osm2cai_id' => 142],
    ]);

    expect($where->identifier)->toBe('osm2cai-142');
});

it('falls back to the slugged name for legacy records without a source id', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => 'Area F (Nord)'],
        'properties' => ['source' => 'geohub_conf_32'],
    ]);

    expect($where->identifier)->toBe('geohub-conf-32-area-f-nord');
});

it('stamps the platform name as source on manually created records', function () {
    config()->set('app.name', 'Forestas');

    $where = TaxonomyWhere::create(['name' => ['it' => 'Area Nuova']]);

    expect($where->fresh()->properties['source'])->toBe('forestas');
});

it('appends the model id to the identifier of manually created records', function () {
    config()->set('app.name', 'Forestas');

    $where = TaxonomyWhere::create(['name' => ['it' => 'Area Nuova']]);

    expect($where->fresh()->identifier)->toBe('forestas-area-nuova-'.$where->id);
});

it('keeps manually created records unique even with the same name', function () {
    config()->set('app.name', 'Forestas');

    $first = TaxonomyWhere::create(['name' => ['it' => 'Area Nuova']]);
    $second = TaxonomyWhere::create(['name' => ['it' => 'Area Nuova']]);

    expect($second->fresh()->identifier)->not->toBe($first->fresh()->identifier);
});

it('does not change the identifier when the source name is corrected', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => '올리아스트라'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R19621461'],
    ]);

    $where->update(['name' => ['it' => 'Ogliastra']]);

    expect($where->fresh()->identifier)->toBe('osmfeatures-r19621461');
});
```

- [ ] **Step 2: Lanciare il test e verificare che fallisca**

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TaxonomyWhereIdentifierTest.php"
```

Atteso: FAIL — la derivazione di default usa ancora il solo nome, e `source` non
viene valorizzato sui record manuali.

- [ ] **Step 3: Implementare override e hook su TaxonomyWhere**

In `src/Models/TaxonomyWhere.php` aggiungere `use Illuminate\Support\Str;` in
testa e i seguenti membri. **Non** aggiungere `identifier` a `$fillable`.

```php
    /**
     * True quando il record e' stato creato senza sorgente esterna e la
     * sorgente e' stata timbrata con il nome della piattaforma. Transiente:
     * serve solo a distinguere, dopo l'insert, un record creato a mano da un
     * record legacy che una sorgente ce l'aveva gia'.
     */
    protected bool $sourceStamped = false;

    protected static function booted(): void
    {
        // Un record creato a mano da Nova non ha sorgente esterna: la sorgente
        // e' la piattaforma stessa. Cosi' ogni record ha sempre un source ed e'
        // filtrabile da TaxonomyWhereSourceFilter.
        static::creating(function (self $taxonomyWhere) {
            $properties = $taxonomyWhere->properties ?? [];

            if (empty($properties['source'])) {
                $properties['source'] = Str::slug((string) config('app.name'), '-');
                $taxonomyWhere->properties = $properties;
                $taxonomyWhere->sourceStamped = true;
            }
        });

        // Solo i record creati a mano ricevono il suffisso: non hanno ne' id di
        // sorgente ne' altra garanzia di unicita'. L'id del modello non esiste
        // ancora in creating(), quindi si completa dopo l'insert.
        // I record legacy (source presente, nessun id di sorgente) restano
        // "{source}-{slug(name)}" come da regola.
        static::created(function (self $taxonomyWhere) {
            if (! $taxonomyWhere->sourceStamped || $taxonomyWhere->identifier === null) {
                return;
            }

            $taxonomyWhere->identifier = $taxonomyWhere->identifier.'-'.$taxonomyWhere->id;
            $taxonomyWhere->saveQuietly();
        });
    }

    /**
     * Identifier derivato dalla sorgente del dato, mai dal nome quando un id di
     * sorgente e' disponibile: i nomi da OSMFeatures sono instabili (assenti o
     * in alfabeto non latino), gli id no.
     */
    public function generateIdentifier(): ?string
    {
        $source = $this->properties['source'] ?? null;
        $sourceId = $this->getSourceId();

        if ($sourceId !== null) {
            return trim(((string) $source).'-'.$sourceId, '-');
        }

        return trim(((string) $source).'-'.((string) parent::generateIdentifier()), '-');
    }

    /**
     * Id del record presso la sorgente esterna, se presente.
     */
    public function getSourceId(): ?string
    {
        $sourceId = $this->properties['osmfeatures_id']
            ?? $this->properties['osm2cai_id']
            ?? null;

        return $sourceId !== null ? (string) $sourceId : null;
    }
```

- [ ] **Step 4: Lanciare il test e verificare che passi**

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TaxonomyWhereIdentifierTest.php"
```

Atteso: PASS su tutti e otto i casi.

- [ ] **Step 5: Verificare che `saving()` continui ad applicarsi**

`AbstractObserver::saving()` sincronizza `properties['name']` dalle traduzioni:
va confermato che l'hook `created()` con `saveQuietly()` non lo abbia disattivato
per il primo salvataggio.

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Unit/Observers"
```

Aggiungere in `tests/Feature/TaxonomyWhereIdentifierTest.php`:

```php
it('still syncs the translated name into properties', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => 'Cagliari', 'en' => 'Cagliari'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R276369'],
    ]);

    expect($where->fresh()->properties['name'])->toBe(['it' => 'Cagliari', 'en' => 'Cagliari']);
});
```

Atteso: PASS.

- [ ] **Step 6: Commit** *(istruzione per il developer)*

```bash
git add src/Models/TaxonomyWhere.php tests/Feature/TaxonomyWhereIdentifierTest.php
git commit -m "fix(oc:8469): derive TaxonomyWhere identifier from its data source"
```

---

### Task 4: Import resiliente alle collisioni

Con la regola source-based la collisione è impossibile sui record da import;
resta possibile su record legacy o manuali. Oggi `TaxonomyObserver` risponde con
`ValidationException`, che **abortisce l'intero import**: va degradata a skip del
singolo record, conteggiato e riportato all'utente.

**Files:**
- Modify: `src/Nova/Actions/ImportTaxonomyWhere.php:88-145` (ramo osmfeatures) e
  `:186-235` (ramo osm2cai)
- Modify: `resources/lang/en.json`, `resources/lang/it.json`
- Test: `tests/Feature/TaxonomyWhereIdentifierTest.php` (stesso file, caso finale)

**Interfaces:**
- Consumes: `TaxonomyWhere::generateIdentifier()` (Task 3), colonna unique (Task 2).
- Produces: nessuna nuova firma pubblica.

- [ ] **Step 1: Scrivere il test che fallisce**

Aggiungere a `tests/Feature/TaxonomyWhereIdentifierTest.php`:

```php
it('rejects a duplicate identifier with a validation error', function () {
    TaxonomyWhere::create([
        'name' => ['it' => 'Cagliari'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R276369'],
    ]);

    expect(fn () => TaxonomyWhere::create([
        'name' => ['it' => 'Altro nome'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R276369'],
    ]))->toThrow(Illuminate\Validation\ValidationException::class);
});
```

- [ ] **Step 2: Lanciare il test e verificare che passi già**

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TaxonomyWhereIdentifierTest.php"
```

Atteso: PASS — è il comportamento attuale dell'observer, e serve come base per il
punto successivo: l'action deve *catturare* questa eccezione, non evitarla.

- [ ] **Step 3: Catturare la collisione nel ramo osmfeatures**

In `src/Nova/Actions/ImportTaxonomyWhere.php`, aggiungere in testa:

```php
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
```

In `handleOsmfeatures()`, dichiarare il contatore accanto a `$skipped`:

```php
        $count = 0;
        $skipped = 0;
        $skippedCollision = 0;
```

Avvolgere il blocco `if ($existing) { ... } else { ... }` che crea/aggiorna il
record (attualmente subito dopo la costruzione di `$properties`):

```php
            try {
                if ($existing) {
                    $existing->update([
                        'name' => $item['name'],
                        'properties' => array_merge($existing->properties ?? [], $properties),
                    ]);
                    $this->assignTaxonomyUserFromApp($existing, $app);
                    FetchTaxonomyWhereGeometryJob::dispatch($existing->id);
                } else {
                    $taxonomyWhere = TaxonomyWhere::create([
                        'name' => $item['name'],
                        'properties' => $properties,
                    ]);
                    $this->assignTaxonomyUserFromApp($taxonomyWhere, $app);
                    FetchTaxonomyWhereGeometryJob::dispatch($taxonomyWhere->id);
                }
            } catch (ValidationException|QueryException $e) {
                // Identifier gia' presente: si salta il singolo record invece di
                // interrompere l'import.
                $skippedCollision++;

                continue;
            }

            $count++;
```

E prima del `return`, dopo la riga che compone `$msg`:

```php
        if ($skippedCollision > 0) {
            $msg .= ' '.__(':count records skipped: identifier already in use.', [
                'count' => $skippedCollision,
            ]);
        }
```

- [ ] **Step 4: Applicare lo stesso trattamento al ramo osm2cai**

In `handleOsm2cai()`, aggiungere `$skippedCollision = 0;` accanto a `$skipped`,
avvolgere il blocco create/update nello stesso `try/catch` (con
`FetchOsm2caiSectorGeometryJob` al posto di `FetchTaxonomyWhereGeometryJob`) e
appendere la stessa riga al messaggio finale.

- [ ] **Step 5: Aggiungere le traduzioni**

In `resources/lang/en.json`:

```json
":count records skipped: identifier already in use.": ":count records skipped: identifier already in use."
```

In `resources/lang/it.json`:

```json
":count records skipped: identifier already in use.": ":count record saltati: identifier già in uso."
```

- [ ] **Step 6: Verificare la suite completa**

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest"
```

Atteso: PASS, nessuna regressione.

- [ ] **Step 7: Commit** *(istruzione per il developer)*

```bash
git add src/Nova/Actions/ImportTaxonomyWhere.php resources/lang/en.json resources/lang/it.json tests/Feature/TaxonomyWhereIdentifierTest.php
git commit -m "fix(oc:8469): skip colliding records instead of aborting the TaxonomyWhere import"
```

---

### Task 5: Verifica empirica dell'import su tutti i livelli

Il bug originale si manifesta solo dall'interfaccia Nova: la suite non lo
sostituisce. Questa verifica conferma anche l'assenza di collisioni reali ai
livelli bassi (L9/L10), che era il rischio residuo dichiarato nell'overview.

**Files:** nessuno — verifica manuale.

**Interfaces:**
- Consumes: tutti i task precedenti.

- [ ] **Step 1: PHPStan**

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/phpstan analyse"
```

Atteso: nessun errore nuovo sui file toccati.

- [ ] **Step 2: Import da Nova, un livello alla volta**

Su `http://127.0.0.1:8000/nova/resources/taxonomy-wheres`, eseguire
`Import TaxonomyWhere` per ciascuna sorgente della tendina, annotando il messaggio
finale: L4 (Regione), L6 (Provincia), L8 (Comune), L9 (Municipio),
L10 (Quartiere), OSM2CAI.

Atteso: nessun errore SQL; il messaggio riporta creati/aggiornati e, se presenti,
i saltati. **Ogni record saltato per collisione va annotato in `notes.md`** con il
livello in cui è comparso.

- [ ] **Step 3: Verificare gli identifier generati**

```bash
docker exec -i postgres-forestas psql -U forestas -d forestas -c \
  "select properties->>'source' src, count(*), count(distinct identifier) uniq from taxonomy_wheres group by 1;"
```

Atteso: `count` e `uniq` coincidono per ogni sorgente; nessun `identifier` `NULL`
sui record importati.

- [ ] **Step 4: Verificare la sync delle track**

```bash
docker exec -i postgres-forestas psql -U forestas -d forestas -c \
  "select count(*) from ec_tracks where properties->'taxonomy_where' != '{}'::jsonb;"
```

Atteso: valore non nullo e coerente con il numero di track che intersecano le aree
importate — conferma che la denormalizzazione (che usa l'id di sorgente, non
l'identifier) è rimasta intatta.

---

### Task 6: Allineamento consumer e submodule

**Files:**
- Modify: `wm-package` pointer nel repo `forestas`

**Interfaces:**
- Consumes: tutti i task precedenti.

- [ ] **Step 1: Verificare il gate delle migration mancanti**

```bash
docker exec -it php-forestas php artisan wm-package:publish-missing-migrations --dry-run
```

Atteso: dopo il `vendor:publish` del Task 2, la nuova migration non deve più
comparire tra quelle mancanti.

- [ ] **Step 2: Documentare il requisito di rollout**

Nel corpo della PR di `wm-package` indicare esplicitamente che i progetti
consumer, all'aggiornamento, devono eseguire:

```bash
php artisan vendor:publish --tag=wm-package-migrations
php artisan migrate
```

- [ ] **Step 3: Bump del submodule in forestas** *(istruzione per il developer)*

Dopo il merge della PR di `wm-package` su `develop`:

```bash
cd /Users/bongiu/Documents/geobox2/forestas
git add wm-package
git commit -m "fix(oc:8469): bump wm-package with TaxonomyWhere identifier fix"
```

Nota: finché il pointer non viene aggiornato, forestas continua a puntare al
commit precedente e il bug resta presente.

---

## Self-Review

**1. Copertura della spec.** Gli 13 requisiti dell'overview sono mappati:
colonna e stub → Task 2; derivazione sovrascrivibile e default invariato per le
altre taxonomy → Task 1; regola source-based, `source` da `app.name`, suffisso id
sui record manuali → Task 3; guard invariato e confronto strict → Task 1;
`$fillable` non toccato → Task 3 (vincolo esplicito); backfill SQL → Task 2;
skip collisioni e stringa tradotta → Task 4; import funzionante su tutte le
sorgenti → Task 5; gate CI e submodule → Task 6.

**2. Placeholder.** Nessun "TBD"/"gestire gli edge case": ogni step contiene il
codice o il comando effettivo, e i due punti di verifica manuale (Task 5)
riportano il comando e il risultato atteso.

**3. Coerenza dei tipi.** `generateIdentifier(): ?string` è definito in Task 1 e
sovrascritto con la stessa firma in Task 3; `getSourceId(): ?string` è introdotto
in Task 3 e usato solo lì e nell'hook `created()` dello stesso task; il nome della
colonna `identifier` è coerente tra Task 2, 3 e 4.

**Punto di attenzione per l'esecutore:** `getSourceId()` in Task 3 convive con i
metodi già presenti `getOsmfeaturesId()` e `getAdminLevel()` di
`src/Models/TaxonomyWhere.php`. Non sostituirli: sono usati altrove.

**Secondo punto di attenzione:** il suffisso `-{id}` va applicato **solo** ai
record creati a mano, non a quelli legacy. I due casi si somigliano (entrambi
senza id di sorgente) e si distinguono unicamente con il flag transiente
`$sourceStamped`, valorizzato in `creating()` quando la sorgente viene timbrata.
Usare `getSourceId() === null` come condizione darebbe il suffisso anche ai
record legacy, rompendo il quarto test del Task 3.

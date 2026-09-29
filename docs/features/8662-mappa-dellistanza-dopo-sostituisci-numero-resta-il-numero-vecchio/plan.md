> Ticket: oc:8662

# Mappa del catasto: numero del codice in esame, segnavia CAI, ricarica e profilo — piano di implementazione

> **Per chi esegue:** sub-skill richiesta: `superpowers:subagent-driven-development` (consigliata)
> oppure `superpowers:executing-plans`. Gli step usano le checkbox (`- [ ]`).
>
> **In questo progetto i commit sono vietati durante l'esecuzione.** Gli step «Commit» sono
> istruzioni testuali per il dev: non eseguire `git add`, `git commit`, `git push`, né creare
> branch.

**Goal:** la mappa del Catasto Sentieri mostra sempre il numero del codice in esame, disegna i
numeri come segnavia CAI distinti per stato, si ricarica da sola dopo le Action e mostra il
profilo altimetrico della linea giusta.

**Architecture:** il PHP (`TrailRegistryCode::getFeatureCollectionMap()`) marca nel GeoJSON la
feature del codice in esame (`current`, `label`, `codeStatus`, `slopeChart`, `subjectKind`) e lo
stato dei vicini (`codeStatus`); il Field `TrailRegistryMap` espone un meta `mapVersion` che
cambia con il codice mostrato. Il Vue del catasto trasforma le etichette in punti con un'icona
disegnata su canvas (segnavia), aggiunge la versione all'URL del GeoJSON e accende il profilo. Il
componente condiviso `FeatureCollectionMap` impara due cose opzionali: preferire la linea marcata
`slopeChart` e non reinquadrare alla ricarica.

**Tech Stack:** Laravel 12, Nova 5, PostGIS, Pest; Vue 3, OpenLayers 9, chart.js, vitest,
laravel-mix 6 (webpack 5.103.0).

**Spec:** [overview.md](overview.md)

## Global Constraints

- Tutto il codice sta nel submodule `wm-package`; in Forestas cambia solo il puntatore del submodule.
- Documentazione e commenti in italiano; termini tecnici in inglese.
- Le stringhe visibili sono in italiano dentro `__()`: il package non ha file di lingua.
- Le modifiche al componente condiviso `FeatureCollectionMap` sono **additive**: senza le nuove
  property/prop il comportamento è identico a oggi.
- Colori: tracciato istanza `rgba(234, 88, 12, 1)`, sentiero `rgba(22, 163, 74, 1)`, rosso CAI
  `rgba(220, 38, 38, 1)`, grigio liberato `rgba(148, 163, 184, 1)`. PHP (legenda) e JS (segnavia)
  li ripetono e vanno cambiati insieme.
- I `dist/` si ricompilano con `npm ci` dal lockfile (dove non si aggiunge una dipendenza) e
  `npm run prod`; webpack resta `5.103.0`.
- Test PHP: solo dal container, con il DB del package `wm_package` (vedi Task 0).

## Review Focus

1. **Istanza senza vicini nel settore** (prima del settore): il numero dell'istanza deve comparire
   → test JS su `buildLabelFeatures()` con zero vicini (Task 5) e verifica manuale (Task 7).
2. **Due istanze con geometria identica** (istanze 2 e 3 in locale): il numero corrente resta
   sopra e leggibile → layer dedicato senza `declutter` (Task 6), verifica manuale su
   `trail-applications/2` (Task 7).
3. **Istanza rifiutata**: il codice è `released`, il numero va grigio e barrato, non arancione →
   test PHP `codeStatus = released` (Task 1) e test JS `signKind()` (Task 5).
4. **Dopo «Approva»** l'id del codice non cambia: la versione deve cambiare lo stesso → test PHP
   sulla transizione di stato (Task 2).
5. **Mappa delle anomalie**: stesso Field, deve restare senza profilo → test PHP sul meta
   `enableSlopeChart` della Resource anomalie (Task 2).

---

### Task 0: Verifica dell'isolamento dei test

**Files:** nessuno.

- [ ] **Step 1: niente `phpunit.xml` locale nel package**

Run: `ls /Users/bongiu/Documents/geobox2/forestas/wm-package/phpunit.xml`
Expected: `No such file or directory` (vale il `.dist`, con `DB_DATABASE=wm_package`).

- [ ] **Step 2: il `.dist` punta a `wm_package`**

Run: `grep -n 'DB_DATABASE' /Users/bongiu/Documents/geobox2/forestas/wm-package/phpunit.xml.dist`
Expected: `<env name="DB_DATABASE" value="wm_package"/>`

- [ ] **Step 3: il database esiste**

Run: `docker exec postgres-forestas psql -U forestas -lqt | cut -d'|' -f1 | grep -w wm_package`
Expected: `wm_package`. Se uno dei tre controlli fallisce: **fermarsi e chiedere al dev**, non
lanciare test.

Comando dei test PHP usato in tutto il piano:

```bash
docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest <file> --filter='<nome>'
```

---

### Task 1: GeoJSON — codice in esame e stato dei vicini

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-geojson-codice-in-esame-e-stato-dei-vicini)

**Files:**
- Modify: `src/TrailRegistry/Models/Concerns/ComposesTrailRegistryMap.php` (`neighbourCodes()`: `c.status` nella SELECT)
- Modify: `src/TrailRegistry/Models/TrailRegistryCode.php` (`getFeatureCollectionMap()`, `neighbourFeatures()`, nuove costanti e `markSubject()`)
- Modify: `src/TrailRegistry/Models/TrailApplication.php:122-125` (`getFeatureCollectionMap()`)
- Test: `tests/Feature/TrailRegistry/TrailRegistryCodeMapTest.php`, `tests/Feature/TrailRegistry/TrailApplicationMapTest.php`

**Interfaces:**
- Produces:
  - `TrailRegistryCode::MAP_SUBJECT_CODE = 'code'`, `TrailRegistryCode::MAP_SUBJECT_APPLICATION = 'application'`
  - `TrailRegistryCode::getFeatureCollectionMap(string $subject = self::MAP_SUBJECT_CODE): array`
  - property GeoJSON sulla feature del codice in esame: `current: true`, `label: string`
    (es. `'62'`, `'62A'`), `codeStatus: 'reserved'|'assigned'|'released'`, `slopeChart: true`,
    `subjectKind: 'track'|'application'`
  - property GeoJSON sui vicini: `codeStatus: 'reserved'|'assigned'` (in più a quelle esistenti)

- [ ] **Step 1: test che falliscono in `TrailRegistryCodeMapTest.php`** (in fondo al file)

```php
it('i vicini portano lo stato del loro codice', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $inEsame = makeCode(['status' => TrailCodeStatus::Assigned, 'taxonomy_where_id' => $sectorId, 'number' => 62]);
    makeCode(['status' => TrailCodeStatus::Assigned, 'taxonomy_where_id' => $sectorId, 'number' => 63]);
    makeCode(['status' => TrailCodeStatus::Reserved, 'taxonomy_where_id' => $sectorId, 'number' => 64]);

    DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326)', ['MULTILINESTRING Z((1 1 0, 2 2 0))']);

    $stati = collect(TrailRegistryCode::findOrFail($inEsame)->getFeatureCollectionMap()['features'])
        ->filter(fn (array $f) => ($f['properties']['neighbour'] ?? false) === true)
        ->mapWithKeys(fn (array $f) => [$f['properties']['label'] => $f['properties']['codeStatus']])
        ->all();

    expect($stati)->toBe(['63' => 'assigned', '64' => 'reserved']);
});

it('marca il sentiero come codice in esame quando c e, con numero stato e profilo', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $applicationId = DB::table('trail_applications')->insertGetId([
        'user_id' => makeTrailRegistryTestUser(),
        'source' => 'api',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $code = TrailRegistryCode::findOrFail(makeCode([
        'status' => TrailCodeStatus::Assigned,
        'taxonomy_where_id' => $sectorId,
        'trail_application_id' => $applicationId,
        'number' => 62,
        'variant' => 'A',
    ]));
    foreach ([['trail_applications', $applicationId], ['ec_tracks', $code->ec_track_id]] as [$table, $id]) {
        DB::statement("UPDATE {$table} SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?", ['MULTILINESTRING Z((1 1 0, 2 2 0))', $id]);
    }

    $correnti = array_values(array_filter(
        $code->fresh()->getFeatureCollectionMap()['features'],
        fn (array $f) => ($f['properties']['current'] ?? false) === true,
    ));

    expect($correnti)->toHaveCount(1)
        ->and($correnti[0]['properties'])->toMatchArray([
            'label' => '62A',
            'codeStatus' => 'assigned',
            'slopeChart' => true,
            'subjectKind' => 'track',
        ])
        ->and($correnti[0]['properties']['tooltip'])->toContain('Sentiero');
});

it('senza sentiero marca la traccia dell istanza come codice in esame', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $code = TrailRegistryCode::findOrFail(makeCode(['status' => TrailCodeStatus::Reserved, 'taxonomy_where_id' => $sectorId, 'number' => 66]));

    $correnti = array_values(array_filter(
        $code->getFeatureCollectionMap()['features'],
        fn (array $f) => ($f['properties']['current'] ?? false) === true,
    ));

    expect($correnti)->toHaveCount(1)
        ->and($correnti[0]['properties'])->toMatchArray([
            'label' => '66',
            'codeStatus' => 'reserved',
            'subjectKind' => 'application',
        ]);
});

it('un codice liberato resta il codice in esame, con lo stato released', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $code = TrailRegistryCode::findOrFail(makeCode(['status' => TrailCodeStatus::Released, 'taxonomy_where_id' => $sectorId, 'number' => 59]));

    // makeCode crea l'istanza solo per i Reserved: qui la geometria va data a mano.
    $applicationId = DB::table('trail_applications')->insertGetId([
        'user_id' => makeTrailRegistryTestUser(), 'source' => 'office', 'status' => 'rejected',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::statement('UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', ['MULTILINESTRING Z((1 1 0, 2 2 0))', $applicationId]);
    $code->update(['trail_application_id' => $applicationId, 'ec_track_id' => null]);

    $corrente = collect($code->fresh()->getFeatureCollectionMap()['features'])
        ->first(fn (array $f) => ($f['properties']['current'] ?? false) === true);

    expect($corrente['properties']['codeStatus'])->toBe('released');
});
```

Nota: se `makeCode()` con `Released` non accetta `ec_track_id => null` o crea già
un'istanza, leggere `tests/Pest.php:69` e adattare solo la preparazione, non le asserzioni.

- [ ] **Step 2: test che falliscono in `TrailApplicationMapTest.php`** (in fondo al file)

```php
it('nella scheda dell istanza il codice in esame e il profilo stanno sul tracciato proposto', function () {
    $application = applicationWithGeometry();
    $codeId = makeCode(['status' => TrailCodeStatus::Assigned, 'trail_application_id' => $application->id, 'number' => 66]);
    $code = TrailRegistryCode::findOrFail($codeId);
    DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', ['MULTILINESTRING Z((1 1 0, 2 2 0))', $code->ec_track_id]);

    $correnti = array_values(array_filter(
        $application->fresh()->getFeatureCollectionMap()['features'],
        fn (array $f) => ($f['properties']['current'] ?? false) === true,
    ));

    expect($correnti)->toHaveCount(1)
        ->and($correnti[0]['properties']['subjectKind'])->toBe('application')
        ->and($correnti[0]['properties']['slopeChart'])->toBeTrue()
        ->and($correnti[0]['properties']['label'])->toBe('66');
});
```

Aggiungere in testa al file: `use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;` e
`use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;`.

- [ ] **Step 3: verificare che falliscano**

Run: `docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryCodeMapTest.php tests/Feature/TrailRegistry/TrailApplicationMapTest.php`
Expected: i 5 test nuovi FAIL (chiave `codeStatus`/`current` assente), gli esistenti PASS.

- [ ] **Step 4: `c.status` fra le colonne dei vicini**

In `ComposesTrailRegistryMap::neighbourCodes()`, nella SELECT, dopo `c.variant,`:

```sql
                c.status,
```

- [ ] **Step 5: stato sui vicini**

In `TrailRegistryCode::neighbourFeatures()`, nell'array `properties`, dopo `'label' => $label,`:

```php
                    // Validato o proposto: il componente Vue ne ricava lo stile del segnavia (oc:8662).
                    'codeStatus' => (string) $row->status,
```

- [ ] **Step 6: costanti, firma e marcatura del codice in esame**

In `TrailRegistryCode`, prima di `getFeatureCollectionMap()`:

```php
    /**
     * Da quale scheda si guarda la mappa (oc:8662). Decide quale linea porta
     * il numero del codice in esame e il profilo altimetrico:
     *
     * - dalla scheda del codice, il sentiero se c'e', altrimenti la traccia
     *   dell'istanza: il codice appartiene al sentiero da quando esiste;
     * - dalla scheda dell'istanza, sempre la traccia proposta: e' quella che
     *   il gestore sta valutando.
     */
    public const MAP_SUBJECT_CODE = 'code';

    public const MAP_SUBJECT_APPLICATION = 'application';
```

Sostituire `getFeatureCollectionMap()` con:

```php
    public function getFeatureCollectionMap(string $subject = self::MAP_SUBJECT_CODE): array
    {
        $novaPath = $this->novaPath();

        $track = $this->trackFeature($novaPath);
        $application = $this->applicationFeature($novaPath);

        if ($subject === self::MAP_SUBJECT_CODE && $track !== null) {
            $track = $this->markSubject($track, 'track');
        } elseif ($application !== null) {
            $application = $this->markSubject($application, 'application');
        }

        $features = array_values(array_filter(array_merge(
            $this->sectorFeatures($novaPath),
            $this->neighbourFeatures($novaPath),
            [$track, $application],
        )));

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * La linea che rappresenta il codice in esame: porta il suo numero, il suo
     * stato e il profilo altimetrico. Una sola per mappa — anche quando
     * sentiero e traccia coincidono — cosi' il numero compare una volta.
     *
     * `current`, `codeStatus`, `slopeChart` e `subjectKind` sono il contratto
     * con il componente Vue del campo e con la legenda (oc:8662).
     *
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>
     */
    protected function markSubject(array $feature, string $kind): array
    {
        $feature['properties'] += [
            'current' => true,
            'label' => $this->label,
            'codeStatus' => $this->status->value,
            'slopeChart' => true,
            'subjectKind' => $kind,
        ];

        return $feature;
    }
```

- [ ] **Step 7: l'istanza chiede la mappa dal proprio punto di vista**

In `TrailApplication::getFeatureCollectionMap()` (`src/TrailRegistry/Models/TrailApplication.php`):

```php
    public function getFeatureCollectionMap(): array
    {
        return $this->mapCode()?->getFeatureCollectionMap(TrailRegistryCode::MAP_SUBJECT_APPLICATION)
            ?? parent::getFeatureCollectionMap();
    }
```

Aggiornare il docblock sopra: «dalla scheda dell'istanza numero e profilo stanno sulla traccia
proposta anche dopo l'approvazione (oc:8662)». Verificare che `TrailRegistryCode` sia già
importato nel file (usato da `mapCode()`); se no, aggiungere lo `use`.

- [ ] **Step 8: test verdi**

Run: stesso comando dello Step 3.
Expected: tutti PASS, compreso `la mappa dell istanza e quella del suo codice` (codice riservato
senza sentiero: i due punti di vista coincidono).

- [ ] **Step 9: Commit (istruzione per il dev)**

```bash
git add src/TrailRegistry/Models tests/Feature/TrailRegistry/TrailRegistryCodeMapTest.php tests/Feature/TrailRegistry/TrailApplicationMapTest.php
git commit -m "fix(oc:8662): numero e stato del codice in esame nel GeoJSON della mappa"
```

---

### Task 2: versione della mappa e profilo per Resource

**Files:**
- Modify: `src/TrailRegistry/Models/TrailRegistryCode.php` (nuovo `mapVersion()`)
- Modify: `src/TrailRegistry/Models/TrailApplication.php` (nuovo `mapVersion()`)
- Modify: `src/TrailRegistry/Nova/Fields/TrailRegistryMap.php` (costruttore, `resolve()`)
- Modify: `src/TrailRegistry/Nova/TrailApplication.php:153`, `src/TrailRegistry/Nova/TrailRegistryCode.php:229`
- Test: create `tests/Feature/TrailRegistry/TrailRegistryMapFieldTest.php`

**Interfaces:**
- Consumes: `TrailRegistryService::replaceNumber()`, `confirm()`, `release()` (esistenti)
- Produces:
  - `TrailRegistryCode::mapVersion(): string` — `"{id}-{status}-{updated_at timestamp}"`
  - `TrailApplication::mapVersion(): string` — `"{versione del mapCode o 'none'}-{status}-{updated_at timestamp}"`
  - meta del Field `mapVersion: string` (solo se il modello ha `mapVersion()`)
  - `TrailRegistryMap` nasce con `enableSlopeChart = false`; le Resource istanza e codice lo accendono

- [ ] **Step 1: test che falliscono**

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Nova\Fields\TrailRegistryMap;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication as TrailApplicationResource;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly as TrailRegistryAnomalyResource;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode as TrailRegistryCodeResource;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
    Bus::fake();
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
});

function mapFieldOf(array $fields): ?TrailRegistryMap
{
    return collect($fields)->first(fn ($f) => $f instanceof TrailRegistryMap);
}

function applicationForMap(): TrailApplication
{
    $application = TrailApplication::factory()->create();
    DB::statement('UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]);

    return $application->fresh();
}

it('la versione della mappa cambia quando si sostituisce il numero', function () {
    $application = applicationForMap();
    $service = app(TrailRegistryService::class);
    $code = $service->reserve($application);
    $prima = $application->fresh()->mapVersion();

    $service->replaceNumber($code->fresh(), 90, '0', null);

    expect($application->fresh()->mapVersion())->not->toBe($prima);
});

it('la versione della mappa cambia quando cambia lo stato del codice, anche a parita di id', function () {
    $application = applicationForMap();
    $code = app(TrailRegistryService::class)->reserve($application);
    $prima = $application->fresh()->mapVersion();

    // Stesso secondo, stesso id: deve bastare lo stato (e' il caso di «Rifiuta»).
    $code->update(['status' => \Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus::Released]);

    expect($application->fresh()->mapVersion())->not->toBe($prima);
});

it('la versione della mappa cambia quando cambia lo stato dell istanza', function () {
    $application = applicationForMap();
    app(TrailRegistryService::class)->reserve($application);
    $prima = $application->fresh()->mapVersion();

    $application->update(['status' => TrailApplicationStatus::Rejected]);

    expect($application->fresh()->mapVersion())->not->toBe($prima);
});

it('il campo espone la versione della mappa come meta', function () {
    $application = applicationForMap();
    app(TrailRegistryService::class)->reserve($application);

    $field = TrailRegistryMap::make('Mappa', 'geometry');
    $field->resolve($application->fresh());

    expect($field->meta['mapVersion'] ?? null)->toBe($application->fresh()->mapVersion());
});

it('il campo nasce senza profilo altimetrico', function () {
    expect(TrailRegistryMap::make('Mappa', 'geometry')->jsonSerialize()['enableSlopeChart'])->toBeFalse();
});

it('istanza e codice accendono il profilo, le anomalie no', function () {
    $application = applicationForMap();
    $code = app(TrailRegistryService::class)->reserve($application);
    $request = NovaRequest::create('/');

    expect(mapFieldOf((new TrailApplicationResource($application->fresh()))->fields($request))->jsonSerialize()['enableSlopeChart'])->toBeTrue()
        ->and(mapFieldOf((new TrailRegistryCodeResource($code->fresh()))->fields($request))->jsonSerialize()['enableSlopeChart'])->toBeTrue()
        ->and(mapFieldOf((new TrailRegistryAnomalyResource(new \Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly))->fields($request))->jsonSerialize()['enableSlopeChart'])->toBeFalse();
});
```

Se una delle tre Resource non si costruisce con un `NovaRequest::create('/')` generico
(`TrailRegistryNovaResourcesTest.php:138` segnala un caso così per l'indice delle istanze),
seguire lo stesso ripiego di quel test: non cambiare l'asserzione.

- [ ] **Step 2: verificare che falliscano**

Run: `docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryMapFieldTest.php`
Expected: FAIL (`mapVersion` non esiste; `enableSlopeChart` vale `true`).

- [ ] **Step 3: `TrailRegistryCode::mapVersion()`**

```php
    /**
     * Cambia ogni volta che cambia cio' che la mappa di questo codice disegna
     * per lui: un codice nuovo (id), una transizione (stato) o un nuovo
     * detentore (updated_at). Il campo la mette nell'URL del GeoJSON, e il
     * componente Vue riscarica la mappa quando l'URL cambia (oc:8662).
     *
     * Lo stato e' esplicito e non affidato a updated_at: due transizioni nello
     * stesso secondo lascerebbero il timestamp identico.
     */
    public function mapVersion(): string
    {
        return sprintf('%d-%s-%d', $this->id, $this->status->value, $this->updated_at?->getTimestamp() ?? 0);
    }
```

- [ ] **Step 4: `TrailApplication::mapVersion()`**

```php
    /**
     * La versione della mappa dell'istanza: quella del codice mostrato, piu'
     * lo stato dell'istanza — «Approva» e «Rifiuta» cambiano l'istanza anche
     * quando il codice non cambia id (oc:8662).
     */
    public function mapVersion(): string
    {
        return sprintf(
            '%s-%s-%d',
            $this->mapCode()?->mapVersion() ?? 'none',
            $this->status?->value ?? '',
            $this->updated_at?->getTimestamp() ?? 0,
        );
    }
```

Verificare che `status` sia castato a `TrailApplicationStatus` nei `$casts` del modello; se è una
stringa, usare `(string) $this->status`.

- [ ] **Step 5: il Field — profilo spento di default e meta `mapVersion`**

In `TrailRegistryMap::__construct()`, dopo `$this->onlyOnDetail();`:

```php
        // Il profilo si accende Resource per Resource: la scheda delle
        // anomalie usa questo stesso campo e non lo vuole (oc:8662).
        $this->enableSlopeChart(false);
```

Nuovo metodo:

```php
    /**
     * Oltre alla geometria, espone la versione della mappa del modello: il
     * componente la aggiunge all'URL del GeoJSON, cosi' dopo un'Action Nova —
     * che rilegge la risorsa ma non ricrea il componente — l'URL cambia e la
     * mappa si riscarica da sola (oc:8662).
     */
    public function resolve($resource, ?string $attribute = null): void
    {
        parent::resolve($resource, $attribute);

        if (is_object($resource) && method_exists($resource, 'mapVersion')) {
            $this->withMeta(['mapVersion' => (string) $resource->mapVersion()]);
        }
    }
```

- [ ] **Step 6: le due Resource accendono il profilo**

`src/TrailRegistry/Nova/TrailApplication.php:153` e `src/TrailRegistry/Nova/TrailRegistryCode.php:229`:

```php
        $fields[] = TrailRegistryMap::make(__('Mappa'), 'geometry')->enableSlopeChart();
```

- [ ] **Step 7: test verdi**

Run: stesso comando dello Step 2, poi tutta la cartella:
`docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest tests/Feature/TrailRegistry`
Expected: PASS.

- [ ] **Step 8: Commit (istruzione per il dev)**

```bash
git add src/TrailRegistry tests/Feature/TrailRegistry/TrailRegistryMapFieldTest.php
git commit -m "fix(oc:8662): versione della mappa nel campo e profilo altimetrico per Resource"
```

---

### Task 3: legenda dei segnavia e del profilo

**Files:**
- Modify: `src/TrailRegistry/Nova/MapLegendRenderer.php`
- Modify: `src/TrailRegistry/Nova/TrailApplication.php:155-159` (passa il punto di vista)
- Test: `tests/Feature/TrailRegistry/MapLegendRendererTest.php`

**Interfaces:**
- Consumes: property `current`, `codeStatus`, `neighbour`, `subjectKind` (Task 1); `TrailRegistryCode::MAP_SUBJECT_*` (Task 1)
- Produces: `MapLegendRenderer::render(TrailRegistryCodeModel $code, string $subject = TrailRegistryCodeModel::MAP_SUBJECT_CODE): string`

- [ ] **Step 1: test che falliscono** (in fondo a `MapLegendRendererTest.php`)

```php
it('spiega i segnavia presenti sulla mappa', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $code = TrailRegistryCode::findOrFail(makeCode(['status' => TrailCodeStatus::Reserved, 'taxonomy_where_id' => $sectorId, 'number' => 66]));
    makeCode(['status' => TrailCodeStatus::Assigned, 'taxonomy_where_id' => $sectorId, 'number' => 63]);
    DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326)', ['MULTILINESTRING Z((1 1 0, 2 2 0))']);

    $html = MapLegendRenderer::render($code->fresh());

    expect($html)->toContain('Numero di questo codice')
        ->and($html)->toContain('Numero di un sentiero validato')
        ->and($html)->not->toContain('Numero proposto da un\'altra istanza')
        ->and($html)->not->toContain('Numero liberato');
});

it('su un codice liberato spiega il numero barrato', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $code = TrailRegistryCode::findOrFail(makeCode(['status' => TrailCodeStatus::Reserved, 'taxonomy_where_id' => $sectorId, 'number' => 59]));
    $code->update(['status' => TrailCodeStatus::Released]);

    expect(MapLegendRenderer::render($code->fresh()))->toContain('Numero liberato')
        ->and(MapLegendRenderer::render($code->fresh()))->not->toContain('Numero di questo codice');
});

it('dice di quale linea e il profilo altimetrico', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $code = TrailRegistryCode::findOrFail(makeCode(['status' => TrailCodeStatus::Reserved, 'taxonomy_where_id' => $sectorId]));

    expect(MapLegendRenderer::render($code))->toContain('Profilo altimetrico')
        ->and(MapLegendRenderer::render($code))->toContain('traccia dell\'istanza');
});
```

- [ ] **Step 2: verificare che falliscano**

Run: `docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest tests/Feature/TrailRegistry/MapLegendRendererTest.php`
Expected: i 3 nuovi FAIL.

- [ ] **Step 3: implementazione**

In `MapLegendRenderer`:

1. Firma: `public static function render(TrailRegistryCodeModel $code, string $subject = TrailRegistryCodeModel::MAP_SUBJECT_CODE): string`
   e `$features = $code->getFeatureCollectionMap($subject)['features'];`.
2. Dopo il `foreach` delle `ENTRIES`, prima del controllo `$rows === []`:

```php
        foreach (self::signRows($features) as $row) {
            $rows[] = $row;
        }

        $subjectFeature = collect($features)->first(fn (array $f) => ($f['properties']['slopeChart'] ?? false) === true);

        if ($subjectFeature !== null) {
            $rows[] = sprintf(
                '<li style="margin:0 0 6px 0;list-style:none">%s</li>',
                e(($subjectFeature['properties']['subjectKind'] ?? '') === 'track'
                    ? __('Profilo altimetrico sotto la mappa: del sentiero')
                    : __('Profilo altimetrico sotto la mappa: della traccia dell\'istanza')),
            );
        }
```

3. Nuovi membri:

```php
    /**
     * I segnavia, con gli stessi colori del componente Vue
     * (`TrailRegistryMapField/resources/js/trail-sign.mjs`): vanno cambiati
     * insieme (oc:8662). [bande, bordo, barrato, etichetta]
     *
     * @var array<string, array{0: string|null, 1: string, 2: bool, 3: string}>
     */
    protected const SIGNS = [
        'current' => ['rgba(234, 88, 12, 1)', 'rgba(234, 88, 12, 1)', false, 'Numero di questo codice'],
        'released' => ['rgba(148, 163, 184, 1)', 'rgba(148, 163, 184, 1)', true, 'Numero liberato: non appartiene piu\' a questa istanza'],
        'assigned' => ['rgba(220, 38, 38, 1)', 'rgba(220, 38, 38, 1)', false, 'Numero di un sentiero validato'],
        'reserved' => [null, 'rgba(220, 38, 38, 1)', false, 'Numero proposto da un\'altra istanza'],
    ];

    /**
     * Una voce per ogni tipo di segnavia davvero presente sulla mappa.
     *
     * @param  array<int, array<string, mixed>>  $features
     * @return array<int, string>
     */
    protected static function signRows(array $features): array
    {
        $present = [];

        foreach ($features as $f) {
            $p = $f['properties'];

            if (($p['current'] ?? false) === true) {
                $present[($p['codeStatus'] ?? '') === 'released' ? 'released' : 'current'] = true;
            } elseif (($p['neighbour'] ?? false) === true && isset($p['codeStatus'])) {
                $present[$p['codeStatus']] = true;
            }
        }

        $rows = [];

        foreach (self::SIGNS as $key => [$band, $border, $strike, $label]) {
            if (! isset($present[$key])) {
                continue;
            }

            $bandCss = $band ?? '#fff';
            $middle = $strike
                ? sprintf('background:linear-gradient(to bottom right,transparent 44%%,%1$s 44%%,%1$s 56%%,transparent 56%%),#fff', e($border))
                : 'background:#fff';

            $swatch = sprintf(
                '<span style="display:inline-flex;flex-direction:column;width:24px;border:1px solid %s;vertical-align:middle">'
                .'<span style="height:4px;background:%s"></span><span style="height:8px;%s"></span><span style="height:4px;background:%s"></span></span>',
                e($border), e($bandCss), $middle, e($bandCss),
            );

            $rows[] = sprintf(
                '<li style="margin:0 0 6px 0;list-style:none">%s <span style="margin-left:8px">%s</span></li>',
                $swatch,
                e(__($label)),
            );
        }

        return $rows;
    }
```

4. In `src/TrailRegistry/Nova/TrailApplication.php`, nella legenda:

```php
            return $code === null ? '' : MapLegendRenderer::render($code, TrailRegistryCodeModel::MAP_SUBJECT_APPLICATION);
```

(con `use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode as TrailRegistryCodeModel;` se non
c'è già; controllare gli alias esistenti in testa al file).

- [ ] **Step 4: test verdi**

Run: stesso comando dello Step 2. Expected: PASS, compresi i due test esistenti.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add src/TrailRegistry/Nova tests/Feature/TrailRegistry/MapLegendRendererTest.php
git commit -m "fix(oc:8662): legenda dei segnavia e del profilo altimetrico"
```

---

### Task 4: componente condiviso — linea del profilo esplicita e vista mantenuta

**Files:**
- Modify: `src/Nova/Fields/FeatureCollectionMap/resources/js/slope-chart/utils.mjs:134-150`
- Modify: `src/Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue`
- Test: `src/Nova/Fields/FeatureCollectionMap/resources/js/slope-chart/utils.test.mjs`

**Interfaces:**
- Produces:
  - `findExplicitSlopeChartFeature(featureCollection): object|null` (export di `utils.mjs`)
  - `getSlopeChartTrackFromGeojson()` preferisce la feature con `properties.slopeChart === true`
  - prop `preserveViewOnReload: Boolean` (default `false`) di `FeatureCollectionMap`
  - evento `map-ready` con in più `reloaded: boolean` (`true` dal secondo caricamento in poi)

- [ ] **Step 1: test che falliscono** (in fondo a `utils.test.mjs`; aggiungere
  `findExplicitSlopeChartFeature` all'import)

```js
describe('linea del profilo indicata nel GeoJSON (oc:8662)', () => {
    const line = (props, coords = [[0, 0, 1], [0, 0.001, 2]]) => ({
        type: 'Feature',
        properties: props,
        geometry: { type: 'LineString', coordinates: coords },
    });

    it('con piu linee sceglie quella marcata slopeChart', () => {
        const fc = { type: 'FeatureCollection', features: [line({ neighbour: true }), line({ slopeChart: true, id: 'x' }), line({})] };
        expect(getSlopeChartTrackFromGeojson(fc)?.properties?.id).toBe('x');
    });

    it('senza marcatura resta il comportamento di prima: piu linee, niente profilo', () => {
        const fc = { type: 'FeatureCollection', features: [line({}), line({})] };
        expect(getSlopeChartTrackFromGeojson(fc)).toBeNull();
    });

    it('senza marcatura una sola linea da ancora il profilo', () => {
        const fc = { type: 'FeatureCollection', features: [line({ id: 'solo' })] };
        expect(getSlopeChartTrackFromGeojson(fc)?.properties?.id).toBe('solo');
    });

    it('ignora la marcatura su una geometria che non e una linea', () => {
        const fc = { type: 'FeatureCollection', features: [{ type: 'Feature', properties: { slopeChart: true }, geometry: { type: 'Point', coordinates: [0, 0] } }] };
        expect(findExplicitSlopeChartFeature(fc)).toBeNull();
    });

    it('con il profilo spento non restituisce nulla anche se marcato', () => {
        const fc = { type: 'FeatureCollection', features: [line({ slopeChart: true })] };
        expect(getSlopeChartTrackFromGeojson(fc, false)).toBeNull();
    });
});
```

- [ ] **Step 2: verificare che falliscano**

Run: `cd /Users/bongiu/Documents/geobox2/forestas/wm-package/src/Nova/Fields/FeatureCollectionMap && npm test`
Expected: FAIL (`findExplicitSlopeChartFeature` non esportata).

- [ ] **Step 3: `utils.mjs`**

Sopra `getSlopeChartTrackFromGeojson`:

```js
/**
 * La linea che il GeoJSON indica esplicitamente per il profilo
 * (`properties.slopeChart === true`). Serve alle mappe con molte linee, dove
 * la regola «una sola linea» non sceglierebbe nulla (oc:8662).
 */
export function findExplicitSlopeChartFeature(featureCollection) {
    const feats = Array.isArray(featureCollection?.features) ? featureCollection.features : [];

    return feats.find((f) => {
        const t = f?.geometry?.type;
        return f?.properties?.slopeChart === true && (t === 'LineString' || t === 'MultiLineString');
    }) || null;
}
```

In `getSlopeChartTrackFromGeojson`, subito dopo il controllo `typeof featureCollection !== 'object'`:

```js
    const explicit = findExplicitSlopeChartFeature(featureCollection);
    if (explicit) {
        return toLineStringFeatureObject(explicit);
    }
```

- [ ] **Step 4: test verdi**

Run: `npm test` nella stessa cartella. Expected: PASS, compresi i test esistenti.

- [ ] **Step 5: `FeatureCollectionMap.vue` — prop, stato, import**

Import: `import { findExplicitSlopeChartFeature, getSlopeChartTrackFromGeojson, toLineStringFeatureObject } from '../slope-chart/utils.mjs';`

Nelle `props`, dopo `enableSlopeChart`:

```js
        // Alla ricarica dei dati mantiene centro e zoom scelti dall'utente:
        // l'inquadratura sul tracciato si fa solo al primo caricamento (oc:8662).
        preserveViewOnReload: {
            type: Boolean,
            default: false
        }
```

Nel `setup`, accanto a `slopeChartAllowed`:

```js
        // True quando il GeoJSON indica esplicitamente la linea del profilo:
        // allora ne' il passaggio del mouse ne' il clic su altre linee la
        // sostituiscono (oc:8662).
        const slopeChartLocked = ref(false);
        let hasFitted = false;
```

In `setDefaultTrackForChartFromGeojson`, prima di `slopeChartAllowed.value = !!track;`:

```js
            slopeChartLocked.value = props.enableSlopeChart && !!findExplicitSlopeChartFeature(data);
```

- [ ] **Step 6: vista mantenuta alla ricarica**

In `applyGeoJSONData`, sostituire `if (features.length > 0) {` (il blocco dell'inquadratura) con:

```js
            const reloaded = hasFitted;

            if (features.length > 0 && !(props.preserveViewOnReload && reloaded)) {
```

e subito dopo la chiusura di quel blocco `if`:

```js
            if (features.length > 0) {
                hasFitted = true;
            }
```

Nell'`emit('map-ready', …)` aggiungere `reloaded` all'oggetto:

```js
            emit('map-ready', { map: map.value, features, geojson: data, featuresMap: featuresMap.value, reloaded });
```

- [ ] **Step 7: il profilo segue solo la linea indicata**

In `handleFeatureClick`, sostituire la condizione iniziale:

```js
                if (!props.enableSlopeChart || !slopeChartAllowed.value) {
```

con:

```js
                if (slopeChartLocked.value) {
                    // La linea del profilo la decide il GeoJSON: il clic su un'altra
                    // linea apre il suo link ma non cambia il profilo.
                } else if (!props.enableSlopeChart || !slopeChartAllowed.value) {
```

Nel gestore del movimento del mouse (blocco «Sync hover mappa -> slope chart», circa riga 540),
sostituire `if (type === 'LineString' || type === 'MultiLineString') {` con:

```js
                    const isChartLine = !slopeChartLocked.value || feature.get('slopeChart') === true;
                    if ((type === 'LineString' || type === 'MultiLineString') && isChartLine) {
```

- [ ] **Step 8: controllo del watcher del profilo**

Leggere `components/SlopeChart.vue:455-470`: il `watch` su `props.track` deve ridisegnare il
grafico quando la traccia cambia. Se lo fa, nessuna modifica. Se non lo fa, annotarlo in
`notes.md` e fermarsi a chiedere al dev.

- [ ] **Step 9: Commit (istruzione per il dev)**

```bash
git add src/Nova/Fields/FeatureCollectionMap/resources
git commit -m "fix(oc:8662): linea del profilo esplicita e vista mantenuta alla ricarica nella mappa condivisa"
```

---

### Task 5: segnavia CAI — funzioni pure

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-segnavia-cai-funzioni-pure)

**Files:**
- Create: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/resources/js/trail-sign.mjs`
- Create: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/resources/js/trail-sign.test.mjs`
- Modify: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/package.json` (script `test`, devDependency `vitest`)

**Interfaces:**
- Produces:
  - `SIGN_STYLES: Record<'assigned'|'reserved'|'current'|'released', {band: string|null, border: string, text: string, strike: boolean}>`
  - `signKind({ current: boolean, codeStatus: string }): 'assigned'|'reserved'|'current'|'released'`
  - `halfwayCoordinate(lines: number[][][]): number[]|null` — punto a metà della parte più lunga
  - `buildLabelFeatures(features): { neighbours: object[], current: object[] }` — descrittori `{ coordinate, label, kind }`
  - `paintSign(doc, label, kind, ratio): HTMLCanvasElement`

- [ ] **Step 1: vitest nel campo**

```bash
cd /Users/bongiu/Documents/geobox2/forestas/wm-package/src/TrailRegistry/Nova/Fields/TrailRegistryMapField
npm install --save-dev vitest@^3.2.4
```

In `package.json`, in `scripts`: `"test": "vitest run"`. Controllare con `git diff package.json`
che `webpack` sia rimasto `5.103.0`.

- [ ] **Step 2: test che falliscono** (`trail-sign.test.mjs`)

```js
import { describe, expect, it } from 'vitest';
import { buildLabelFeatures, halfwayCoordinate, signKind } from './trail-sign.mjs';

describe('signKind', () => {
    it('il codice in esame e arancione finche e attivo', () => {
        expect(signKind({ current: true, codeStatus: 'reserved' })).toBe('current');
        expect(signKind({ current: true, codeStatus: 'assigned' })).toBe('current');
    });

    it('il codice in esame liberato e grigio barrato', () => {
        expect(signKind({ current: true, codeStatus: 'released' })).toBe('released');
    });

    it('i vicini seguono il loro stato', () => {
        expect(signKind({ current: false, codeStatus: 'assigned' })).toBe('assigned');
        expect(signKind({ current: false, codeStatus: 'reserved' })).toBe('reserved');
    });

    it('un vicino senza stato e trattato come validato', () => {
        expect(signKind({ current: false })).toBe('assigned');
    });
});

describe('halfwayCoordinate', () => {
    it('sta a meta della lunghezza, non al centro dell estensione', () => {
        // Una L: 10 in orizzontale e 10 in verticale; a meta si e all angolo.
        expect(halfwayCoordinate([[[0, 0], [10, 0], [10, 10]]])).toEqual([10, 0]);
    });

    it('di una MultiLineString usa la parte piu lunga', () => {
        expect(halfwayCoordinate([[[0, 0], [1, 0]], [[100, 0], [120, 0]]])).toEqual([110, 0]);
    });

    it('senza coordinate restituisce null', () => {
        expect(halfwayCoordinate([])).toBeNull();
    });
});

describe('buildLabelFeatures', () => {
    const fake = (props, coords) => ({
        get: (k) => props[k],
        getGeometry: () => ({ getType: () => 'LineString', getCoordinates: () => coords }),
    });

    it('produce il numero del codice in esame anche senza vicini', () => {
        const out = buildLabelFeatures([fake({ current: true, label: '66', codeStatus: 'reserved' }, [[0, 0], [2, 0]])]);
        expect(out.neighbours).toEqual([]);
        expect(out.current).toEqual([{ coordinate: [1, 0], label: '66', kind: 'current' }]);
    });

    it('separa vicini e codice in esame', () => {
        const out = buildLabelFeatures([
            fake({ neighbour: true, label: '61', codeStatus: 'reserved' }, [[0, 0], [2, 0]]),
            fake({ current: true, label: '66', codeStatus: 'reserved' }, [[0, 0], [2, 0]]),
            fake({ tooltip: 'Settore' }, [[0, 0], [2, 0]]),
        ]);
        expect(out.neighbours).toEqual([{ coordinate: [1, 0], label: '61', kind: 'reserved' }]);
        expect(out.current).toEqual([{ coordinate: [1, 0], label: '66', kind: 'current' }]);
    });

    it('salta le feature senza etichetta', () => {
        const out = buildLabelFeatures([fake({ neighbour: true, codeStatus: 'assigned' }, [[0, 0], [2, 0]])]);
        expect(out.neighbours).toEqual([]);
    });
});
```

- [ ] **Step 3: verificare che falliscano**

Run: `npm test` nella cartella del campo. Expected: FAIL (modulo inesistente).

- [ ] **Step 4: `trail-sign.mjs`**

```js
/**
 * I segnavia della mappa del catasto (oc:8662): il numero di un sentiero
 * disegnato come la bandierina CAI — banda, fascia bianca con il numero,
 * banda — orizzontale e posato a meta' del tracciato, invece del testo
 * piegato lungo la linea.
 *
 * Qui stanno solo funzioni pure e il disegno su canvas: niente OpenLayers,
 * cosi' si testano senza mappa. I colori sono gli stessi della legenda
 * (`MapLegendRenderer::SIGNS`) e vanno cambiati insieme.
 */

export const SIGN_STYLES = {
    // Sentiero validato: bande rosse piene.
    assigned: { band: 'rgba(220, 38, 38, 1)', border: 'rgba(220, 38, 38, 1)', text: 'rgba(15, 23, 42, 1)', strike: false },
    // Proposto da un'altra istanza: stessa forma, bande vuote.
    reserved: { band: null, border: 'rgba(220, 38, 38, 1)', text: 'rgba(15, 23, 42, 1)', strike: false },
    // Codice in esame: il colore del tracciato dell'istanza.
    current: { band: 'rgba(234, 88, 12, 1)', border: 'rgba(234, 88, 12, 1)', text: 'rgba(15, 23, 42, 1)', strike: false },
    // Codice in esame liberato: non appartiene piu' a nessuno.
    released: { band: 'rgba(148, 163, 184, 1)', border: 'rgba(148, 163, 184, 1)', text: 'rgba(100, 116, 139, 1)', strike: true },
};

export function signKind({ current, codeStatus }) {
    if (current) {
        return codeStatus === 'released' ? 'released' : 'current';
    }

    return codeStatus === 'reserved' ? 'reserved' : 'assigned';
}

function length(line) {
    let total = 0;
    for (let i = 1; i < line.length; i++) {
        total += Math.hypot(line[i][0] - line[i - 1][0], line[i][1] - line[i - 1][1]);
    }
    return total;
}

/**
 * Il punto a meta' della lunghezza della parte piu' lunga. Non il centro
 * dell'estensione: su un sentiero a U quello cade fuori dal sentiero.
 */
export function halfwayCoordinate(lines) {
    const candidates = (lines || []).filter((l) => Array.isArray(l) && l.length > 0);
    if (candidates.length === 0) {
        return null;
    }

    const line = candidates.reduce((a, b) => (length(b) > length(a) ? b : a));
    let remaining = length(line) / 2;

    for (let i = 1; i < line.length; i++) {
        const [x0, y0] = line[i - 1];
        const [x1, y1] = line[i];
        const step = Math.hypot(x1 - x0, y1 - y0);
        if (step >= remaining && step > 0) {
            const t = remaining / step;
            return [x0 + (x1 - x0) * t, y0 + (y1 - y0) * t];
        }
        remaining -= step;
    }

    return [line[0][0], line[0][1]];
}

function linesOf(geometry) {
    const type = geometry?.getType?.();
    if (type === 'LineString') {
        return [geometry.getCoordinates()];
    }
    if (type === 'MultiLineString') {
        return geometry.getCoordinates();
    }
    return [];
}

/**
 * Dalle feature caricate, i descrittori delle etichette: i vicini da una
 * parte, il codice in esame dall'altra — quest'ultimo anche quando i vicini
 * non ci sono, che e' il caso della prima istanza di un settore.
 */
export function buildLabelFeatures(features) {
    const out = { neighbours: [], current: [] };

    for (const f of features || []) {
        const label = f.get('label');
        const isCurrent = f.get('current') === true;
        const isNeighbour = f.get('neighbour') === true;

        if (!label || (!isCurrent && !isNeighbour)) {
            continue;
        }

        const coordinate = halfwayCoordinate(linesOf(f.getGeometry()));
        if (!coordinate) {
            continue;
        }

        const item = { coordinate, label, kind: signKind({ current: isCurrent, codeStatus: f.get('codeStatus') }) };
        (isCurrent ? out.current : out.neighbours).push(item);
    }

    return out;
}

/**
 * Disegna il segnavia su un canvas, alla densita' dello schermo (`ratio`).
 */
export function paintSign(doc, label, kind, ratio = 1) {
    const style = SIGN_STYLES[kind] || SIGN_STYLES.assigned;
    const font = 'bold 12px sans-serif';
    const band = 5;
    const middle = 16;
    const padX = 5;

    const measure = doc.createElement('canvas').getContext('2d');
    measure.font = font;
    const width = Math.ceil(measure.measureText(label).width) + padX * 2;
    const height = band * 2 + middle;

    const canvas = doc.createElement('canvas');
    canvas.width = Math.ceil((width + 2) * ratio);
    canvas.height = Math.ceil((height + 2) * ratio);
    const ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.translate(1, 1);

    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, width, height);

    if (style.band) {
        ctx.fillStyle = style.band;
        ctx.fillRect(0, 0, width, band);
        ctx.fillRect(0, height - band, width, band);
    }

    ctx.strokeStyle = style.border;
    ctx.lineWidth = 1.5;
    ctx.strokeRect(0, 0, width, height);

    ctx.fillStyle = style.text;
    ctx.font = font;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, width / 2, height / 2 + 0.5);

    if (style.strike) {
        ctx.strokeStyle = style.border;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(1, height - 1);
        ctx.lineTo(width - 1, 1);
        ctx.stroke();
    }

    return canvas;
}
```

- [ ] **Step 5: test verdi**

Run: `npm test` nella cartella del campo. Expected: PASS.

- [ ] **Step 6: Commit (istruzione per il dev)**

```bash
git add src/TrailRegistry/Nova/Fields/TrailRegistryMapField/resources/js/trail-sign.mjs src/TrailRegistry/Nova/Fields/TrailRegistryMapField/resources/js/trail-sign.test.mjs src/TrailRegistry/Nova/Fields/TrailRegistryMapField/package.json src/TrailRegistry/Nova/Fields/TrailRegistryMapField/package-lock.json
git commit -m "fix(oc:8662): segnavia CAI per i numeri della mappa del catasto"
```

---

### Task 6: `DetailField.vue` del catasto — segnavia, numero corrente, ricarica, profilo

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-detailfield-del-catasto-segnavia-numero-corrente-ricarica-profilo)

**Files:**
- Modify: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/resources/js/components/DetailField.vue`

**Interfaces:**
- Consumes: `buildLabelFeatures`, `paintSign` (Task 5); `map-ready.reloaded`, prop `preserveViewOnReload` (Task 4); meta `mapVersion`, `enableSlopeChart` (Task 2); property GeoJSON del Task 1.

- [ ] **Step 1: template**

```vue
            <FeatureCollectionMap
                :geojson-url="geojsonUrl"
                :height="field.height || 500"
                :enable-slope-chart="field.enableSlopeChart === true"
                :preserve-view-on-reload="true"
                :resource-name="resourceName"
                :resource-id="currentResourceId"
                @map-ready="handleMapReady" />
```

- [ ] **Step 2: import e stato**

```js
import Feature from 'ol/Feature';
import Point from 'ol/geom/Point';
import VectorLayer from 'ol/layer/Vector';
import VectorSource from 'ol/source/Vector';
import { Style, Stroke, Icon } from 'ol/style';
import { buildLabelFeatures, paintSign } from '../trail-sign.mjs';
```

`data()` aggiunge `currentLabelLayer: null`; `created() { this.signCache = new Map(); }` (fuori
dalla reattività: sono canvas); `beforeUnmount` azzera anche `currentLabelLayer`.

Aggiornare il docblock del componente: punto 4 «il numero del codice in esame, sempre visibile,
su un layer proprio sopra i vicini»; punto 5 «l'URL porta la versione della mappa, cosi' dopo
un'Action la mappa si riscarica» (oc:8662).

- [ ] **Step 3: URL con la versione**

```js
        geojsonUrl() {
            const base = this.field.geojsonUrl
                || `/nova-vendor/feature-collection-map/${this.resourceName}/${this.currentResourceId}`;

            // La versione cambia con il codice mostrato: dopo un'Action Nova
            // rilegge la risorsa, questo URL cambia e il watcher del
            // componente condiviso riscarica la mappa (oc:8662).
            if (!this.field.mapVersion) {
                return base;
            }

            return `${base}${base.includes('?') ? '&' : '?'}v=${encodeURIComponent(this.field.mapVersion)}`;
        },
```

- [ ] **Step 4: `handleMapReady` e rimozione dei layer**

```js
        handleMapReady({ map, features, reloaded }) {
            if (!map || !Array.isArray(features)) {
                return;
            }

            // A ogni caricamento si riparte da zero: una ricarica senza vicini
            // non deve lasciare sulla mappa i numeri della risposta prima.
            this.removeOwnLayers(map);

            const neighbours = features.filter((f) => f.get('neighbour') === true);
            const labels = buildLabelFeatures(features);

            if (neighbours.length > 0) {
                this.moveNeighboursToOwnLayer(map, neighbours, labels.neighbours);
            }

            // Fuori dall'if dei vicini: la prima istanza di un settore non ha
            // vicini, e il suo numero deve comparire lo stesso.
            this.addCurrentLabel(map, labels.current);

            if (!reloaded) {
                this.refitOn(map, features.filter((f) => f.get('neighbour') !== true));
            }
        },

        removeOwnLayers(map) {
            ['neighbourLayer', 'labelLayer', 'currentLabelLayer'].forEach((key) => {
                if (this[key]) {
                    map.removeLayer(this[key]);
                    this[key] = null;
                }
            });
        },
```

- [ ] **Step 5: `moveNeighboursToOwnLayer`**

Firma: `moveNeighboursToOwnLayer(map, neighbours, labelItems)`. Togliere i due blocchi
`if (this.neighbourLayer) map.removeLayer(...)` / `if (this.labelLayer) ...` (ora in
`removeOwnLayers`). Sostituire la costruzione di `labelFeatures` (i cloni) con:

```js
            // Le etichette sono punti a meta' tracciato, feature nuove e non
            // cloni: niente `tooltip` ne' `link`, cosi' la sola feature
            // raggiungibile col mouse resta la linea (vedi sopra).
            const labelFeatures = labelItems.map((item) => new Feature({
                geometry: new Point(item.coordinate),
                label: item.label,
                kind: item.kind,
            }));
```

Il resto (layer con `declutter: true`, `zIndex: 20`, `labelStyle`) resta.

- [ ] **Step 6: layer del numero corrente**

```js
        addCurrentLabel(map, items) {
            if (!items || items.length === 0) {
                return;
            }

            // Sopra i vicini e **senza** declutter: con declutter OpenLayers
            // potrebbe nasconderlo quando si sovrappone a un vicino con la
            // stessa geometria, che e' proprio il caso da cui nasce oc:8662.
            // Nessuna soglia di zoom: e' l'informazione principale della mappa.
            this.currentLabelLayer = new VectorLayer({
                source: new VectorSource({
                    features: items.map((item) => new Feature({
                        geometry: new Point(item.coordinate),
                        label: item.label,
                        kind: item.kind,
                    })),
                }),
                zIndex: 30,
                style: (feature) => this.signStyle(feature),
            });

            map.addLayer(this.currentLabelLayer);
        },
```

- [ ] **Step 7: stili**

`labelStyle(map, feature, resolution)`: tenere il controllo di `label` e della soglia di zoom;
sostituire il `return new Style({ text: … })` finale con `return this.signStyle(feature);`.
Rimuovere `Text`, `Fill` dagli import se non più usati.

```js
        signStyle(feature) {
            const label = feature.get('label');
            const kind = feature.get('kind');
            const ratio = window.devicePixelRatio || 1;
            const key = `${kind}|${label}|${ratio}`;

            // Un canvas per etichetta e stato, creato una volta: la style
            // function gira a ogni frame di zoom e pan.
            if (!this.signCache.has(key)) {
                const canvas = paintSign(document, label, kind, ratio);

                this.signCache.set(key, new Style({
                    image: new Icon({
                        img: canvas,
                        size: [canvas.width, canvas.height],
                        scale: 1 / ratio,
                    }),
                }));
            }

            return this.signCache.get(key);
        },
```

Verificare nella doc di OpenLayers 9 (`node_modules/ol/style/Icon.d.ts`) che `img` con canvas
accetti `size`; se l'opzione è diversa (`width`/`height`), adattare solo quella riga.

- [ ] **Step 8: build di prova**

```bash
cd /Users/bongiu/Documents/geobox2/forestas/wm-package/src/TrailRegistry/Nova/Fields/TrailRegistryMapField
npm run prod
```

Expected: compilazione senza errori.

- [ ] **Step 9: Commit (istruzione per il dev)**

```bash
git add src/TrailRegistry/Nova/Fields/TrailRegistryMapField/resources
git commit -m "fix(oc:8662): mappa del catasto con segnavia, numero del codice in esame, ricarica e profilo"
```

---

### Task 7: bundle, verifica in Nova, submodule

**Files:**
- Modify: `src/Nova/Fields/FeatureCollectionMap/dist/**`, `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/dist/**`

- [ ] **Step 1: sorgenti pulite prima della build**

Run: `git -C /Users/bongiu/Documents/geobox2/forestas/wm-package status --short src/Nova/Fields/FeatureCollectionMap src/TrailRegistry/Nova/Fields/TrailRegistryMapField`
Expected: solo i file dei Task 4-6. Niente prop spurie (regola in `docs/knowledge/campi-nova-custom-e-build.md`).

- [ ] **Step 2: build dei due campi**

```bash
cd /Users/bongiu/Documents/geobox2/forestas/wm-package/src/Nova/Fields/FeatureCollectionMap && npm ci && npm run prod && npm test
cd /Users/bongiu/Documents/geobox2/forestas/wm-package/src/TrailRegistry/Nova/Fields/TrailRegistryMapField && npm ci && npm run prod && npm test
```

Expected: build e test verdi.

- [ ] **Step 3: grep sui dist**

```bash
grep -c "preserveViewOnReload\|slopeChart" /Users/bongiu/Documents/geobox2/forestas/wm-package/src/Nova/Fields/FeatureCollectionMap/dist/js/field.js
grep -c "mapVersion" /Users/bongiu/Documents/geobox2/forestas/wm-package/src/TrailRegistry/Nova/Fields/TrailRegistryMapField/dist/js/field.js
```

Expected: conteggi maggiori di 0.

- [ ] **Step 4: tutta la suite del catasto e PHPStan**

```bash
docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest tests/Feature/TrailRegistry
docker exec -w /var/www/html/forestas php-forestas vendor/bin/phpstan analyse
```

Expected: PASS; PHPStan senza errori nuovi sui file toccati.

- [ ] **Step 5: verifica manuale in Nova (dev)**

Dopo `docker exec php-forestas php artisan nova:publish` se serve, su `http://127.0.0.1:8000/nova/resources/trail-applications/2`:

1. il tracciato mostra il segnavia arancione «66», sopra il «61» dell'istanza 3;
2. i vicini sono segnavia rossi (validati) o bianchi con bordo rosso (proposti);
3. sotto la mappa c'è il profilo altimetrico; passando sui vicini non si sposta;
4. «Sostituisci numero» → la mappa si ricarica da sola con il numero nuovo, senza cambiare zoom;
5. la legenda nomina i segnavia presenti e il profilo;
6. la scheda di un codice in `trail-registry-codes` mostra lo stesso, con il profilo sul sentiero;
7. la scheda di un'anomalia non ha il profilo.

- [ ] **Step 6: Commit (istruzione per il dev)**

```bash
git add src/Nova/Fields/FeatureCollectionMap/dist src/TrailRegistry/Nova/Fields/TrailRegistryMapField/dist
git commit -m "fix(oc:8662): bundle ricompilati della mappa condivisa e del catasto"
```

Poi in Forestas: aggiornare il puntatore del submodule `wm-package` (istruzione per il dev).

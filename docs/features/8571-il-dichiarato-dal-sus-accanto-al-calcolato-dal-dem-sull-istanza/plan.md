> Ticket: oc:8571

# DEM sull'istanza del Catasto Sentieri — piano di implementazione

> **Per chi esegue:** sub-skill richiesta `superpowers:subagent-driven-development` (consigliata) o
> `superpowers:executing-plans`. I passi usano le checkbox (`- [ ]`).
>
> **Nessun commit, nessun branch da parte di Claude.** I blocchi «Commit» sono istruzioni testuali
> per il dev, che committa lui dopo il review-gate.

**Obiettivo:** l'istanza (`TrailApplication`) ha il tab DEM del sentiero, il calcolo DEM automatico,
la modifica dei soli valori manuali in istruttoria, il file originale conservato e la mappa del suo
codice; `updateManualData()` smette di cancellare i valori manuali.

**Architettura:** il calcolo DEM si estrae da `EcTrackService::updateDemData()` in due metodi puri
riusati da un nuovo job dell'istanza, che scrive in SQL solo `dem_data` e la geometria. Il modello
accoda il job alla creazione (`afterCommit`) e delega la mappa al proprio codice. La Resource Nova
passa a `AbstractGeometryResource` e riusa `getDemTabFields()`, filtrandolo per il form di modifica.

**Stack:** Laravel 12, Nova 5, PostgreSQL/PostGIS, Spatie Media Library, Pest 2 (Testbench).

**Spec:** [overview.md](overview.md) — approvata. Le decisioni della challenge sono in
[notes.md](notes.md).

## Vincoli globali

- Repo unico: `wm-package` (submodule di `forestas`). Nessun file di `forestas` cambia.
- PHP minimo `>8.1`: mai `const` dentro un trait.
- Le geometrie PostGIS passano sempre da SQL puro, mai dall'ORM (regola del package).
- Documentazione, commenti e messaggi di commit in italiano; termini tecnici in inglese.
- Traduzioni del package in `resources/lang/*.json`, non in `lang/`.
- Unità dei valori DEM (verificate nel servizio `geobox2/dem`, `SlopeAndElevationTrait::calcDuration`):
  `distance` in km, `ascent`/`descent`/`ele_*` in metri, `duration_*` in minuti.
- Test: dentro il container, da `wm-package/`:
  `docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest --filter=<nome>`.
  Il DB dei test è `wm_package` (`phpunit.xml.dist`); **prima di lanciare** verifica che non esista
  un `wm-package/phpunit.xml` locale senza le righe `DB_*` (vedi `CLAUDE.md` del package).
- Nessun test deve uscire verso `dem.maphub.it`: ogni chiamata al DEM è finta (`Http::fake`) o il job
  è finto (`Bus::fake`).

## Review Focus

Casi che la spec implica e che è facile rompere senza accorgersene. Ognuno ha un test nel task che
possiede il codice.

1. **Istanza creata con un settore inesistente** → la creazione va in rollback e il job DEM **non**
   deve partire (Task 4, test «non accoda il job se la creazione va in rollback»).
2. **Valore manuale cancellato dall'operatore** (campo svuotato) → `manual_data.<campo>` diventa
   `null` e il valore corrente torna al DEM (Task 6, test «svuotare un manuale fa tornare il DEM»).
3. **Risposta DEM con geometria che non è una MultiLineString** (una LineString) → la geometria
   salvata resta una `MultiLineStringZ` valida (Task 3, test «normalizza una LineString»).
4. **Sentiero con un manuale già presente e geometria modificata** → la catena di EcTrack non lo
   cancella (Task 2 e Task 7).
5. **Istanza rifiutata** → nel dettaglio compare comunque la mappa del suo ultimo codice (Task 5,
   test «istanza rifiutata usa l'ultimo codice»).

---

### Task 1: estrarre il calcolo DEM da `updateDemData()`

**File:**
- Modifica: `src/Services/Models/EcTrackService.php:58-91`
- Test: `tests/Unit/Services/EcTrackService/NormalizeDemDataTest.php` (nuovo)

**Interfacce:**
- Produce:
  - `EcTrackService::fetchDemTechData(array $geojson): array` — restituisce la risposta di
    `DemClient::getTechData()` (`['properties' => [...], 'geometry' => [...]]`); solleva se il DEM
    fallisce, come oggi.
  - `EcTrackService::normalizeDemData(array $properties): array` — `duration_forward` e
    `duration_backward` presi da `*_hiking`, resto invariato. Pura, senza I/O.

- [ ] **Passo 1: test che fallisce**

```php
<?php

use Wm\WmPackage\Services\Models\EcTrackService;

it('normalizza le durate prendendole dall escursionismo', function () {
    $normalized = app(EcTrackService::class)->normalizeDemData([
        'distance' => 5.2,
        'ascent' => 300,
        'duration_forward_hiking' => 120,
        'duration_backward_hiking' => 110,
        'duration_forward_bike' => 40,
        'duration_backward_bike' => 35,
    ]);

    expect($normalized)
        ->toMatchArray([
            'distance' => 5.2,
            'ascent' => 300,
            'duration_forward' => 120,
            'duration_backward' => 110,
            'duration_forward_bike' => 40,
        ]);
});

it('non inventa le durate se il DEM non le restituisce', function () {
    $normalized = app(EcTrackService::class)->normalizeDemData(['distance' => 1.0]);

    expect($normalized)->toHaveKey('duration_forward', null)
        ->and($normalized)->toHaveKey('duration_backward', null);
});
```

- [ ] **Passo 2: eseguilo e verifica che fallisca**

Run: `docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest --filter=NormalizeDemDataTest`
Atteso: FAIL, `Call to undefined method ...normalizeDemData()`.

- [ ] **Passo 3: implementazione minima**

In `EcTrackService`, sopra `updateDemData()`:

```php
    /**
     * La risposta grezza del servizio DEM per una traccia.
     *
     * Separata da updateDemData() perche' la usa anche l'istanza del Catasto
     * Sentieri, che salva il risultato in SQL e non via Eloquent.
     */
    public function fetchDemTechData(array $geojson): array
    {
        return $this->demClient->getTechData($geojson);
    }

    /**
     * I valori DEM nella forma che si salva in `properties['dem_data']`: le
     * durate "correnti" sono quelle per l'escursionismo.
     */
    public function normalizeDemData(array $properties): array
    {
        $properties['duration_forward'] = $properties['duration_forward_hiking'] ?? null;
        $properties['duration_backward'] = $properties['duration_backward_hiking'] ?? null;

        return $properties;
    }
```

E in `updateDemData()` sostituisci le righe 60-66:

```php
        $geojson = $track->getGeojson();

        $responseData = $this->fetchDemTechData($geojson);
        $demData = $this->normalizeDemData($responseData['properties']);
```

Il resto di `updateDemData()` resta identico.

- [ ] **Passo 4: esegui il nuovo test e quello esistente**

Run: `... vendor/bin/pest --filter="NormalizeDemDataTest|UpdateDemDataTest"`
Atteso: PASS. `UpdateDemDataTest` deve passare senza modifiche: il comportamento di EcTrack non
cambia.

- [ ] **Passo 5: commit (istruzione per il dev)**

```bash
git add src/Services/Models/EcTrackService.php tests/Unit/Services/EcTrackService/NormalizeDemDataTest.php
git commit -m "refactor(oc:8571): estrae il calcolo DEM da updateDemData"
```

---

### Task 2: `updateManualData()` non cancella più i valori manuali

**File:**
- Modifica: `src/Services/Models/EcTrackService.php:209-241`
- Test: `tests/Unit/Services/EcTrackService/UpdateManualDataTest.php` (aggiunta)

**Interfacce:**
- Consuma: nulla dai task precedenti.
- Produce: `updateManualData(EcTrack $track)` con la stessa firma; nuovo comportamento: parte dal
  `manual_data` esistente.

- [ ] **Passo 1: test che fallisce** — aggiungi in fondo alla classe `UpdateManualDataTest`:

```php
    /** @test */
    public function update_manual_data_keeps_existing_manual_values()
    {
        // Un valore scritto dal tab DEM vive solo in manual_data: il primo
        // livello e' vuoto, come su Forestas e sulle istanze del Catasto.
        $this->track->properties = [
            ...$this->track->properties,
            'manual_data' => ['duration_forward' => 180],
        ];

        $this->ecTrackService->updateManualData($this->track);

        $this->assertEquals(180, $this->track->properties['manual_data']['duration_forward']);
    }

    /** @test */
    public function update_manual_data_adds_top_level_value_without_dropping_existing_ones()
    {
        $this->track->properties = [
            ...$this->track->properties,
            'ascent' => self::DIRTY_FIELDS[self::ASCENT_FIELD_LABEL],
            'manual_data' => ['duration_forward' => 180],
        ];

        $this->ecTrackService->updateManualData($this->track);

        $this->assertEquals(180, $this->track->properties['manual_data']['duration_forward']);
        $this->assertEquals(
            self::DIRTY_FIELDS[self::ASCENT_FIELD_LABEL],
            $this->track->properties['manual_data']['ascent'],
        );
    }
```

- [ ] **Passo 2: eseguilo e verifica che fallisca**

Run: `... vendor/bin/pest --filter=UpdateManualDataTest`
Atteso: FAIL sui due nuovi test (`manual_data` ricostruito da zero).

- [ ] **Passo 3: implementazione minima** — in `updateManualData()` sostituisci
  `$manualData = null;` con:

```php
        // Si parte dai manuali gia' presenti: quelli scritti dal tab DEM vivono
        // solo in manual_data, e ricostruirlo dal primo livello li cancellerebbe
        // (oc:8571). Il primo livello resta una sorgente in piu', per il flusso
        // OSM/GeoHub che lo scrive ancora (eliminazione in oc:8642).
        $existing = $track->properties['manual_data'] ?? [];
        $manualData = is_array($existing) ? $existing : (json_decode((string) $existing, true) ?: []);
```

Il ciclo sui campi resta identico: aggiunge a `$manualData` solo i valori al primo livello diversi da
DEM e OSM. In fondo, se `$manualData` è vuoto, scrivi `null` come oggi:

```php
        $properties['manual_data'] = $manualData === [] ? null : $manualData;
```

- [ ] **Passo 4: esegui tutti i test del service**

Run: `... vendor/bin/pest tests/Unit/Services/EcTrackService`
Atteso: PASS, compresi i test già esistenti di `UpdateManualDataTest`. Se uno esistente si aspetta
che un manuale presente venga cancellato, **fermati e segnalalo**: è il comportamento che il ticket
cambia, e il test va aggiornato solo dopo averlo detto al dev.

- [ ] **Passo 5: commit (istruzione per il dev)**

```bash
git add src/Services/Models/EcTrackService.php tests/Unit/Services/EcTrackService/UpdateManualDataTest.php
git commit -m "fix(oc:8571): updateManualData conserva i valori manuali esistenti"
```

---

### Task 3: il job DEM dell'istanza

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3--il-job-dem-dellistanza)

**File:**
- Crea: `src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php`
- Modifica: `tests/Pest.php` (fake di default per la cartella TrailRegistry)
- Test: `tests/Feature/TrailRegistry/TrailApplicationDemJobTest.php` (nuovo)

**Interfacce:**
- Consuma: `EcTrackService::fetchDemTechData(array)`, `EcTrackService::normalizeDemData(array)`
  (Task 1).
- Produce: `new UpdateTrailApplicationDemJob(int $applicationId)`; coda `dem`; `ShouldBeUnique` con
  `uniqueId()` = id dell'istanza.

- [ ] **Passo 1: fake di default nei test TrailRegistry** — in `tests/Pest.php`, sotto la riga
  `uses(TestCase::class)->in(__DIR__);`:

```php
// Ogni istanza creata accoda il calcolo DEM (oc:8571): nei test del catasto il
// job e' finto per default, cosi' nessun test esce verso il servizio DEM. Chi
// vuole il job vero lo esegue a mano con Http::fake().
uses()->beforeEach(function () {
    \Illuminate\Support\Facades\Bus::fake([
        \Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob::class,
    ]);
})->in('Feature/TrailRegistry');
```

- [ ] **Passo 2: test che fallisce** — `TrailApplicationDemJobTest.php`:

```php
<?php

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

beforeEach(function () {
    runTrailRegistryStubs();

    $this->application = TrailApplication::factory()->create();
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $this->application->id]
    );
});

function fakeDemResponse(array $geometry): void
{
    Http::fake([
        '*' => Http::response([
            'type' => 'Feature',
            'properties' => [
                'distance' => 157.2,
                'ascent' => 300,
                'descent' => 290,
                'ele_max' => 350,
                'ele_min' => 50,
                'ele_from' => 60,
                'ele_to' => 70,
                'round_trip' => false,
                'duration_forward_hiking' => 120,
                'duration_backward_hiking' => 110,
                'duration_forward_bike' => 40,
                'duration_backward_bike' => 35,
            ],
            'geometry' => $geometry,
        ]),
    ]);
}

it('scrive dem_data e la quota del DEM senza toccare il resto di properties', function () {
    DB::statement(
        "UPDATE trail_applications SET properties = ?::jsonb WHERE id = ?",
        [json_encode(['protocollo' => 'P-1', 'manual_data' => ['duration_forward' => 180]]), $this->application->id]
    );
    fakeDemResponse(['type' => 'MultiLineString', 'coordinates' => [[[1, 1, 100], [2, 2, 200]]]]);

    (new UpdateTrailApplicationDemJob($this->application->id))->handle(app(\Wm\WmPackage\Services\Models\EcTrackService::class));

    $fresh = $this->application->fresh();
    expect($fresh->properties['dem_data']['ascent'])->toBe(300)
        ->and($fresh->properties['dem_data']['duration_forward'])->toBe(120)
        ->and($fresh->properties['protocollo'])->toBe('P-1')
        ->and($fresh->properties['manual_data']['duration_forward'])->toBe(180);

    $wkt = DB::selectOne('SELECT ST_AsText(geometry) AS wkt FROM trail_applications WHERE id = ?', [$this->application->id])->wkt;
    expect($wkt)->toBe('MULTILINESTRING Z ((1 1 100,2 2 200))');
});

it('normalizza una LineString restituita dal DEM in MultiLineString', function () {
    fakeDemResponse(['type' => 'LineString', 'coordinates' => [[1, 1, 100], [2, 2, 200]]]);

    (new UpdateTrailApplicationDemJob($this->application->id))->handle(app(\Wm\WmPackage\Services\Models\EcTrackService::class));

    $type = DB::selectOne('SELECT GeometryType(geometry::geometry) AS t FROM trail_applications WHERE id = ?', [$this->application->id])->t;
    expect($type)->toBe('MULTILINESTRING');
});

it('non fa nulla se l istanza non esiste piu', function () {
    Http::fake();

    (new UpdateTrailApplicationDemJob(999999))->handle(app(\Wm\WmPackage\Services\Models\EcTrackService::class));

    Http::assertNothingSent();
});

it('e unico per istanza e gira sulla coda dem', function () {
    $job = new UpdateTrailApplicationDemJob($this->application->id);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $this->application->id)
        ->and($job->queue)->toBe('dem');
});
```

Nota: se il WKT restituito da PostGIS ha spazi diversi (`1 1 100, 2 2 200`), adegua solo la stringa
attesa, non il codice.

- [ ] **Passo 3: eseguilo e verifica che fallisca**

Run: `... vendor/bin/pest --filter=TrailApplicationDemJobTest`
Atteso: FAIL, classe `UpdateTrailApplicationDemJob` inesistente.

- [ ] **Passo 4: implementazione** — `src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php`:

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * Il calcolo DEM di un'istanza del Catasto Sentieri: scrive
 * `properties['dem_data']` e mette nella geometria la quota del nostro DEM.
 *
 * Scrive in SQL solo le proprie chiavi, senza risalvare il modello: la
 * geometria nel package non passa dall'ORM, e un salvataggio dell'intero
 * `properties` potrebbe coprire un valore manuale appena scritto
 * dall'operatore. Il file caricato resta intatto nella collection
 * `original_geometry` (oc:8571).
 *
 * Riceve l'id e non il modello: un'istanza sparita nel frattempo e' un
 * job che non ha niente da fare, non un errore.
 */
class UpdateTrailApplicationDemJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Il lock di ShouldBeUnique scade da solo: un job perso non blocca per
     * sempre il ricalcolo di quell'istanza.
     */
    public int $uniqueFor = 600;

    public function __construct(public int $applicationId)
    {
        $this->onQueue('dem');
    }

    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    public function handle(EcTrackService $ecTrackService): void
    {
        $application = TrailApplication::find($this->applicationId);

        if ($application === null) {
            return;
        }

        $response = $ecTrackService->fetchDemTechData($application->getGeojson());
        $demData = $ecTrackService->normalizeDemData($response['properties'] ?? []);

        DB::update(
            <<<'SQL'
            UPDATE trail_applications
            SET properties = jsonb_set(COALESCE(properties, '{}'::jsonb), '{dem_data}', :dem::jsonb),
                geometry = ST_Multi(ST_Force3D(ST_SetSRID(ST_GeomFromGeoJSON(:geometry), 4326)))::geography,
                updated_at = now()
            WHERE id = :id
            SQL,
            [
                'dem' => json_encode($demData),
                'geometry' => json_encode($response['geometry']),
                'id' => $this->applicationId,
            ],
        );
    }
}
```

Se `$this->onQueue('dem')` non valorizza `$job->queue` nel test, sostituiscilo con la proprietà
`public $queue = 'dem';` come in `UpdateEcTrackDemJob`.

- [ ] **Passo 5: esegui i test**

Run: `... vendor/bin/pest --filter=TrailApplicationDemJobTest`
Atteso: PASS.

- [ ] **Passo 6: commit (istruzione per il dev)**

```bash
git add src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php tests/Pest.php tests/Feature/TrailRegistry/TrailApplicationDemJobTest.php
git commit -m "feat(oc:8571): job DEM dell'istanza con scrittura SQL mirata"
```

---

### Task 4: il modello — file originale, innesco del DEM, mappa del codice

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4--il-modello)

**File:**
- Modifica: `src/TrailRegistry/Models/TrailApplication.php`
- Test: `tests/Feature/TrailRegistry/TrailApplicationDemTriggerTest.php` (nuovo)

**Interfacce:**
- Consuma: `UpdateTrailApplicationDemJob` (Task 3).
- Produce:
  - collection media `TrailApplication::ORIGINAL_GEOMETRY_COLLECTION = 'original_geometry'`
    (costante sulla classe, non in un trait);
  - `TrailApplication::needsDem(): bool` — geometria valida e `dem_data` assente o vuoto;
  - `TrailApplication::dispatchDemIfMissing(): void` — accoda il job solo se `needsDem()`;
  - `TrailApplication::mapCode(): ?TrailRegistryCode` — codice attivo, altrimenti il più recente;
  - `TrailApplication::getFeatureCollectionMap(): array` — mappa di `mapCode()`, o la sola traccia.

- [ ] **Passo 1: test che fallisce** — `TrailApplicationDemTriggerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
});

function applicationWithGeometry(array $properties = []): TrailApplication
{
    $application = TrailApplication::factory()->create(['properties' => $properties]);
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]
    );

    return $application->fresh();
}

it('accoda il job DEM alla creazione', function () {
    $application = TrailApplication::factory()->create();

    Bus::assertDispatched(UpdateTrailApplicationDemJob::class, fn ($job) => $job->applicationId === $application->id);
});

it('non accoda il job se la creazione va in rollback', function () {
    try {
        DB::transaction(function () {
            TrailApplication::factory()->create();
            throw new RuntimeException('settore non trovato');
        });
    } catch (RuntimeException) {
    }

    Bus::assertNotDispatched(UpdateTrailApplicationDemJob::class);
});

it('serve il DEM solo con geometria valida e dem_data vuoto', function () {
    expect(applicationWithGeometry()->needsDem())->toBeTrue()
        ->and(applicationWithGeometry(['dem_data' => []])->needsDem())->toBeTrue()
        ->and(applicationWithGeometry(['dem_data' => ['ascent' => 10]])->needsDem())->toBeFalse();
});

it('non rilancia il job dove dem_data esiste gia', function () {
    $application = applicationWithGeometry(['dem_data' => ['ascent' => 10]]);
    Bus::fake([UpdateTrailApplicationDemJob::class]);

    $application->dispatchDemIfMissing();

    Bus::assertNotDispatched(UpdateTrailApplicationDemJob::class);
});

it('rilancia il job dove dem_data manca', function () {
    $application = applicationWithGeometry();
    Bus::fake([UpdateTrailApplicationDemJob::class]);

    $application->dispatchDemIfMissing();

    Bus::assertDispatched(UpdateTrailApplicationDemJob::class);
});

it('la mappa dell istanza e quella del suo codice', function () {
    $application = applicationWithGeometry();
    $code = app(TrailRegistryService::class)->reserve($application);

    expect($application->fresh()->getFeatureCollectionMap())->toBe($code->fresh()->getFeatureCollectionMap());
});

it('un istanza rifiutata usa l ultimo codice', function () {
    $application = applicationWithGeometry();
    $service = app(TrailRegistryService::class);
    $code = $service->reserve($application);
    $service->release($code, null);
    $application->update(['status' => TrailApplicationStatus::Rejected]);

    expect($application->fresh()->mapCode()?->id)->toBe($code->id);
});

it('senza codici la mappa e la sola traccia', function () {
    $application = applicationWithGeometry();

    expect($application->mapCode())->toBeNull()
        ->and($application->getFeatureCollectionMap()['features'])->toHaveCount(1);
});
```

Prima di scrivere il test «rifiutata», leggi la firma di `TrailRegistryService::release()` (e come la
chiama `RejectTrailApplication`): usa la stessa chiamata, non inventarne una.

- [ ] **Passo 2: eseguilo e verifica che fallisca**

Run: `... vendor/bin/pest --filter=TrailApplicationDemTriggerTest`
Atteso: FAIL (`needsDem`, `mapCode` inesistenti; job non accodato).

- [ ] **Passo 3: implementazione** — nel modello `TrailApplication`:

```php
    public const ORIGINAL_GEOMETRY_COLLECTION = 'original_geometry';

    /**
     * Il calcolo DEM parte a ogni istanza nuova, dopo il commit: Nova crea
     * l'istanza e riserva il codice nella stessa transazione, e se la
     * prenotazione fallisce non deve restare in coda un job per una riga che
     * non esiste (oc:8571).
     */
    protected static function booted(): void
    {
        static::created(function (TrailApplication $application) {
            UpdateTrailApplicationDemJob::dispatch($application->id)->afterCommit();
        });
    }

    /**
     * Il file caricato, conservato tale e quale: la geometria riceve la quota
     * del nostro DEM, e questo resta il riferimento di cio' che e' stato
     * dichiarato.
     */
    public function registerMediaCollections(): void
    {
        parent::registerMediaCollections();

        $this->addMediaCollection(self::ORIGINAL_GEOMETRY_COLLECTION)->singleFile();
    }

    /**
     * Serve il DEM se c'e' una traccia su cui calcolarlo e il dato manca.
     */
    public function needsDem(): bool
    {
        if (! empty($this->properties['dem_data'] ?? null)) {
            return false;
        }

        return (bool) DB::selectOne(
            'SELECT geometry IS NOT NULL AND ST_IsValid(geometry::geometry) AND NOT ST_IsEmpty(geometry::geometry) AS ok
             FROM trail_applications WHERE id = ?',
            [$this->id],
        )?->ok;
    }

    public function dispatchDemIfMissing(): void
    {
        if ($this->needsDem()) {
            UpdateTrailApplicationDemJob::dispatch($this->id);
        }
    }

    /**
     * Il codice di cui mostrare la mappa: quello attivo, oppure, per
     * un'istanza rifiutata, l'ultimo che ha avuto.
     */
    public function mapCode(): ?TrailRegistryCode
    {
        return $this->activeCode ?? $this->codes()->latest('id')->first();
    }

    /**
     * La mappa del codice, la stessa della scheda nel registro: settore,
     * vicini, traccia dell'istanza e, se approvata, il sentiero. Chi cambia
     * TrailRegistryCode::getFeatureCollectionMap() cambia anche questa.
     */
    public function getFeatureCollectionMap(): array
    {
        return $this->mapCode()?->getFeatureCollectionMap() ?? parent::getFeatureCollectionMap();
    }
```

Aggiungi gli `use` per `DB`, `UpdateTrailApplicationDemJob`. Aggiorna il docblock della classe: la
frase «non da EcTrack: riusa geometria ... senza portarsi dietro ... observer» resta vera (nessun
observer di EcTrack), aggiungi una riga sul job DEM.

- [ ] **Passo 4: esegui il test nuovo e tutta la cartella TrailRegistry**

Run: `... vendor/bin/pest tests/Feature/TrailRegistry`
Atteso: PASS. Se un test esistente fallisce perché ora la creazione accoda un job (es. un test che
conta i job con `Bus::assertDispatchedTimes`), segnalalo invece di adattarlo in silenzio.

- [ ] **Passo 5: commit (istruzione per il dev)**

```bash
git add src/TrailRegistry/Models/TrailApplication.php tests/Feature/TrailRegistry/TrailApplicationDemTriggerTest.php
git commit -m "feat(oc:8571): l'istanza accoda il DEM e mostra la mappa del suo codice"
```

---

### Task 5: la Resource Nova dell'istanza

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5--la-resource-nova)

**File:**
- Modifica: `src/TrailRegistry/Nova/TrailApplication.php`
- Test: `tests/Feature/TrailRegistry/TrailApplicationDemTabTest.php` (nuovo) e
  `tests/Feature/TrailRegistry/TrailApplicationCreateFormTest.php` (aggiunta)

**Interfacce:**
- Consuma: `TrailApplication::dispatchDemIfMissing()`, `mapCode()`,
  `ORIGINAL_GEOMETRY_COLLECTION` (Task 4); `AbstractGeometryResource::getDemTabFields()`.
- Produce: `TrailApplication` (Resource) `extends AbstractGeometryResource`;
  `manualDemFields(): array` (i nove Field `properties->manual_data->*`).

- [ ] **Passo 1: test che fallisce** — `TrailApplicationDemTabTest.php`:

```php
<?php

use Illuminate\Http\Request;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\AbstractGeometryResource;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication as TrailApplicationResource;

beforeEach(function () {
    runTrailRegistryStubs();
});

function resourceFor(TrailApplicationStatus $status): TrailApplicationResource
{
    return new TrailApplicationResource(TrailApplicationModel::factory()->create(['status' => $status]));
}

it('estende la base geometrica del sentiero', function () {
    expect(is_subclass_of(TrailApplicationResource::class, AbstractGeometryResource::class))->toBeTrue();
});

it('si modifica solo in istruttoria', function () {
    $request = Request::create('/');

    expect(resourceFor(TrailApplicationStatus::UnderReview)->authorizedToUpdate($request))->toBeTrue()
        ->and(resourceFor(TrailApplicationStatus::Approved)->authorizedToUpdate($request))->toBeFalse()
        ->and(resourceFor(TrailApplicationStatus::Rejected)->authorizedToUpdate($request))->toBeFalse();
});

it('il form di modifica contiene solo i nove valori manuali', function () {
    $fields = collect(resourceFor(TrailApplicationStatus::UnderReview)->fieldsForUpdate(NovaRequest::create('/')))
        ->map(fn (Field $f) => $f->attribute);

    expect($fields)->toHaveCount(9)
        ->and($fields->every(fn ($a) => str_starts_with($a, 'properties->manual_data->')))->toBeTrue();
});
```

In `TrailApplicationCreateFormTest.php` aggiungi un test che salva il file originale. Prima di
scriverlo leggi come quel file già costruisce la richiesta di creazione (usa `UploadedFile::fake()`
e `CreateResourceRequest`): ripeti la stessa costruzione e verifica

```php
    expect($application->getFirstMedia(TrailApplicationModel::ORIGINAL_GEOMETRY_COLLECTION))
        ->not->toBeNull()
        ->and(file_get_contents($application->getFirstMediaPath(TrailApplicationModel::ORIGINAL_GEOMETRY_COLLECTION)))
        ->toBe(gpxWithTrack());
```

- [ ] **Passo 2: eseguili e verifica che falliscano**

Run: `... vendor/bin/pest --filter="TrailApplicationDemTabTest|TrailApplicationCreateFormTest"`
Atteso: FAIL.

- [ ] **Passo 3: implementazione** — nella Resource:

1. `class TrailApplication extends AbstractGeometryResource` (sostituisci `use Laravel\Nova\Resource`
   con `use Wm\WmPackage\Nova\AbstractGeometryResource`). Mantieni `use ResolvesCanonicalResources`.
2. Sostituisci `authorizedToUpdate()` e il suo docblock:

```php
    /**
     * Si modifica solo in istruttoria, e solo nei valori manuali del tab DEM
     * (vedi fieldsForUpdate): la traccia non cambia, quindi non cambiano
     * settore e prefisso del codice gia' comunicato. Approvata o rifiutata,
     * l'istanza e' uno storico (oc:8571).
     */
    public function authorizedToUpdate(Request $request): bool
    {
        return $this->resource->status === TrailApplicationStatus::UnderReview;
    }
```

3. In `fields()`, prima del `return`:

```php
        if ($request->isResourceDetailRequest()) {
            // Il DEM manca solo se il job alla creazione e' fallito: lo si
            // rilancia qui, e solo dove serve (oc:8571).
            $this->resource->dispatchDemIfMissing();
        }

        $fields[] = TrailRegistryMap::make(__('Mappa'), 'geometry');

        $fields[] = Text::make(__('Legenda'), function () {
            $code = $this->resource->mapCode();

            return $code === null ? '' : MapLegendRenderer::render($code);
        })->asHtml()->onlyOnDetail();

        $fields[] = Tab::group(__('Dettagli'), [
            Tab::make(__('DEM'), $this->getDemTabFields()),
        ]);
```

   (`use Laravel\Nova\Tabs\Tab;`, `use Wm\WmPackage\TrailRegistry\Nova\Fields\TrailRegistryMap;`,
   `use Wm\WmPackage\TrailRegistry\Nova\MapLegendRenderer;`). Il Field `Code` «Proprietà» resta.
4. Aggiungi:

```php
    /**
     * I nove valori manuali del tab DEM, presi da getDemTabFields() e non
     * riscritti. Gli altri Field del tab scrivono in `dem_data` e non devono
     * finire nel form: il calcolato e' il riferimento del confronto.
     *
     * @return array<int, Field>
     */
    protected function manualDemFields(): array
    {
        return array_values(array_filter(
            $this->getDemTabFields(),
            fn ($field) => $field instanceof Field
                && str_starts_with((string) $field->attribute, 'properties->manual_data->'),
        ));
    }

    public function fieldsForUpdate(NovaRequest $request): array
    {
        return $this->manualDemFields();
    }
```

5. Salvataggio del file originale in `afterCreate()`, **dopo** `reserve()` (se la prenotazione
   fallisce, la transazione va in rollback e non resta nulla):

```php
        $file = $request->file('geometry');

        if ($file !== null) {
            $model->addMedia($file->getRealPath())
                ->preservingOriginal()
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection(TrailApplicationModel::ORIGINAL_GEOMETRY_COLLECTION);
        }
```

   Aggiorna il docblock di `fieldsForCreate()`: il commento «Il file non si conserva» non è più
   vero — il file si conserva in `original_geometry`, lo `store` callback continua a estrarre la
   geometria.
6. Nel dettaglio, il link per scaricare il file:

```php
        $fields[] = Text::make(__('File originale'), function () {
            $media = $this->resource->getFirstMedia(TrailApplicationModel::ORIGINAL_GEOMETRY_COLLECTION);

            return $media === null ? '—' : sprintf('<a class="link-default" href="%s">%s</a>', e($media->getUrl()), e($media->file_name));
        })->asHtml()->onlyOnDetail();
```

- [ ] **Passo 4: esegui i test**

Run: `... vendor/bin/pest tests/Feature/TrailRegistry`
Atteso: PASS, compresi `TrailRegistryNovaResourcesTest` e `TrailApplicationCreateFormTest` già
esistenti. Controlla in particolare che l'index e il form di creazione non abbiano Field nuovi: i
Field del tab DEM hanno `onlyOnDetail()`/`onlyOnForms()`, ma `Round Trip` e le durate bici/escursionismo
no — se compaiono nel form di **creazione**, aggiungi un `fieldsForCreate()` invariato (c'è già) e
verifica che venga usato.

- [ ] **Passo 5: commit (istruzione per il dev)**

```bash
git add src/TrailRegistry/Nova/TrailApplication.php tests/Feature/TrailRegistry/TrailApplicationDemTabTest.php tests/Feature/TrailRegistry/TrailApplicationCreateFormTest.php
git commit -m "feat(oc:8571): tab DEM, modifica dei manuali e mappa sull'istanza"
```

---

### Task 6: validazione e unità dei valori manuali in `getDemTabFields()`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6--validazione-dei-manuali)

**File:**
- Modifica: `src/Nova/AbstractGeometryResource.php:133-169`
- Modifica: `resources/lang/it.json`, `resources/lang/en.json` (e gli altri `*.json` presenti) per
  i testi di help
- Test: `tests/Feature/TrailRegistry/TrailApplicationDemTabTest.php` (aggiunta)

**Interfacce:**
- Consuma: `manualDemFields()` (Task 5), solo nei test.
- Produce: i nove Field `manual_data` con `rules('nullable', 'numeric', 'min:0')` e help con l'unità.

- [ ] **Passo 1: test che fallisce** — in `TrailApplicationDemTabTest.php`:

```php
it('i valori manuali accettano solo numeri non negativi o vuoto', function () {
    $fields = resourceFor(TrailApplicationStatus::UnderReview)->fieldsForUpdate(NovaRequest::create('/'));

    foreach ($fields as $field) {
        expect($field->rules)->toContain('nullable', 'numeric', 'min:0');
    }
});

it('svuotare un manuale fa tornare il DEM', function () {
    $model = TrailApplicationModel::factory()->create([
        'properties' => ['dem_data' => ['ascent' => 300], 'manual_data' => ['ascent' => 350]],
    ]);
    $resource = new TrailApplicationResource($model);

    $properties = $model->properties;
    $properties['manual_data']['ascent'] = null;
    $model->properties = $properties;

    expect($resource->classifyField($model, 'ascent')['currentValue'])->toBe(300);
});

it('ogni valore manuale dichiara la sua unita', function () {
    $fields = collect(resourceFor(TrailApplicationStatus::UnderReview)->fieldsForUpdate(NovaRequest::create('/')))
        ->mapWithKeys(fn ($f) => [str_replace('properties->manual_data->', '', $f->attribute) => $f->helpText]);

    expect($fields['duration_forward'])->toBe(__('In minuti'))
        ->and($fields['distance'])->toBe(__('In km'))
        ->and($fields['ascent'])->toBe(__('In metri'));
});
```

Se `classifyField()` non è pubblico, chiamalo come lo chiama `EcTrackExcelExporter` (tramite il
trait `HasDemClassification`), senza cambiarne la visibilità.

- [ ] **Passo 2: eseguilo e verifica che fallisca**

Run: `... vendor/bin/pest --filter=TrailApplicationDemTabTest`
Atteso: FAIL sui test di regole e unità.

- [ ] **Passo 3: implementazione** — in `getDemTabFields()`:

```php
        $units = [
            'ascent' => __('In metri'),
            'descent' => __('In metri'),
            'distance' => __('In km'),
            'ele_max' => __('In metri'),
            'ele_min' => __('In metri'),
            'ele_from' => __('In metri'),
            'ele_to' => __('In metri'),
            'duration_forward' => __('In minuti'),
            'duration_backward' => __('In minuti'),
        ];
```

e il Field manuale diventa:

```php
            $fields[] = Text::make($label, 'properties->manual_data->'.$fieldKey)
                ->onlyOnForms()
                ->rules('nullable', 'numeric', 'min:0')
                ->help($units[$fieldKey]);
```

Aggiungi le chiavi `"In metri"`, `"In km"`, `"In minuti"` in ogni `resources/lang/*.json` presente
(`it`: stesso testo; `en`: `"In meters"`, `"In km"`, `"In minutes"`; `de`, `es`, `fr`: traduzione
corrispondente).

- [ ] **Passo 4: esegui i test di Nova e del sentiero**

Run: `... vendor/bin/pest tests/Feature/TrailRegistry` e
`... vendor/bin/pest --filter=EcTrack`
Atteso: PASS.

- [ ] **Passo 5: conteggio dei manuali non numerici su Forestas (sola lettura)**

```bash
docker exec php-forestas php artisan tinker --execute="
echo DB::selectOne(\"SELECT count(*) AS n FROM ec_tracks, jsonb_each_text(properties->'manual_data') kv
  WHERE jsonb_typeof(properties->'manual_data') = 'object' AND kv.value IS NOT NULL AND kv.value !~ '^[0-9]+(\\.[0-9]+)?$'\")->n;"
```

Riporta il numero al dev: sono i sentieri che non si potranno salvare senza correggere il campo.

- [ ] **Passo 6: commit (istruzione per il dev)**

```bash
git add src/Nova/AbstractGeometryResource.php resources/lang/*.json tests/Feature/TrailRegistry/TrailApplicationDemTabTest.php
git commit -m "feat(oc:8571): validazione e unita' sui valori manuali del tab DEM"
```

---

### Task 7: i manuali arrivano intatti sul sentiero

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7--i-manuali-arrivano-sul-sentiero)

**File:**
- Test: `tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php` (aggiunta)

**Interfacce:**
- Consuma: `updateManualData()` corretto (Task 2), `ApproveTrailApplication` invariata.

- [ ] **Passo 1: test** — in `ApproveTrailApplicationTest.php`:

```php
it('i valori manuali corretti in istruttoria restano sul sentiero dopo la catena', function () {
    DB::statement(
        "UPDATE trail_applications SET properties = COALESCE(properties, '{}'::jsonb) || ?::jsonb WHERE id = ?",
        [json_encode(['manual_data' => ['duration_forward' => 180]]), $this->application->id]
    );

    (new ApproveTrailApplication)->handle(emptyActionFields(), collect([$this->application->fresh()]));

    $track = EcTrack::query()->latest('id')->first();
    app(\Wm\WmPackage\Services\Models\EcTrackService::class)->updateManualData($track);

    expect($track->fresh()->properties['manual_data']['duration_forward'])->toBe(180);
});

it('il file originale arriva sul sentiero', function () {
    $this->application->addMediaFromString(gpxContentForApproval())
        ->usingFileName('traccia.gpx')
        ->toMediaCollection(TrailApplication::ORIGINAL_GEOMETRY_COLLECTION);

    (new ApproveTrailApplication)->handle(emptyActionFields(), collect([$this->application->fresh()]));

    $track = EcTrack::query()->latest('id')->first();
    expect($track->getFirstMedia(TrailApplication::ORIGINAL_GEOMETRY_COLLECTION))->not->toBeNull();
});

function gpxContentForApproval(): string
{
    return '<?xml version="1.0"?><gpx version="1.1"><trk><trkseg><trkpt lat="1" lon="1"/><trkpt lat="2" lon="2"/></trkseg></trk></gpx>';
}
```

- [ ] **Passo 2: esegui**

Run: `... vendor/bin/pest --filter=ApproveTrailApplicationTest`
Atteso: PASS (il comportamento lo garantiscono Task 2 e `copyMedia()`). Se il primo test fallisce,
il problema è in Task 2: non correggere qui.

- [ ] **Passo 3: commit (istruzione per il dev)**

```bash
git add tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php
git commit -m "test(oc:8571): manuali e file originale arrivano sul sentiero"
```

---

### Task 8: verifica finale

- [ ] **Passo 1: suite del package**

Run: `docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest`
Atteso: PASS. Riporta al dev l'output completo in caso di fallimenti.

- [ ] **Passo 2: PHPStan**

Run: `docker exec -w /var/www/html/forestas/wm-package php-forestas composer analyse`
Atteso: nessun errore nuovo sui file toccati.

- [ ] **Passo 3: Pint solo sui file toccati** (mai `composer format` senza scope):

```bash
docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pint \
  src/Services/Models/EcTrackService.php src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php \
  src/TrailRegistry/Models/TrailApplication.php src/TrailRegistry/Nova/TrailApplication.php \
  src/Nova/AbstractGeometryResource.php tests/Pest.php tests/Feature/TrailRegistry tests/Unit/Services/EcTrackService
```

- [ ] **Passo 4: prova in Nova su Forestas locale** — crea un'istanza da un GPX, attendi il job sulla
  coda `dem` (Horizon), apri il dettaglio: tab DEM compilato, mappa del codice con legenda, link al
  file originale; apri la modifica: solo i nove valori manuali; scrivi «2:30» in un tempo e verifica
  l'errore di validazione.

> Ticket: oc:8660

# DEM automatico alla visualizzazione anche per EcTrack — Piano di implementazione

> **Per chi esegue:** sub-skill richiesta `superpowers:subagent-driven-development` (consigliata) o
> `superpowers:executing-plans`. I passi usano le checkbox (`- [ ]`).
>
> **In questo progetto i commit sono vietati durante l'esecuzione.** I blocchi `git commit` qui
> sotto sono istruzioni per il dev, da eseguire solo dopo il review-gate: chi esegue il piano
> scrive i file e basta.

**Obiettivo:** quando si apre il dettaglio Nova di una EcTrack o di una TrailApplication con il DEM
mancante (`dem_data` vuoto o quote tutte a zero), il ricalcolo parte da solo, con un lock di un'ora
per model.

**Architettura:** la logica sale nel padre `Models\Abstracts\MultiLineString`: `needsDem()`,
`acquireDemLock()`, `dispatchDemIfMissing()` e un `dispatchDem()` vuoto che ogni figlio ridefinisce.
TrailApplication accoda il suo job; EcTrack accoda una catena costruita da `EcTrackService` con le
liste già esistenti. Un trait Nova, chiamato esplicitamente nel `fields()` delle due Resource, fa
partire tutto solo sulla richiesta di dettaglio.

**Stack:** Laravel 12, Nova 5, PostgreSQL + PostGIS, Pest, Redis (cache store `redis` per il lock).

**Spec:** [overview.md](overview.md)

## Vincoli globali

- Repo: solo `wm-package`. Forestas non cambia nel codice.
- PHP minimo `>8.1`: **mai `const` dentro un trait**; le costanti stanno sulla classe.
- Le geometrie PostGIS si leggono e si scrivono in SQL puro, mai via ORM.
- Commenti e docblock in italiano, termini tecnici in inglese.
- Durata del lock: **3600 secondi**, uguale per EcTrack e TrailApplication.
- Esclusi dalla catena EcTrack: solo `SyncModelTaxonomyWhereJob`. `GenerateEcTrackPBFBatch` resta.
- Su un solo record la velocità non è un criterio: si sceglie la soluzione più corretta.
- Test solo sulla suite del package (database `wm_package`), dentro `php-forestas`, da
  `wm-package/`, **dopo** aver verificato l'isolamento (Task 0).

## Punti da guardare in review

1. **Geometria 2D o con la Z assente in alcuni punti:** una coordinata senza terza componente conta
   come quota zero. Coperto dal test "Z assente conta come zero" nel Task 2.
2. **Sentiero con qualche punto a quota 0 ma non tutti** (tratto sul mare): non deve ripartire.
   Coperto dal test "un solo punto sopra zero basta" nel Task 2.
3. **Un dettaglio aperto subito dopo la creazione:** non deve accodare una seconda catena. Coperto
   nei Task 2 e 3.
4. **Una Resource senza model `MultiLineString`** che usa per sbaglio il trait: non deve esplodere.
   Coperto dal test del trait nel Task 4.
5. **Una richiesta Nova che non è il dettaglio** (index, update, action): non deve accodare nulla.
   Coperto nel Task 4.

---

## Task 0: branch e isolamento dei test

**Files:** nessuno.

- [ ] **Passo 1: branch.** Il submodule è in detached HEAD. Il dev crea il branch dedicato:

```bash
cd /Users/bongiu/Documents/geobox2/forestas/wm-package
git checkout -b feature/oc-8660-dem-automatico-alla-visualizzazione-anche-per-ectrack
```

- [ ] **Passo 2: isolamento.** Verificare che la suite punti a `wm_package` e che il database esista:

```bash
grep -n 'DB_DATABASE' wm-package/phpunit.xml.dist        # atteso: value="wm_package"
ls wm-package/phpunit.xml 2>/dev/null                    # atteso: nessun file (avrebbe la precedenza)
docker exec postgres-forestas psql -U forestas -lqt | cut -d'|' -f1 | grep -w wm_package
```

Se anche uno dei tre controlli non torna, **fermarsi e chiedere al dev** prima di lanciare qualsiasi
test.

Comando di test usato in tutto il piano:

```bash
docker exec php-forestas sh -c 'cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter="<filtro>"'
```

---

## Task 1: lo store `redis` dei test diventa `array`

Il lock prende `Cache::store('redis')`. Nei test del package Redis non è raggiungibile (nessun
`REDIS_HOST` nel container): con il lock dentro `createDataChain()` ogni test che crea una EcTrack
fallirebbe. Oggi l'override esiste solo per `tests/Feature/TrailRegistry` in `tests/Pest.php`.

**Files:**
- Modify: `tests/TestCase.php` (`getEnvironmentSetUp()`)

- [ ] **Passo 1: aggiungere l'override** in `getEnvironmentSetUp()`, dopo le righe esistenti:

```php
        // Il lock del DEM (oc:8660) e i job con uniqueVia() usano lo store
        // `redis`: nei test Redis non c'e', e ogni EcTrack creata prende il
        // lock in createDataChain(). Uno store in memoria per test basta.
        $app['config']->set('cache.stores.redis.driver', 'array');
```

- [ ] **Passo 2: verificare che nulla si rompa** sui test che creano EcTrack e sul catasto:

Run: `vendor/bin/pest --filter="EcTrack|TrailApplication|UpdateDataChain"`
Atteso: PASS, stessi risultati di prima della modifica.

---

## Task 2: il padre `MultiLineString` e il passaggio di TrailApplication

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-il-padre-multilinestring-e-il-passaggio-di-trailapplication)

**Files:**
- Modify: `src/Models/Abstracts/MultiLineString.php`
- Modify: `src/TrailRegistry/Models/TrailApplication.php` (booted, rimozione di `needsDem()` e
  `dispatchDemIfMissing()`, nuovo `dispatchDem()`)
- Modify: `src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php` (`uniqueFor`)
- Create: `tests/Feature/MultiLineStringDemTest.php`
- Modify + rename: `tests/Feature/TrailRegistry/TrailApplicationDemTriggerTest.php` →
  `tests/Feature/TrailRegistry/TrailApplicationMapTest.php` (restano solo i tre test della mappa)

**Interfacce prodotte (usate dai Task 3 e 4):**
- `MultiLineString::DEM_LOCK_SECONDS = 3600` (costante di classe)
- `MultiLineString::needsDem(): bool`
- `MultiLineString::acquireDemLock(): bool`
- `MultiLineString::dispatchDemIfMissing(): void`
- `MultiLineString::dispatchDem(): void` (vuoto nel padre)

- [ ] **Passo 1: scrivere i test** in `tests/Feature/MultiLineStringDemTest.php`. I test DEM di
  `TrailApplicationDemTriggerTest.php` si spostano qui, riscritti sui nuovi helper. I nomi degli
  helper sono diversi da `applicationWithGeometry()`, che resta nel file della mappa: Pest carica
  tutti i file e due funzioni globali con lo stesso nome darebbero un fatal error.

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/*
 * Il DEM mancante si ricalcola all'apertura del dettaglio (oc:8660). La logica
 * sta nel padre MultiLineString: qui i casi valgono per ogni figlio che la
 * usa, TrailApplication ed EcTrack.
 */

beforeEach(function () {
    runTrailRegistryStubs();
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    Bus::fake([UpdateTrailApplicationDemJob::class]);
});

/**
 * Un'istanza con la geometria data e il lock della creazione gia' rilasciato,
 * cosi' il test osserva solo la chiamata che fa lui.
 */
function demTrailApplication(array $properties = [], string $wkt = 'MULTILINESTRING Z ((1 1 0, 2 2 0))'): TrailApplication
{
    $application = TrailApplication::factory()->create(['properties' => $properties]);
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        [$wkt, $application->id]
    );
    Cache::store('redis')->flush();
    Bus::fake([UpdateTrailApplicationDemJob::class]);

    return $application->fresh();
}

describe('TrailApplication', function () {
    it('accoda il job DEM alla creazione, dopo il commit', function () {
        // Sotto Bus::fake() il rinvio al commit non e' osservabile: si verifica
        // che il job sia marcato afterCommit, lo scarto al rollback lo fa il
        // dispatcher vero di Laravel.
        $application = TrailApplication::factory()->create();

        Bus::assertDispatched(
            UpdateTrailApplicationDemJob::class,
            fn ($job) => $job->applicationId === $application->id && $job->afterCommit === true
        );
    });

    it('serve il DEM con dem_data vuoto', function () {
        expect(demTrailApplication()->needsDem())->toBeTrue()
            ->and(demTrailApplication(['dem_data' => []])->needsDem())->toBeTrue();
    });

    it('serve il DEM con dem_data pieno e quote tutte a zero', function () {
        expect(demTrailApplication(['dem_data' => ['ascent' => 10]])->needsDem())->toBeTrue();
    });

    it('non serve il DEM con dem_data pieno e quote calcolate', function () {
        $application = demTrailApplication(['dem_data' => ['ascent' => 10]], 'MULTILINESTRING Z ((1 1 120, 2 2 140))');

        expect($application->needsDem())->toBeFalse();
    });

    it('un solo punto sopra zero basta: un tratto sul mare ha quote vere a zero', function () {
        $application = demTrailApplication(['dem_data' => ['ascent' => 10]], 'MULTILINESTRING Z ((1 1 0, 2 2 35))');

        expect($application->needsDem())->toBeFalse();
    });

    it('non serve il DEM con geometria non valida', function () {
        // Una linea con un solo punto distinto: ST_IsValid la rifiuta.
        $application = demTrailApplication([], 'MULTILINESTRING Z ((1 1 0, 1 1 0))');

        expect($application->needsDem())->toBeFalse();
    });

    it('non serve il DEM senza geometria', function () {
        $application = demTrailApplication();
        DB::statement('UPDATE trail_applications SET geometry = NULL WHERE id = ?', [$application->id]);

        expect($application->fresh()->needsDem())->toBeFalse();
    });

    it('rilancia il job dove il DEM manca', function () {
        demTrailApplication()->dispatchDemIfMissing();

        Bus::assertDispatched(UpdateTrailApplicationDemJob::class);
    });

    it('non rilancia il job dove il DEM c e', function () {
        demTrailApplication(['dem_data' => ['ascent' => 10]], 'MULTILINESTRING Z ((1 1 120, 2 2 140))')
            ->dispatchDemIfMissing();

        Bus::assertNotDispatched(UpdateTrailApplicationDemJob::class);
    });

    it('due aperture di fila accodano un solo job', function () {
        $application = demTrailApplication();

        $application->dispatchDemIfMissing();
        $application->dispatchDemIfMissing();

        Bus::assertDispatchedTimes(UpdateTrailApplicationDemJob::class, 1);
    });

    it('il dettaglio aperto subito dopo la creazione non accoda un secondo job', function () {
        $application = TrailApplication::factory()->create();
        DB::statement(
            'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
            ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]
        );

        $application->fresh()->dispatchDemIfMissing();

        Bus::assertDispatchedTimes(UpdateTrailApplicationDemJob::class, 1);
    });
});
```

- [ ] **Passo 2: lanciarli e vederli fallire**

Run: `vendor/bin/pest --filter="MultiLineStringDemTest"`
Atteso: FAIL. Il test sulle quote a zero con `dem_data` pieno fallisce (oggi `needsDem()` guarda
solo `dem_data`), e quello "dopo la creazione" fallisce perché la creazione non prende il lock.

- [ ] **Passo 3: il padre.** Sostituire `src/Models/Abstracts/MultiLineString.php` con:

```php
<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Traits\HasPackageFactory;

abstract class MultiLineString extends GeometryModel
{
    use HasPackageFactory;

    /**
     * Quanto dura il lock del ricalcolo DEM di un model (oc:8660): una
     * traccia che il servizio DEM non riesce a calcolare riprova al massimo
     * una volta in questo intervallo, non a ogni apertura del dettaglio.
     */
    public const DEM_LOCK_SECONDS = 3600;

    /**
     * Serve il DEM se la geometria e' valida e manca il dato: `dem_data`
     * vuoto, oppure quote tutte a zero, che e' la geometria come arriva da
     * un file senza quota prima che il nostro DEM la riempia.
     *
     * La validita' la giudica PostGIS, come faceva l'istanza del catasto
     * (oc:8571): una geometria rotta farebbe partire un job destinato a
     * fallire. La stessa query restituisce il GeoJSON su cui controllare le Z.
     */
    public function needsDem(): bool
    {
        $row = DB::selectOne(
            "SELECT ST_AsGeoJSON(geometry) AS geojson
             FROM {$this->getTable()}
             WHERE id = ?
               AND geometry IS NOT NULL
               AND ST_IsValid(geometry::geometry)
               AND NOT ST_IsEmpty(geometry::geometry)",
            [$this->getKey()],
        );

        if ($row === null) {
            return false;
        }

        if (empty($this->properties['dem_data'] ?? null)) {
            return true;
        }

        return $this->hasOnlyZeroElevations(json_decode($row->geojson, true) ?? []);
    }

    /**
     * Prende il lock del ricalcolo DEM di questo model. Vale anche per la
     * creazione: il dettaglio che Nova apre subito dopo trova il lock preso e
     * non accoda una seconda catena in parallelo alla prima (oc:8660).
     *
     * Il lock sta qui e non nei job: Bus::chain() non rispetta ShouldBeUnique,
     * perche' PendingChain::dispatch() passa dal Dispatcher e non da
     * PendingDispatch, dove il controllo vive.
     */
    public function acquireDemLock(): bool
    {
        return Cache::store('redis')->add(
            'dem-lock:'.$this->getTable().':'.$this->getKey(),
            true,
            self::DEM_LOCK_SECONDS,
        );
    }

    public function dispatchDemIfMissing(): void
    {
        if ($this->needsDem() && $this->acquireDemLock()) {
            $this->dispatchDem();
        }
    }

    /**
     * Cosa accodare per ricalcolare il DEM: lo decide ogni figlio. Qui non fa
     * nulla, e non e' abstract, perche' non tutti i figli lo usano ancora
     * (UgcTrack).
     */
    public function dispatchDem(): void {}

    /**
     * Una coordinata senza terza componente conta come quota zero.
     */
    protected function hasOnlyZeroElevations(array $geometry): bool
    {
        foreach ($geometry['coordinates'] ?? [] as $line) {
            foreach ($line as $point) {
                if ((float) ($point[2] ?? 0) !== 0.0) {
                    return false;
                }
            }
        }

        return true;
    }
}
```

- [ ] **Passo 4: TrailApplication.** In `src/TrailRegistry/Models/TrailApplication.php`:

  - in `booted()`, prendere il lock prima del dispatch:

```php
        static::created(function (TrailApplication $application) {
            // Il lock si prende anche qui: il dettaglio che Nova apre subito
            // dopo non deve accodare un secondo job (oc:8660).
            $application->acquireDemLock();
            UpdateTrailApplicationDemJob::dispatch($application->id)->afterCommit();
        });
```

  - rimuovere i metodi `needsDem()` e `dispatchDemIfMissing()` (righe 101-122 circa, con il
    docblock) e al loro posto mettere:

```php
    public function dispatchDem(): void
    {
        UpdateTrailApplicationDemJob::dispatch($this->id);
    }
```

  - togliere `use Illuminate\Support\Facades\DB;` solo se non è più usato nel file (controllare
    con `grep -n 'DB::' src/TrailRegistry/Models/TrailApplication.php`).

- [ ] **Passo 5: il lock del job.** In `src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php`:

```php
    public int $uniqueFor = MultiLineString::DEM_LOCK_SECONDS;
```

con `use Wm\WmPackage\Models\Abstracts\MultiLineString;` e il docblock di `$uniqueFor` aggiornato:
"stessa durata del lock del ricalcolo alla visualizzazione (oc:8660)".

- [ ] **Passo 6: il file vecchio.** Da `tests/Feature/TrailRegistry/TrailApplicationDemTriggerTest.php`
  togliere i cinque test DEM (`accoda il job DEM alla creazione`, `accoda il job DEM dopo il
  commit`, `serve il DEM solo con…`, `non rilancia…`, `rilancia…`) e gli `use` di
  `Bus` e `UpdateTrailApplicationDemJob` rimasti inutilizzati. Restano `applicationWithGeometry()`
  e i tre test della mappa. Rinominare il file, perché non parla più del DEM:

```bash
git mv tests/Feature/TrailRegistry/TrailApplicationDemTriggerTest.php tests/Feature/TrailRegistry/TrailApplicationMapTest.php
```

- [ ] **Passo 7: lanciarli e vederli passare**

Run: `vendor/bin/pest --filter="MultiLineStringDemTest|TrailApplicationMapTest|TrailRegistry"`
Atteso: PASS.

- [ ] **Passo 8: commit (istruzione per il dev)**

```bash
git add src/Models/Abstracts/MultiLineString.php src/TrailRegistry/Models/TrailApplication.php \
  src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php tests/TestCase.php \
  tests/Feature/MultiLineStringDemTest.php tests/Feature/TrailRegistry/
git commit -m "feat(oc:8660): ricalcolo DEM mancante nel padre MultiLineString"
```

---

## Task 3: EcTrack

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-ectrack)

**Files:**
- Modify: `src/Services/Models/EcTrackService.php` (`createDataChain()`, nuovo `dispatchDemChain()`)
- Modify: `src/Models/EcTrack.php` (nuovo `dispatchDem()`)
- Modify: `tests/Feature/MultiLineStringDemTest.php` (blocco `describe('EcTrack')`)

**Interfacce:**
- Consuma: `MultiLineString::needsDem()`, `acquireDemLock()`, `dispatchDemIfMissing()` (Task 2)
- Produce: `EcTrackService::dispatchDemChain(EcTrack $track): void`, `EcTrack::dispatchDem(): void`

- [ ] **Passo 1: scrivere i test**, in coda a `tests/Feature/MultiLineStringDemTest.php`, con gli
  `use` in testa al file:

```php
use Wm\WmPackage\Jobs\Pbf\GenerateEcTrackPBFBatch;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrack3DDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAppRelationsInfoJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackCurrentDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackGenerateElevationChartImage;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackManualDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackOrderRelatedPoi;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackSlopeValues;
use Wm\WmPackage\Models\EcTrack;
```

```php
/**
 * Una EcTrack con la geometria data e il lock della creazione gia' rilasciato.
 * Bus::fake() prima della creazione: senza, l'observer eseguirebbe la catena
 * vera, che chiama il servizio DEM.
 */
function demEcTrack(array $properties = [], string $wkt = 'MULTILINESTRING Z ((1 1 0, 2 2 0))'): EcTrack
{
    Bus::fake();
    $track = EcTrack::factory()->create(['osmid' => null, 'properties' => $properties]);
    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        [$wkt, $track->id]
    );
    Cache::store('redis')->flush();
    Bus::fake();

    return $track->fresh();
}

describe('EcTrack', function () {
    it('serve il DEM con dem_data vuoto', function () {
        expect(demEcTrack()->needsDem())->toBeTrue();
    });

    it('serve il DEM con dem_data pieno e quote tutte a zero', function () {
        expect(demEcTrack(['dem_data' => ['ascent' => 10]])->needsDem())->toBeTrue();
    });

    it('non serve il DEM con dem_data pieno e quote calcolate', function () {
        expect(demEcTrack(['dem_data' => ['ascent' => 10]], 'MULTILINESTRING Z ((1 1 120, 2 2 140))')->needsDem())
            ->toBeFalse();
    });

    it('Z assente conta come zero', function () {
        // ST_AsGeoJSON di una geometria 2D emette due sole coordinate.
        $track = demEcTrack(['dem_data' => ['ascent' => 10]]);

        expect((fn () => $this->hasOnlyZeroElevations([
            'type' => 'MultiLineString',
            'coordinates' => [[[1, 1], [2, 2]]],
        ]))->call($track))->toBeTrue();
    });

    it('non serve il DEM con geometria non valida', function () {
        expect(demEcTrack([], 'MULTILINESTRING Z ((1 1 0, 1 1 0))')->needsDem())->toBeFalse();
    });

    it('accoda la catena del DEM, col PBF e senza la taxonomy where', function () {
        demEcTrack()->dispatchDemIfMissing();

        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackManualDataJob::class,
            UpdateEcTrackCurrentDataJob::class,
            UpdateEcTrack3DDemJob::class,
            UpdateEcTrackSlopeValues::class,
            UpdateEcTrackGenerateElevationChartImage::class,
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
        Bus::assertNotDispatched(SyncModelTaxonomyWhereJob::class);
    });

    it('non accoda nulla dove il DEM c e', function () {
        demEcTrack(['dem_data' => ['ascent' => 10]], 'MULTILINESTRING Z ((1 1 120, 2 2 140))')
            ->dispatchDemIfMissing();

        Bus::assertNothingDispatched();
    });

    it('due aperture di fila accodano una sola catena', function () {
        $track = demEcTrack();

        $track->dispatchDemIfMissing();
        $track->dispatchDemIfMissing();

        Bus::assertDispatchedTimes(UpdateEcTrackDemJob::class, 1);
    });

    it('il dettaglio aperto subito dopo la creazione non accoda una seconda catena', function () {
        Bus::fake();
        $track = EcTrack::factory()->create(['osmid' => null, 'properties' => []]);

        $track->fresh()->dispatchDemIfMissing();

        // Solo la catena della creazione: createDataChain() ha preso il lock.
        Bus::assertDispatchedTimes(UpdateEcTrackDemJob::class, 1);
    });
});
```

- [ ] **Passo 2: lanciarli e vederli fallire**

Run: `vendor/bin/pest --filter="MultiLineStringDemTest"`
Atteso: FAIL sui test della catena (EcTrack non ridefinisce `dispatchDem()`) e su quello "dopo la
creazione" (`createDataChain()` non prende il lock). I test su `needsDem()` passano già: la logica
è nel padre.

- [ ] **Passo 3: il service.** In `src/Services/Models/EcTrackService.php`:

  - in testa a `createDataChain()`:

```php
    public function createDataChain(EcTrack $track)
    {
        // Il dettaglio che Nova apre subito dopo la creazione trova il lock
        // preso e non accoda una seconda catena in parallelo (oc:8660).
        $track->acquireDemLock();

        $chain = [];
```

  - subito dopo `publicationJobs()`, il metodo nuovo:

```php
    /**
     * La catena del DEM mancante, accodata all'apertura del dettaglio
     * (oc:8660): i job che dipendono dalla geometria, poi la pubblicazione.
     * Fuori solo la taxonomy where, che dipende dalla forma della traccia e
     * non dalle quote. Il PBF resta: le tile leggono distanza e durate anche
     * da `dem_data`.
     */
    public function dispatchDemChain(EcTrack $track): void
    {
        Bus::chain([
            ...$this->geometryDependentJobs($track, [SyncModelTaxonomyWhereJob::class]),
            ...$this->publicationJobs($track),
        ])->dispatch();
    }
```

- [ ] **Passo 4: il model.** In `src/Models/EcTrack.php`, dopo `booted()`:

```php
    public function dispatchDem(): void
    {
        EcTrackService::make()->dispatchDemChain($this);
    }
```

(`EcTrackService` è già importato nel file.)

- [ ] **Passo 5: lanciarli e vederli passare, più le catene esistenti**

Run: `vendor/bin/pest --filter="MultiLineStringDemTest|UpdateDataChain|ExecuteEcTrackDataChain|Reverse|EcTrack"`
Atteso: PASS.

- [ ] **Passo 6: commit (istruzione per il dev)**

```bash
git add src/Services/Models/EcTrackService.php src/Models/EcTrack.php tests/Feature/MultiLineStringDemTest.php
git commit -m "feat(oc:8660): ricalcolo DEM mancante su EcTrack"
```

---

## Task 4: il trait Nova e le due Resource

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-il-trait-nova-e-le-due-resource)

**Files:**
- Create: `src/Nova/Traits/DispatchesDemOnDetail.php`
- Modify: `src/Nova/EcTrack.php` (`fields()`)
- Modify: `src/TrailRegistry/Nova/TrailApplication.php` (`fields()`, righe 146-150 circa)
- Create: `tests/Feature/Nova/DispatchesDemOnDetailTest.php`

**Interfacce:**
- Consuma: `MultiLineString::dispatchDemIfMissing()` (Task 2)
- Produce: `DispatchesDemOnDetail::dispatchDemOnDetail(NovaRequest $request): void`

- [ ] **Passo 1: scrivere il test** in `tests/Feature/Nova/DispatchesDemOnDetailTest.php`:

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\ResourceDetailRequest;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Traits\DispatchesDemOnDetail;

/**
 * Una Resource finta che espone il trait: basta un oggetto con $resource.
 */
function demOnDetailResource(mixed $model): object
{
    return new class($model)
    {
        use DispatchesDemOnDetail;

        public function __construct(public mixed $resource) {}

        public function run(NovaRequest $request): void
        {
            $this->dispatchDemOnDetail($request);
        }
    };
}

beforeEach(function () {
    Bus::fake();
    $this->track = EcTrack::factory()->create(['osmid' => null, 'properties' => []]);
    Cache::store('redis')->flush();
    Bus::fake();
});

it('sul dettaglio accoda il DEM mancante', function () {
    demOnDetailResource($this->track->fresh())->run(ResourceDetailRequest::create('/'));

    Bus::assertDispatched(UpdateEcTrackDemJob::class);
});

it('fuori dal dettaglio non accoda nulla', function () {
    demOnDetailResource($this->track->fresh())->run(NovaRequest::create('/'));

    Bus::assertNothingDispatched();
});

it('su un model che non e una MultiLineString non fa nulla', function () {
    demOnDetailResource(new EcPoi)->run(ResourceDetailRequest::create('/'));

    Bus::assertNothingDispatched();
});
```

- [ ] **Passo 2: lanciarlo e vederlo fallire**

Run: `vendor/bin/pest --filter="DispatchesDemOnDetailTest"`
Atteso: FAIL con "Trait ... DispatchesDemOnDetail not found".

- [ ] **Passo 3: il trait** `src/Nova/Traits/DispatchesDemOnDetail.php`:

```php
<?php

namespace Wm\WmPackage\Nova\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Abstracts\MultiLineString;

/**
 * Ricalcola il DEM mancante quando si apre il dettaglio (oc:8660).
 *
 * Va chiamato esplicitamente nel fields() della Resource, non messo nel
 * padre di tutte le Resource geometriche: fields() gira anche per
 * validazione e azioni, e un effetto nascosto li' e' difficile da trovare.
 */
trait DispatchesDemOnDetail
{
    protected function dispatchDemOnDetail(NovaRequest $request): void
    {
        if ($request->isResourceDetailRequest() && $this->resource instanceof MultiLineString) {
            $this->resource->dispatchDemIfMissing();
        }
    }
}
```

- [ ] **Passo 4: la Resource EcTrack.** In `src/Nova/EcTrack.php` aggiungere
  `use Wm\WmPackage\Nova\Traits\DispatchesDemOnDetail;`, il trait nella riga `use` della classe, e
  in `fields()`:

```php
    public function fields(NovaRequest $request): array
    {
        $this->dispatchDemOnDetail($request);

        return [
            ...$this->fieldsTrait($request),
```

- [ ] **Passo 5: la Resource TrailApplication.** In `src/TrailRegistry/Nova/TrailApplication.php`
  aggiungere il trait (import e `use` della classe accanto a `HidesWhenTrailRegistryDisabled,
  ResolvesCanonicalResources`) e sostituire il blocco:

```php
        if ($request->isResourceDetailRequest()) {
            // Il DEM manca solo se il job alla creazione e' fallito: lo si
            // rilancia qui, e solo dove serve (oc:8571).
            $this->resource->dispatchDemIfMissing();
        }
```

con:

```php
        // Il DEM manca se il job alla creazione e' fallito o se la geometria
        // e' senza quote: lo si rilancia qui, e solo dove serve (oc:8571, oc:8660).
        $this->dispatchDemOnDetail($request);
```

- [ ] **Passo 6: lanciarli e vederli passare**

Run: `vendor/bin/pest --filter="DispatchesDemOnDetailTest|MultiLineStringDemTest|TrailRegistry"`
Atteso: PASS.

- [ ] **Passo 7: commit (istruzione per il dev)**

```bash
git add src/Nova/Traits/DispatchesDemOnDetail.php src/Nova/EcTrack.php \
  src/TrailRegistry/Nova/TrailApplication.php tests/Feature/Nova/DispatchesDemOnDetailTest.php
git commit -m "feat(oc:8660): DEM mancante ricalcolato all'apertura del dettaglio Nova"
```

---

## Task 5: verifica finale

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-verifica-finale)

- [ ] **Passo 1: la suite del package intera**

Run: `docker exec php-forestas sh -c 'cd /var/www/html/forestas/wm-package && vendor/bin/pest'`
Atteso: PASS, o solo i fallimenti già presenti prima del lavoro (confrontare con un run su
`develop` se ne compaiono).

- [ ] **Passo 2: PHPStan**

Run: `docker exec php-forestas sh -c 'cd /var/www/html/forestas/wm-package && composer analyse'`
Atteso: nessun errore nei file toccati.

- [ ] **Passo 3: Pint solo sui file toccati** (mai `composer format` senza scope)

```bash
docker exec php-forestas sh -c 'cd /var/www/html/forestas/wm-package && vendor/bin/pint \
  src/Models/Abstracts/MultiLineString.php src/Models/EcTrack.php \
  src/Services/Models/EcTrackService.php src/Nova/EcTrack.php src/Nova/Traits/DispatchesDemOnDetail.php \
  src/TrailRegistry/Models/TrailApplication.php src/TrailRegistry/Nova/TrailApplication.php \
  src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php tests/TestCase.php \
  tests/Feature/MultiLineStringDemTest.php tests/Feature/Nova/DispatchesDemOnDetailTest.php \
  tests/Feature/TrailRegistry/TrailApplicationMapTest.php'
```

- [ ] **Passo 4: prova a mano su Forestas.** Aprire in Nova il dettaglio di una EcTrack senza DEM
  (per esempio la 118, che in locale ha le quote a zero), attendere che Horizon esegua la catena e
  ricaricare: il tab DEM mostra i valori e la mappa ha le quote. Riaprire il dettaglio: in Horizon
  non compare una seconda catena.

> Ticket: oc:9050
> Stato: seguito, con 2 divergenze annotate in notes.md

# Catene di EcTrack senza elevation chart e con pubblicazione di ripiego — piano

> **Per chi esegue:** usare `superpowers:executing-plans` o `superpowers:subagent-driven-development`,
> un task per volta. **Nessun commit durante l'esecuzione**: le righe «Commit» sono il testo da usare
> quando il dev farà il commit.

**Obiettivo:** le catene di EcTrack non accodano più `UpdateEcTrackGenerateElevationChartImage`, e se
un job fallisce accodano comunque PBF (dove c'è la geometria) e pubblicazione.

**Architettura:** tutto in `src/Services/Models/EcTrackService.php`. Le quattro catene passano da un
metodo unico che aggiunge un `->catch()` con closure `static`; la closure cattura solo l'id della
traccia e i nomi delle classi da accodare, e chiama un metodo pubblico del service che ricostruisce i
job.

**Tech stack:** Laravel 12 (`Bus::chain`, `PendingChain::catch`), Pest/PHPUnit.

**Spec:** [overview.md](overview.md)

## Vincoli globali

- PHP minimo `>8.1`: niente `const` nei trait.
- Identificatori in inglese, commenti in italiano.
- La classe `UpdateEcTrackGenerateElevationChartImage` non si cancella ([R1](overview.md#r1)).
- La closure del `catch()` è `static` e non cattura `$this` né il modello: solo `int` e `array` di
  stringhe. È serializzata nel payload di ogni catena.
- I test del package girano col comando del `CLAUDE.md` dello shard:
  `docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest <test>`
  (da `/Users/peco/Documents/BackEnd/camminiditalia`).

## Punti da guardare in review

1. **Traccia cancellata prima del `catch()`**: `dispatchFallbackJobs()` non trova il modello e non
   accoda niente, senza eccezioni (test in Task 2).
2. **Catena di creazione**: il ripiego accoda solo `UpdateEcTrackAwsJob` e
   `UpdateEcTrackOrderRelatedPoi`, come la creazione riuscita; niente PBF né
   `UpdateEcTrackAppRelationsInfoJob` (test in Task 2).
3. **Inversione senza geometria** (solo scambi): il ripiego è la catena stessa, `GenerateEcTrackPBFBatch`
   e `UpdateEcTrackAwsJob` (test in Task 2).
4. **Payload serializzabile**: `serialize()` del primo job della catena non lancia (test in Task 2).
5. **Successo invariato** ([R3](overview.md#r3)): gli `assertChained` esistenti, tolto solo
   l'elevation chart, restano veri (Task 1).

---

### Task 1: elevation chart fuori dalle catene

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-elevation-chart-fuori-dalle-catene)

Requisiti: [R1](overview.md#r1), [R3](overview.md#r3)

**File:**
- Modifica: `src/Services/Models/EcTrackService.php` — `createDataChain()` (riga ~366) e
  `geometryDependentJobs()` (riga ~401)
- Test: `tests/Unit/Services/EcTrackService/UpdateDataChainTest.php`,
  `tests/Unit/Services/EcTrackService/ReverseTest.php`, `tests/Feature/MultiLineStringDemTest.php`,
  `tests/Feature/OrderListReorderTest.php`

**Interfacce:**
- Produce: `geometryDependentJobs()` e `createDataChain()` senza `UpdateEcTrackGenerateElevationChartImage`;
  firme invariate.

- [ ] **Passo 1:** nei quattro test, togli `UpdateEcTrackGenerateElevationChartImage::class` dalle liste
  attese di `Bus::assertChained` e aggiungi dove serve
  `Bus::assertNotDispatched(UpdateEcTrackGenerateElevationChartImage::class)`. Aggiungi in
  `UpdateDataChainTest` il test `test_create_data_chain_does_not_include_elevation_chart` che chiama
  `createDataChain()` e asserisce la catena `[UpdateEcTrackDemJob, UpdateEcTrackManualDataJob,
  UpdateEcTrackCurrentDataJob, UpdateEcTrack3DDemJob, UpdateEcTrackSlopeValues,
  SyncModelTaxonomyWhereJob, UpdateEcTrackAwsJob, UpdateEcTrackOrderRelatedPoi]`.
- [ ] **Passo 2:** lancia i quattro file: falliscono, perché il job c'è ancora.
- [ ] **Passo 3:** togli il job da `createDataChain()` e da `geometryDependentJobs()`; se l'`use` della
  classe resta inutilizzato in `EcTrackService.php`, toglilo. Commento in italiano sopra la lista:
  il job è fuori dal flusso per oc:9050 (`canvas` non si carica con Node 20), la classe resta.
- [ ] **Passo 4:** rilancia i quattro file: passano.
- [ ] **Passo 5:** lascia invariato il `Bus::fake` di `tests/Pest.php` che cita la classe: serve a chi
  la accoda a mano.
- [ ] **Commit:** `fix(oc:9050): immagine dell'elevation chart fuori dalle catene delle EcTrack`

### Task 2: pubblicazione di ripiego quando un job della catena fallisce

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-pubblicazione-di-ripiego-quando-un-job-della-catena-fallisce)

Requisiti: [R2](overview.md#r2), [R3](overview.md#r3)

**File:**
- Modifica: `src/Services/Models/EcTrackService.php` — `createDataChain()`, `dispatchDemChain()`,
  `reverse()` (blocco `DB::afterCommit`, riga ~557), `updateDataChain()` (riga ~669)
- Crea: `tests/Unit/Services/EcTrackService/ChainFallbackTest.php` (estende
  `AbstractEcTrackServiceTest`, `RefreshDatabase`, `Bus::fake()` nel `setUp()`)

**Interfacce:**
- Consuma: `publicationJobs(EcTrack $track): array` (esistente).
- Produce:
  - `protected function dispatchChainWithFallback(EcTrack $track, array $chain, array $fallbackJobClasses): void`
    — `Bus::chain($chain)->catch(static function (Throwable $e) use ($trackId, $fallbackJobClasses): void { EcTrackService::make()->dispatchFallbackJobs($trackId, $fallbackJobClasses); })->dispatch()`.
  - `public function dispatchFallbackJobs(int $trackId, array $jobClasses): void` — rilegge la traccia
    con `config('wm-package.ec_track_model')::find($trackId)`; se manca ritorna; altrimenti
    `Bus::chain(array_map(fn ($class) => new $class($track), $jobClasses))->dispatch()`, **senza**
    `catch()`.
  - `public function fallbackJobClasses(EcTrack $track, bool $withPbf): array` — `GenerateEcTrackPBFBatch::class`
    se `$withPbf`, poi le classi di `publicationJobs($track)`.

Ripiego per catena:

| Catena | `$fallbackJobClasses` |
|---|---|
| `updateDataChain()` con geometria cambiata | `fallbackJobClasses($track, true)` |
| `updateDataChain()` senza geometria | `fallbackJobClasses($track, false)` |
| `dispatchDemChain()` | `fallbackJobClasses($track, true)` |
| `reverse()` con geometria | `fallbackJobClasses($track, true)` |
| `reverse()` solo scambi | `[GenerateEcTrackPBFBatch::class, UpdateEcTrackAwsJob::class]` |
| `createDataChain()` | `[UpdateEcTrackAwsJob::class, UpdateEcTrackOrderRelatedPoi::class]` |

- [ ] **Passo 1:** scrivi `ChainFallbackTest` con questi test. Per ognuno: lancia la catena, prendi il
  primo job con `Bus::dispatched(<prima classe>)->first()`, asserisci
  `count($job->chainCatchCallbacks) === 1`, poi `Bus::fake()` di nuovo,
  `$job->invokeChainCatchCallbacks(new RuntimeException('boom'))` e `Bus::assertChained([...])` con la
  riga della tabella.
  - `test_update_chain_with_geometry_falls_back_to_pbf_and_publication` → `[GenerateEcTrackPBFBatch, UpdateEcTrackAwsJob, UpdateEcTrackAppRelationsInfoJob, UpdateEcTrackOrderRelatedPoi]`
  - `test_update_chain_without_geometry_falls_back_to_publication` → `[UpdateEcTrackAwsJob, UpdateEcTrackAppRelationsInfoJob, UpdateEcTrackOrderRelatedPoi]`
  - `test_dem_chain_falls_back_to_pbf_and_publication` → come il primo
  - `test_create_chain_falls_back_to_its_own_publication` → `[UpdateEcTrackAwsJob, UpdateEcTrackOrderRelatedPoi]`
  - `test_reverse_swap_only_falls_back_to_pbf_and_aws` → `[GenerateEcTrackPBFBatch, UpdateEcTrackAwsJob]`
    (per `reverse()` segui come `ReverseTest` attiva `DB::afterCommit`)
  - `test_fallback_does_nothing_when_track_was_deleted`: `dispatchFallbackJobs(999999, [...])` →
    `Bus::assertNothingDispatched()`
  - `test_fallback_chain_has_no_catch_callback`: dopo il ripiego, il primo job accodato ha
    `chainCatchCallbacks` vuoto (niente loop)
  - `test_chain_with_fallback_is_serializable`: `serialize($job)` sul primo job non lancia
- [ ] **Passo 2:** lancia `ChainFallbackTest`: fallisce (nessun `catch()`).
- [ ] **Passo 3:** implementa i tre metodi e fai passare le quattro catene da
  `dispatchChainWithFallback()` con i ripieghi della tabella. Docblock in italiano su
  `dispatchChainWithFallback()`: perché il ripiego (oc:9050), che il job fallito resta in
  `failed_jobs`, che il ripiego non ha `catch()` per non andare in loop.
- [ ] **Passo 4:** rilancia `ChainFallbackTest` e i quattro file del Task 1: passano.
- [ ] **Passo 5:** lancia l'intera suite del package: nessun test nuovo rosso rispetto a `develop`.
- [ ] **Commit:** `fix(oc:9050): le catene delle EcTrack pubblicano anche quando un job fallisce`

### Task 3: documentazione

Requisiti: [R1](overview.md#r1), [R2](overview.md#r2)

**File:**
- Modifica: `.claude/rules/job-e-import.md` — la riga su `Bus::chain()` che si ferma al primo job
  che fallisce: aggiungi che le catene di EcTrack hanno il ripiego in
  `EcTrackService::dispatchChainWithFallback()` (oc:9050).

- [ ] **Passo 1:** aggiorna la riga.
- [ ] **Commit:** insieme al Task 2.

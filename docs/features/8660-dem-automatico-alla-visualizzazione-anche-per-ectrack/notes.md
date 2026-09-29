> Ticket: oc:8660

# Notes — DEM automatico alla visualizzazione anche per EcTrack

## Divergenze dal piano, task per task

### Task 2 il padre MultiLineString e il passaggio di TrailApplication

- L'helper `demTrailApplication()` libera il lock con `app('cache')->forgetDriver('redis')` e non
  con `Cache::store('redis')->flush()`, come scritto nel piano. Su `ArrayStore`, `flush()` svuota
  solo `$storage`, mentre il lock di `ShouldBeUnique` del job preso alla creazione vive in `$locks`
  e sopravvive. È la stessa tecnica che usava già il vecchio `TrailApplicationDemTriggerTest.php`.
- Il file vecchio è stato rinominato in `TrailApplicationMapTest.php` con un `mv` semplice e non
  con `git mv`: durante l'esecuzione non si fa staging. Al commit git riconosce il rename.

### Task 3 EcTrack

- Anche `demEcTrack()` usa `forgetDriver('redis')`, per coerenza con il Task 2.
- Il blocco `describe('EcTrack')` ha un `beforeEach` che il piano non prevedeva: imposta
  `wm-package.shard_name`, `Storage::fake('s3')`/`Storage::fake('wmfe')`, chiavi S3 fittizie e
  `medialibrary.disk_name`. Senza questo setup `EcTrack::factory()->create()` fallisce su
  `StorageService::getShardName()`. Lo stesso setup è già presente in `GetUpdatedAtTrackTest` e in
  `ExecuteEcTrackDataChainActionTest`.

### Task 4 il trait Nova e le due Resource

- In `DispatchesDemOnDetail` il controllo `instanceof MultiLineString` porta
  `@phpstan-ignore instanceof.alwaysTrue`, con un commento. Nel contesto delle Resource attuali
  PHPStan lo considera sempre vero, ma il trait è generico: il controllo protegge una Resource
  futura che lo usasse su un altro model ed è coperto da un test.
- Anche `DispatchesDemOnDetailTest` contiene il setup shard/storage del Task 3.

### Task 5 verifica finale

- La review finale di tutto il branch ha trovato un punto che né l'overview né il piano
  coprivano. Quando la geometria viene modificata, `EcTrackObserver::updated()` →
  `updateDataChain()` accoda di nuovo i job DEM senza prendere il lock; Nova poi reindirizza al
  dettaglio, che trova il lock libero e accoda una seconda catena in parallelo. `reverse()` ha lo
  stesso problema quando inverte la geometria. Correzione: il lock si prende anche in
  `updateDataChain()`, nel ramo `wasChanged('geometry')`, e in `reverse()` quando `$geometry` è
  vero.
- Nella stessa correzione `needsDem()` usa `$this->getConnection()` e `$this->getKeyName()`, al
  posto della connessione di default e della colonna `id` scritta nel codice.

## Bug trovati

- `vendor/bin/pest` lanciato senza percorsi fallisce su tutta la suite del package
  (`Tests\TestCase not found`): è colpa di test con un namespace che l'autoload-dev non mappa, per
  esempio `AddSurnameToUsersMigrationTest.php`. Il problema esiste già prima di questo lavoro, che
  non lo tocca. I test vanno lanciati per file o per cartella, un percorso per comando.
- Fallimenti già presenti prima di questo lavoro, verificati uno per uno sulla causa:
  - `ExecuteEcTrackDataChainActionTest` (8): `Class "App\Models\EcTrack" not found`, perché
    `config('wm-package.ec_track_model')` punta a una classe che sotto Testbench non esiste.
  - `EcTrackColorProvenanceTest` (4): `LayerFactory` legge un `id` su null e
    `getShardName()` restituisce null.
  - `UpdateDataChainTest` (1): "The expected chain was not dispatched". Falliva già prima che il
    lock fosse aggiunto.
- PHPStan sul package conta 970 errori preesistenti, e la baseline ne contiene solo 3.

## Decisioni

- La chiave del lock è `dem-lock:<tabella>:<id>` e non "classe e id", come dice l'overview: in
  questo modo `App\Models\EcTrack` e `Wm\WmPackage\Models\EcTrack`, che sono la stessa riga,
  condividono il lock.
- Il lock alla creazione può ritardare fino a un'ora il recupero se il primo job DEM fallisce
  (servizio DEM giù): il dettaglio non lo rilancia finché il lock non scade. È coerente con la
  scelta di un'ora uguale per tutti i model. Se servirà, la mitigazione è liberare il lock nel
  `failed()` dei job DEM.
- `tests/TestCase.php` imposta lo store `redis` su `array` per tutta la suite. Gli override
  locali in `tests/Pest.php`, `EcPoiAppRelationTest`, `LayerMediaObserverTest` ed
  `EcDescriptionTiptapTest` diventano ridondanti, ma restano: toglierli non rientra in questo
  lavoro.
- `build/report.junit.xml`, che è versionato, viene rigenerato da Pest a ogni run: va riportato
  allo stato di HEAD prima del commit.

- **Bypass PHPStan al review-gate** (2026-09-29 11:39, confermato esplicitamente dal dev, che ne ha la
  responsabilità): 15 errori preesistenti sui file toccati, nessuno sulle righe modificate o
  aggiunte (verificato incrociando le righe del diff con l'output di PHPStan); totale del package
  sceso da 970 a 969.

## Follow-up

- Un helper di test condiviso per il setup shard/storage delle EcTrack, che oggi è copiato in
  cinque file.
- Liberare il lock DEM nel `failed()` dei job DEM, se il ritardo di un'ora dopo un fallimento
  diventa un problema.
- Applicare il meccanismo a `UgcTrack` (ticket separato).
- Ricalcolo in massa delle tracce senza DEM, al go-live di Forestas (se ne occupa il dev).

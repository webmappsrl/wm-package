> Ticket: oc:8677

# Notes — Nel file dei POI mancano nome e testo per la ricerca

## Divergenze dal piano, task per task

### Task 1 regola propertiesname riusabile e applicata dallimport

- Il test di `importData` aggiorna un POI già esistente (creato con `createQuietly` e lo stesso
  `geohub_id`) invece di crearne uno nuovo: così non servono geometria, app e utente, e non
  servono i concern `InjectsGeohubImportService` e `DisablesForeignKeyConstraints`. Passa dallo
  stesso `persistQuietly`.

### Task 2 getsearchablestring corretto

- I test creano le App senza `native_app_deep_link_enabled`: la colonna non esiste nel database
  di maphub su cui girano i test, e il default della factory non attiva il deep link.
- Prima dell'encode JSON gli spazi (a capo compresi) di ogni traduzione diventano uno spazio
  solo. Senza, gli a capo escapati diventano lettere spurie quando si tolgono i backslash: nel
  `pois.geojson` di Geohub compare `rnLa chiesa…` al posto di `La chiesa…`.
- `use Illuminate\Support\Facades\App` tolto da `EcPoi`: `App` ora è il modello dello stesso
  namespace. Pint ha di conseguenza semplificato `\Wm\WmPackage\Models\App::class` in
  `App::class` nella relazione `app()`, ed è stato tolto il commento che spiegava il nome
  completo con il conflitto con la facade.

- Dopo la review: la firma è `getSearchableString(?App $app = null)`, senza il ramo `int` del
  piano. Nessun chiamante passa un id, e il ramo invitava a ripetere `App::find()` per ogni POI.
- Una lista `poi_searchables` con valori non stringa (`["name", 5]`) vale come «tutti i campi»,
  come prescrive il piano (la prima versione filtrava i valori non stringa).
- Una traduzione salvata come stringa invece che come oggetto (per esempio
  `properties.description` stringa, dati legacy) entra nel testo come `{it:…}`: meglio includerla
  che perderla, la ricerca è per sottostringa.

### Task 3 name e searchable in poisgeojson

- `tests/Unit/Models/AppGetAllPoisGeojsonGlobalTest.php` non si può lanciare da maphub: estende
  `Wm\WmPackage\Tests\TestCase`, che il consumer non carica. Il filtro `global` è comunque
  coperto dai test nuovi, che creano POI con `global = true`.
- Dopo la review: `searchableForPoi()` è `protected` invece che pubblico (non è API del package).
  Il test lo chiama da una sottoclasse anonima di `App` e verifica anche il warning nel log, con
  l'id del POI. Il messaggio di log descrive l'errore invece di citare il ticket, e usa
  `Illuminate\Support\Facades\Log` importato.
- Dopo la review: i POI si caricano con `with('taxonomyPoiTypes')`, perché `getSearchableString()`
  legge i tipi di POI per ogni POI: senza, una query in più per POI.

### Task 4 rigenerare poisgeojson al cambio di poi_searchables

- Il test usa `Bus::fake()` senza lista e non imposta `native_app_deep_link_enabled` (colonna
  assente nel database di maphub, vedi Task 2).
- Tolta la condizione `! $app->wasRecentlyCreated`: `wasChanged()` è già falso in creazione, e
  la condizione bloccava anche gli aggiornamenti fatti sulla stessa istanza appena creata.

### Task 5 rigenerare poisgeojson a fine import dei poi

- Dopo la review: la catena ha un `catch` che lancia comunque `BuildAppPoisGeojsonJob`. Una catena
  si ferma al primo job che fallisce, e su dev `SyncTaxonomyWhereJob` è già andato in timeout
  (`failed_jobs` di dev.maphub: 3 righe del 02/10/2026, `TimeoutExceededException` e poi
  `MaxAttemptsExceededException`): senza `catch` il file restava quello vecchio.
- Dopo la review: anche il batch delle immagini (`ec_media`) rigenera il file a fine import,
  perché gira in parallelo a quello dei POI e il file contiene le immagini dei POI.
- `dispatchAfterEcImportBatch()` resta pubblico e statico perché lo chiama la closure del
  `finally()` del batch, che viene serializzata; è marcato `@internal`. Le chiavi dei batch che lo
  usano stanno in `POST_IMPORT_ENTITY_KEYS`.
- I test che lanciano `BuildAppPoisGeojsonJob` mettono la cache `redis` su `array`: il job è
  `ShouldBeUnique` con lock su Redis, `Bus::fake()` non evita il lock e con il `TestCase` di maphub
  finirebbe nel Redis vero (un lock rimasto da un test faceva fallire il successivo).

### Task 6 limport porta poi_searchables e track_searchables

- Task annullato dopo la review: `ImportAppJob::transformData()` non usa il mapping `fields`, copia
  tutte le colonne che Geohub ha in comune con `apps` (`src/Jobs/Import/ImportAppJob.php:78`).
  `poi_searchables` e `track_searchables` erano già importati: le righe aggiunte al config non
  servivano e il test verificava un percorso che l'import non usa. Config ripristinato, test
  tolto.

### Task 7 verifica complessiva

- Il comando del Passo 1 comprende `tests/Unit/Models/`, dove `AppGetAllPoisGeojsonGlobalTest` non
  si lancia da maphub (vedi Task 3): i test sono stati lanciati per file. PHPStan di maphub non
  analizza `wm-package/`: è stato lanciato anche quello del package sui soli file toccati.

## Verifiche eseguite

- Dopo le correzioni della review, da maphub
  (`docker exec php-maphub vendor/bin/pest wm-package/tests/...`): test nuovi e test esistenti
  vicini al codice toccato (import Geohub, `ImportAppJob`, `TaxonomyWhere`, import UGC POI),
  64/64. `tests/Feature/Import/ImportUgcTrackJobTest.php` è escluso: fallisce per la tabella
  `ugc_track_matching_runs` mancante nel database di maphub (vedi Follow-up).
- Pint lanciato solo sui file toccati.
- PHPStan di maphub (quello della CI, che non analizza `wm-package/`): 0 errori.
- PHPStan del package sui soli file toccati: nessun errore sulle righe modificate. I due errori
  che c'erano (`getTranslations()` su un `Model` generico, `App.php` ed `EcPoi.php`) sono corretti
  con un `@var` sulla variabile del ciclo, che toglie anche errori preesistenti nello stesso
  ciclo. Restano errori preesistenti in quei file (PHPDoc non validi, tipi Eloquent generici).
- Da fare dal dev: verifica sul dev dopo restore e nuovo import (requisito «Verifica sul dev»
  dell'overview).

## Bug trovati

- `EcPoi::getSearchableString($app_id)` andava in errore con qualsiasi app: importava la facade
  `App` invece del modello. Leggeva descrizione ed estratto da colonne che in wm-package non
  esistono (stanno in `properties`) e scriveva gli accenti come escape Unicode.
- L'import da Geohub non rigenerava `pois.geojson` a fine import: dopo un nuovo import il file
  poteva restare quello generato prima del ricalcolo delle località o prima delle immagini.
- In review: la prima versione della rigenerazione dipendeva dal successo di
  `SyncTaxonomyWhereJob`, che su dev è già andato in timeout (vedi Task 5).

## Decisioni

- Con `poi_searchables` vuoto `searchable` include tutti i campi, descrizione compresa, come
  Geohub (`pois.geojson` pubblico dell'app 28). Era stata proposta prima la ricerca solo per nome
  e tipo di POI, per il peso del file; scartata perché il collaudo confronta con Geohub, che già
  serve quel peso.
- La ricerca cambia comportamento sugli shard che oggi cercano solo per nome: accettato e
  documentato nei rischi dell'overview (cammini: «via» da 23 a 42 risultati).
- Review formale con `wm-review-ticket` (15/10/2026): un bloccante (il file non si rigenerava se
  falliva il ricalcolo delle località) e undici cleanup. Corretti il bloccante e i cleanup che
  restano dentro il ticket; due vanno in follow-up perché escono dal ticket (sotto).
- Verifiche dei rischi fatte in sola lettura su dev.maphub, prod.maphub, cammini, camminidev,
  wm.osm2cai e uat.osm2cai il 15/10/2026. cai.osm2cai e dev.osm2cai non verificati: host key SSH
  cambiata.

## Follow-up

- Applicare la regola su `properties.name` agli altri salvataggi silenziosi:
  `src/Nova/Actions/BulkEditAction.php:128`, `src/Nova/Actions/ConvertUgcPoiToEcPoi.php:70`,
  `src/Imports/EcPoiFromSpreadsheet.php:104`, `src/Imports/EcTrackFromSpreadsheet.php:47`.
- Valutare un default più leggero di `poi_searchables` vuoto (oggi include la descrizione e quasi
  raddoppia `pois.geojson` delle app con descrizioni lunghe).
- Correggere `EcTrack::getSearchableString()` con le stesse regole dei POI (accenti, a capo, campi
  vuoti), oggi scritto due volte con formati diversi. Esce dal ticket: alimenta l'indice Elastic
  delle tracce di tutti gli shard e richiede un reindex.
- Spostare la regola su `properties.name` da un metodo statico di `AbstractObserver` a un trait
  sul modello, che l'observer e l'import chiamano: da fare insieme agli altri salvataggi
  silenziosi del primo follow-up.
- `tests/Feature/Import/ImportUgcTrackJobTest.php` fallisce sul database di maphub per la tabella
  `ugc_track_matching_runs` mancante (migration di oc:8773 non ancora pubblicata in maphub): non
  legato a questo lavoro.

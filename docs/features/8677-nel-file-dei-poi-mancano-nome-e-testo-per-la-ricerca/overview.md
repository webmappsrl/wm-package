> Ticket: oc:8677

# Nel file dei POI mancano nome e testo per la ricerca: l'app non trova i POI

## Cosa cambia

Interventi in wm-package:

1. **L'import da Geohub scrive `properties.name` come un salvataggio normale.** Il salvataggio
   silenzioso dell'import (`persistQuietly`) applica la stessa regola di
   `AbstractObserver::saving`: su ogni modello con `name` traducibile copia
   `getTranslations('name')` in `properties.name`. Vale per POI, tracce e tassonomie importati,
   non solo per i POI. Non per i layer: `Layer::save()` (`src/Models/Layer.php:308-317`) riscrive
   `properties.name` con la stringa nella lingua corrente dopo la regola, quindi un layer importato
   resta come prima (i layer non sono in `pois.geojson`, per wm-core non cambia nulla). La regola è
   definita in un punto solo e la usano sia l'observer sia l'import da Geohub.
2. **`pois.geojson` contiene `name` e `searchable` per ogni POI.** `App::getAllPoisGeojson()`
   aggiunge alle properties di ogni POI `name` (`getTranslations('name')`) e `searchable` (da
   `EcPoi::getSearchableString()` per l'app che genera il file), calcolati mentre genera il file,
   come fa già per `taxonomyIdentifiers`. `searchable` non viene salvato nel database. È lo stesso
   schema di Geohub (`app/Models/EcPoi.php:350` su geohub `631e274`). Il calcolo di `searchable`
   ha un `try` suo: se fallisce, `searchable` resta vuoto e il POI resta nel file.
3. **`EcPoi::getSearchableString()` torna a funzionare.** Oggi:
   - con un `app_id` va in errore: `src/Models/EcPoi.php:9` importa la facade
     `Illuminate\Support\Facades\App` e non il modello, quindi `App::find()` lancia
     `BadMethodCallException: Method Illuminate\Foundation\Application::find does not exist`;
   - descrizione ed estratto li legge dalle colonne, ma in wm-package stanno in `properties`:
     su un POI con la descrizione in `properties` il risultato è `{it:<nome>} [] [] …`, senza
     descrizione e con due `[]`;
   - `json_encode` senza `JSON_UNESCAPED_UNICODE` scrive `Localit\u00e0`, e la ricerca di
     «Località» non lo trova.

   Il metodo riceve l'app come oggetto (`?App`, non l'id: nessuna query per POI), legge descrizione ed
   estratto da `properties` come `EcTrack::getSearchableString()` (`src/Models/EcTrack.php:776-810`),
   salta i campi vuoti e mantiene gli accenti. Con `poi_searchables` vuoto, o con un valore che
   non è una lista di stringhe, include tutti i campi (nome, descrizione, estratto, osmid, tipi di
   POI), come fa oggi Geohub.
4. **`pois.geojson` si rigenera quando serve.**
   - Alla fine dell'import dei POI di un'app, dopo il ricalcolo delle località (il file usa anche
     quelle), parte `BuildAppPoisGeojsonJob($appId)`, in catena dopo `SyncTaxonomyWhereJob`. Se il
     ricalcolo fallisce, il `catch` della catena rigenera comunque il file: su dev
     `SyncTaxonomyWhereJob` è già andato in timeout (`failed_jobs`, 02/10/2026). Prima l'import
     lanciava solo `SyncTaxonomyWhereJob`, e l'API serve il file già salvato sullo storage
     (`AppAPIController.php:96`), che un restore del database non cancella.
   - Alla fine dell'import delle immagini dell'app parte `BuildAppPoisGeojsonJob($appId)`: il
     batch delle immagini gira in parallelo a quello dei POI e il file contiene le immagini dei POI.
   - Quando in Nova cambia «POI Search In» (`poi_searchables`) di un'app, `AppObserver` lancia
     `BuildAppPoisGeojsonJob($app->id)`. Oggi rigenera le uscite solo al cambio delle località
     mostrate (`AppObserver.php:165-176`).

L'import porta già da Geohub la configurazione della ricerca dell'app (`poi_searchables`,
`track_searchables`): `ImportAppJob::transformData()` copia tutte le colonne che Geohub ha in comune
con `apps` (`src/Jobs/Import/ImportAppJob.php:78`). Le opzioni coincidono nei due sistemi (Geohub
`app/Nova/App.php:977-984`, wm-package `src/Nova/App.php:776-795`). Non serve nessuna modifica.

## Perché

Nel collaudo dell'import Geohub 28 → maphubdev 3 (tag [COLLAUDO][ITINERA-ROMANICA-PLUS][2026]1)
la ricerca dei POI nell'app non trova nulla. wm-core cerca i POI solo nella stringa
`JSON.stringify(properties.name) + properties.searchable`
(`wm-core/projects/wm-core/src/store/features/ec/utils.ts:56-57`), e nel `pois.geojson` dei POI
importati mancano entrambi i campi.

- `name` manca perché lo scrive solo `AbstractObserver::saving` (`src/Observers/AbstractObserver.php:9-12`),
  e l'import salva con `persistQuietly` (`src/Services/Import/GeohubImportService.php:303-307`), che
  non fa partire gli observer. Lo stesso difetto riguarda le tracce importate.
- `searchable` sui POI non lo scrive nessun codice, e il metodo che dovrebbe calcolarlo è rotto.

`searchable` si calcola quando si genera il file e non al salvataggio, perché dipende dalla
configurazione dell'app (`apps.poi_searchables`): il file si rigenera quando quella configurazione
cambia, e il risultato non dipende da come il POI è stato salvato.

Con `poi_searchables` vuoto si includono tutti i campi, descrizione compresa, perché è ciò che fa
Geohub: nel `pois.geojson` di produzione dell'app 28 il `searchable` contiene nome, descrizione,
estratto e tipo di POI. Un import deve riprodurre la ricerca che il cliente ha oggi; alleggerire il
default è un'altra decisione.

Obiettivo del ticket: l'import da Geohub produce dati corretti, anche per il consumer frontend.

## Requisiti

- [x] Un POI o una traccia salvati dall'import con `persistQuietly` hanno `properties.name`
      uguale a `getTranslations('name')`, come dopo un salvataggio da Nova (i layer no: vedi
      «Cosa cambia», punto 1).
- [x] La regola che copia `name` in `properties.name` è definita in un punto solo e la usano sia
      `AbstractObserver::saving` sia l'import da Geohub.
- [x] In `pois.geojson` ogni POI ha `properties.name` come oggetto tradotto (`{lang: testo}`) e
      `properties.searchable` come stringa: è il formato che legge wm-core (`utils.ts:56-57`).
- [x] `EcPoi::getSearchableString()` non va in errore quando riceve l'app, legge descrizione ed
      estratto da `properties`, non aggiunge `[]` per i campi vuoti e mantiene gli accenti
      («Cathédrale», non `Cath\u00e9drale`).
- [x] `properties.searchable` rispetta `poi_searchables` dell'app per cui si genera il file; con
      la lista vuota include nome, descrizione, estratto, osmid e tipi di POI, come Geohub.
- [x] Un errore nel calcolo di `searchable` non toglie il POI dal file: `searchable` resta vuoto e
      l'errore finisce nel log con l'id del POI.
- [x] Alla fine dell'import dei POI di un'app parte `BuildAppPoisGeojsonJob` per quell'app, dopo
      il ricalcolo delle località, e anche se il ricalcolo fallisce.
- [x] Alla fine dell'import delle immagini di un'app parte `BuildAppPoisGeojsonJob` per quell'app.
- [x] Il cambio di `poi_searchables` di un'app lancia `BuildAppPoisGeojsonJob`; un salvataggio
      dell'app che non lo cambia non lo lancia.
- [x] L'import da Geohub copia `poi_searchables` e `track_searchables` dell'app (già vero prima
      di questo lavoro, `ImportAppJob.php:78`: nessuna modifica).
- [x] Test in wm-package (Pest, `DatabaseTransactions`, record creati dal test con `uniqid`) per
      ogni requisito sopra che richiede codice nuovo.
- [ ] Verifica sul dev dopo il restore del database e il nuovo import dell'app 28, lanciati dal
      dev: in `pois.geojson` dell'app importata tutti i POI hanno `name` e `searchable`;
      nell'app la ricerca «Cathédrale» trova la cattedrale (POI Geohub 2365), e una parola presente
      solo nella descrizione trova lo stesso POI che trova su Geohub; la config dell'app e l'indice
      Elastic delle tracce non peggiorano. L'app va provata con il file scaricato di nuovo, non da
      una copia già in cache sul dispositivo.

## Rischi

Verificati in sola lettura sugli shard il 15/10/2026 (dev.maphub, prod.maphub, cammini,
camminidev, wm.osm2cai, uat.osm2cai) e sul `pois.geojson` pubblico di Geohub. cai.osm2cai e
dev.osm2cai non sono stati verificati: la loro host key SSH risulta cambiata.

- **Il peso di `pois.geojson` cresce** perché `searchable` ripete descrizione ed estratto, che il
  file contiene già in `properties`. Per l'app 3 di dev la descrizione pesa circa 1,7 MB su 211
  POI; su cammini circa 200 kB; su osm2cai app 2 circa 850 kB. È lo stesso peso che il file di
  Geohub ha già oggi (app 28: 4,3 MB). Mitigazione: nessuna in questo ticket, il default si può
  alleggerire in un ticket a parte.
- **La ricerca dei POI cambia comportamento sugli shard che oggi cercano solo per nome.** Con
  `poi_searchables = []` la ricerca guarda anche descrizione ed estratto. Sui 906 POI di cammini:
  «via» passa da 23 a 42 risultati, «chiesa» da 55 a 81. È un cambio visibile agli utenti, non
  solo un vantaggio. Chi vuole la ricerca solo per nome seleziona `name` in «POI Search In», e il
  file si rigenera al salvataggio dell'app.
- **Il fix arriva anche a camminiditalia e osm2cai2.** Confronto, su tutti i POI con app, fra il
  `properties.name` di oggi e `getTranslations('name')` (colonna senza lingue nulle o vuote):

  | Shard | `properties.name` oggi | Dopo il fix |
  |---|---|---|
  | prod.maphub (93), cammini (906), camminidev (906) | oggetto, identico | invariato |
  | dev.maphub | 93 identici, 211 assenti (app 3 importata) | i 211 ricevono il nome |
  | wm.osm2cai | 4631 identici, 592 stringa, 177 `[]`, 1554 assenti | stringa → `{"it": …}`, `[]` resta `[]`, assenti → nome |
  | uat.osm2cai | 1661 identici, 377 stringa, 1426 assenti | come sopra |

  Per wm-core i casi che cambiano non sono un problema: la ricerca fa `JSON.stringify(name)`,
  che contiene il testo sia da stringa sia da oggetto (`utils.ts:56-57`, uguale su wm-core
  `origin/main` `f91ee91`); la pipe `wmtrans` mostra `"Fonte Peticosa"` e
  `{"it":"Fonte Peticosa"}` allo stesso modo, e per `[]` restituisce una stringa vuota
  (`pipes/wmtrans.pipe.ts:27-42`). Il campo nuovo è `searchable`, che oggi nessuno shard ha
  (0 POI). Il file cambia quando viene rigenerato (salvataggio di un POI o dell'app, fine import,
  job o comando). osm2cai2 è fermo a wm-package `fd77c39` (12/05/2026) e riceverà il fix solo al
  prossimo bump.
- **Costo di generazione del file**: il ciclo fa già quattro query per POI sulle tassonomie
  (`src/Models/App.php:269-272`). `getSearchableString` riceve l'app come oggetto e legge i tipi di
  POI caricati insieme ai POI (`with('taxonomyPoiTypes')`, `App.php:257`): nessuna query in più per
  POI. L'app più grande verificata ha 4323 POI (osm2cai app 3).
- **Build di fine import scartato dal lock**: `BuildAppPoisGeojsonJob` è `ShouldBeUnique` per
  app, con il lock tenuto fino a fine esecuzione. Se quando parte quello di fine import ne sta
  già girando un altro della stessa app (batch delle immagini, `TaxonomyPoiTypeablesObserver`),
  viene scartato senza errori e il file resta quello del build in corso. Il ricalcolo delle
  località dura minuti e i build durante l'import sono brevi, quindi la sovrapposizione è rara.
  Mitigazione: documentato nel codice; non si passa a `ShouldBeUniqueUntilProcessing`, che
  cambierebbe il comportamento per tutti i chiamanti del job.
- **Rollback**: il revert del codice non toglie `properties.name` scritto nel frattempo dagli
  import, che per wm-core è innocuo; i file già generati perdono `searchable` alla rigenerazione
  successiva, e le copie già scaricate sui dispositivi restano finché l'app non riscarica il file.
- **I dati già importati restano senza `properties.name`** finché non si rifà l'import. Riguarda
  solo l'app 3 di dev: su prod.maphub 93 POI su 93 e 29 tracce su 29 hanno già `properties.name`.

## Out of scope

- Applicare la regola su `properties.name` agli altri salvataggi silenziosi del package, che oggi
  aggiornano la colonna `name` senza `properties.name`: `src/Nova/Actions/BulkEditAction.php:128`,
  `src/Nova/Actions/ConvertUgcPoiToEcPoi.php:70`, `src/Imports/EcPoiFromSpreadsheet.php:104`,
  `src/Imports/EcTrackFromSpreadsheet.php:47`. Il difetto c'è già oggi e non riguarda l'import da
  Geohub.
- Comando di riallineamento di `properties.name` sui modelli già importati: i dati di dev si
  riallineano rifacendo l'import.
- Alleggerire il default di `poi_searchables` vuoto, che oggi include la descrizione.
- Rimozione del vecchio `getGeojson` commentato in `src/Models/EcPoi.php:139-259`.
- Ricerca delle tracce in wm-core: passa da Elastic e wm-core non legge `searchable` delle tracce.
- Correggere lo stesso testo di ricerca delle tracce (`EcTrack::getSearchableString()`, accenti
  scritti come escape): alimenta l'indice Elastic delle tracce di tutti gli shard e richiede un
  reindex.
- Ricerca dei POI per tema: il cliente cita la ricerca «per tipo o tema», ma `searchable` contiene
  i tipi di POI e non i temi, come su Geohub e come prevedono le opzioni di «POI Search In».

## Moduli toccati

Tutti in **wm-package**:

- `src/Observers/AbstractObserver.php` — la regola su `properties.name` diventa riusabile.
- `src/Services/Import/GeohubImportService.php` — `persistQuietly` applica la regola prima di
  `saveQuietly()`.
- `src/Jobs/Import/ImportAppJob.php` — rigenerazione di `pois.geojson` a fine import dei POI
  (anche se il ricalcolo delle località fallisce) e delle immagini.
- `src/Observers/AppObserver.php` — rigenerazione di `pois.geojson` al cambio di `poi_searchables`.
- `src/Models/App.php` — `getAllPoisGeojson()` aggiunge `name` e `searchable`.
- `src/Models/EcPoi.php` — `getSearchableString()`: import del modello `App`, descrizione ed
  estratto da `properties`, accenti, campi vuoti, app passata come oggetto.
- `tests/` — nuovi test per ogni requisito.

In **maphub** nessun file: il bump del submodule lo allineano i dev.

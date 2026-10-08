> Ticket: oc:8718

# Notes — UgcTrack duplicati con lo stesso uuid

## Deviazioni dal piano

- **Setup dei test.** I test API richiedono `artisan('jwt:secret --always-no')` nel `beforeEach` (come `UgcPoiControllerTest`), e l'helper che imita l'app manda `images[]` solo se ci sono file: con `images => []` lo store falliva con `RequestDoesNotHaveFile`, mentre l'app il campo non lo manda proprio.
- **Immagini nei test.** I JPEG con byte arbitrari previsti dal piano facevano fallire le conversioni di Spatie (`CouldNotLoadImage`): usato `UploadedFile::fake()->image()`, deterministico a parità di dimensioni.
- **Test che passavano senza implementazione.** "Conserva le chiavi" (store e command) passava già, perché il retry creava un record nuovo o il command non faceva nulla: aggiunte le asserzioni sul numero di record. Il test dei media del command, rosso all'inizio per un problema di setup, è stato verificato con una controprova (unione disattivata → rosso).
- **`taxonomy_where` nel command non si ricalcola** (l'overview diceva il contrario): la geometria del padre non cambia, quindi il risultato sarebbe identico.

## Divergenze dal piano, task per task

### Task 3: report senza CSV

Il piano faceva scrivere al command un CSV in `storage/logs/`. Tolto su richiesta del dev: il report è la tabella stampata a schermo; ogni gruppo (con l'esito, compresi i "da verificare") e il riepilogo di ogni lancio vanno nel canale di log `duplicated-ugc`, che esisteva in `config/wm-logging.php` ma non era usato (daily, 14 giorni); la traccia permanente di cosa è stato unito è `ugc_duplicates_archive`.

## Bug trovati

- **Il fix dei POI di oc:6951 non scattava per le richieste dell'app.** Leggeva l'uuid con `$request->input('properties.uuid')`, ma l'app manda un multipart con il JSON nel campo `feature`. Verificato il 07/10/2026 con due richieste HTTP reali a `api/v2/ugc/poi/store` sul DB di sviluppo (POI 131 e 132, poi cancellati) e con il test `vale anche per i POI con la richiesta multipart dell'app`, rosso prima del fix. Corretto spostando la ricerca in `UgcController::store()`.

## Decisioni

- **Tolti dal piano dopo l'approvazione**, perché a bassa probabilità: spostamento generico dei riferimenti esterni (FK verso `ugc_tracks`) nel command, controllo della dimensione prima dell'hash dei media vecchi, deduplica di immagini identiche nella stessa richiesta, log `duplicated-ugc` nello store (il command invece ci scrive, vedi Task 3).
- **POI inclusi** nel fix dello store: il codice è condiviso, il costo è un test.
- **Tag Orchestrator associati in `environment-setup`:** `camminiditalia` (495) e `wm-package` (635).
- **PHPStan:** nessun errore nuovo sui file toccati; gli errori segnalati in `UgcController.php:220`, `UgcPoiController.php:43-44` e `WmPackageServiceProvider.php` sono su righe non modificate e c'erano già. L'analisi completa con i worker paralleli fallisce per la race sulla cache Nette (già nota da oc:8312): lanciata in un solo processo (`--debug`).

## Test del caso reale

`tests/Feature/WmFixDuplicatedUgcTracksRealCaseTest.php` riproduce quattro gruppi presi dal DB di sviluppo di camminiditalia (dump della produzione) il 07/10/2026: `1529846e` (modifica dell'utente sulla copia, chiavi del server nelle properties, scarto di arrotondamento sulla geometria), `3e301343` (tre invii con le stesse tre foto), `806f2dc5` (primo invio con una foto, retry con tre), `ae43aa51` (quattro righe, `layer_id` sul padre). Properties ridotte, geometrie accorciate, immagini sostituite da JPEG minuscoli con lo stesso schema di doppioni: gli sha256 sono stati calcolati sulle immagini vere, copiate dal bucket pubblico di produzione in MinIO locale. Le asserzioni sono la previsione scritta prima di eseguire il command. Controprova: senza lo scarto per contenuto il test fallisce (9 immagini invece di 3 sul padre di `3e301343`). Da conservare.

`tests/Feature/UgcStoreRetryRealCaseTest.php` fa lo stesso lato store, con il payload multipart dell'app e le properties vere: `806f2dc5` (primo invio con una foto, retry con tre → una traccia, tre foto), `3e301343` (due retry con le stesse tre foto → nessun duplicato), traccia 321 di oc:8466 (GPX importato, cammino corretto a mano dall'Administrator → un retry non lo annulla). Controprova: sul `UgcController` di `develop` falliscono tutti e tre (2-3 tracce invece di una).

## Review finale

Review di un subagente isolato su tutto il branch: nessun Critical, due Important corretti con test rosso → verde:
- un media vecchio con il file non leggibile su S3 faceva fallire lo store (l'app avrebbe ritentato all'infinito) e il command: ora l'hash non calcolabile conta come "diverso" e si logga sul canale `ugc`;
- il CSV del command aveva un nome per giorno e il rilancio di verifica sovrascriveva quello di `--execute`. Corretto, poi **il CSV è stato tolto su richiesta del dev**: il report è la tabella a schermo, e la traccia di cosa è stato unito è `ugc_duplicates_archive`. Il CSV era previsto dal ticket, sul modello del command dei POI di osm2cai2.

Minor non corretti: `images` come file singolo invece di `images[]` (l'app usa sempre `images[]`); geometria nulla di padre o copia trattata come distanza 0 (riguarda osm2cai2); mancano test sul padre più recente e sul pareggio di `updatedAt`; media scartati che restano orfani se il processo muore tra il commit e la cancellazione; ordinamento di `updatedAt` come stringa.

## Review formale (08/10/2026)

`wm-review-ticket` con cinque finder: verdetto approvato con riserve. Un punto ridecido con il dev: la ricerca per uuid non filtrava per utente, e l'avevamo giudicato ipotetico perché un uuid altrui non sarebbe conoscibile. La review ha trovato che l'uuid è pubblico nel link di condivisione `/share/ugc-track/{uuid}` (oc:8183): aggiunto `where('user_id', auth()->id())` in `findExistingByUuid()`, con il test `uno store con l'uuid di una traccia di un altro utente non la tocca e crea un record nuovo` (rosso → verde). Altri candidati verificati sui dati e classificati ipotetici: padre per `id` contro `created_at` (0 casi discordi su 285 tracce), copie di utenti o app diversi (0), pivot morph orfani dopo il DELETE in SQL (nessuno, solo 1 riga di audit Nova).

## POI allineati alle tracce (08/10/2026)

Dopo la review il dev ha chiesto di allineare i POI: il command si chiama ora `wm:fix-duplicated-ugc` (prima `wm:fix-duplicated-ugc-tracks`), con `--type=tracks|pois` e di default entrambi; `UgcDuplicatesService` riceve la classe del modello e ne ricava tabella e morph. Un solo command invece di due, anche per non confondersi con `osm2cai:fix-duplicated-ugc-pois` di osm2cai2, che marca le copie invece di archiviarle. Test: `WmFixDuplicatedUgcPoisCommandTest` (unione con foto doppie, POI distanti più di un metro, `--type`). Sul DB di sviluppo di camminiditalia i POI duplicati sono 0.

## Da sapere

- Durante l'esecuzione il submodule è tornato da solo su `develop` (reflog: `checkout` alle 15:42:40 del 07/10/2026, un minuto dopo la creazione del branch, non eseguito da questa sessione). Le modifiche erano nel working tree e il branch della feature puntava allo stesso commit: riportato con `git switch` senza perdite. Probabilmente un'altra sessione o l'IDE sullo stesso repo.

## Follow-up

- **osm2cai2:** prima di lanciare il command lì vanno gestiti `ugc_media.ugc_track_id` (FK `ON DELETE SET NULL`) e le colonne di validazione. Dettaglio in [docs/knowledge/aggiornare-wm-package-su-osm2cai2.md](../../knowledge/aggiornare-wm-package-su-osm2cai2.md).
- **camminiditalia, passaporto (oc:8165):** `validated_ec_track_ugc_track` ha una FK verso `ugc_tracks` con `ON DELETE CASCADE`. Quando arriverà su `develop`, il command cancellerebbe a cascata le righe delle copie: va esteso prima di un `--execute` successivo a quel merge.

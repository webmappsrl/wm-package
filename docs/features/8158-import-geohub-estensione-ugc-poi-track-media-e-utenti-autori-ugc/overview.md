> Ticket: oc:8158

# Import GeoHub: estensione UGC (POI, Track, Media) e utenti autori UGC

## Cosa cambia

Il comando `wm:import-from-geohub app <id>` viene esteso per importare, oltre ai contenuti editoriali già gestiti, anche i contributi degli utenti finali (UGC):

- `ugc_pois` e `ugc_tracks` (modelli già esistenti in wm-package, oggi mai popolati dall'import GeoHub)
- le foto UGC collegate — **nessun nuovo modello `UgcMedia`**: vengono allegate come Spatie Media Library direttamente a `UgcPoi`/`UgcTrack` (entrambi implementano già `HasMedia` tramite `GeometryModel`), stesso identico meccanismo con cui `EcMediaImportService::processEcMediaImport()` allega oggi le foto agli EC. Non viene creata alcuna tabella/pivot dedicata a mirror dello schema GeoHub.

Gli utenti che risultano autori di UGC (e non hanno già un ruolo) ricevono il ruolo `Contributor`, pre-seedato via migration stub (non creato "pigramente" al primo utilizzo) per evitare una race condition di creazione concorrente quando molti job di import girano in parallelo su Horizon con `tries => 1`. Il proprietario dell'app mantiene il ruolo `Editor` esistente (oc:8042), invariato.

`ugc_poi`, `ugc_track` restano **opt-in**, non nel default: si importano solo passando esplicitamente `--dependencies=...,ugc_poi,ugc_track` al comando. `wm-package` è condiviso con altri consumer (es. osm2cai2, già configurato con credenziali GeoHub) — includerli in `default_dependencies['app']` cambierebbe silenziosamente il comportamento del comando per chiunque altro lo lanci senza flag.

**Comportamento update-if-newer** (diverso sia dal resto della pipeline sia dalla proposta iniziale del ticket): un record UGC già importato per un dato `geohub_id` viene aggiornato da un successivo re-import **solo se** la colonna `updated_at` su GeoHub è più recente del timestamp dell'ultimo sync salvato localmente (`properties->geohub_synced_at`, stesso pattern già usato da `ImportAppJob`/`EcMediaImportService`). Se non è stato modificato, il record locale resta invariato. Motivazione (confermata dal referente tecnico in call, vedi Note): un UGC può essere modificato solo dal suo autore reale tramite l'app collegata a GeoHub — non esiste un percorso di moderazione locale su Maphub che un reimport rischierebbe di sovrascrivere, quindi non serve nessun meccanismo di protezione aggiuntivo (lock/flag), a differenza di quanto ipotizzato inizialmente.

**Autore reale, non owner dell'app**: `BaseImportJob::transformData()` (ereditato da tutti i job di import) forza `user_id` = proprietario dell'app quando è settato `app_id` — corretto per i contenuti editoriali, sbagliato per gli UGC (ogni riga ha un autore proprio). I job UGC devono sovrascrivere esplicitamente questo comportamento e preservare l'autore reale mappato da GeoHub (`checkUgcUserExistence()`).

**`app_id` GeoHub per gli UGC è l'ID numerico dell'app come stringa** (es. `'49'`), non lo SKU (`it.webmapp.*`) come inizialmente ipotizzato — verificato sui dati reali. Il filtro per app in fase di import userà quindi un cast/confronto numerico diretto, non una lookup su `apps.sku`.

## Perché

Oggi `wm:import-from-geohub` migra solo i contenuti editoriali (EC POI, EC Track, layer, media, taxonomy) e il proprietario dell'app. I contributi UGC creati dagli utenti finali — POI, track e le relative foto — non vengono importati, né i loro autori. Per le app in fase di migrazione da GeoHub a Maphub questo significa una perdita di dati reale: i contenuti generati dagli utenti finali (spesso il valore principale per il cliente) restano indietro su GeoHub.

## Requisiti

- [ ] `ugc_pois` e `ugc_tracks` dell'app importata vengono creati su Maphub con `app_id` mappato all'ID locale e `user_id` mappato all'**autore reale** GeoHub (non il proprietario dell'app) — richiede override esplicito di `transformData()` nei job UGC
- [ ] Nessun modello/migrazione/Nova resource `UgcMedia`. Le foto UGC vengono allegate come Spatie media direttamente al `UgcPoi`/`UgcTrack` locale corrispondente, tramite un nuovo `ImportUgcMediaJob` con entry propria in `import_mapping` (chiave `ugc_media`, `geohub_table: 'ugc_media'`), analogo a `ImportEcMediaJob`: risolve il modello locale via le pivot GeoHub `ugc_media_ugc_poi`/`ugc_media_ugc_track` (stesso idioma di `findEcMediaRelatedModels`) e allega la foto con `addMediaFromUrl()->toMediaCollection('default')`
- [ ] Un download foto fallito viene loggato esplicitamente con il suo `geohub_id` (non inghiottito silenziosamente come nel pattern `ImportEcMediaJob` esistente) e non blocca l'import degli altri record del batch
- [ ] Nuovo ruolo `Contributor` creato dalla **migration stub stessa** (non pigramente al primo utilizzo) — aggiunto anche a `RolesAndPermissionsService::seedDatabase()` per coerenza con gli altri ruoli
- [ ] Utenti importati come autori UGC ricevono `Contributor` solo se `$user->roles->isEmpty()` — nessuna sovrascrittura di ruoli già assegnati manualmente
- [ ] Il proprietario dell'app continua a ricevere il ruolo `Editor` esistente (oc:8042), invariato da questo ticket
- [ ] `ugc_poi`, `ugc_track`, `ugc_media` restano **opt-in** (flag esplicito `--dependencies=...,ugc_poi,ugc_track,ugc_media`), **non** aggiunti a `default_dependencies['app']` — documentato in modo prominente (help del comando + questo `docs/features/`) per non incorrere nel rischio opposto (migrazione Maphub incompleta per flag dimenticato)
- [ ] Un record UGC (poi/track) viene creato se non esiste già per lo stesso `geohub_id` (`properties->geohub_id`); se esiste, viene **aggiornato solo se** `updated_at` GeoHub è successivo a `properties->geohub_synced_at` locale, altrimenti lasciato invariato
- [ ] Un record UGC con `user_id` nullo su GeoHub viene scartato con log di warning, senza interrompere l'import degli altri record del batch (caso mai osservato nei dati reali ma lo schema lo permette)
- [ ] Ordine di dispatch da `ImportAppJob`: `ugc_poi`/`ugc_track` prima di `ugc_media` (la ricerca del modello locale a cui allegare la foto richiede che il POI/Track sia già stato importato)
- [ ] `ImportUgcMediaJob` non trova subito il `UgcPoi`/`UgcTrack` locale a cui allegare la foto (i batch `ugc_poi`/`ugc_track`/`ugc_media` sono paralleli su Horizon, l'ordine di dispatch non garantisce che il modello esista già quando parte il job foto): il job riprova un paio di volte con una breve attesa prima di arrendersi e loggare il fallimento, invece di loggare e scartare al primo tentativo
- [ ] `ImportUgcMediaJob` distingue esplicitamente un URL irraggiungibile (errore di rete) da un content-type non immagine, invece di riusare tal quale il bug di `EcMediaImportService::processEcMediaImport()` dove `get_headers($url, 1)[0]` su un URL non raggiungibile ritorna `false` e finisce comunque nel messaggio fuorviante "non è un'immagine" — fix scoped al solo nuovo codice UGC, `EcMediaImportService` esistente non viene toccato
- [ ] Verificare se `AppClassificationService`/`GeometryComputationService` (che referenziano già `UgcMedia`, oggi inerti perché la classe non esiste) sono raggiungibili da un punto attivo del codice: se sì, vanno adattati per non assumere un modello `UgcMedia` inesistente; se sono dead code non raggiungibile, documentarlo senza modificarli
- [ ] Verifica end-to-end: `php artisan wm:import-from-geohub app 49 --dependencies=...,ugc_poi,ugc_track,ugc_media` popola `ugc_pois`/`ugc_tracks` con autori mappati correttamente (verifica a campione di `user_id`, non solo conteggio) e foto allegate come media Spatie sui rispettivi POI/Track
- [ ] Test Pest esplicito (non solo verifica manuale e2e) che asserisce `user_id` dell'UGC importato è l'autore reale GeoHub e **non** l'owner dell'app, con dati di test in cui i due differiscono — così un futuro refactor che reintroduce l'eredità di `BaseImportJob::transformData()` (che forza `user_id` = owner) nei job UGC fa fallire la CI invece di passare silenziosamente

## Rischi

- **`user_id` errato su tutti gli UGC importati** — il pattern ereditato da `BaseImportJob::transformData()` forzerebbe `user_id` = proprietario app invece dell'autore reale, un bug che passerebbe inosservato da un criterio di accettazione basato solo su conteggi. Mitigato con override esplicito nei job UGC + verifica a campione su `user_id` nel test e2e.
- **Race condition sulla creazione del ruolo Contributor** — creazione "pigra" al primo utilizzo, sotto Horizon con job paralleli su molti autori nuovi, fallirebbe per conflitto di unicità (job senza retry, `tries => 1`). Mitigato pre-seedando il ruolo via migration.
- **Foto UGC che spariscono senza errore visibile** — il pattern di riferimento (`ImportEcMediaJob`) inghiotte le eccezioni di download silenziosamente. Mitigato loggando esplicitamente ogni foto UGC non scaricata con il suo `geohub_id`, senza bloccare il resto del batch.
- **Impatto su altri consumer di wm-package** — includere UGC in `default_dependencies['app']` cambierebbe il comportamento di default anche per osm2cai2 senza alcuna azione da parte loro. Mitigato tenendo UGC opt-in via flag esplicito, documentato.
- **Riattivazione involontaria di codice con assunzioni sbagliate** — `AppClassificationService`/`GeometryComputationService` referenziano già `UgcMedia`; da verificare raggiungibilità prima di assumere che sia solo dead code (vedi Requisiti).
- **Nessun indice su `properties->geohub_id`** per `ugc_pois`/`ugc_tracks` — ogni controllo di idempotenza fa seq-scan; limitazione preesistente nel pattern di import (stessa di EC/Layer/Taxonomy), non introdotta da questo ticket, non affrontata qui.
- **Utenti multi-ruolo**: un utente già Guest/Editor/Validator su un'altra app che risulta anche autore UGC in questa non riceve Contributor (`roles->isEmpty()` lo esclude) — comportamento coerente con "non sovrascrivere ruoli manuali", accettato senza ulteriore gestione.
- **Owner che diventa Contributor "prima" di essere owner altrove, mai promosso a Editor** — se un utente risulta solo autore UGC in un'app A (riceve `Contributor`, `roles` era vuoto) e in un secondo momento (import di un'app B, anche a distanza di tempo) risulta owner di B, `assignEditorRole()` trova `roles->isNotEmpty()` (ha già Contributor) e **non assegna mai Editor** — l'owner resta bloccato senza accesso Nova. Verificato che entro un singolo run non si verifica (l'owner viene risolto sincronamente in `ImportAppJob::transformData()` prima che i job UGC async assegnino Contributor), ma il caso cross-app/cross-run rimane possibile. **Accettato come limite noto, guard `roles->isEmpty()` implementato esattamente come da ticket, senza logica di precedenza aggiuntiva** — se si presenta in produzione, richiede correzione manuale del ruolo utente da Nova.
- **Volume dati e file**: un'app con molti UGC può allungare sensibilmente tempo di import e traffico di download — mitigato dal fatto che l'import gira già su coda dedicata (`geohub-import`).
- **Mismatch di timezone nel confronto `updated_at` (GeoHub `Europe/Rome`, Maphub `UTC`)** — verificato: `config('app.timezone')` è `Europe/Rome` hardcoded su GeoHub, `UTC` di default su Maphub; nessuna conversione esplicita fatta oggi su questi campi (mai confrontati finora, solo copiati). Il confronto "è più recente?" per l'update-if-newer può sballare di 1-2 ore (DST incluso). **Accettato senza fix**: nel caso peggiore un record viene aggiornato/non aggiornato con un ritardo di qualche ora rispetto all'ideale, non è un problema bloccante.

## Out of scope

- Sincronizzazione bidirezionale Maphub → GeoHub
- Un flag/lock esplicito di "modificato localmente" per proteggere eventuali edit fatti da Nova sugli UGC importati — non necessario, dato che l'unico autore possibile di un UGC è l'utente finale via app (vedi "Comportamento update-if-newer")
- Riattivazione del flusso di signup/delete via `AuthController` legacy (dead code, non toccato da questo ticket)
- Migrazione retroattiva automatica delle app già importate prima di questo ticket (per portare i loro UGC servirà rilanciare manualmente `wm:import-from-geohub app <id> --dependencies=...`)
- Azioni di moderazione UGC lato Nova (valida, elimina, ecc.) oltre alla semplice visibilità in index/detail già esistente su `UgcPoi`/`UgcTrack`

## Moduli toccati

**wm-package** (repo `wm/wm-package`, submodule):
- `config/wm-geohub-import.php` — nuove entry `import_mapping` per `ugc_poi`, `ugc_track`, `ugc_media` (opt-in, `default_dependencies['app']` non modificato)
- `src/Services/Import/GeohubImportService.php` — `checkUgcUserExistence()`, `assignContributorRole()`, logica update-if-newer per UGC, mapping `app_id` GeoHub (numerico-come-stringa) → locale
- `src/Services/RolesAndPermissionsService.php` — aggiunta `Contributor` a `seedDatabase()`
- `src/Jobs/Import/ImportUgcPoiJob.php`, `ImportUgcTrackJob.php` (nuovi, con `BaseUgcImportJob` aggiornato per update-if-newer invece di create-only)
- `src/Jobs/Import/ImportUgcMediaJob.php` (nuovo, analogo a `ImportEcMediaJob`: nessun modello dedicato, allega Spatie media al `UgcPoi`/`UgcTrack` risolto via pivot GeoHub)
- `src/Jobs/Import/ImportAppJob.php` — estensione dependencies per `ugc_poi`/`ugc_track`/`ugc_media`, ordine di dispatch (poi/track prima di media)
- `database/migrations/zz_*_add_contributor_role.php.stub` (nuova)
- `tests/Feature/Import/*` (nuovi test Pest per import UGC)

**maphub** (repo principale) — vedi `overview.md` dedicato nella stessa cartella: solo publish della migration stub `Contributor`, nessun codice applicativo aggiuntivo previsto.

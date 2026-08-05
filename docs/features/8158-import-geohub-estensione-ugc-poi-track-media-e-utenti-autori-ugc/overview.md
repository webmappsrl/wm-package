> Ticket: oc:8158

# Import GeoHub: estensione UGC (POI, Track, Media) e utenti autori UGC

## Cosa cambia

Il comando `wm:import-from-geohub app <id>` viene esteso per importare, oltre ai contenuti editoriali già gestiti, anche i contributi degli utenti finali (UGC):

- `ugc_pois` e `ugc_tracks` (modelli già esistenti in wm-package, oggi mai popolati dall'import GeoHub)
- `ugc_media` — nuovo modello/tabella (assente oggi in wm-package/Maphub), con pivot `ugc_media_ugc_poi` / `ugc_media_ugc_track` verso POI e Track, mirror dello schema GeoHub
- i file foto vengono scaricati dallo storage pubblico GeoHub e salvati sullo storage locale Maphub (stesso pattern di `EcMediaImportService::processEcMediaImport()`)

Gli utenti che risultano autori di UGC (e non hanno già un ruolo) ricevono il nuovo ruolo **Contributor** (introdotto in questo ticket, pattern identico a `Editor` in oc:8042). Il proprietario dell'app mantiene il ruolo Editor esistente, invariato.

Viene inoltre aggiunta una risorsa Nova base per `UgcMedia`, coerente con `UgcPoi`/`UgcTrack` già presenti nel menu.

`ugc_poi`, `ugc_track`, `ugc_media` sono **opt-in**, non nel default: si importano solo passando esplicitamente `--dependencies=...,ugc_poi,ugc_track,ugc_media` (o sottoinsieme) al comando. `wm-package` è condiviso con altri consumer (osm2cai2 ha già le credenziali GeoHub configurate) — cambiare il comportamento di default del comando impatterebbe silenziosamente chiunque altro lo lanci senza flag, non solo Maphub. Il flag va documentato in modo prominente per chi lancia migrazioni su Maphub, per non incorrere nel rischio opposto (dimenticarlo e ottenere una migrazione incompleta).

**Comportamento create-only** (diverso dal resto dell'import): un record UGC già importato per un dato `geohub_id` non viene mai aggiornato da un successivo re-import. A differenza dei contenuti editoriali (dove GeoHub resta sempre fonte di verità e un re-import sovrascrive i campi locali via `GeohubImportService::importData()`), per gli UGC la fonte di verità diventa Maphub subito dopo la prima importazione — protegge da sovrascritture silenziose di eventuali azioni di moderazione fatte in locale.

**Autore reale, non owner dell'app**: `BaseImportJob::transformData()` (ereditato da tutti i job di import, incluso il pattern che i job UGC replicherebbero) forza `user_id` = proprietario dell'app quando è settato `app_id` — corretto per i contenuti editoriali, sbagliato per gli UGC (ogni riga ha un autore proprio). I job UGC devono sovrascrivere esplicitamente questo comportamento e preservare l'autore reale mappato da GeoHub.

**Ruolo Contributor pre-seedato, non creato al volo**: a differenza del pattern `assignEditorRole()` (che crea il ruolo "pigramente" al primo utilizzo se assente), il ruolo `Contributor` viene creato dalla migration stub stessa — sempre presente prima che qualsiasi job di import UGC possa girare. Evita una race condition concreta: un import con molti autori nuovi lancia job paralleli su Horizon, e una creazione "pigra" concorrente del ruolo genererebbe un conflitto di unicità che farebbe fallire senza retry (`tries => 1`) i job arrivati "secondi".

## Perché

Oggi `wm:import-from-geohub` migra solo i contenuti editoriali (EC POI, EC Track, layer, media, taxonomy) e il proprietario dell'app. I contributi UGC creati dagli utenti finali — POI, track e le relative foto — non vengono importati, né i loro autori. Per le app in fase di migrazione da GeoHub a Maphub questo significa una perdita di dati reale: i contenuti generati dagli utenti finali dell'app (spesso il valore principale per il cliente) restano indietro su GeoHub.

## Requisiti

- [ ] `ugc_pois` e `ugc_tracks` dell'app importata vengono creati su Maphub con `app_id` mappato all'ID locale (non più la stringa GeoHub) e `user_id` mappato all'**autore reale** GeoHub (non il proprietario dell'app) — richiede override esplicito di `transformData()` nei job UGC, che altrimenti erediterebbero da `BaseImportJob` la forzatura `user_id = owner app` (corretta per EC, sbagliata per UGC)
- [ ] Nuovo modello `UgcMedia` (tabella dedicata, non Spatie Media Library) con relazioni pivot `ugc_media_ugc_poi` / `ugc_media_ugc_track`, mirror 1:1 dello schema GeoHub (`user_id`, `app_id`, `geometry`, `relative_url`, `properties`)
- [ ] File foto UGC scaricati dallo storage pubblico GeoHub (URL costruito come già fa `EcMediaImportService`, es. `config('wm-package.clients.geohub.host').'/storage/'.$relative_url`) e salvati sullo storage locale Maphub configurato; un download fallito per un singolo media viene loggato esplicitamente con il suo `geohub_id` (non inghiottito silenziosamente come nel pattern `ImportEcMediaJob` esistente) e non blocca l'import degli altri record del batch
- [ ] Nuovo ruolo `Contributor` creato dalla **migration stub stessa** (non "pigramente" al primo utilizzo come `assignEditorRole()`) — sempre presente prima che un job di import UGC possa girare, evita una race condition di creazione concorrente su import con molti autori nuovi in parallelo; aggiunto anche a `RolesAndPermissionsService::seedDatabase()` per coerenza con gli altri ruoli
- [ ] Utenti importati come autori UGC ricevono `Contributor` solo se `$user->roles->isEmpty()` — nessuna sovrascrittura di ruoli già assegnati manualmente
- [ ] Il proprietario dell'app continua a ricevere il ruolo `Editor` esistente (oc:8042), invariato da questo ticket
- [ ] `ugc_poi`, `ugc_track`, `ugc_media` restano **opt-in** (flag esplicito `--dependencies=...,ugc_poi,ugc_track,ugc_media`), **non** aggiunti a `default_dependencies['app']` — per non alterare il comportamento di default del comando per altri consumer di wm-package (es. osm2cai2, già configurato con credenziali GeoHub). Il flag va documentato in modo prominente (help del comando + `docs/features/`) per chi lancia migrazioni su Maphub
- [ ] Un record UGC (poi/track/media) viene creato solo se non esiste già localmente per lo stesso `geohub_id` — **mai aggiornato** da re-import successivi dello stesso `app_id` (comportamento create-only, diverso da EC)
- [ ] Un record UGC con `user_id` GeoHub nullo viene scartato con log di warning, senza interrompere l'import degli altri record del batch (caso mai osservato nei dati reali — 0 su oltre 21.000 righe UGC totali su GeoHub — ma lo schema lo permette)
- [ ] Nuova risorsa Nova `UgcMedia` (index/detail base, nessuna azione custom) registrata nel menu Nova di Maphub, stesso pattern di `UgcPoi`/`UgcTrack`
- [ ] Verificare se `AppClassificationService`/`GeometryComputationService` (che referenziano già `UgcMedia`, oggi inerti perché la classe non esiste) sono raggiungibili da un punto attivo del codice: se sì, allinearli al nuovo schema (`app_id` integer, non stringa SKU); se sono dead code non raggiungibile, documentarlo senza modificarli
- [ ] Verifica end-to-end: `php artisan wm:import-from-geohub app 49 --dependencies=...,ugc_poi,ugc_track,ugc_media` popola `ugc_pois` (47 attesi), `ugc_tracks` (74 attesi), `ugc_media` (18 attesi) con pivot media↔POI/Track coerenti, autori con ruolo Contributor **e verifica a campione che `user_id` corrisponda all'autore reale GeoHub** (non solo il conteggio totale delle righe)

## Rischi

**Emersi in challenge, con mitigazione concordata (vedi Requisiti):**

- **`user_id` errato su tutti gli UGC importati** — il pattern ereditato da `BaseImportJob::transformData()` forzerebbe `user_id` = proprietario app invece dell'autore reale, un bug che passerebbe inosservato dal criterio di accettazione originale (basato solo su conteggi). Mitigato con override esplicito nei job UGC + verifica a campione su `user_id` nel test e2e.
- **Race condition sulla creazione del ruolo Contributor** — creazione "pigra" al primo utilizzo, sotto Horizon con job paralleli su molti autori nuovi, può fallire per conflitto di unicità (job senza retry, `tries => 1`). Mitigato pre-seedando il ruolo via migration invece che al volo.
- **Foto UGC che spariscono senza errore visibile** — il pattern di riferimento (`ImportEcMediaJob`) inghiotte le eccezioni di download silenziosamente. Mitigato loggando esplicitamente ogni media UGC non scaricato con il suo `geohub_id`, senza bloccare il resto del batch.
- **Impatto su altri consumer di wm-package** — includere UGC in `default_dependencies['app']` avrebbe cambiato il comportamento di default del comando anche per osm2cai2 (già configurato con credenziali GeoHub) senza alcuna azione da parte loro. Mitigato tenendo UGC opt-in via flag esplicito, documentato per non incorrere nel rischio opposto (migrazioni Maphub incomplete per flag dimenticato).
- **Riattivazione involontaria di codice con schema incompatibile** — `AppClassificationService`/`GeometryComputationService` referenziano già `UgcMedia` assumendo `app_id` come stringa SKU; creare il modello reale li rende eseguibili con l'assunzione sbagliata. Da verificare raggiungibilità in fase di implementazione (vedi Requisiti) — se dead code, solo da documentare.

**Accettati e documentati, nessuna azione richiesta in questo ciclo:**

- **Sovrascrittura contenuti EC esistente ≠ comportamento UGC**: introdurre un percorso "create-only" separato dal resto della pipeline (che è sempre update-or-create) aumenta la complessità del codice — mitigato isolando la logica in un metodo/branch dedicato negli import job UGC, senza toccare `GeohubImportService::importData()` usato da EC/Layer/Taxonomy. Conseguenza accettata: se un bug producesse dati UGC sbagliati alla prima importazione, correggerli richiede intervento manuale (uno script ad-hoc), perché il design create-only impedisce a un secondo run di correggere record già esistenti
- **Nessun indice su `properties->geohub_id`** per `ugc_pois`/`ugc_tracks`/`ugc_media` — ogni controllo di idempotenza fa seq-scan; limitazione preesistente nel pattern di import (stesso identifier usato da EC/Layer/Taxonomy), non introdotta da questo ticket, non affrontata qui
- **`UgcMedia` come tabella dedicata invece di Spatie Media Library**: reimplementa da zero funzionalità che Spatie offre gratis a `EcMedia` (conversions, cleanup, URL generation) — scelta deliberata per rispecchiare 1:1 lo schema GeoHub (che ha `user_id`/`geometry` propri sul media, incompatibile con una collection Spatie), accettata come debito tecnico
- **Volume dati e file**: un'app con molti UGC (osservato: fino a ~12.000 media su tutto GeoHub, 18 sulla sola app 49 di test) può allungare sensibilmente il tempo di import e il traffico di download file — mitigato dal fatto che l'import gira già su coda dedicata (`geohub-import`) con retry configurato
- **Correzione a una premessa iniziale del ticket**: la nota tecnica originale ipotizzava `ugc_*.app_id` su GeoHub come stringa SKU (`it.webmapp.*`); verificato sul DB reale che è invece l'ID numerico dell'app come stringa (es. `'49'`) — il filtro per app in fase di import userà quindi un semplice cast/confronto numerico, non una lookup su `apps.app_id`
- **Ruolo Contributor introdotto ma non attivato altrove**: esiste già codice dormiente nel package (`AuthController::assignRole()`/`delete()`) che presume Contributor esistente, ma non è instradato in nessuna route attiva (le route reali usano `AppAuthController`, che non assegna ruoli). Questo ticket non riattiva quel codice — resta dead code, il nuovo ruolo serve solo per gli autori UGC importati. Un futuro rollback del ruolo Contributor richiederebbe attenzione a questo accoppiamento silenzioso
- **Utenti multi-ruolo**: un utente già Guest/Editor/Validator su un'altra app che risulta anche autore UGC in questa non riceve Contributor (`roles->isEmpty()` lo esclude) — comportamento coerente con "non sovrascrivere ruoli manuali", accettato senza ulteriore gestione

## Out of scope

- Sincronizzazione bidirezionale Maphub → GeoHub
- Aggiornamento di UGC già importati in un secondo momento (per design: create-only, mai più toccati da re-import)
- Riattivazione del flusso di signup/delete via `AuthController` legacy (dead code, non toccato da questo ticket)
- Migrazione retroattiva automatica delle app già importate prima di questo ticket (per portare i loro UGC servirà rilanciare manualmente `wm:import-from-geohub app <id>`)
- Azioni di moderazione UGC lato Nova (nascondi, elimina, ecc.) oltre alla semplice visibilità in index/detail

## Moduli toccati

**wm-package** (repo `wm/wm-package`, submodule):
- `config/wm-geohub-import.php` — nuove entry `import_mapping` per `ugc_poi`, `ugc_track`, `ugc_media` (opt-in, `default_dependencies['app']` non modificato)
- `src/Services/Import/GeohubImportService.php` — `MODEL_IMPORT_ORDER`, nuovo `assignContributorRole()`, mapping `app_id` GeoHub → locale per UGC
- `src/Services/RolesAndPermissionsService.php` — aggiunta `Contributor` a `seedDatabase()`
- `src/Jobs/Import/ImportUgcPoiJob.php`, `ImportUgcTrackJob.php`, `ImportUgcMediaJob.php` (nuovi)
- `src/Jobs/Import/ImportAppJob.php` — estensione dependencies/relations per `ugc_poi`/`ugc_track`/`ugc_media`, gestione ordine di dispatch (utenti → poi/track → media → pivot)
- `src/Models/UgcMedia.php` (nuovo modello)
- `src/Nova/UgcMedia.php` (nuova risorsa Nova base)
- `database/migrations/*.stub` (nuove: tabella `ugc_media`, pivot `ugc_media_ugc_poi`/`ugc_media_ugc_track`, ruolo `Contributor`)
- `tests/Feature/Import/*` (nuovi test Pest per import UGC)

**maphub** (repo principale):
- `database/migrations/` — pubblicazione degli stub wm-package (`wm-package:publish-missing-migrations`)
- `app/Nova/UgcMedia.php` (nuovo stub locale, estende `Wm\WmPackage\Nova\UgcMedia`, pattern identico a `UgcPoi`/`UgcTrack`)
- `app/Providers/NovaServiceProvider.php` — `MenuItem::resource(UgcMedia::class)` nel menu esistente

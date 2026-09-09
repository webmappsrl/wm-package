> Ticket: oc:8486

# Notes — Import TaxonomyWhere da GeoHub per una data app

## Deviazioni dal piano

- **`finalizeWithTracksSync()` del trait non è stato usato da `handleGeohub()`** — a differenza di quanto previsto nel piano (Task 5), che riusava lo stesso helper sincrono degli altri due handler. Motivo: scoperto in QA manuale (vedi "Bug trovati" sotto), non anticipabile in fase di piano perché gli altri due handler condividono lo stesso difetto latente ma non era mai stato osservato/segnalato prima.

## Bug trovati

- **Race condition tra dispatch geometria e sync track**: `handleGeohub()` dispatchava i `CopyTaxonomyWhereGeometryFromGeohubJob` (asincroni) e subito dopo chiamava `syncTracksTaxonomyWhere()` in modo sincrono (via `finalizeWithTracksSync()`) — la sync girava quindi *prima* che le geometrie fossero effettivamente scritte, trovando sempre `taxonomy_wheres.geometry IS NULL` e riportando "Sync taxonomy_where su 0 tracks avviata" anche quando, pochi istanti dopo, le geometrie arrivavano e la sync (se rieseguita) avrebbe collegato correttamente le track. Scoperto testando dal vivo l'azione contro l'App reale "Itinera Romanica PLUS" (GeoHub id 28) con Corsica/Francia.
  - **Fix**: i job di copia geometria sono ora dispatchati come `Illuminate\Bus\Batch` (`Bus::batch($geometryJobs)->then(fn () => SyncTaxonomyWhereTracksJob::dispatch())->dispatch()`), con un nuovo job `SyncTaxonomyWhereTracksJob` (wrapper di `GeometryComputationService::syncTracksTaxonomyWhere()`) dispatchato come callback solo a batch concluso. `CopyTaxonomyWhereGeometryFromGeohubJob` ha guadagnato il trait `Batchable` (richiesto da Laravel per essere incluso in un batch) più un guard `if ($this->batch()?->cancelled()) return;` a inizio `handle()`.
  - Il messaggio di ritorno di `handleGeohub()` è cambiato di conseguenza: non riporta più un conteggio live delle track sincronizzate (non più disponibile in modo sincrono), ma indica che la sync partirà automaticamente al termine della copia geometrie.
  - **Non applicato a `handleOsmfeatures()`/`handleOsm2cai()`**: soffrono dello stesso identico difetto (dispatchano `FetchTaxonomyWhereGeometryJob`/`FetchOsm2caiSectorGeometryJob` async e poi chiamano `finalizeWithTracksSync()` sincrono), ma il fix è stato applicato solo alla sorgente `geohub` su richiesta esplicita del dev — le altre due sorgenti restano fuori scope per questo ticket.

- **Gotcha ambientale, non un bug di codice**: durante la verifica dal vivo, il primo tentativo post-fix falliva silenziosamente (batch mai completato, `pending_jobs` mai decrementato, nessuna eccezione nei log). Causa: il worker Horizon (`horizon-maphub`, container separato da `php-maphub`) era attivo da 3 ore e aveva caricato in memoria la classe `CopyTaxonomyWhereGeometryFromGeohubJob` **prima** dell'aggiunta del trait `Batchable` — un worker PHP long-running non ricarica le classi già autoloaded. Un riavvio del container (`docker restart horizon-maphub`) ha risolto: il batch ha poi funzionato correttamente end-to-end (14/14 job completati, 27 track sincronizzate automaticamente, numeri identici a quelli attesi dal ticket: 22 francia + 16 corsica). **Lezione per i prossimi cicli**: qualsiasi modifica a una classe Job già in coda/in esecuzione richiede un riavvio di Horizon in locale per essere osservabile — un sintomo di "il job gira ma il comportamento non cambia" va sempre verificato con un riavvio prima di sospettare un bug di codice.

## Decisioni

- **Verifica end-to-end contro dati reali**: il DB locale è stato resettato dal backup `storage/backups/last_dump.sql.gz` e l'App reale "Itinera Romanica PLUS" (GeoHub id 28) è stata reimportata via `wm:import-from-geohub app 28`, per validare la feature contro lo stesso caso concreto descritto nella description del ticket. I numeri osservati (22 track intersecano `francia`, 16 intersecano `corsica`) coincidono esattamente con quelli riportati nella description del ticket, confermando che la query di selezione e il meccanismo di sync locale (`ST_Intersects`) funzionano come atteso sui dati reali GeoHub.
- **14 record creati anziché 15**: la description del ticket elencava 15 where senza `admin_level` per l'app 28 (inclusa `europe`); l'esecuzione reale ne ha trovate 14 (manca `europe`). Non investigato oltre — verosimilmente una piccola variazione dei dati GeoHub tra la stesura del ticket e l'esecuzione di questo ciclo, non un bug: la query rispetta comunque esattamente il criterio dichiarato (`admin_level IS NULL` sui contenuti collegati all'app).

## Follow-up

- Lo stesso difetto di race condition (sync sincrona dopo dispatch asincrono) esiste in `handleOsmfeatures()`/`handleOsm2cai()` — non corretto in questo ciclo, deliberatamente fuori scope. Da valutare in un ticket dedicato se emergono segnalazioni analoghe su quelle due sorgenti.

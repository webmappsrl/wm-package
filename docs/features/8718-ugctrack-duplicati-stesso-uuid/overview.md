> Ticket: oc:8718

# UgcTrack duplicati con lo stesso uuid: replicare il fix fatto per gli UgcPoi (oc:6951)

## Cosa cambia

1. **Store idempotente per uuid.** `POST api/ugc/track/store` (e `api/v2/ugc/track/store`, stesso controller) ritrova la traccia con lo stesso `properties.uuid` e la **aggiorna** invece di crearne una nuova, come già fa `UgcPoiController` da oc:6951.
2. **Properties unite, non sostituite.** Quando lo store aggiorna un UGC esistente (traccia o POI), le properties ricevute vengono applicate **sopra** quelle salvate: vincono quelle ricevute, ma le chiavi che l'app non manda (es. `layer_id` calcolato dal server, chiavi custom dei progetti) restano. `taxonomy_where` viene ricalcolato come oggi.
3. **Immagini senza doppioni.** In `UgcController::fillModelWithRequest()` (condiviso da store ed edit, tracce e POI) un'immagine ricevuta viene salvata solo se il suo contenuto (sha256) è diverso da quello delle immagini già associate al record.
4. **Command di normalizzazione** `wm:fix-duplicated-ugc` per tracce e POI già duplicati (`--type=tracks|pois`, di default entrambi), generico (nessun campo specifico di un progetto):
   - di default solo report (tabella a schermo), con `--execute` applica; ogni gruppo e ogni lancio, con l'esito, finiscono nel canale di log `duplicated-ugc` (anche i gruppi "da verificare"); la traccia permanente di cosa è stato unito è la tabella `ugc_duplicates_archive`;
   - per ogni uuid con più righe il **padre** è la riga più vecchia (`created_at`, poi `id`);
   - **geometria**: resta quella del padre; se una copia si discosta oltre 1 m (`ST_HausdorffDistance`) il gruppo non viene toccato e finisce nel report come "da verificare";
   - **properties**: le copie si applicano sopra il padre in ordine di data (`properties.updatedAt` del device, poi `updated_at`, poi `id`), chiave per chiave al primo livello; `id`, `created_at`, `updated_at`, `taxonomy_where`/`taxonomyWheres` restano quelli del padre; `taxonomy_where` non si ricalcola, perché la geometria del padre non cambia;
   - **media**: spostati sul padre, scartando quelli con lo stesso sha256 di uno già presente;
   - **riferimenti esterni** verso le copie (FK su `ugc_tracks`): non gestiti in questo ciclo, su `develop` di camminiditalia non ce ne sono; vedi [docs/knowledge/aggiornare-wm-package-su-osm2cai2.md](../../knowledge/aggiornare-wm-package-su-osm2cai2.md);
   - **copie**: archiviate nella nuova tabella `ugc_duplicates_archive` e poi cancellate, un gruppo per transazione.
5. **Tabella `ugc_duplicates_archive`** (unica per tracce e POI): `model_type`, `original_id`, `reference_id` (senza FK), `uuid`, `user_id`, `app_id`, `properties` (jsonb), `geometry` (senza vincolo di tipo), `media_ids`, `original_created_at`, `original_updated_at`, `archived_at`. Ci scrivono sia le tracce sia i POI.

## Perché

L'app (wm-core, `UgcService`) tiene la traccia in coda sul device finché la store non risponde con successo; su qualunque errore (timeout, rete, upload immagini fallito a metà) la rimanda con lo **stesso uuid** e **tutte le immagini**, dopo 60 s o alla riapertura dell'app. Il backend oggi crea sempre una riga nuova: su camminiditalia 15 uuid duplicati per 33 righe, geometrie identiche, immagini sparse tra le copie (es. `09885316`: 0 immagini sulla prima copia, 2 sulla seconda).

Il fix dei POI di oc:6951 legge l'uuid con `$request->input('properties.uuid')`, ma l'app manda un multipart con la feature in JSON nel campo `feature`: per le richieste dell'app l'uuid risulta `null` e il fix non scatta. Verificato il 07/10/2026 con due richieste HTTP reali a `api/v2/ugc/poi/store` sul DB di sviluppo: due POI creati con lo stesso uuid. La ricerca si sposta quindi in `UgcController::store()` sui dati validati, e vale per tracce e POI.

Per le tracce di camminiditalia il `layer_id` lo scrive sempre il server (`populateLayerId()`, solo alla creazione: nessuna delle 285 tracce ha `form.layer_id`) e l'app non lo rimanda mai: sostituire le properties a ogni retry lo cancellerebbe, compreso quello corretto a mano dall'Administrator (caso traccia 321, oc:8466). Da qui l'unione invece della sostituzione.

## Requisiti

- [ ] `UgcController::store()` ritrova il record con lo stesso `properties.uuid` (letto dai dati validati, perché l'app manda il JSON nel campo `feature` del multipart) tra gli UGC dell'utente autenticato e lo aggiorna, altrimenti ne crea uno nuovo; con duplicati già presenti prende il più vecchio (`orderBy('id')`); vale per tracce e POI
- [ ] Lo store su un UGC esistente unisce le properties ricevute a quelle salvate (vincono le ricevute); vale per tracce e POI
- [ ] Lo store su un UGC nuovo e l'edit (`legacyUpdate`, `updateV3`) restano invariati per le properties
- [ ] `fillModelWithRequest()` scarta le immagini ricevute con sha256 uguale a un media già associato; l'hash viene salvato nei `custom_properties` del media all'upload; per i media che non ce l'hanno si calcola al volo scaricando il file, e si salva
- [ ] Migration (stub) per `ugc_duplicates_archive`
- [ ] Command di normalizzazione con report a schermo di default e `--execute`; un gruppo per transazione; idempotente (rilanciato non trova più nulla)
- [ ] Nel command: padre = più vecchio; geometria del padre; gruppi oltre 1 m saltati e segnalati, con la distanza calcolata **in metri** (la colonna è `geography`: in gradi la soglia non scatterebbe mai); properties unite in ordine di data esclusi i campi del server, e colonna `name` allineata a `properties.name`; media spostati con dedup sha256; riferimenti esterni non gestiti; copie archiviate e cancellate con SQL dopo lo spostamento; i file dei media scartati come doppioni si cancellano solo dopo il commit del gruppo
- [ ] Test: store con uuid nuovo/esistente/assente, unione delle properties (chiave non inviata conservata), dedup immagini per contenuto, command in report e in execute sui casi del DB (geometria uguale, geometria diversa, media sparsi, più i casi reali di camminiditalia; i riferimenti esterni non sono gestiti)

## Rischi

- **Cancellare un UgcTrack cancella anche i file dei suoi media** (Spatie) e applica le FK verso `ugc_tracks` (cascade o set null): i media vanno spostati sul padre **prima** della cancellazione, nella stessa transazione; backup del DB obbligatorio prima di `--execute`. Le FK non vengono gestite: su `develop` di camminiditalia non ce ne sono, per gli altri progetti vedi la pagina di conoscenza su osm2cai2.
- **Geometrie via ORM** (regola del package): risalvare un modello geometrico con Eloquent corrompe la geometria. Il command non risalva il padre con Eloquent: le properties si aggiornano e le copie si archiviano con SQL puro (`INSERT … SELECT` per l'archivio, `UPDATE` della sola colonna `properties` per il padre).
- **Hash dei media esistenti**: calcolarlo richiede di scaricare il file da S3; serve solo per i media salvati prima del deploy, costo accettabile sui numeri attuali (87 media sulle tracce di camminiditalia).
- **Limite noto, accettato**: se un utente modifica sul device una traccia non ancora sincronizzata e intanto l'Administrator ne corregge il cammino, la correzione resta (l'app non manda `layer_id`) — salvo progetti il cui form tracce contenga `layer_id`, dove un retry tardivo riporta la scelta dell'utente.
- **Race tra due richieste simultanee**: nessun indice unico su `properties->>'uuid'`, due store nello stesso istante creano ancora due righe. Dentro una stessa app le sync sono serializzate (`syncQueue`), quindi è raro; un nuovo lancio del command lo copre.
- **File S3 fuori dalla transazione**: le cancellazioni di file fatte da Spatie non si annullano con il rollback; per questo i file dei media scartati si cancellano dopo il commit, e le copie si cancellano con SQL (senza l'evento Eloquent che rimuove i file).
- **Rollback**: non c'è un restore automatico dall'archivio; si torna indietro con il backup, l'archivio resta come traccia consultabile.
- **Edit (`updateV3`) e dedup immagini**: l'edit passa da `fillModelWithRequest()`, quindi anche lì un'immagine identica a una già presente viene scartata. Comportamento voluto.

## Out of scope

- Rilascio su osm2cai2: avverrà con l'attività separata di aggiornamento e collaudo del submodule.
- Riprodurre in wm-package il comportamento del command dei POI di osm2cai2 (copie marcate e assegnate a un utente tecnico): il command del package archivia e cancella, per tracce e POI.
- Scheduling del command (si lancia a mano).
- Indice unico su `properties->>'uuid'`.
- Correzioni lato app (gestione dell'id restituito dalla store, ordine push/fetch alla riapertura).

## Moduli toccati

wm-package:
- `src/Http/Controllers/Api/Abstracts/UgcController.php` (ricerca per uuid tra gli UGC dell'utente, unione properties, dedup immagini)
- `src/Http/Controllers/Api/UgcPoiController.php` (tolta la vecchia ricerca per uuid di oc:6951)
- `src/Services/Models/UgcMediaHashService.php`, `src/Services/Models/UgcDuplicatesService.php`
- `src/Commands/WmFixDuplicatedUgcCommand.php` (`wm:fix-duplicated-ugc`), registrato in `src/WmPackageServiceProvider.php`
- `database/migrations/zz_2026_10_07_000001_create_ugc_duplicates_archive_table.php.stub`
- `tests/Feature/`: `UgcStoreUuidTest`, `UgcStoreImageDedupTest`, `UgcStoreRetryRealCaseTest`, `WmFixDuplicatedUgcCommandTest`, `WmFixDuplicatedUgcTracksRealCaseTest`, `WmFixDuplicatedUgcPoisCommandTest`
- `docs/knowledge/duplicati-ugc-e-retry-dell-app.md`, `docs/knowledge/aggiornare-wm-package-su-osm2cai2.md`, `CLAUDE.md`

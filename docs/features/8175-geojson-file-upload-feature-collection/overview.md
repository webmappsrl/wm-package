> Ticket: oc:8175

# Add GeoJSON file upload for FeatureCollection upload mode

## Cosa cambia

Il form Nova della risorsa `FeatureCollection` aggiunge un campo `File` nel panel Source per caricare direttamente il GeoJSON dall'interfaccia admin. Il file viene salvato su MinIO tramite `StorageService::storeFeatureCollection()` (già esistente) e `file_path` viene aggiornato automaticamente. Il campo è sempre visibile nei form con help text "Only used in upload mode" (stesso pattern di External URL).

## Perché

Attualmente `mode: upload` è inutilizzabile da Nova — il campo file manca e l'unico workaround è caricare manualmente il GeoJSON su MinIO via tinker. Questo rende la modalità indisponibile a chi non ha accesso SSH al server. Il problema è emerso concretamente durante oc:7605 (configurazione overlay Forestas).

## Requisiti

- [x] Campo `File::make(__('GeoJSON File'), 'file_path')` nel panel `Source` di `FeatureCollection.php`
- [x] Campo sempre visibile nei form con `->help('Only used in upload mode')` (come External URL)
- [x] Guardia server-side nel callback `->store()`: se `mode !== 'upload'`, restituire `true` (Nova non tocca l'attributo) senza processare il file
- [x] Se `storeFeatureCollection()` restituisce `false`, restituire `true` per preservare il `file_path` precedente — **fix PR#238**: il valore `null` causava la sovrascrittura silenziosa del path esistente
- [x] Upload in Create supportato tramite `afterCreate()` statico sulla Resource
- [x] Re-upload sovrascrive il file esistente su MinIO (stesso path `/{shard}/{appId}/feature-collection/{id}.geojson`)
- [x] Tipi accettati: `.geojson`, `.json`
- [x] Dimensione massima: 20 MB (`->rules('max:20480')`)
- [x] Campo nascosto dall'index (`->hideFromIndex()`)
- [x] `->disk('wmfe')` per download corretto dal detail view
- [ ] Test coverage in `tests/Feature/Nova/FeatureCollectionUploadTest.php`: guard mode≠upload, guard no-file, update happy path, create via afterCreate

## Rischi

- **`app_id` null**: se l'utente non seleziona l'App durante la creazione, `storeFeatureCollection($model->app_id, ...)` riceve null. Mitigazione: il campo `app` è già `->required()` in Nova. Non protegge da chiamate API dirette o tinker.
- **Campo sempre visibile**: non usa `dependsOn` (vedi notes.md). Un utente può caricare un file anche con `mode !== 'upload'` — la guardia server-side nel `->store()` lo ignora comunque.
- **Nessun audit trail**: non c'è log di chi ha caricato un GeoJSON e quando. Se un overlay smette di funzionare per un file sovrascritto con dati sbagliati, non è possibile recuperare la versione precedente. Tech debt consapevole.

## Out of scope

- Visibilità condizionale del campo tramite `dependsOn` (vedi notes.md per motivazione)
- Cancellazione del file (`->deletable()`) — il re-upload diretto sovrascrive, la cancellazione non è richiesta
- Validazione del contenuto GeoJSON (struttura, geometrie valide)
- Migrazione automatica dei record esistenti con `mode=upload` e `file_path` null

## Moduli toccati

| File | Repo | Tipo |
|---|---|---|
| `src/Nova/FeatureCollection.php` | wm-package | Modifica |
| `tests/Feature/Nova/FeatureCollectionUploadTest.php` | wm-package | Nuovo |
| `docker/configs/phpfpm/php.ini` | forestas | Modifica |

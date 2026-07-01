> Ticket: oc:8175

# Notes — deviazioni dal piano

## 1. `dependsOn` rimosso — campo sempre visibile

**Piano originale:** campo GeoJSON File visibile solo con `mode === 'upload'` tramite `->dependsOn()`.

**Problema riscontrato:** in Nova 5, `dependsOn` per i `File` fields non si attiva al caricamento iniziale del form. Sul primo render la `FormData` è vuota (il GET su `update-fields` non porta il param `dependsOn`) — il campo risultava sempre nascosto anche con `mode=upload` già impostato. Tentativi con `->hide()`/`->show()`, `->hideWhenCreating()`/`->hideWhenUpdating()` + `showOnCreation = true` falliti per lo stesso motivo.

**Decisione:** campo sempre visibile nei form con `->help('Only used in upload mode')`, coerente con il pattern già usato da `External URL`. La guardia server-side nel `->store()` garantisce che il file non venga processato se `mode !== 'upload'`.

---

## 2. Upload in Create — `afterCreate` statico

**Piano originale:** file opzionale in Create, upload solo in Edit.

**Problema riscontrato:** il callback `->store()` gira prima del `$model->save()` — `$model->id` è null per i nuovi record → `storeFeatureCollection()` riceveva `null` come ID → TypeError.

**Decisione:** aggiunto `afterCreate(NovaRequest $request, Model $model)` statico sulla Resource. Gira dopo il salvataggio del record, quindi `$model->id` è disponibile. Il `->store()` callback salta silenziosamente se `$model->id === null` (guard), `afterCreate` gestisce il caso Create.

---

## 3. `->rules('max:20480')` invece di `max:10240`

**Piano originale:** limite 10 MB.

**Motivazione cambio:** il php.ini aveva `upload_max_filesize = 2M` (default), causando `validation.uploaded` su file da 2.3 MB. Alzato il php.ini a 20M e allineato il limite Nova a 20480 KB per coerenza.

---

## 4. `->disk('wmfe')` aggiunto

Non previsto nel piano. Necessario per il corretto funzionamento del Download nel detail view — senza, Nova cercava il file sul disco locale invece che su MinIO.

---

## 5. Test non eseguiti (ciclo 1) → risolto in ciclo 2

La nota originale dichiarava test in `FeatureCollectionUploadTest.php` mai creati — confermato da git history (PR review Alessandro Peci, PR#238). File inesistente, non un file con test falliti.

**Ciclo 2:** file creato correttamente in `tests/Feature/Nova/FeatureCollectionUploadTest.php` con quattro casi che usano `DatabaseTransactions` + `Storage::fake('wmfe')`.

---

## 6. Fix `->store()` callback — `null` → `true` (ciclo 2)

**Bug trovato in review PR#238:** il callback ritornava `null` nei tre casi di guard (`mode !== 'upload'`, `file === null`, `storeFeatureCollection() === false`). Nova interpreta `null` come "imposta l'attributo a null", distruggendo il `file_path` esistente in ogni update che non carica un file.

**Fix:** tutti i `return null` nei guard sostituiti con `return true`. Nova interpreta `true` come "non toccare l'attributo".

---

## 7. `overlays_label` NOT NULL in forestas — workaround nei test

La tabella `apps` di forestas ha la colonna `overlays_label NOT NULL` (aggiunta da una migration locale non presente nel wm-package). Il `AppFactory` del package non la popola → `QueryException` su tutti i test che usano `App::factory()` in questo progetto (bug preesistente, visibile anche in `FeatureCollectionModelTest.php`). Workaround nei nostri test: `App::factory()->createQuietly(['overlays_label' => 'Layers'])`.

---

## 8. Fix post-review: `afterCreate` lancia eccezione su storage failure

**Trovato in review:** se `storeFeatureCollection()` ritorna `false`, l'utente non riceveva nessun errore — il record veniva creato con `file_path = null` in silenzio.

**Fix:** sostituito `if ($path) { $model->update(...) }` con eccezione esplicita quando `$path === false`. Nova cattura l'eccezione e mostra un messaggio di errore all'utente.

---

## 9. Fix post-review: `updateQuietly()` per evitare doppio dispatch observer

**Trovato in review:** `$model->update(['file_path' => $path])` in `afterCreate` triggerava l'observer `saved` una seconda volta → `UpdateAppConfigJob` dispatched due volte su ogni create con file upload.

**Fix:** sostituito con `$model->updateQuietly(['file_path' => $path])`.

---

## 10. `storageCallback` è public — reflection non necessaria

Ipotesi iniziale per i test: accesso via reflection alla closure del callback. Verificando `Laravel\Nova\Fields\File`, la property `$storageCallback` è `public`. Accesso diretto: `$field->storageCallback`.

---

## 11. `return $path ?: true` — ritorna la stringa del path nel happy path

**Deviazione da plan.md §Task4:** il piano descriveva "tutti i return null sostituiti con return true", ma il terzo caso (storage riuscito) restituisce `$path` (stringa), non `true`. `return true` avrebbe detto a Nova "non toccare l'attributo" → `file_path` non sarebbe mai scritto su DB in update. La stringa ritornata viene usata da Nova come valore da persistere.

In Ciclo 3 il codice è stato ristrutturato esplicitamente per chiarezza:
```php
if (! $path) {
    Log::warning('FeatureCollection GeoJSON upload failed', ['fc_id' => $model->id]);
    return true;
}
return $path;
```

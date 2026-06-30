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

## 5. Test non eseguiti

I test scritti in `tests/Feature/Nova/FeatureCollectionUploadTest.php` non sono stati eseguiti con successo: il DB `wm_package` necessitava di PostGIS e permessi schema. L'implementazione è stata verificata manualmente in Nova. I test restano nel repo ma vanno ricontrollati prima del prossimo CI.

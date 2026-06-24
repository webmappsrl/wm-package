> Ticket: oc:8041

# Import POI: associazione taxonomy type non funzionante

## Cosa cambia

`ImportTaxonomyJob::processDependencies()` usa `$model->properties['geohub_id']` (ID GeoHub del taxonomy type appena salvato) invece di `$this->entityId` per chiamare `getTaxonomyMorphableRecords()`. La semantica è identica ma il valore è letto direttamente dalla proprietà del modello locale, eliminando qualsiasi dipendenza dall'ID passato al costruttore del job.

## Perché

Dopo il fix di oc:8013 (`$model->id` → `$this->entityId`), i POI importati rimangono ancora senza associazione taxonomy type. La causa è che `$this->entityId` — pur essendo concettualmente corretto — può divergere da `$model->properties['geohub_id']` in scenari di re-import o stato inconsistente del DB. Il valore autoritativo è quello salvato nel modello locale dopo l'import, ovvero `$model->properties['geohub_id']`.

## Requisiti

- [ ] `ImportTaxonomyJob::processDependencies()` chiama `getTaxonomyMorphableRecords()` passando `$model->properties['geohub_id']` invece di `$this->entityId`
- [ ] Il fix si applica a tutti i job che estendono `ImportTaxonomyJob` (activity, theme, poi_types) senza modifiche aggiuntive
- [ ] Import con APP ID 60 e 63 produce EcPoi con taxonomy type associati correttamente

## Rischi

- Nessun rischio architetturale: è un one-liner su un parametro, nessuna logica nuova.
- Se `$model->properties` fosse null o privo di `geohub_id`, si avrebbe un errore PHP. Tuttavia `transformProperties()` garantisce sempre la presenza di `geohub_id` dopo il save — il rischio è teorico.

## Out of scope

- Fix bidirezionale (dal lato EcPoi che cerca le taxonomy già importate) — rimandato
- Test automatici del flusso di import
- Altre taxonomy (activity, theme) non sono oggetto di verifica specifica in questo ticket

## Moduli toccati

| File | Repo |
|------|------|
| `src/Jobs/Import/ImportTaxonomyJob.php` | `wm-package` |

> Ticket: oc:8013

# Fix: getTaxonomyMorphableRecords usa model->id invece di entityId

## Cosa cambia

`ImportTaxonomyJob::processDependencies()` passerà `$this->entityId` (ID GeoHub) invece di `$model->id` (ID Maphub locale) a `getTaxonomyMorphableRecords()`.

## Perché

Durante l'import di un'app da GeoHub, le taxonomy (activity, poi_type) non vengono associate ai modelli importati. Il motivo è che `getTaxonomyMorphableRecords` interroga il database GeoHub usando l'ID ricevuto come parametro, ma riceve l'ID locale Maphub (`$model->id`) che non esiste su GeoHub — quindi restituisce zero record e le relazioni non vengono mai create.

## Requisiti

- [ ] Sostituire `$model->id` con `$this->entityId` nella chiamata a `getTaxonomyMorphableRecords()` in `ImportTaxonomyJob::processDependencies()`
- [ ] Il fix si applica a tutti i job che estendono `ImportTaxonomyJob` (`ImportTaxonomyActivityJob`, `ImportTaxonomyPoiTypeJob`) senza modifiche aggiuntive, poiché non sovrascrivono `processDependencies()`

## Rischi

- **Nessun rischio architetturale** — la modifica è una sostituzione di variabile in una singola riga; non cambia la firma del metodo né il comportamento esterno del job.
- **Dati storici** — i record già importati con il bug attivo potrebbero avere taxonomy mancanti. Questo è out of scope per questo fix; richiede un'operazione di re-sync separata se necessaria.

## Out of scope

- Test unitari/feature per `ImportTaxonomyJob` (ticket separato se necessario)
- Aggiornamento dei messaggi di log che ancora usano `$model->id`
- Re-sync dei dati storici importati prima del fix

## Moduli toccati

| File | Repo |
|---|---|
| `wm-package/src/Jobs/Import/ImportTaxonomyJob.php` | `wm-package` |

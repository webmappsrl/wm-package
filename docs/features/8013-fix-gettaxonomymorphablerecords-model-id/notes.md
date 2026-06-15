> Ticket: oc:8013

# Notes — Fix: getTaxonomyMorphableRecords usa model->id invece di entityId

## Deviazioni dal piano

Nessuna.

## Bug trovati

Nessun bug aggiuntivo rispetto a quanto descritto nel ticket.

## Decisioni

- Fix minimo: nessuna modifica ai log, nessun test, nessuna rimozione del dead code (`$syncData`).
- I log che mostrano `$model->id` invece di `$this->entityId` rimangono invariati — out of scope.

## Follow-up

- **Ticket separato consigliato:** `ImportTaxonomyActivityJob::handle()` cattura tutte le eccezioni senza log né rethrow — un errore nel job è completamente invisibile in produzione.
- **Dati storici:** le app importate prima di questo fix potrebbero avere taxonomy non associate. Re-sync manuale da valutare caso per caso.
- **Dead code:** il loop che costruisce `$syncData` in `processDependencies()` non viene mai usato — da rimuovere in un refactor separato.

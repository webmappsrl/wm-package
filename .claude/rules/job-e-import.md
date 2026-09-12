---
paths:
  - "src/Jobs/**"
  - "src/Commands/**"
  - "src/Imports/**"
  - "src/Services/**"
---

# Trappole: job, code e import

Si applica quando tocchi job, comandi artisan, import o servizi del package.

- Modificare una classe Job **non ha effetto su un worker Horizon già in esecuzione**: gira con la
  classe caricata in memoria prima della modifica, il batch resta fermo e non c'è alcuna eccezione
  visibile. Riavvia il worker prima di verificare.
- Un `catch` che logga senza rilanciare fa apparire il job **completato** su Horizon: nessun retry,
  nessun fallimento visibile (oc:8158, oc:8014).
- Un errore Postgres non gestito dentro un batch sincrono aborta la transazione per tutte le query
  successive, e il messaggio che vedi nasconde la causa vera (oc:8158).
- `MODEL_IMPORT_ORDER` non garantisce l'ordine: in produzione ogni dipendenza è un `Bus::batch()`
  indipendente, senza chaining (oc:8094).
- `syncWithoutDetaching()` con pivot data non vuoto **sovrascrive** un pivot esistente: serve
  l'exists-check prima dell'`attach()` (oc:8094).
- Non chiamare una sync sincrona subito dopo il dispatch asincrono di job che ne producono
  l'input: è una race condition che riporta zero risultati senza errori (oc:8486).

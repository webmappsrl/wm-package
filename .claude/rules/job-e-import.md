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
- Un job `ShouldBeUnique` con lock su `CACHE_STORE=database`, dispatchato dentro una transazione
  (es. un salvataggio Nova), fa fallire con `25P02` su PostgreSQL se la riga di lock esiste già —
  `DatabaseLock::acquire()` (Laravel) prova un `INSERT` e ripiega su `UPDATE` nello stesso
  try/catch, e Postgres blocca tutte le query dopo la prima fallita nella stessa transazione. Usa
  `uniqueVia() { return Cache::store('redis'); }` (oc:8564, pattern preesistente in
  `BuildAppPoisGeojsonJob`).
- Nelle query raw con binding posizionali l'operatore jsonb `?` va scritto `??`, altrimenti PDO lo
  prende per un segnaposto (oc:8588).
- Niente commenti `--` con token tipo `:parola` (per esempio `oc:8588`) dentro una query raw:
  sotto PHP < 8.4 PDO non salta i commenti e può leggerli come segnaposti nominati, in conflitto
  con quelli posizionali. I commenti vanno nel PHP (oc:8588).
- `$this->confirm()` in un comando lanciato senza terminale (`--no-interaction`, scheduler,
  `Artisan::call`) restituisce il default senza chiedere: un comando che chiede conferma deve
  avere un `--force` e fallire se la conferma manca (oc:8588).

> Ticket: oc:8974
> Overview approvata il 2026-11-12 · impronta `055f9ed551ac14f9dff0690c5758616f796dab05`

# Notes — Associazione layer - new track

## Modifiche ai requisiti

Nessuna finora.

## Divergenze dal piano, task per task

Nessuna finora.

## Bug trovati

Nessuno oltre a quello del ticket.

## Decisioni

### 2026-11-12 — La diagnosi è passata da una strumentazione temporanea, poi rimossa
Il log di `artisan serve` non riporta il path delle richieste `nova-api`, e il Debugbar non
registra una richiesta che dura 124 s. Per avere il path e lo stack è stato aggiunto in
`AppServiceProvider::boot()` un listener temporaneo su `RequestHandled` e `DB::listen`, usato
per tre misure e poi tolto (`git status` pulito prima di scrivere codice).
- **Scartato:** leggere i tempi dai log di produzione — dicono quanto dura una richiesta, non
  dove il PHP si ferma.

### 2026-11-12 — Saltati tag Orchestrator e stima
Chiesto dal dev per ragioni di tempo. La stima sarebbe stata saltata comunque: il ticket è di
tipo Bug, non Feature.

## Follow-up

- Far sì che i campi delle tassonomie non vengano costruiti quando Nova compone una Resource solo
  per ricavarne titolo e avatar (gli elenchi di relazione). Toglierebbe il costo alla radice
  invece di alleggerirlo; dopo i due interventi di questo ticket non resta però niente di
  misurabile da guadagnare.

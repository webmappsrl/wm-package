> Ticket: oc:8958

# Notes — Nomenclatura regioni su "Filtri" (`wm-package`)

## Divergenze dal piano, task per task

### Task 1: `name` per lingua nella Resource `TaxonomyWhere`

Il test `test_other_fields_are_unchanged` non verifica il pannello Properties: il piano lo
prevedeva come istanza di `PropertiesPanel`, ma `PropertiesPanel::makeWithModel()` restituisce un
`Panel` di Nova, quindi l'asserzione era sbagliata ed è stata tolta.

La verifica manuale è stata fatta in locale dal dev il 25/11/2026: tab per lingua presenti,
salvataggio della `TaxonomyWhere` «Friuli-Venezia Giulia» con le 5 lingue conservate.

## Bug trovati

Nessuno.

## Decisioni

- La policy resta invariata: modifica solo per `Administrator`.
- La modifica vale per tutti gli shard che montano il package, come per le altre tassonomie.

## Follow-up

- Un reimport delle regioni (`ImportTaxonomyWhere`, `RetryTaxonomyWhereGeometryFetch` →
  `FetchTaxonomyWhereGeometryJob::syncNameFromDetail()`) riscrive i nomi corretti a mano in Nova:
  rischio accettato in oc:8958, da riconsiderare se i reimport diventano frequenti.

> Ticket: oc:8041

# Notes — Import POI: associazione taxonomy type non funzionante

## Deviazioni dal piano

- **Nome branch:** `oc_8041` anziché `feature/oc-8041-import-poi-associazione-taxonomy-type-non-funzionante` — convenzione semplificata su richiesta esplicita dell'utente.

## Bug trovati

Nessun bug aggiuntivo durante l'implementazione.

## Decisioni

- **Timing fuori scope:** la challenge aveva evidenziato un possibile problema di timing (taxonomy dispatchata prima che gli EcPoi siano salvati). Confermato out of scope: il fix si limita al parametro ID.
- **`$model->properties['geohub_id']` vs `$this->entityId`:** il valore autoritativo è quello salvato nel modello locale dopo il save (`properties['geohub_id']`), non il parametro del costruttore del job che in scenari di re-import può divergere.
- **Null guard aggiunto post-review:** la review ha evidenziato che passare `null` a `int $modelId` causa `TypeError` in PHP 8.4. Aggiunto guard su `$geohubId === null` con `Log::error` e early return anziché lasciare il one-liner senza protezione.
- **Cast `(int)` su `$geohubId` aggiunto post-review:** rende il tipo esplicito e protegge da future attivazioni di `strict_types`.
- **Log message arricchito post-review:** aggiunto `getModelKey()` nel messaggio di errore per rendere il log azionabile senza query aggiuntive.
- **`taxonomy_poi_types` aggiunto a `default_dependencies` post-review:** la review ha evidenziato che il config non includeva `taxonomy_poi_types` nell'array `default_dependencies.app`. Aggiunto nella stessa PR per completare il fix end-to-end.
- **`sync()` → `syncWithoutDetaching()` post-review:** il vecchio `sync()` azzerava tutte le associazioni di ogni record lasciando solo l'ultimo taxonomy importato (bug critico confermato su app 63 durante OC:8014). `syncWithoutDetaching()` aggiunge l'associazione senza rimuovere quelle esistenti. Trade-off noto: de-associazioni effettuate in GeoHub non vengono propagate su Maphub al re-import. Comportamento intenzionale per questo flusso import unidirezionale.

## Follow-up

- Fix bidirezionale (lato EcPoi che cerca le taxonomy già importate) — rimandato
- Problema timing dispatch — da valutare in ticket separato se il bug persiste dopo questo fix
- Warning Intelephense `Undefined type 'Log'` su righe 18/25/30 — pre-esistente, fuori scope
- **Esito test manuale APP GeoHub 63 (app locale id 3):** import completato con esito positivo.
  - EcPoi 119/119, EcTrack 22/22, Layer 9/9
  - Pivot theme (POI/Track/Layer): 182/25/9 — allineati al benchmark
  - Pivot poi_types (POI): 119 — confermato fix ID
  - Spot check multi-associazione: EcTrack *Sentiero Marcò* (3 temi), EcPoi *Laghetto Welsperg* (3 temi) — confermato fix `syncWithoutDetaching`
  - **Race condition al primo passaggio:** pivot a 0 dopo import completo; risolto con re-import esplicito `--dependencies=taxonomy_activity,taxonomy_poi_types,taxonomy_theme` → bug strutturale noto, tracciato in OC:8094
  - **1 job fallito:** `ImportTaxonomyActivityJob` su attività GeoHub id 1 (~83k morphables) supera il limite 65.535 parametri PostgreSQL — non blocca l'import, non incide sui contenuti dell'app 63

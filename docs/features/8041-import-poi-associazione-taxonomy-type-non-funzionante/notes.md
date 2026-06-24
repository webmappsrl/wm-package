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

## Follow-up

- Fix bidirezionale (lato EcPoi che cerca le taxonomy già importate) — rimandato
- Problema timing dispatch — da valutare in ticket separato se il bug persiste dopo questo fix
- **`taxonomy_poi_types` assente da `default_dependencies`** — durante la review è emerso che il config `wm-geohub-import.php` non include `taxonomy_poi_types` nell'array `default_dependencies.app`, a differenza di `taxonomy_activity` e `taxonomy_theme`. Se il job non viene dispatchato nel flusso standard, il fix sull'ID non basta. Da investigare e tracciare in ticket separato.
- Warning Intelephense `Undefined type 'Log'` su righe 18/25/30 — pre-esistente, fuori scope

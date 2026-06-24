> Ticket: oc:7913

# Notes — Esposizione assets API wm-package

## Deviazioni dal piano

- Test automatici non inclusi: la scrittura del test è stata valutata durante l'implementazione ma scartata. La verifica avviene manualmente (curl sull'endpoint).

## Verifica locale

- Il fix supera correttamente la prima guardia (`getMedia()->first()` trova il record media).
- La risposta in locale è `404 "File not found"` (seconda guardia) perché il bucket MinIO locale (`wmfe/maphub`) contiene solo le cartelle `1` e `json` — App 2 non ha file fisici sincronizzati localmente.
- La verifica completa del 200 richiede staging/produzione, oppure un upload dell'icona di App 2 via Nova in locale.

## Decisioni

- Durante l'analisi è emerso un secondo bug nello stesso metodo: `getCustomProperty('mime-type')` restituisce null perché la custom property non viene salvata al caricamento (il campo nativo Spatie è `mime_type`). Tracciato in oc:8122, non incluso in questo fix per mantenere il cambiamento minimale.

## Follow-up

- oc:8122 — fix `Content-Type` null in `getOrDownloadIcon` (`getCustomProperty('mime-type')` → `mime_type`)

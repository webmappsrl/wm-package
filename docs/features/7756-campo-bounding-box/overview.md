> Ticket: oc:7756

# Campo bounding box

## Cosa cambia
Il campo `map_bbox` nella risorsa Nova `App` viene arricchito con due elementi nel tab "map":
1. **Campo testo** (editabile) — mantiene il nome "Bounding BOX", aggiunge `copyable()` e un help text aggiornato con link a boundingbox.klokantech.com
2. **Preview mappa** (read-only, solo detail) — mostra visivamente il bbox come rettangolo su mappa, usando il componente `feature-collection-map`

## Perché
Il campo `map_bbox` era solo un testo grezzo senza contesto visivo. L'utente non aveva feedback visivo del bbox generato né un riferimento rapido per interpretarlo. La richiesta replica l'UX già presente in Nova Geohub.

## Requisiti
- [ ] Il campo testo `map_bbox` resta editabile e aggiunge `->copyable()` per copiare il valore con un click
- [ ] L'help text del campo testo diventa: *"Calcolato automaticamente dalle tracks associate all'app. Per visualizzare l'area: [boundingbox.klokantech.com](https://boundingbox.klokantech.com/)"*
- [ ] Un secondo campo "Bounding BOX" di tipo `FeatureCollectionMap`, posizionato subito sotto nel tab "map", mostra la preview del bbox come Polygon su mappa
- [ ] La preview è `->onlyOnDetail()` — non appare in edit né in index
- [ ] La preview converte il bbox `[minLon,minLat,maxLon,maxLat]` in una geometria Polygon via PostGIS `ST_MakeEnvelope` per renderizzarla nel componente
- [ ] Se `map_bbox` è null o non valido, la preview non mostra errori ma un campo vuoto

## Rischi
- **Dipendenza da PostGIS**: la conversione bbox → geometria usa `ST_MakeEnvelope` — richiede PostGIS attivo (già assunto dall'intero codebase).
- **Valore `map_bbox` in formato stringa JSON**: il campo è salvato come stringa `[9.9,43.9,11.3,45.0]` — la conversione deve essere robusta rispetto a valori null, vuoti o malformati.

## Out of scope
- Ricalcolo automatico di `map_bbox` al salvataggio dell'App (già gestito altrove)
- Rendere il campo text read-only (annotato come possibile miglioramento futuro)
- Modifica al formato di storage di `map_bbox`

## Moduli toccati
| File | Repo | Tipo modifica |
|------|------|---------------|
| `src/Nova/App.php` | `wm-package` | Modifica `map_tab()` — campo bbox + preview |

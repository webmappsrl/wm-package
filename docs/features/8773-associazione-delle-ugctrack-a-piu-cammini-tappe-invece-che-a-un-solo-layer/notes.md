> Ticket: oc:8773

# Notes — Corrispondenza UGC track ↔ EC track per segmenti (wm-package)

## Divergenze dal piano, task per task

### Task 2 service di segmentazione e corrispondenza

La SQL del piano era corretta ma troppo lenta sulle UGC track dense: la 297 (85 km, 19.994
punti) richiedeva 95,7 s, oltre il timeout del job. La causa misurata era il prefiltro delle
EC track candidate, che faceva `ST_DWithin` geodetico fra geometrie intere (94,9 s da solo),
più il ricalcolo di `ST_LineSubstring` per ogni coppia segmento-pezzo dovuto ai CTE inline.

Riscritta con: prefiltri solo sul box (`&& _ST_Expand(geography, distanza)`, la stessa
condizione che `ST_DWithin` usa sull'indice), UGC track spezzata in pezzi di 64 vertici
consecutivi (non `ST_Subdivide`, che aggiunge vertici), box allargato precalcolato per pezzo,
CTE `MATERIALIZED`, `SET LOCAL jit = off`. Risultato: 297 da 95,7 s a ~3 s, 108 (183 km) da
10 s a 2,3 s, tutte le 267 UGC track di camminiditalia in ~67 s. Verificata l'identità dei
risultati riga per riga contro lo snapshot precedente (697 righe, 0 differenze).

Aggiunto `ON CONFLICT (ugc_track_id, ec_track_id) DO UPDATE`: senza, due calcoli
concorrenti sulla stessa coppia (UGC track ed EC track) andavano in unique violation.

### Task 3 job e coda dedicata

Il piano prevedeva timeout 300 s e `$tries = 3`. Il `retry_after` di Redis nei consumer è
90 s: un job più lungo veniva rimesso in coda mentre girava, e i rilasci di
`WithoutOverlapping` consumavano i tentativi. Ora: timeout 85 s, `retryUntil` 15 minuti con
`maxExceptions = 3`, `uniqueFor = 3600` (dalla review finale: senza scadenza un job perso
lasciava il lock per sempre), `chunkById` nel job per App.

### Task 7 Nova campo filtro parametri traduzioni

- Tab `ugc_coverage` della App sostituito dal tab generico **UGC**, con una sezione che spiega
  la corrispondenza fra UGC track ed EC track e un testo di aiuto specifico per ciascun campo
  (richiesta del dev).
- Il filtro per layer propone solo i layer attraversati da almeno una UGC track (48 su 120 in
  camminiditalia).
- Il campo del dettaglio sta subito dopo la mappa della geometria.
- Il campo mostra anche il valore aggregato per layer (vedi Decisioni) e rilancia il calcolo
  quando manca o è fallito.

## Bug trovati

- **Precedenza delle traduzioni invertita da oc:8247** (preesistente, emerso qui):
  `loadTranslationsFrom(resources/lang)` senza namespace metteva la cartella del package nei
  `paths` del loader dopo la `lang/` dello shard; `FileLoader::loadJsonPaths()` rilegge i JSON
  anche da lì, quindi il JSON del package vinceva su quello dello shard. Corretto spostando i
  `validation.php` di oc:8247 in `resources/lang-php/`; aggiunto `TranslationPrecedenceTest`.
  Al prossimo bump, forestas/maphub/osm2cai2 tornano a vedere i propri override. Colleghi di
  oc:8247 informati su Slack.
- **`failed()` sovrascriveva un calcolo riuscito più recente** (visto sulla 297): ora lo stato
  `failed` si scrive solo se non c'è un calcolo `computed` partito dopo l'accodamento del job.
- **`updateOsmData()`** riscriveva la geometria della EC track con `saveQuietly()` senza
  ricalcolo: ora accoda il matching.

## Decisioni

- **Nomi** (richiesta del dev): tutto il concetto si chiama *matching* (corrispondenza), non
  *coverage*: tabelle `ugc_track_ec_track_matches` / `ugc_track_matching_runs`, colonne
  `matched_segments` / `total_segments` / `max_distance_meters`, `UgcTrackMatchingService`,
  job `Match*`, command `wm:match-ugc-tracks`, config `ugc_track_matching`, coda
  `ugc-track-matching`.
- **Valore aggregato per layer**: segmenti percorsi su **tutte** le EC track del layer, non solo
  su quelle toccate (scelta del dev). Il totale delle EC track non toccate si calcola con la
  stessa CTE di segmentazione (`segmentCtes()`), condivisa con l'INSERT.
- **Rilancio all'apertura del dettaglio** (richiesta del dev), sullo schema di
  `dispatchDemIfMissing()`: se lo stato manca, è fallito o ha parametri diversi da quelli
  attuali, accoda il job con lock Redis da 300 s.
- **Segmenti residui di pochi millimetri** (`CEIL(len/seg)`): restano segmenti a pieno titolo,
  come nello stash di oc:8165 validato col cliente.
- **Guardia di `failed()`** con confronto stretto `>` sul secondo: a parità vince `failed`,
  che si ripara da solo; un `computed` sbagliato no.
- **`layer_id` inviato dall'app su upload GPX** (wm-core `modal-ugc-uploader`, oc:7639) non
  viene filtrato: nessuno lo legge più sulle UGC track e il command lo toglie.
- **Controllo consumer**: maphub, osm2cai2, forestas e wm-core non leggono
  `properties.layer_id` delle UGC track (git grep su `origin/main`).
- Nessun tag Orchestrator di ambiente o di contenuto associato (candidati non pertinenti; il
  dev ha scelto di saltare i tag di contenuto). Stima non scritta su Orchestrator (scelta del
  dev).

## Follow-up

- **oc:8165 (passaporto)**: lo stash `feature/oc-8165-…` ha già segmentazione e parametri
  propri (`passport_segment_length_meters`, `passport_buffer_meters`, tab "passport" in
  `App\Nova\App`). Alla ripresa deve leggere segmento e distanza da
  `UgcTrackMatchingService::params()`, togliere quei due campi, e unire gli indici di
  `ugc_track_ec_track_matches` (solo righe con i parametri attuali, solo tracce con
  `locations`, un uuid conta una volta) invece di rifare la geometria.
- Requisiti di piattaforma: PostgreSQL ≥ 12 (`MATERIALIZED`), PostGIS ≥ 3.4
  (`ST_LineSubstring` su geography), uso di `_ST_Expand` (funzione interna di PostGIS).
  Da verificare in produzione prima del deploy.
- Misurare `matchEcTrack` su una EC track lunga con molte UGC track vicine.
- Minor rinviati: FK `constrained('ec_tracks')` scritto a mano (consumer con
  `EC_TRACK_TABLE` diverso); cache dei totali per layer (caso peggiore ~2,9 s); aggregato
  incoerente per qualche istante dopo un cambio di geometria della EC track; `form.layer_id`
  non tolto dal command; mancano test su `--app` del command e su `matchEcTrack` con righe
  obsolete.

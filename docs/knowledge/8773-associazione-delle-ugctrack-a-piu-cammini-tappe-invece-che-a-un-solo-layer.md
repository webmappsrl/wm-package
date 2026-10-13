# Corrispondenza fra UGC track ed EC track

## Come funziona oggi

Una UGC track non ha un `layer_id`: il package salva **quali segmenti di quali EC track** ha
percorso, e i layer si ricavano da lì passando per `layerables`.

- **Segmentazione.** Ogni EC track è divisa in segmenti di lunghezza fissa misurati su
  geography (`ST_LineSubstring`), numerati da 0 dall'inizio; se la EC track è fatta di più
  pezzi, la numerazione continua da un pezzo al successivo. L'ultimo segmento può essere più
  corto, anche di pochi millimetri, e conta come gli altri.
- **Corrispondenza.** Un segmento è percorso se la UGC track passa entro la distanza massima
  (`ST_DWithin` su geography, sul segmento come linea continua). Solo EC track della stessa App.
- **Dati.** `ugc_track_ec_track_matches`: una riga per coppia con `matched_segments` (indici),
  `total_segments` e i parametri usati. `ugc_track_matching_runs`: esito dell'ultimo calcolo di
  ogni UGC track (`computed`/`failed`), anche quando non c'è nessuna corrispondenza.
- **Parametri per App.** Lunghezza del segmento (default 400 m, 50–2000) e distanza massima
  (default 50 m, 5–200) nelle properties della App, modificabili dal tab "UGC" di Nova App;
  default in `config('wm-package.ugc_track_matching')`. Cambiarli ricalcola tutta l'App
  (`RematchAppUgcTracksJob`, unico per App).
- **Quando si ricalcola.** UGC track creata o con geometria cambiata (anche dal retry dell'app
  e da `CleanUgcTrackGeometryJob`); EC track creata, modificata, invertita o aggiornata da OSM
  (`EcTrackService::dispatchUgcTrackMatching()`, fuori dalla catena dei job); apertura del
  dettaglio Nova se il calcolo manca, è fallito o ha parametri vecchi
  (`UgcTrack::dispatchMatchingIfNeeded()`, lock Redis 300 s). Coda `ugc-track-matching`,
  supervisor `supervisor-ugc-track-matching`. Tracce esistenti: `wm:match-ugc-tracks`.
- **Nova.** Nel dettaglio della UgcTrack, dopo la mappa: layer attraversati con il valore
  aggregato (segmenti percorsi su tutte le EC track del layer), EC track toccate, gruppo "fuori
  da ogni layer". Filtro per layer con i soli layer attraversati da almeno una UGC track.
- **Gli UgcPoi non cambiano**: hanno ancora un solo `layer_id`, risolto per prossimità.
- Requisiti: PostgreSQL ≥ 12, PostGIS ≥ 3.4.

## Perché così

- **Indici dei segmenti per traccia invece di una percentuale** (oc:8773): la regola del
  passaporto di Cammini d'Italia (oc:8165) unisce tutte le tracce di un utente in un corridoio;
  un segmento coperto dall'unione è coperto da almeno una traccia, quindi salvando gli indici
  per traccia il consumer ottiene la copertura per utente unendoli, senza rifare la geometria.
  Una percentuale per traccia non si può sommare (i tratti percorsi due volte conterebbero doppio).
- **Niente `layer_id` sulle UGC track per tutti i consumer** (oc:8773): una traccia attraversa
  più layer, e il valore singolo era anche sbagliato sulle tracce lunghe (oc:8466: UGC track
  321 sulla Via degli Dei assegnata alla Via Mater Dei).
- **Pezzi da 64 vertici e prefiltro sul box con `_ST_Expand`** (oc:8773): `ST_DWithin` fra
  geometrie intere richiedeva 95 s su una traccia da 20.000 punti; così ~3 s, con risultati
  identici. `_ST_Expand` è interna a PostGIS ma è quella che `ST_DWithin` usa sull'indice, e
  non esiste un'alternativa pubblica per geography.
- **Timeout 85 s, `retryUntil` e `uniqueFor`** (oc:8773): il `retry_after` di Redis nei consumer
  è 90 s; un timeout più lungo rimetteva in coda il job mentre girava.
- **`failed()` non sovrascrive un calcolo riuscito partito dopo l'accodamento** (oc:8773): è
  successo sulla traccia 297.

## Come ci siamo arrivati

- **`layer_id` singolo risolto per prossimità anche sulle UGC track** (oc:8079/oc:8466, superato da
  oc:8773): EC track più vicina e centroide del layer; inaffidabile sulle tracce lunghe.
- **Nome "coverage"** (oc:8773, superato prima del merge): il concetto è stato rinominato
  "matching" (corrispondenza) in codice, DB e interfaccia.

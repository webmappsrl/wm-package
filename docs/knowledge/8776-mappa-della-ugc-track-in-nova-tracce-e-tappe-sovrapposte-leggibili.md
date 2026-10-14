# Mappa della UGC track in Nova: traccia, tappe e cammini sovrapposti

## Come funziona oggi

La mappa del dettaglio di una UGC track è il campo `FeatureCollectionMap` (proprietà delle feature
e opzioni nel suo `README.md`); il GeoJSON lo costruisce `UgcTrack::getFeatureCollectionMap()`, e
le larghezze sono le costanti `TRACK_*`, `CONTEXT_*` e `ZOOMED_OUT_*` del modello. Con le **tappe
di contesto** (le EcTrack dei layer dell'App nella zona della traccia, oc:8747):

- **Traccia** e tratti ricostruiti (oc:8719): sempre della stessa misura, con un contorno bianco
  (`casing`), sopra a tutto. Nel tooltip «Traccia registrata», per prima (`tooltipOrder`).
- **Quali tappe**: con la corrispondenza `computed` di oc:8773, solo le EcTrack toccate dalla
  traccia; senza, tutte quelle della zona. Lo decide `UgcTrack::contextRows()`, che usano anche
  la legenda e i pezzi toccati.
- **Come**: intere e sottili, più larghe solo nei **pezzi toccati**, cioè entro
  `max_distance_meters` dalla traccia, calcolati da `ContextTrackBandsService` in PostGIS e messi
  in cache. Il nome della tappa sta nel tooltip, non lungo la linea.
- **Da lontano** — dalla `resolution` in cui la tolleranza sta in `ZOOMED_OUT_TOLERANCE_PIXELS`,
  divisa per cos(lat) perché la `resolution` di OpenLayers è in unità EPSG:3857 — sui pezzi
  toccati dove passano più cammini, un pezzo della tappa del primo cammino a **segmenti
  alternati** nei colori di tutti (`zoomedOutStyle.dashColors`).
- **Tooltip**: `stackedTooltip()` elenca tutte le linee sotto il mouse.
- Se il calcolo dei pezzi fallisce, le tappe restano intere a `CONTEXT_STAGE_WIDTH` e la mappa
  resta visibile. Senza tappe di contesto la mappa è quella di prima.

Vincoli: le proprietà nuove del campo sono facoltative e una feature che non le usa ha lo stile di
sempre, perché il campo è condiviso con TrailRegistry, Layer, TaxonomyWhere e altri. Il calcolo
dei pezzi costa ~1,5 s alla prima apertura di una UGC lunga (321 di camminiditalia), poi è in
cache, con una chiave che cambia da sola con tappe, traccia e tolleranza.

## Perché così

- **Traccia sempre della stessa misura** (oc:8776): una linea che si stringe sopra le tappe e si
  allarga dove va da sola si legge come due cose diverse. Il contorno bianco la stacca dai colori
  dei cammini; più sottile di così il blu, con l'antialiasing, diventa grigiastro.
- **Solo le tappe toccate** (oc:8776): le varianti e gli altri cammini della zona, tortuosi, a zoom
  basso diventavano macchie più vistose della traccia.
- **Tappe sottili, piene solo dove la traccia passa** (oc:8776): una tappa incrociata per 400 m
  non deve colorarsi per tutti i suoi 20–30 km. «Tocca» è calcolato pezzo per pezzo
  (`max_distance_meters`, di default 50 m), più
  preciso dei tratti da 400 m della corrispondenza di oc:8773, che resta il criterio per decidere
  quali tappe mostrare.
- **Segmenti alternati su una geometria sola** (oc:8776): tutti i colori sullo stesso pezzo, con
  un `Stroke` per colore e `lineDashOffset`, non possono sfasarsi; si alternano solo i cammini di
  quel pezzo, alla pari. Estremità piatte: con quelle tonde ogni segmento invade il vicino.
- **Calcoli nel browser dove dipendono dallo zoom** (oc:8776): la soglia e i segmenti sono in pixel
  e cambiano con la `resolution` senza ricaricare; il server manda solo geometrie e proprietà.
- **Tooltip «Traccia registrata»** (oc:8776): il nome dato dall'utente non dice cosa sia la linea.

## Come ci siamo arrivati

Tutto in oc:8776, provato a occhio sulla UGC 321 con il dev; dettagli in
`docs/features/8776-mappa-della-ugc-track-in-nova-tracce-e-tappe-sovrapposte-leggibili/notes.md`.

- **Tratteggio su tutte le tappe con K = cammini della mappa** (superata): su un tratto condiviso
  da 2 di 7 cammini, uno dominava per 5/7 della linea.
- **Traccia larga da sola e sottile sopra le tappe, con «riempimenti» calcolati dalla
  corrispondenza** (superata): misura non costante, e una query da ~0,7 s legata a oc:8773.
- **Fasce concentriche** attorno alla traccia, prima larghe quanto i cammini (fino a 28px), poi in
  6px fissi (superate): troppo larghe, oppure fasce sotto 1px che non si leggevano.
- **Linee affiancate** con spostamento laterale in pixel (superata): non è piaciuta.
- **Colori dei cammini sulla geometria della traccia**, per centrarla (scartata prima di
  implementarla, a favore delle tappe intere).
- **Tappe vicine smorzate** a 3px al 50% (superata): meglio toglierle.
- **Larghezze dipendenti dallo zoom** (scartata): fraintendimento di una richiesta.
- I **nomi lungo le linee** (`lineLabel`, oc:8747) sono stati tolti dalla mappa della UGC track:
  coprivano la mappa. L'opzione del campo resta, per chi la usa.

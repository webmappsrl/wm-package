> Ticket: oc:8719

# Pulizia automatica dei punti GPS errati nelle tracce UGC

## Cosa cambia

Quando una traccia UGC viene salvata con `properties.locations` presenti, la sua `geometry` viene
ricostruita a partire da `properties.locations` **durante il salvataggio**, scartando i punti
inutilizzabili:

- i punti con accuracy oltre una soglia configurabile (default **40 m**) **che si allontanano più
  di una distanza configurabile (default 50 m)** dal tratto fra il punto tenuto precedente e il
  successivo: l'accuracy dice che un punto è sospetto, la distanza dice se è davvero sbagliato;
- i punti con latitudine e longitudine entrambe a 0.

Poiché la pulizia avviene prima della scrittura, tutto ciò che viene calcolato dopo (cammino
associato, località, mappa, app) usa già la geometria pulita.

`properties.locations` non viene mai modificato: è lo storico dei punti grezzi, da cui la
geometria si può sempre ricalcolare. Le tracce senza `locations` non vengono toccate.

Le stesse regole valgono per le statistiche dell'immagine di condivisione (distanza, durata,
dislivello).

Un command applica la pulizia alle tracce già esistenti, con un'opzione `--dry-run`.

In Nova, per le tracce con `locations`, la geometria non è più modificabile a mano; nel dettaglio
compaiono un riepilogo della pulizia e, sulla mappa, i tratti ricostruiti al posto dei punti
scartati, disegnati tratteggiati.

Il comportamento è attivo per tutti i progetti che usano wm-package: è la correzione di un bug.

## Perché

L'app registra ogni posizione che riceve dal telefono, senza guardare l'accuracy. Quando il GPS
perde il segnale, il telefono passa alla posizione calcolata dalla rete cellulare, con errori di
chilometri, e la traccia salvata sulla mappa mostra linee dritte e salti.

Caso di riferimento, UgcTrack 169: 1016 punti, 100 con accuracy oltre 100 m (fino a 7,9 km). La
lunghezza della linea è 112 km, contro i circa 28 km percorsi davvero. L'immagine di condivisione
calcolata oggi da `TrackStatsService` riporta 112,2 km.

Sul DB di sviluppo di camminiditalia, 56 tracce su 150 con `locations` hanno punti con accuracy
oltre 40 m (654 punti su 182.981). In 2 tracce (217, 238) la geometria inviata dall'app inizia
con un punto (0,0): l'app usa `[0, 0, 0]` quando la registrazione parte senza una posizione
valida (`_getEmptyWmFeature` in wm-core). Con la regola finale (accuracy oltre 40 m **e** oltre
50 m dal tratto) la pulizia cambia 19 tracce (dry-run sul DB di sviluppo): la 169 scarta 31 punti
su 1016 e passa da 114,1 a 28,9 km. Con la sola accuracy erano 57 tracce, ma molti punti scartati
stavano sul percorso. Con la regola di sola accuracy, in 2 tracce (217, 223) la tappa più vicina,
da cui si ricava il cammino associato, cambiava fra geometria grezza e pulita.

Le app di tracciamento fanno la stessa cosa: scartano i punti per accuracy (Avenza: soglia di
default 32 m; Map My Tracks: 50 m; OsmAnd e GPSLogger: soglia configurabile). L'accuracy di un
punto è il raggio entro cui sta la posizione vera con il 68% di probabilità. Fino a 20 m è
l'errore normale di un telefono sotto gli alberi; fra 20 e 50 m il segnale è degradato; oltre
50 m il punto è inutilizzabile. 40 m sta in mezzo alla fascia degradata.

## Requisiti

- [ ] Un servizio unico con le regole di filtro: scarta i punti (0,0) e senza coordinate; un punto
      con accuracy > soglia (sospetto) si scarta solo se dista più di `ugc_track_max_deviation_meters`
      dal tratto fra il punto tenuto precedente e il successivo (o dal solo punto tenuto vicino, in
      testa e in coda); tiene tutto il resto, nell'ordine di `locations`. Lo usano la pulizia al salvataggio, il command, la vista Nova e
      `TrackStatsService`.
- [ ] Pulizia durante il salvataggio di una UgcTrack, prima della scrittura, ogni volta che
      `properties.locations` è presente e cambiano `geometry` o `properties.locations`
      (creazione, modifica dall'app con le route `edit` e `v3/ugc/track/edit`, import). Cammino e
      località vengono così calcolati sulla geometria pulita.
- [ ] La pulizia è agganciata a un observer dedicato, registrato una sola volta dal modello
      `UgcTrack`, non a `UgcObserver` (che in camminiditalia è registrato due volte).
- [ ] La geometria pulita ha lo stesso formato di quella attuale (MultiLineString 3D: la quota è
      l'`altitude` del punto), così l'app la accetta senza modifiche.
- [ ] La geometria passa da SQL (espressione PostGIS), non da un oggetto geometrico costruito con
      l'ORM (regola del repo).
- [ ] Se `properties.locations` manca o è vuoto, o se dopo il filtro restano meno di 2 punti, la
      geometria resta quella ricevuta.
- [ ] `TrackStatsService` (immagine di condivisione) calcola distanza, durata e dislivello sui
      soli punti tenuti dal filtro.
- [ ] Soglia in `config/wm-package.php`:
      `'ugc_track_max_accuracy_meters' => env('UGC_TRACK_MAX_ACCURACY_METERS', 40)` e
      `'ugc_track_max_deviation_meters' => env('UGC_TRACK_MAX_DEVIATION_METERS', 50)`. Senza
      variabili nel `.env` valgono 40 e 50.
- [ ] Command per le tracce esistenti, eseguito tramite un job per traccia, con `--dry-run` che
      elenca le tracce che cambierebbero con i punti scartati e i km prima e dopo, senza scrivere
      nulla. Si lancia a mano dopo il deploy.
- [ ] Nova: per le tracce con `properties.locations` il campo geometria è in sola lettura (niente
      caricamento di GPX/GeoJSON/KML); per le tracce senza `locations` resta modificabile come oggi.
- [ ] Vista Nova del dettaglio UgcTrack: riepilogo con punti scartati sul totale, accuracy massima
      scartata, lunghezza prima e dopo la pulizia. Calcolato al volo da `properties.locations` con
      il servizio di filtro, senza nuove colonne.
- [ ] Mappa Nova (`FeatureCollectionMap`): i tratti che uniscono l'ultimo punto tenuto prima di una
      sequenza scartata al primo punto tenuto dopo sono disegnati tratteggiati, con il dettaglio al
      click (punti scartati, durata, accuracy massima). I punti scartati non compaiono sulla mappa.
- [ ] Legenda sulla mappa (richiesta del dev dopo la prima verifica in Nova): il campo
      `FeatureCollectionMap` accetta `->legend([...])` (voci con etichetta, colore, tratteggio);
      la risorsa UgcTrack la mostra solo se la traccia ha tratti ricostruiti, con le voci
      «Traccia registrata» e «Tratto ricostruito: punti GPS scartati perché imprecisi e lontani
      dal percorso».
- [ ] Il campo `FeatureCollectionMap` supporta il tratteggio come proprietà opzionale della
      feature; le mappe che non la usano restano identiche. Dist ricompilato.
- [ ] La vista segue i permessi già esistenti sulla traccia: nessun controllo di ruolo in più.
- [ ] Testi Nova traducibili in `resources/lang/{it,en,de,es,fr}.json`.
- [ ] Test: traccia con i `locations` della 169 salvata via API → la geometria restituita da
      `GET /api/v2/ugc/track/index` è pulita, è una MultiLineString 3D, `uuid` e `properties`
      invariati; il cammino assegnato è calcolato sulla geometria pulita.
- [ ] Verifica manuale sul dispositivo: una traccia registrata e sincronizzata arriva pulita
      sull'app alla sincronizzazione successiva.

## Rischi

- **L'app rimanda la geometria grezza a ogni modifica.** Le route di modifica sostituiscono la
  `geometry` con quella inviata. Coperto dalla pulizia a ogni salvataggio.
- **Cammino e località calcolati sulla geometria sporca.** `UgcObserver::created()` sceglie il
  cammino dalla tappa più vicina; con un job in coda la scelta avverrebbe prima della pulizia (2
  casi su 62 cambiano cammino). Coperto dalla pulizia durante il salvataggio. Le tracce già
  esistenti pulite dal command non ricalcolano il cammino: in sviluppo i due casi coinvolti non
  hanno un cammino salvato.
- **Una correzione manuale in Nova verrebbe sovrascritta.** Coperto rendendo la geometria in sola
  lettura per le tracce con `locations`.
- **Statistiche dell'immagine di condivisione gonfiate.** Coperto facendo usare lo stesso filtro a
  `TrackStatsService`.
- **Doppio observer su UgcTrack.** Il package registra `UgcObserver` dal modello e camminiditalia
  registra la propria sottoclasse. Coperto con un observer dedicato registrato solo dal modello.
- **Formato della geometria verso l'app.** L'app confronta la geometria del server con la sua
  copia e la sostituisce se diversa (`_isFeatureModified` in wm-core). Coperto dal test
  sull'endpoint `index`.
- **Cambio di comportamento per tutti i progetti.** Voluto: è la correzione di un bug, nessun
  interruttore per spegnerlo.
- **Pause e buchi di segnale non si distinguono.** L'app non salva le pause nei `locations`. Per
  questo non ci sono regole su tempo e distanza: un filtro sui salti scarterebbe punti giusti dopo
  ogni pausa, anche a catena (verificato sulla traccia 198).

## Out of scope

- Riconoscere i tratti fatti con mezzi di trasporto.
- Agganciare il percorso alla rete dei sentieri.
- Segnalare nella vista Nova i buchi di tempo (pausa, sosta o segnale perso).
- Filtrare i punti nell'app durante la registrazione.
- Pulire le tracce senza `locations`.
- Ricalcolare il cammino delle tracce già esistenti.

## Moduli toccati

Tutto in **wm-package**:

- nuovo servizio con le regole di filtro (`src/Services/`)
- nuovo observer dedicato, registrato in `src/Models/UgcTrack.php`
- nuovo job e nuovo command per le tracce esistenti (`src/Jobs/`, `src/Console/Commands/`)
- `config/wm-package.php`
- `src/Services/Models/StoryShare/TrackStatsService.php`
- `src/Models/UgcTrack.php` (`getFeatureCollectionMap()` con i tratti ricostruiti)
- `src/Nova/UgcTrack.php` o `src/Nova/Traits/MultiLinestringResourceTrait.php` (riepilogo, campo
  in sola lettura)
- `src/Nova/Fields/FeatureCollectionMap/` (tratteggio) e relativo `dist`
- `resources/lang/{it,en,de,es,fr}.json`
- test in `tests/`

Nel **repo camminiditalia**: solo l'aggiornamento del puntatore al submodule `wm-package`.

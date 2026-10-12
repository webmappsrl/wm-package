> Ticket: oc:8742

# UGC: velocità, tempo e dislivello calcolati dal server sui punti GPS puliti (properties.stats)

## Cosa cambia

Il server calcola e salva in `properties.stats` di ogni UgcTrack registrata i dati tecnici
dell'uscita: distanza, dislivelli, quote, tempo totale ed effettivo, velocità media e massima.
Li calcola sui soli punti tenuti dalla pulizia di oc:8719. L'app (oc:8743) e Nova mostrano quei
valori invece di calcolarli ognuno per conto suo dai punti grezzi.

Formato (chiavi e unità di `dem_data` dove esistono già):

```json
"stats": {
  "distance": 9.4,
  "ascent": 313,
  "descent": 610,
  "ele_min": 935,
  "ele_max": 1456,
  "ele_from": 1303,
  "ele_to": 994,
  "duration": 165,
  "duration_moving": 144,
  "avg_speed": 3.4,
  "max_speed": 6.5,
  "points_total": 418,
  "points_discarded": 37,
  "computed_at": "2026-10-08T08:05:25Z"
}
```

| Chiave | Unità | Come si calcola |
|---|---|---|
| `distance` | km | somma delle distanze fra punti tenuti consecutivi |
| `ascent`, `descent`, `ele_min`, `ele_max`, `ele_from`, `ele_to` | m | servizio DEM (`dem.maphub.it`) sulla geometria pulita, come per le EcTrack |
| `duration` | minuti interi | ultimo punto tenuto meno il primo |
| `duration_moving` | minuti interi | somma della durata dei tratti con velocità media ≥ 1 km/h |
| `avg_speed` | km/h | distanza / tempo dei soli tratti in movimento con GPS buono (entrambi i punti con accuracy ≤ 40 m), in secondi, arrotondata alla fine; `null` se quel tempo è 0 |
| `max_speed` | km/h | 95° percentile del campo `speed` dei punti tenuti; se `speed` manca, 95° percentile della velocità fra punti consecutivi |
| `points_total`, `points_discarded` | — | punti di `locations` e punti scartati dalla pulizia |
| `computed_at` | ISO 8601 UTC | momento del calcolo |

Esempio verificato sui dati locali, traccia 174: oggi l'app mostra velocità massima 1.056,6 km/h,
media 18,7 km/h e dislivello 481 m. Con questo calcolo: velocità massima 6,5 km/h, media 3,4 km/h,
tempo totale 165 minuti ed effettivo 144, salita 313 m e discesa 610 m dal DEM.

## Perché

L'app calcola oggi i dettagli tecnici di una traccia da tutti i punti di `properties.locations`,
compresi quelli che la pulizia di oc:8719 scarta, e mostra valori assurdi. Allo scrum del
08/10/2026 (call delle 09:35) si è deciso che i valori diventano campi calcolati dal backend sui
punti già puliti, e che app, web e Nova mostrano lo stesso numero. L'app continua a calcolarli
lei solo per le tracce non ancora sincronizzate, con la stessa regola e gli stessi parametri del
server.

Le scelte di calcolo vengono dai dati locali (116 tracce con punti GPS, 156.651 tratti) e dalla
documentazione pubblica di Strava:

- **Velocità massima come 95° percentile del campo `speed`.** Anche dopo la pulizia restano picchi
  (traccia 174: 890 km/h nel campo `speed`, 75 km/h fra due punti consecutivi): la pulizia scarta i
  punti lontani dal percorso, non quelli che saltano in avanti lungo il percorso. Su 39 tracce con
  più di 100 punti il massimo semplice dà valori assurdi in 14 casi (campo `speed`) e in 26 (fra
  punti consecutivi, il metodo che Strava documenta); la finestra mobile di 60 s in 5; il 95°
  percentile del campo `speed` in nessuno.
- **Il campo `speed` è già in km/h.** Il suo 95° percentile coincide con quello calcolato dalle
  posizioni in km/h; l'app oggi lo mostra come km/h senza conversioni, e il valore è corretto.
- **Dislivello dal DEM.** La quota GPS del telefono oscilla e il rumore si somma come dislivello:
  traccia 121, 28 km lungo la costa con quota fra 1 e 15 m, dà 536 m di salita dal GPS e 123 dal
  DEM. Strava documenta lo stesso approccio per i dispositivi senza altimetro barometrico.
- **Tempo effettivo con la sola soglia di velocità.** Strava calcola la media sul tempo in
  movimento. L'app registra un punto solo dopo 10 m di spostamento (`distanceFilter`): da fermi non
  registra, e ogni sosta diventa un unico tratto lungo nel tempo e di circa 10 m (traccia 176:
  26 minuti per 10 m, 0,02 km/h). Una regola sulla durata dei tratti (pausa oltre 120 s) è stata
  scartata: sulla 174 gli intervalli lunghi coprono centinaia di metri (10 minuti per 221 m), cioè
  cammino senza segnale, e la regola li toglieva dal tempo effettivo. La soglia è 1 km/h: sotto
  i 2 km/h la distribuzione delle velocità è piatta, ma fra 1 e 2 ci sono le salite ripide.
- **Media sui soli tratti con GPS buono.** Dove il GPS è disturbato ma i punti restano entro 50 m
  dal percorso, la pulizia li tiene e la distanza si gonfia: traccia 104 (18 s), 15,9 km/h dalle
  posizioni, 4,8 sui soli tratti con accuracy ≤ 40 m; traccia 34, 11,7 contro 5,1. Sulle tracce
  con GPS buono non cambia nulla: metà delle tracce ha il GPS buono per il 100% del tempo, il 90%
  per almeno il 99%. Il tempo effettivo invece comprende anche i tratti disturbati, perché il tempo
  è reale anche quando la posizione non lo è.

## Requisiti

- [ ] `properties.stats` si calcola solo se la traccia ha `properties.locations` con almeno 2 punti
  tenuti dalla pulizia. Senza, nessun `stats`: un `stats` presente va rimosso. Vale per i file
  caricati (GPX, KML, GeoJSON) e per le registrazioni delle app 3.1.2–3.1.6, che non inviavano i
  punti.
- [ ] Ogni valore si scrive solo se ci sono i dati per calcolarlo, altrimenti la chiave è `null`
  (mai `0` al posto di un dato mancante).
- [ ] L'observer (`UgcTrackGeometryCleanupObserver::saving`) ricalcola solo quando la traccia è
  nuova o quando cambiano davvero i punti GPS: confronta `properties.locations` salvate nel DB con
  quelle in arrivo, in PHP. In quel caso ricostruisce la geometria (come oggi), calcola i valori
  locali (`distance`, `duration`, `duration_moving`, `avg_speed`, `max_speed`, `points_total`,
  `points_discarded`, `computed_at`) sulla stessa lista di punti tenuti
  (`UgcTrackCleanupService::keptLocations()`), mette a `null` le chiavi DEM e accoda il job DEM.
- [ ] Se i punti non cambiano (cambio di layer in Nova, di nome, di form) non si ricalcola nulla e
  `stats` resta quello salvato nel DB (`getOriginal`): un cambio di layer non perde il dislivello.
- [ ] Un `stats` inviato dal client (store, compreso il merge di oc:8718, ed edit) non viene mai
  salvato: vince sempre quello calcolato dal server o quello già salvato.
- [ ] I valori DEM (`ascent`, `descent`, `ele_min`, `ele_max`, `ele_from`, `ele_to`) si calcolano
  con un job sulla coda `dem`, accodato dopo il commit, che chiama lo stesso client DEM delle
  EcTrack sulla geometria pulita. Finché il job non riesce restano `null`.
- [ ] Il job DEM scrive in SQL solo le proprie chiavi di `stats`, senza far ripartire l'observer e
  senza toccare i valori locali.
- [ ] Parametri in `config/wm-package.php`, ciascuno con la sua variabile d'ambiente e il default
  scelto sui dati: percentile della velocità massima (95) e velocità minima del tempo effettivo
  (1 km/h). Le due soglie della pulizia esistono già (40 m, 50 m): l'accuracy serve anche a
  riconoscere i tratti con GPS buono per la media.
- [ ] Il `config.json` dell'app espone i quattro parametri in `GEOLOCATION.record.stats`
  (`max_accuracy`, `max_deviation`, `max_speed_percentile`, `moving_min_speed`), solo quando la
  registrazione è attiva (`GEOLOCATION.record` presente).
- [ ] `wm:clean-ugc-track-geometry` e il suo job calcolano `stats` per **ogni** traccia con punti
  GPS, anche quando la geometria non cambia (oggi il job salta le tracce con geometria uguale: nel
  DB locale 121 su 134 non hanno punti scartati), e accodano il job DEM quando le sue chiavi
  mancano o sono `null`. L'aggiornamento della geometria resta condizionato alla differenza, come
  oggi. Valgono le opzioni già presenti (`--dry-run`, `--app-id`, `--queue`).
- [ ] L'immagine di condivisione della traccia (`ShareStoryImageController`, oc:8183) legge i
  valori da `stats`; `StoryShare/TrackStatsService` resta solo come ripiego per le tracce che non
  hanno ancora `stats`. Oggi calcola il dislivello dalla quota GPS e la durata totale: l'utente
  vedrebbe nell'app 313 m di salita e nell'immagine condivisa 481.
- [ ] Il dettaglio Nova della UgcTrack mostra i valori di `stats`, solo in lettura, accanto a
  «GPS cleanup». Etichette in inglese come chiave, tradotte in it, de, es, fr.
- [ ] Casi di test condivisi con wm-core (oc:8743): insiemi di punti con i punti tenuti e i valori
  locali attesi, in un formato che anche i test dell'app possono leggere.
- [ ] Test: traccia 174 (dati reali), `stats` inviato dal client mai salvato, cambio di sole
  properties che conserva i valori DEM, traccia senza `locations` senza `stats`, punti senza
  `speed`, tratti con GPS disturbato esclusi dalla media, DEM che fallisce, command su tracce con
  geometria invariata, immagine di condivisione che legge `stats`.
- [ ] Pagina di conoscenza `docs/knowledge/dati-tecnici-delle-tracce-ugc.md`, scritta come
  **specifica del calcolo** per chi lo rifà nell'app (oc:8743), non solo come spiegazione. Per ogni
  chiave di `stats`: da quali dati parte, la formula passo per passo, l'unità, l'arrotondamento e
  cosa succede nei casi limite (meno di 2 punti, `time` mancante o non in ordine, `speed` assente,
  `altitude` assente). Vanno fissati i dettagli che altrimenti ogni implementazione sceglie da sé:
  il raggio terrestre della distanza (6.371.000 m, già usato da `TrackStatsService`), il metodo
  del percentile, i confini delle soglie (≥ o >). Le chiavi DEM rimandano al servizio, che l'app
  non replica. La pagina rimanda ai casi di test condivisi e spiega il motivo delle scelte
  (percentile, DEM, tempo effettivo). Il ticket oc:8743 deve linkarla.

## Rischi

- **Il job DEM può fallire o rispondere lento.** Il servizio è esterno: i valori DEM restano
  `null` e il job si ritenta. L'app mostra i valori locali senza dislivello finché non arrivano.
- **Valori DEM persi o sostituiti a ogni salvataggio.** L'observer oggi scatta a ogni modifica di
  `properties` e riassegna sempre la geometria (`UgcTrackGeometryCleanupObserver.php:21-28`): un
  cambio di layer in Nova (`HasLayerOverride`) farebbe ricalcolare `stats`, e i valori DEM
  andrebbero azzerati (mai più riaccodati) o presi dai dati in arrivo (falsificabili dal client
  con il merge di oc:8718). Mitigato: si ricalcola solo se cambiano i punti GPS, altrimenti
  `stats` resta quello del DB; il job DEM scrive in SQL solo le proprie chiavi.
- **Le tracce con geometria invariata resterebbero senza `stats`.** Il job del command salta le
  tracce la cui geometria non cambia (`CleanUgcTrackGeometryJob.php:40-43`). Mitigato: `stats` si
  calcola per ogni traccia con punti GPS.
- **Tracce di pochi secondi.** Con 2–7 punti il 95° percentile ha pochi valori e la velocità
  massima può risultare più bassa della media (6 tracce su 124, la più lunga di 7 punti). Accettato
  e scritto nella specifica: nessuna correzione, perché sono registrazioni di prova.
- **Prima e dopo la sincronizzazione il dislivello cambia.** L'app senza rete calcola il dislivello
  dalla quota GPS, il server dal DEM. È accettato: dopo la sincronizzazione l'app legge `stats`.
- **Il `config.json` non si rigenera da solo** quando cambia una variabile d'ambiente: si scrive al
  salvataggio dell'App o con `UpdateAppConfigJob`. Dopo un cambio dei parametri va rigenerato.
- **Il 95° percentile non è il massimo.** Chi confronta `max_speed` con un altro strumento vede un
  valore più basso: va scritto nella pagina di conoscenza.

## Out of scope

- La parte app (lettura di `stats`, calcolo al volo per le tracce non sincronizzate, visualizzazione
  di tempo totale ed effettivo): oc:8743.
- Le UgcPoi e le EcTrack: il calcolo riguarda solo le UgcTrack.

## Moduli toccati

Tutto in `wm-package` (submodule). Nel repo camminiditalia solo il bump del submodule e la riga nel
`CLAUDE.md`.

- `src/Services/Models/UgcTrackCleanupService.php` o un nuovo servizio di calcolo di `stats`, che
  riusa `keptLocations()` e la distanza di `StoryShare/TrackStatsService`
- `src/Observers/UgcTrackGeometryCleanupObserver.php`
- nuovo job per il DEM delle UgcTrack, sul client DEM di `EcTrackService`
- `src/Jobs/CleanUgcTrackGeometryJob.php` e il command `wm:clean-ugc-track-geometry`
- `config/wm-package.php`
- `src/Services/Models/App/AppConfigService.php` (`config_section_geolocation()`)
- `src/Nova/UgcTrack.php`
- `src/Http/Controllers/Api/ShareStoryImageController.php` e `StoryShare/TrackStatsService.php`
- `resources/lang/{en,it,de,es,fr}.json`
- `tests/` (Unit e Feature, più i casi di test condivisi)
- `docs/knowledge/`: pagina sulla pulizia GPS di oc:8719 o una nuova sui dati tecnici delle UGC

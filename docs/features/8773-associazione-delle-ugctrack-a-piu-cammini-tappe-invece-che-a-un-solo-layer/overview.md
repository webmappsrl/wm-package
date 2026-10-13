> Ticket: oc:8773

# Associazione delle UgcTrack a più cammini/tappe invece che a un solo layer — parte wm-package

## Cosa cambia

Una UgcTrack non ha più un solo `properties->layer_id`. Al suo posto il package salva **quali
parti delle EcTrack** (segmenti di lunghezza fissa) la traccia copre. I layer che la traccia
attraversa si ricavano da quella corrispondenza, passando per le EcTrack e `layerables`.

- Le EcTrack vengono divise in segmenti di lunghezza fissa (parametro), misurati in metri reali.
- Un segmento è **coperto** da una UgcTrack se il segmento, preso come linea continua, passa entro
  una distanza massima (parametro) dalla geometria della traccia: `ST_DWithin(segmento, traccia, distanza massima)` su
  geography, equivalente a "il segmento sta entro la distanza massima dalla traccia".
- Per ogni coppia (UgcTrack, EcTrack) con almeno un segmento coperto si salvano gli indici dei
  segmenti coperti e i parametri usati per calcolarli.
- Il calcolo vale per **tutte** le UgcTrack (registrate, importate, importate da GPX). Filtri
  come "solo tracce registrate" o "un uuid conta una volta" sono regole del consumer.
- Segmento e distanza massima sono parametri **per App**: properties della App, modificabili da Nova App,
  con default in config.
- `UgcObserver::populateLayerId()` smette di scrivere `layer_id` sulle UgcTrack, **per tutti i
  consumer**. Sugli UgcPoi non cambia niente.
- La risorsa Nova `Wm\WmPackage\Nova\UgcTrack` mostra, nel detail, i layer e le EcTrack
  attraversati con la corrispondenza di ciascuna EcTrack, e offre un filtro per layer basato sulla
  nuova corrispondenza. I consumer che la estendono (es. `App\Nova\UgcTrack` di camminiditalia)
  li ricevono tramite `parent::fields()`.

La regola di corrispondenza è quella dell'algoritmo del passaporto validato con Cammini d'Italia
(oc:8165, call del 03/09/2026), scomposta traccia per traccia. Le due cose danno lo stesso
risultato: un segmento è coperto dall'unione delle aree entro la distanza massima di più tracce se e solo se è coperto da almeno una di esse entro la distanza massima. Il calcolo per utente (unione dei segmenti, soglie, sblocchi) non
sta qui: è oc:8165, nel consumer.

## Perché

Una UgcTrack è un percorso lungo (nel DB di camminiditalia: media 16,9 km, massimo 183 km), che
attraversa più EcTrack e più layer. Associarla a un solo layer è concettualmente sbagliato. È
anche inaffidabile: l'algoritmo attuale (EcTrack più vicina entro 500 m, poi layer con il
centroide più vicino) ha assegnato la UgcTrack 321 (circa 100 km sulla Via degli Dei) alla Via
Mater Dei (oc:8466). Su un campione di 5 UgcTrack, 2 toccano tappe di 2 layer diversi entro 50 m.

`layer_id` ha lo stesso significato in tutti i consumer, quindi il problema non è solo di
camminiditalia, e la correzione va nel package. Il package oggi scrive il `layer_id` delle
UgcTrack ma non lo legge per nessuna logica: lo usa solo come scorciatoia in
`UgcService::resolveLayer()`.

## Requisiti

- [ ] Migration: tabella di corrispondenza UgcTrack ↔ EcTrack con `ugc_track_id`, `ec_track_id`,
      indici dei segmenti coperti, lunghezza del segmento e distanza massima usati, timestamp; FK con
      cancellazione a cascata su entrambi i lati.
- [ ] Segmentazione deterministica di una EcTrack: si parte dall'inizio della geometria, taglio
      con `ST_LineSubstring` su geography (su geometry in gradi i segmenti da 400 m variavano da
      332 a 521 m, oc:8165), l'ultimo segmento può essere più corto. Se la EcTrack è fatta di più
      pezzi, la numerazione dei segmenti è **unica per EcTrack** e non riparte da 0 a ogni pezzo.
      Stessa geometria + stessi parametri = stessi segmenti con gli stessi indici, sempre.
- [ ] Corrispondenza: `ST_DWithin(segmento, UgcTrack, distanza massima)` su geography, con prefiltro spaziale per
      non confrontare tutte le EcTrack.
- [ ] Calcolo su tutte le UgcTrack, senza filtri su origine o uuid, ma solo contro le EcTrack della
      **stessa App** della UgcTrack (`app_id`), con i parametri di quella App.
- [ ] Il tipo delle EcTrack in `layerables` si ricava sempre con `getMorphClass()` del modello
      configurato (`wm-package.ec_track_model`), mai con il nome della classe del package scritto a
      mano: in camminiditalia vale `App\Models\EcTrack`.
- [ ] **Stato del calcolo su ogni UgcTrack**, anche senza alcuna corrispondenza: data del calcolo e parametri
      usati (segmento, distanza massima), oppure "fallito" con il motivo. Serve a distinguere "nessuna tappa
      coperta" da "calcolo mai riuscito" (job in timeout, Horizon fermo).
- [ ] UgcTrack degeneri (meno di 2 punti, geometria vuota dopo la pulizia GPS): stato "calcolato",
      zero righe, nessun errore e nessun retry.
- [ ] Parametri `segment_length` e `distanza massima` nelle properties della App, con default in
      `config/wm-package.php`; campi Number nella risorsa Nova App del package, con validazione
      lato server di minimi **e massimi** (es. segmento 50–2000 m, distanza massima 5–200 m) e un testo di
      aiuto che avvisa che il cambio ricalcola tutte le tracce dell'App.
- [ ] Un cambio dei parametri da Nova accoda **un solo job per App**, unico (`ShouldBeUnique` per
      App), che accoda i job per traccia. Ogni job per traccia legge i parametri quando gira e salta
      le tracce già calcolate con quelli: più correzioni di fila producono un solo giro.
- [ ] Job di calcolo su una coda dedicata (es. `ugc-track-matching`), separata da mail, DEM e import.
- [ ] Il calcolo è un job pubblico del package, per id di UgcTrack, unico per traccia e accodato
      dopo il commit (`afterCommit`).
- [ ] Calcolo accodato quando una UgcTrack viene creata (dopo la pulizia GPS di oc:8719, che gira in
      `saving()`) e quando una UgcTrack esistente cambia geometria via Eloquent, compreso il retry
      dell'app che aggiorna la traccia con lo stesso uuid (oc:8718: scatta `updated`, non `created`).
- [ ] **Ogni percorso che scrive la geometria di una UgcTrack in SQL puro accoda il ricalcolo in modo
      esplicito**: `CleanUgcTrackGeometryJob` lo fa accanto a `UpdateModelWithGeometryTaxonomyWhere`
      (riga 52), perché il suo `DB::update` non fa scattare gli observer.
- [ ] Quando una EcTrack viene **creata** o cambia geometria, si cancellano le righe di quella
      EcTrack e si rifà il calcolo contro le UgcTrack **vicine** (prefiltro `ST_DWithin` con il
      distanza massima), non solo contro quelle che avevano già una riga. Vale anche per i percorsi che
      scrivono la geometria della EcTrack senza observer: `EcTrackService::reverse()` (oc:8543:
      l'inversione rinumera i segmenti), i `saveQuietly()` di `EcTrackService`,
      `ApproveTrailApplication`.
- [ ] Le righe spariscono quando una UgcTrack viene cancellata o archiviata come duplicata
      (oc:8718).
- [ ] `UgcObserver::populateLayerId()` non scrive più `layer_id` sulle UgcTrack; gli UgcPoi restano
      invariati, compreso `UgcService::resolveLayer()`.
- [ ] Relazioni sul modello UgcTrack per leggere la corrispondenza: EcTrack attraversate con i
      segmenti coperti, layer ricavati (via `layerables`).
- [ ] Command con `--dry-run` che, per le UgcTrack esistenti, calcola la corrispondenza e toglie
      `layer_id` (e `layer_id_auto_resolved`, se presente) da `properties`. Idempotente: lavora
      solo sulle tracce mai calcolate, fallite o calcolate con parametri diversi da quelli attuali
      della loro App.
- [ ] Nova `Wm\WmPackage\Nova\UgcTrack`: campo HTML in sola lettura, solo detail, con i layer
      attraversati e, per ciascuno, le EcTrack toccate con la corrispondenza (segmenti coperti / totali).
      Link al layer e alla EcTrack in una nuova scheda (`rel="noopener noreferrer"`), nomi escapati.
      Una EcTrack che sta in più layer compare sotto ciascuno, senza doppioni nello stesso layer;
      i layer che non esistono più (righe orfane in `layerables`) vengono ignorati. Le EcTrack
      che non stanno in nessun layer (33 su 1295 in camminiditalia) compaiono in un gruppo
      "senza layer". Se il calcolo non è stato fatto o è fallito, il campo lo dice.
- [ ] Nova `Wm\WmPackage\Nova\UgcTrack`: filtro per layer (UgcTrack con almeno un segmento coperto
      su una EcTrack di quel layer).
- [ ] Campo e filtro non hanno un controllo di ruolo proprio: seguono l'autorizzazione della
      risorsa, così ogni consumer resta con le sue regole di visibilità.
- [ ] Etichette in `resources/lang/*.json` del package, con termini generici ("layer", "EcTrack"):
      il consumer le ritraduce con il proprio lessico.
- [ ] Test del package: segmentazione deterministica, corrispondenza (dentro/fuori distanza massima, traccia
      su due EcTrack di layer diversi), nessun `layer_id` scritto su una UgcTrack nuova, UgcPoi
      invariati, ricalcolo al cambio di geometria (Eloquent, `CleanUgcTrackGeometryJob`,
      `EcTrackService::reverse()`), EcTrack nuova confrontata con le UgcTrack esistenti, isolamento
      per App, stato del calcolo (zero corrispondenza vs fallito, traccia degenere), cancellazione a
      cascata, command in dry-run e idempotente, contenuto del campo Nova (layer orfano ignorato,
      EcTrack in due layer, gruppo "senza layer") e filtro per layer.

## Rischi

- **Altri consumer o l'app potrebbero leggere `properties.layer_id` di una UgcTrack.** Nel package
  nessuno lo legge per una logica, ma wm-core e gli altri consumer non sono verificabili da questo
  repo. Va controllato prima del merge (ricerca nei repo dei consumer e in wm-core).
- **Segmenti instabili.** Se la regola di segmentazione cambia anche di poco, le righe salvate non
  corrispondono più e la corrispondenza sbaglia senza errori. Mitigazione: regola unica in un solo
  service, parametri salvati su ogni riga, test sulla stabilità.
- **Ordine di rilascio.** Prima il merge in wm-package, poi il bump del submodule in camminiditalia
  insieme alla parte locale e alla migration pubblicata (procedura
  `docs/howto/migration-wm-package.md`).
- **Costo del calcolo** su tracce molto lunghe (183 km). Mitigazione: job in coda, unico per
  traccia (`ShouldBeUniqueUntilProcessing` + `WithoutOverlapping`, schema già usato nello stash
  di oc:8165), prefiltro spaziale con indice, calcolo una sola volta per traccia.
- **Codice già scritto nello stash di oc:8165** (branch
  `feature/oc-8165-passaporto-camminatore-badge-automatico-da-gps` di camminiditalia,
  `stash@{0}`): SQL di segmentazione, job, trigger e parametri da Nova App esistono già, ma
  calcolano per utente e vivono in camminiditalia. Vanno riportati qui, per traccia, senza
  lasciare due implementazioni della stessa regola.

## Out of scope

- Calcolo per utente (unione dei segmenti, soglia di sblocco della tappa e del cammino, date,
  numero di uscite): oc:8165, nel consumer camminiditalia.
- Soglia di sblocco e ogni altro parametro del passaporto: restano nel consumer.
- Varianti dei cammini.
- Qualsiasi cambiamento agli UgcPoi.

## Moduli toccati

**wm-package**
- `database/migrations/` (tabella di corrispondenza, con stub per i consumer)
- `src/Observers/UgcObserver.php` (`populateLayerId()` solo per gli UgcPoi)
- nuovo service di segmentazione e corrispondenza in `src/Services/`
- nuovo job di calcolo in `src/Jobs/`
- `src/Models/UgcTrack.php` (relazioni), modello della tabella di corrispondenza
- observer su UgcTrack/EcTrack per ricalcolo e cancellazione
- nuovo command in `src/Commands/` + registrazione in `WmPackageServiceProvider`
- `config/wm-package.php` (parametri)
- `src/Nova/UgcTrack.php` (campo HTML e filtro per layer)
- risorsa Nova App del package (campi segmento e distanza massima)
- `resources/lang/*.json`
- `tests/`

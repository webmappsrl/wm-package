> Ticket: oc:8571

# Il dichiarato dal SUS accanto al calcolato dal DEM, sull'istanza

## Cosa cambia

L'istanza del Catasto Sentieri (`TrailApplication`) riceve la stessa gestione DEM che il sentiero
(`EcTrack`) ha già: un tab DEM con il confronto fra valore calcolato, valore manuale e valore
corrente, e il calcolo DEM che parte da solo.

- **Il file caricato si conserva tale e quale.** Il GPX o GeoJSON da cui nasce l'istanza va in una
  collection media dedicata (`original_geometry`), non si tocca mai più, si scarica dal dettaglio e
  all'approvazione arriva anche sul sentiero.
- **Il calcolo DEM parte alla creazione dell'istanza**, in coda (`dem`), dopo il commit: scrive
  `properties['dem_data']` e mette nella geometria la Z del nostro DEM. Dopo qualche minuto il tab è
  completo.
- **All'apertura del dettaglio il job si rilancia solo se serve**: l'istanza ha una geometria valida
  e `dem_data` è assente o vuoto. Dove il dato esiste già non parte nulla.
- **L'operatore corregge i valori manuali dal tab DEM**, tutti e nove come sul sentiero, con
  validazione numerica e unità di misura indicate. Cancellando un valore manuale torna a valere il
  DEM. È l'unica modifica possibile sull'istanza.
- **La modifica è possibile solo mentre l'istanza è in istruttoria** (`under_review`). Approvata o
  rifiutata, l'istanza è congelata.
- **I valori manuali non si perdono più**, né all'approvazione né alle modifiche successive della
  traccia del sentiero: `updateManualData()` smette di cancellarli.
- **Il dettaglio dell'istanza mostra la mappa del suo codice**, la stessa del registro codici, con
  la sua legenda: settore, altri settori, sentieri vicini, traccia dell'istanza e, se l'istanza è
  approvata, il sentiero. Sentiero e istanza restano distinguibili per colore, tooltip e legenda.

Il principio: **un'istanza è un sentiero in attesa di convalida**, e i due modelli hanno già la stessa
base (`MultiLineString`). Quello che oggi è scritto solo per EcTrack si porta sulla base comune invece
di duplicarlo.

## Perché

Nella call tecnica Sardegna Sentieri del 15/09/2026 si è deciso che, per i dati tecnici del
sentiero, l'istanza mostra la stessa tabellina del sentiero:

- Saba (Forestas): «Mentre lunghezza dislivello sono dati oggettivi, quindi mi va bene che che siano
  misurati perché mi sta dando la traccia GPX. Il tempo di percorrenza è un dato di progetto, il
  progettista può stimarlo per varie valutazioni e io questa cosa non voglio perderla».
- Bonfanti: «facciamo la solita tabellina, Dem manuale e te lo vedi nell'istanza. Se ti piace lo
  accetti, se non ti piace cancelli il manuale e si puppa il dem». Saba: «perfetto».

Perché il confronto sia possibile, il DEM deve esistere già sull'istanza e non solo dopo che è
diventata un sentiero.

La Z viene sempre dal nostro DEM perché le quote dei file caricati sono spesso calcolate da altri
sistemi e non rilevate sul campo; per i casi rilevati sul campo il file originale resta conservato.

## Requisiti

### Geometria e file originale

- [ ] Alla creazione il file caricato si salva, senza modifiche, nella collection media
      `original_geometry` dell'istanza (`singleFile()`), e resta scaricabile dal dettaglio
- [ ] All'approvazione il file arriva sul sentiero con `ApproveTrailApplication::copyMedia()`, già
      esistente

### Calcolo DEM

- [ ] Alla creazione si accoda sulla coda `dem`, con `afterCommit()`, il job DEM dell'istanza: se la
      creazione va in rollback (es. settore non trovato o esaurito) non si accoda nulla
- [ ] Il calcolo dei valori DEM (chiamata a `DemClient`, durate da `*_hiking`) è estratto da
      `EcTrackService::updateDemData()` in un metodo che restituisce `dem_data` senza salvare: EcTrack
      e istanza lo usano entrambi, EcTrack continua a salvare come oggi
- [ ] Il job dell'istanza scrive in SQL solo le sue chiavi: `properties['dem_data']` con `jsonb_set`
      e la geometria con la Z del DEM, senza risalvare il modello via Eloquent
- [ ] `dem_data` ha la stessa struttura del sentiero, campi per bici ed escursionismo compresi
- [ ] Sull'istanza **non** girano `updateManualData()` e `updateCurrentData()`
- [ ] All'apertura del dettaglio il job DEM si rilancia **solo** se l'istanza ha una geometria valida
      e `properties['dem_data']` è assente o vuoto; se `dem_data` contiene già dati non si accoda
      nulla
- [ ] Il job DEM dell'istanza è unico per istanza (`ShouldBeUnique`, `uniqueId()` = id dell'istanza,
      `uniqueFor` di qualche minuto)

### Resource Nova e modifica

- [ ] La Resource Nova dell'istanza estende `AbstractGeometryResource` e mostra un tab DEM costruito
      con `getDemTabFields()`, senza duplicarlo
- [ ] `authorizedToUpdate()` è vero solo per le istanze `under_review`
- [ ] `fieldsForUpdate()` contiene **solo** i nove Field `properties->manual_data->*`, presi da
      `getDemTabFields()` filtrando per attributo: niente `name`, niente Field su `dem_data`
- [ ] In `getDemTabFields()` i nove Field `manual_data` hanno
      `->rules('nullable', 'numeric', 'min:0')` e un help con l'unità del campo (unità da confermare
      nel codice). Vale per tutti i form che usano il metodo, sentiero compreso
- [ ] Cancellando un valore manuale il valore corrente torna al DEM (precedenza già garantita da
      `HasDemClassification::classifyField()`)

### Approvazione e valori manuali

- [ ] `EcTrackService::updateManualData()` parte dal `manual_data` esistente invece che da `null` e
      non cancella mai un valore già presente; aggiunge solo i campi al primo livello di `properties`
      diversi da DEM e OSM, come oggi
- [ ] All'approvazione `manual_data` arriva sul sentiero e resta intatto dopo la catena che
      l'approvazione lancia sul nuovo EcTrack

### Mappa

- [ ] Il dettaglio dell'istanza mostra `TrailRegistryMap` e la legenda di `MapLegendRenderer`, con
      `TrailApplication::getFeatureCollectionMap()` che delega al codice dell'istanza: quello attivo,
      altrimenti il più recente (istanza rifiutata). Nessun nuovo componente Vue né bundle
- [ ] Istanza senza nessun codice: la mappa mostra solo la sua traccia
      (`GeometryModel::getFeatureCollectionMap()`), senza legenda
- [ ] La mappa del codice non cambia: sentiero (verde, tooltip «Sentiero <codice>») e istanza
      (arancio, tooltip «Istanza #<id>») restano distinti per colore, tooltip e voce di legenda

### Test

- [ ] La base dei test TrailRegistry disattiva per default la chiamata al DEM (`Http::fake()` o
      `Bus::fake()` sul job dell'istanza): nessun test esistente esce verso `dem.maphub.it`
- [ ] File originale salvato in `original_geometry`; job accodato dopo il commit e non accodato con
      la prenotazione del settore fallita; `dem_data` e Z scritti con la risposta DEM finta; rilancio
      dal dettaglio solo con geometria valida e `dem_data` vuoto, nessun job con `dem_data` presente;
      unicità del job; update negato fuori da `under_review`; form di modifica con i soli nove Field
      manuali; validazione numerica; `updateManualData()` che conserva `manual_data`; `manual_data`
      intatto dopo l'approvazione; mappa dell'istanza uguale a quella del suo codice, anche da
      rifiutata, e sola traccia senza codice

## Rischi

- **`updateManualData()` è condiviso da tutti i consumer.** La correzione cambia un comportamento:
  i valori manuali non vengono più cancellati alla modifica della geometria. Per il canale OSM e
  GeoHub del primo livello non cambia nulla; per osm2cai2 `app_id=2` non cambia nulla, perché
  `CleanupSiHikingRoutesManualDataCommand` ha già svuotato `manual_data`. Va scritto nel ticket per
  chi fa il bump negli altri consumer.
- **La validazione in `getDemTabFields()` vale per tutti i sentieri.** Un sentiero con un valore
  manuale non numerico già salvato non si salva finché il campo non viene corretto. Mitigato da un
  conteggio dei `manual_data` non numerici nel DB di Forestas prima del rilascio.
- **La geometria dell'istanza cambia: prende la Z del DEM.** Il DEM calcola sulla geometria 2D e
  restituisce la stessa traccia con le quote; va verificato nel test che X/Y restino invariate, perché
  il settore del codice è stato calcolato sulla geometria originale. Il file originale resta come
  riferimento.
- **Un effetto collaterale su una richiesta di lettura.** Il rilancio dal dettaglio accoda un job su
  una GET; lo limitano la condizione su `dem_data` vuoto e `ShouldBeUnique`, il cui lock su Forestas
  sta nella tabella `cache_locks` (`CACHE_STORE=database`).
- **Istanza approvata prima che il DEM sia arrivato.** Il sentiero nasce senza `dem_data`, ma la
  catena di EcTrack lanciata dall'approvazione lo ricalcola.
- **Estendere `AbstractGeometryResource` porta con sé i metodi della base.** `cards()` e `lenses()`
  restituiscono array vuoti come la `Resource` di Nova; `fields()`, `filters()` e `actions()` sono già
  ridefiniti dall'istanza. Va controllato che nulla cambi nell'index e nel form di creazione.
- **La mappa dell'istanza dipende da quella del codice.** Chi cambia
  `TrailRegistryCode::getFeatureCollectionMap()` o `MapLegendRenderer` cambia anche la scheda
  dell'istanza: è voluto, e va scritto nei docblock.
- **Aprire l'update tocca un divieto motivato.** `authorizedToUpdate()` oggi nega tutto perché un
  cambio di traccia potrebbe cambiare il settore e quindi il prefisso di un codice già comunicato. Il
  form esporrà solo i valori manuali, mai la geometria.

Valutati e scartati come ipotetici: una correzione manuale salvata proprio durante la chiamata al DEM
(chiuso comunque dalla scrittura mirata in SQL), istanze vecchie senza DEM (la produzione parte da
zero e UAT si azzera ogni notte), servizio DEM giù con rilanci ripetuti (due operatori), dati
personali verso il servizio DEM (è nostro e usa solo geometria e `id`).

## Out of scope

- Il SUS e il recupero dei valori dichiarati dal richiedente tramite API: niente scrittura di
  `manual_data` alla creazione dal SUS, niente conversioni di unità dei tempi dichiarati
- L'import da Sardegna Sentieri (Drupal), che scrive ancora distanza, dislivello e tempi in
  `manual_data` di EcTrack: la decisione del 14/09 di ignorarli è in oc:8641
- L'eliminazione dei campi DEM al primo livello di `properties` (package e osm2cai2): oc:8642
- Il ripristino della geometria originale dal file conservato
- Rendere lunghezza e dislivello non modificabili a mano: restano modificabili tutti e nove i valori
- Correggere su EcTrack i cinque Field di `getDemTabFields()` che scrivono in `dem_data` dal form:
  l'istanza li esclude con `fieldsForUpdate()`
- Restrizioni per ruolo sul dominio TrailRegistry (oggi chiunque entri in Nova crea e approva
  istanze): follow-up
- Un indicatore «DEM non ancora calcolato» nel tab
- Le traduzioni delle etichette del tab DEM, che mancano già oggi in `resources/lang/*.json`
- Qualsiasi comunicazione al richiedente sul valore adottato
- I valori del registro catastale storico (Excel)

## Moduli toccati

Tutto nel repo `wm-package`.

- `src/TrailRegistry/Nova/TrailApplication.php` — estende `AbstractGeometryResource`, tab DEM,
  `authorizedToUpdate()` limitato a `under_review`, `fieldsForUpdate()` con i soli valori manuali,
  salvataggio del file originale in creazione, rilancio del job DEM all'apertura del dettaglio,
  mappa e legenda del codice
- `src/TrailRegistry/Models/TrailApplication.php` — collection media `original_geometry`, innesco del
  job DEM alla creazione con `afterCommit()`, `getFeatureCollectionMap()` che delega al codice
- `src/Nova/AbstractGeometryResource.php` — validazione e unità sui nove Field `manual_data`
- `src/Services/Models/EcTrackService.php` — calcolo DEM estratto da `updateDemData()`;
  `updateManualData()` che conserva `manual_data`
- `src/Jobs/` — nuovo job DEM dell'istanza, coda `dem`, `ShouldBeUnique`, scrittura in SQL
- `tests/Feature/TrailRegistry/` e `tests/Unit/Services/EcTrackService/` — base dei test con il DEM
  disattivato e test elencati nei Requisiti

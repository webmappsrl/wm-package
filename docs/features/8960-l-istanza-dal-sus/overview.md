> Ticket: oc:8960

# L'istanza dal SUS: i dati che arrivano dall'API e le regole che li proteggono

## Cosa cambia

Oggi un'istanza di accatastamento (`TrailApplication`) porta quattro dati: chi l'ha inserita, lo
stato, una denominazione e una geometria. Dopo questo lavoro porta anche **tutto ciò che il
richiedente ha dichiarato sul SUS** — lo Sportello Unico dei Servizi della Regione Sardegna, il
portale realizzato da Engineering su cui il cittadino presenta la domanda — e i documenti che vi ha
allegato.

Cambiano tre cose:

1. Il payload che arriva dal SUS si conserva **com'è arrivato** in `properties → api_data`. Nessuna
   colonna nuova sulla tabella: quei dati non vengono mai corretti, quindi non serve una seconda
   copia che possa divergere.
2. Il modello acquisisce **sei collection media**, una per ciascun allegato previsto dal format.
3. Nasce lo stato `under_compilation`: una domanda che il SUS ha creato ma non ha ancora finito di
   consegnare. Finché è lì, l'operatore Forestas la vede nell'elenco ma non può istruirla né
   approvarla.

I dati dichiarati sono in sola lettura per l'operatore: si accettano o si respingono con
motivazione, non si correggono.

## Perché

Il format dell'istanza (`SUS-EVO_ANALOGHI_FORES_02_Format_Istanza accatastamento_VALIDATA_221026.xlsx`,
foglio `Format istanza accatastamento V`) descrive 75 campi, di cui 35 righe non portano il
marcatore `DA NON INVIARE AL CATASTO`: sono i 27 dati e i 6 documenti che il Catasto deve ricevere,
più due label di sezione. Oggi nel modello non esiste nulla per accoglierli, quindi **nessun
endpoint del SUS può essere scritto prima**: questo lavoro è il presupposto di tutti gli altri del
tag 709.

Lo stato nuovo nasce da un fatto del protocollo concordato. Il campo 66 del format stabilisce che
«gli allegati non viaggiano nella stessa chiamata che crea l'istanza. Il SUS crea l'istanza, riceve
indietro il suo ID e con quello carica un allegato per chiamata; l'esito complessivo si considera
buono quando sono andate a buon fine la creazione e tutte le chiamate di upload», ed Engineering ha
risposto `Ok` nella propria colonna. Se la domanda arriva in più chiamate esiste un momento in cui è
incompleta, e senza uno stato che lo dichiari l'operatore aprirebbe una domanda priva di metà dei
documenti senza sapere se mancano perché non sono stati mandati o perché non devono esserci. Lo
stato non si limita a informarlo: le autorizzazioni e le Action del dominio sono costruite su
`status`, quindi gli impedisce anche di approvarla prima del tempo.

La sola lettura dei dati dichiarati nasce dalla funzione che hanno: sono il verbale di ciò che il
richiedente ha affermato, e il Catasto non riceve le dichiarazioni formali perché — come concordato
il 15/09 — si dà per scontato che tutte le comunicazioni fatte col SUS le contengano. Se l'operatore
potesse correggerli, quel verbale perderebbe l'unica funzione che ha.

## Requisiti

### Come arrivano e dove si fermano

- [ ] Il payload ricevuto dal SUS viene salvato per intero in `properties → api_data`, comprese le
      chiavi che non sappiamo ancora mappare, e non è modificabile da nessuna interfaccia.
      **Si scrive una volta sola, alla creazione**: dopo, nessun endpoint lo riscrive, quindi il
      verbale è immutabile per costruzione e non per divieto. È una scelta presa adesso e
      reversibile: se un domani servirà correggere una domanda invece di rifarla, servirà un
      endpoint nuovo e una regola nuova. Va dichiarato nella documentazione in modo esplicito,
      perché è il tipo di vincolo che chi integra scopre tardi: **questa chiamata non si corregge,
      si rifà**.
- [ ] **Gli identifier già in uso nel package si riusano**, per non dover mappare: `from`, `to`,
      `distance`, `ascent`, `duration_forward`, `duration_backward`, `difficulty`, e le due
      relazioni `taxonomyActivities` e `taxonomyThemes`.
- [ ] **I quattro soggetti prendono i nomi che l'import usa già** — `soggetto_gestore`,
      `soggetto_rilevatore`, `soggetto_manutentore`, più `soggetto_custode` che nasce ora — e le
      label di `EcTrackRuoloFilter`: Gestore, Rilevatore, Manutentore, Custode. Sono identificatori
      **in italiano**, in deroga alla regola del package, e la deroga è voluta: i primi tre sono
      **gli stessi nomi che i campi hanno su Drupal** — `node__field_soggetto_gestore`,
      `_manutentore`, `_rilevatore`, che `SardegnaSentieriDatabaseSource` espone già senza il
      prefisso `field_` e mappa sui ruoli di `enteables`, 1349 righe. Solo `soggetto_custode` è
      nostro, perché su Drupal non esiste, e segue lo stesso schema. Tradurli significherebbe
      mantenere due vocabolari per sempre: chi legge non li corregga.
- [ ] I **valori** delle select sono invece identifier in inglese, perché non esistono da nessuna
      parte: `applicant`, `municipalities`, `third_party`, `other_surveyor`, `other_legal_entity`,
      `forestas`, e `good_condition`/`dilapidated`/`absent` per la segnaletica. Vanno consegnati a
      Engineering con la loro label italiana, insieme alle tassonomie.
- [ ] Gli altri identifier sono nuovi e in inglese, come ogni identificatore del codice.
- [ ] **L'API accetta le unità del package, non quelle del format**: `distance` in chilometri con un
      decimale, `duration_forward` e `duration_backward` in minuti interi. La documentazione lo
      dichiara per ciascuno dei tre campi con un esempio di valore, e la voce entra fra le divergenze
      da segnalare a Engineering alla pubblicazione delle API.
- [ ] **I valori tecnici dichiarati (campi 32-35) restano in `api_data` e basta: `manual_data` non
      si tocca.** Copiarli lì li farebbe vincere in automatico su tutto il resto —
      `HasDemClassification::classifyField()` restituisce `MANUAL` appena `manual_data` non è vuoto,
      `EcTrackResource` pubblica quel valore e `PBFGeneratorService` (righe 261-267) lo legge anche
      per le tile — cioè farebbe decidere al cittadino, mentre il foglio dice che «l'operatore
      Forestas decide quale diventa il valore corrente». L'operatore li legge fra i dati del
      dati SUS della detail view; se ritiene che il dichiarato sia quello buono lo scrive lui nel tab
      DEM, e allora è una decisione. Conseguenza da dichiarare: la colonna «manuale» della tabellina
      resta vuota finché l'operatore non interviene, mentre il foglio la descrive come occupata dal
      dichiarato (celle S33-S36, «colonne DEM / manuale / valore corrente»).
- [ ] La scrittura in `properties` parte da
      `CASE WHEN jsonb_typeof(properties) = 'object' THEN properties ELSE '{}'::jsonb END`: la
      factory crea le istanze con `properties = []`, e su un array `||` concatena invece di unire,
      facendo sparire la chiave (oc:8571).
- [ ] **`api_data` non viene copiato sul sentiero all'approvazione.** `createTrack()` oggi riporta
      sul nuovo `EcTrack` tutte le properties dell'istanza — `$properties = $application->properties`
      (`ApproveTrailApplication.php:132`), e il docblock lo dichiara: «Le properties dell'istanza
      (anagrafica del proponente, protocollo) vengono riportate sul sentiero». Con dentro i dati
      dichiarati, quella copia porterebbe codici fiscali, e-mail e nomi di persone nel GeoJSON
      pubblico: `EcTrackResource.php:75` toglie solo `manual_data`, `dem_data` e `osm_data`, e
      `GeoJsonService::removeInvalidProperties()` non è una whitelist ma scarta i soli array vuoti.
      Il verbale resta quindi su `trail_applications` e non esce di lì.
- [ ] **Nessuno dei dati nuovi passa sull'`EcTrack` creata dall'approvazione.** Oltre a `api_data`,
      restano sull'istanza anche i cinque allegati nuovi: `copyMedia()`
      (`ApproveTrailApplication.php:204-215`) oggi itera su **tutti** i media e li copia sul
      sentiero, e va ristretto alla sola `original_geometry`, che è ciò che copia oggi perché è
      l'unica collection esistente. Relazione, tabella dati, autorizzazioni, piano di manutenzione e
      foto sono documenti dell'istruttoria e restano sull'istanza. `Media::copy()` duplica il file
      sul disco, quindi una copia fatta per errore non si annulla scollegandola.
- [ ] Ogni parametro documentato porta il **numero di campo del format**; il link al format sta una
      volta sola nell'intestazione della pagina `/docs/api/sus`.

- [ ] **La chiamata che crea l'istanza porta la geometria e tutti i dati dichiarati**, ed è lì che
      avviene la validazione dei campi. Dopo, al SUS restano due sole chiamate possibili: caricare
      un allegato, o dichiarare conclusa la compilazione. La geometria nella chiamata di creazione è
      obbligatoria come nel form d'ufficio di Nova, dove `fieldsForCreate()` chiede `name` e il file
      entrambi `required`: senza, tre cose si romperebbero — `booted()` accoda il calcolo del DEM a
      ogni `created` senza guardie, `UpdateTrailApplicationDemJob::handle()` chiama il servizio DEM
      senza controllare che la geometria ci sia, e `needsDem()` restituisce `false` quando
      `geometry` è NULL, cioè il calcolo non si riaccoderebbe più da solo quando la traccia
      arrivasse dopo.
- [ ] **La risposta alla creazione porta l'ID e i dati tecnici calcolati dal DEM**, non il numero
      del sentiero: quello viene riservato alla creazione come già oggi, ma resta nascosto all'API
      finché un operatore del Catasto non approva l'istanza — è il controller a decidere cosa il SUS
      vede, non il modello. Gli allegati che viaggiano in chiamate successive sono gli altri cinque:
      relazione, tabella dati, foto, autorizzazioni, piano di manutenzione.

- [ ] **Il calcolo del DEM alla creazione avviene una volta sola.** La risposta alla chiamata di
      creazione contiene i dati tecnici calcolati, quindi il calcolo è sincrono (lo fa
      `EcTrackService::fetchDemTechData()`, che chiama il servizio e restituisce senza scrivere).
      Il dispatch automatico di `UpdateTrailApplicationDemJob` in `TrailApplication::booted()` va
      quindi chiuso esplicitamente quando il calcolo è già stato fatto, non lasciato correre
      contando sul lock: sarebbe una seconda chiamata al servizio esterno, che può rispondere
      diversamente, fallire, o arrivare dopo che l'operatore ha già guardato i numeri. Il percorso
      asincrono resta per i casi in cui il DEM manca davvero.

### Validazione

- [ ] **Alla creazione si valida la forma di tutti i campi noti, e si conserva comunque tutto**: le
      regole producono un 422 con il dettaglio, ma in `api_data` finisce il payload intero, chiavi
      sconosciute comprese. Salvare solo i campi validati farebbe perdere ogni campo nuovo del
      format fino al giorno in cui qualcuno se ne accorge.
- [ ] **Alla conferma si valida la completezza dei soli allegati**: i dati sono già stati controllati
      e accettati alla creazione, quindi resta da verificare che ci siano tutti i documenti
      obbligatori, con la risposta che dice in forma strutturata cosa manca. È il momento in cui il
      format considera l'esito «buono»: «sono andate a buon fine la creazione e tutte le chiamate di
      upload».
- [ ] L'elenco dei campi e degli allegati obbligatori è una proprietà dichiarata una volta sola su
      `TrailApplication`, non una regola scritta dentro l'API. **Riguarda `api_data`**, cioè ciò che
      il SUS deve consegnare, e la applica la chiamata di conferma: un'istanza creata d'ufficio in
      Nova non ha `api_data` — quel contenuto esiste solo per il canale API — quindi continua a
      nascere come oggi, con i due campi di `fieldsForCreate()`, senza che la regola la riguardi.
- [ ] Le **attività** si validano contro l'elenco dei sette identificatori, non contro la tabella
      `taxonomy_activities`: quella in locale contiene anche tre identificatori dell'import da
      Sardegna Sentieri (`sardegnasentieri:type:sentiero`, `:itinerario`, `:cammino`) che attività
      non sono.
- [ ] La **difficoltà** si valida come coppia attività → livello: `{"mtb": "EEA"}` è un errore anche
      se `EEA` esiste, perché appartiene a un'altra scala. Le scale **esistono già a database**, come
      famiglie di `taxonomy_terms`: `hiking-difficulty` (T, E, EE, EEA), `mtb-difficulty` (TC, MC,
      BC, OC, EC), `horse-difficulty`, `nordic-walking-difficulty`,
      `educational-trails-difficulty`, `soft-mobility-difficulty`, `inclusivity-difficulty` (AT, AE,
      AEE). La validazione interroga quelle, non un elenco riscritto nel codice.
- [ ] **I valori ammessi che l'API dichiara per il campo 37 sono gli identifier attuali dei termini**,
      anche dove portano il prefisso dell'import (`sardegnasentieri:tid:4908`): cinque famiglie su
      sette li hanno così, perché nascono da `SardegnaSentieriImportService::DRUPAL_TERM_PREFIX`,
      mentre `educational-trails-difficulty-*` e `inclusivity-difficulty-*` sono stati scritti a
      mano e sono parlanti. Uniformarli è una decisione della macro area 4, non di questo ticket.
- [ ] Le select che governano l'obbligatorietà di altri campi (47, 49, 53) si validano come gruppo:
      `soggetto_gestore_other`, `soggetto_rilevatore_other`, `soggetto_rilevatore_email`,
      `soggetto_manutentore_other` e `soggetto_manutentore_cf` sono obbligatori solo per certi
      valori della select che li precede.

### I 27 dati dichiarati

In grassetto gli identifier che esistono già nel package. La colonna «Denominazione nel format» è
il testo verbatim della cella, ed è il riferimento che va scritto nella documentazione accanto a
ogni parametro, nella forma «campo 29 del format — *Origine (da)*». Il link al foglio sta una volta
sola nell'intestazione della pagina.

| # | Denominazione nel format | Identifier | Validazione | Valori |
|---|---|---|---|---|
| 29 | Origine (da) | **`from`** | stringa, obbligatorio | libero |
| 30 | Destinazione (a) | **`to`** | stringa, obbligatorio | libero |
| 31 | Eventuale Meta intermedia | `intermediate_stop` | stringa, facoltativo | libero |
| 32 | Lunghezza (m) | **`distance`** | numero, obbligatorio | chilometri, un decimale |
| 33 | Dislivello (m) | **`ascent`** | numero, facoltativo | metri — nella documentazione va dichiarato il significato che il Catasto gli dà: **dislivello positivo complessivo**, cioè la somma di tutte le salite su un profilo altimetrico lisciato (`calcAscentDescent()` del servizio DEM), non la differenza fra quota massima e minima, che è un altro dato (`ele_max` − `ele_min`) |
| 34 | T. percorrenza (A) | **`duration_forward`** | intero, obbligatorio | minuti |
| 35 | T. percorrenza (R) | **`duration_backward`** | intero, obbligatorio | minuti |
| 36 | Percorribilità e livello/i di difficoltà proposti | `activities` (**`taxonomyActivities`**) | array non vuoto, ogni elemento fra i sette | `trekking`, `hiking`, `mtb`, `horse`, `accessible-trails`, `nordic-walking`, `educational-trails` |
| 37 | Classificazione livello/i difficoltà proposti | **`difficulty`** | mappa attività → livello, si valida la coppia | `T`/`E`/`EE`/`EEA` · `TC`/`MC`/`BC`/`OC`/`EC` · `F`/`M`/`D` · `AT`/`AE`/`AEE` · facile/medio/impegnativo |
| 38 | Stato segnaletica pre-esistente | `existing_signage` | select, **obbligatorio** (lo dice la colonna «Modifiche al format», non quella formale) | `good_condition` «c'è ed è in buono stato» · `dilapidated` «c'è ed è fatiscente» · `absent` «non c'è» |
| 39 | Itinerari sovrapposti (anche solo in parte) | `overlapping_routes` | stringa, facoltativo | libero |
| 42 | Comuni gestori proposti | `proposed_managing_municipalities` | array di stringhe, obbligatorio, almeno uno | denominazioni, validate dal SUS a monte |
| 43 | E-mail del comune su cui il territorio il tracciato insiste | `municipality_email` | e-mail, obbligatorio | — |
| 44 | Altro indirizzo e-mail del comune su cui il territorio il tracciato insiste | `municipality_email_alt` | e-mail, facoltativo | — |
| 45 | Tematismo | `themes` (**`taxonomyThemes`**) | array, facoltativo | **elenco inesistente**, da proporre a Forestas |
| 47 | Soggetto gestore (label: **Gestore**) | `soggetto_gestore` | select, obbligatorio | `applicant` · `municipalities` · `third_party` · `forestas` |
| 48 | Altro soggetto terzo | `soggetto_gestore_other` | stringa, obbligatorio se `soggetto_gestore` = `third_party` | libero |
| 49 | Soggetto rilevatore (label: **Rilevatore**) | `soggetto_rilevatore` | select, obbligatorio | `applicant` · `other_surveyor` |
| 50 | Indicare colui che è stato soggetto rilevatore | `soggetto_rilevatore_other` | stringa, obbligatorio se `soggetto_rilevatore` = `other_surveyor`; «il valore deve essere nome e cognome (o denominazione) reali del rilevatore» | libero, persona o ente |
| 51 | e-mail soggetto rilevatore diverso dal richiedente | `soggetto_rilevatore_email` | e-mail, obbligatorio se `soggetto_rilevatore` = `other_surveyor` | — |
| 53 | Soggetto manutentore (label: **Manutentore**) | `soggetto_manutentore` | select, obbligatorio | `applicant` · `municipalities` · `other_legal_entity` · `forestas` |
| 54 | Denominazione altro soggetto manutentore | `soggetto_manutentore_other` | array di stringhe, obbligatorio se `soggetto_manutentore` = `other_legal_entity` | libero |
| 55 | CF altro soggetto manutentore | `soggetto_manutentore_cf` | array di stringhe, stessa condizione | codice fiscale o partita IVA |
| 56 | Esiste un soggetto custode diverso da gestore, manutentore e rilevatore? | `soggetto_custode_presente` | booleano, obbligatorio | — |
| 58 | Indicare colui che è soggetto custode (label: **Custode**) | `soggetto_custode` | stringa, obbligatorio | libero, persona o ente |
| 64 | Data ultima verifica tracciato | `last_survey_date` | data, obbligatorio, non futura | — |
| 65 | Frequenza manutenzione (Numero di mesi) | `maintenance_frequency_months` | intero positivo, obbligatorio | mesi |

### I 6 allegati

| # | Denominazione nel format | Collection | Formati ammessi | Obbligatorio |
|---|---|---|---|---|
| 67 | Relazione descrittiva | `report` | solo PDF | sì |
| 68 | Traccia GPS/cartografia digitale | `original_geometry` (esiste già) | solo GeoJSON e GPX | sì |
| 69 | Tabella dati sentiero | `trail_data_sheet` | solo ZIP | sì |
| 70 | Documentazione fotografica | `photos` | immagini, da 1 a 5 | sì |
| 71 | Eventuali autorizzazioni | `authorizations` | PDF o ZIP | no |
| 72 | Piano Manutenzione | `maintenance_plan` | PDF o ZIP | sì |

Limite di 5 MB per file su tutti, fissato da Engineering nella colonna «Controlli e Regole».

### Modello, stato e interfaccia

- [ ] `TrailApplication` registra sei collection media, una per allegato: relazione descrittiva
      (campo 67), traccia GPS/cartografia digitale (68), tabella dati sentiero (69), documentazione
      fotografica (70), eventuali autorizzazioni (71), piano manutenzione (72).
- [ ] **Le cinque collection documentali sono `singleFile()`**, come già la traccia: in un
      protocollo con retry — «l'esito complessivo si considera buono quando sono andate a buon fine
      la creazione e tutte le chiamate di upload» — un timeout seguito da un reinvio deve sostituire
      il file, non affiancarlo.
- [ ] **La galleria fotografica deduplica sul contenuto**, riusando `UgcMediaHashService` così
      com'è: `findByContent($model, $hash, $collection)` accetta qualunque modello `HasMedia` e
      qualunque collection, quindi non va copiato né riscritto — di UGC gli resta solo il nome. Il
      limite «da una a cinque» si applica **dopo** la deduplicazione, altrimenti conta i reinvii
      invece delle fotografie e rifiuta il retry legittimo della quinta foto.
- [ ] L'enum `TrailApplicationStatus` acquisisce il valore `under_compilation`, e il docblock che
      oggi dichiara «Tre valori e non cinque» viene riscritto, insieme a quello del modello che
      dichiara «Non esistono istanze non prevalidate».
- [ ] **Le autorizzazioni non si toccano: si verifica che coprano il caso nuovo, e lo si prova con
      i test.** `authorizedToUpdate()` della Resource, `TrailApplicationPolicy::update()` e le
      guardie dentro `handle()` delle tre Action sono tutte scritte come **whitelist** — permettono
      solo `status === UnderReview` — quindi `under_compilation` è già negato per costruzione.
      Aggiungere una negazione esplicita di `under_compilation` le capovolgerebbe in blacklist, e da
      quel momento ogni valore futuro dell'enum sarebbe permesso per difetto. Servono quindi i test
      che dimostrano che un'istanza in `under_compilation` non si modifica, non si approva, non si
      respinge e non cambia numero — non codice di autorizzazione nuovo.
- [ ] I dati dichiarati compaiono in `fields()` della Resource come Field su
      `properties->api_data->…` con `onlyOnDetail()`, quindi fuori dai form per costruzione;
      `fieldsForUpdate()` continua a restituire i soli nove valori manuali del tab DEM.
- [ ] **La resa testuale dello stato va sull'enum, con un metodo `label()`**, e la chiamano sia il
      campo sia il filtro. Oggi i valori grezzi compaiono in inglese in due punti —
      `TrailApplication.php:120`, `Text::make(__('Review status'), fn () => $this->status->value)`,
      che essendo in `summaryFields()` vale per elenco e dettaglio, e
      `TrailApplicationStatusFilter::options()`, che mappa `[$status->value => $status->value]`. Con
      la traduzione sull'enum il prossimo valore la eredita senza che nessuno debba ricordarsi di
      due punti distinti.
- [ ] **Il test `TrailRegistryEnumsTest` va aggiornato**: «espone i tre stati dell istruttoria»
      asserisce `toBe(['under_review', 'rejected', 'approved'])` e fallisce al primo valore
      aggiunto. Va cambiato anche il nome del test, che dichiara «tre».

## Rischi

**L'elenco dei temi (campo 45) non esiste.** `taxonomy_themes` è vuota, nel Catasto nessuna famiglia
di termini è tematica per un sentiero — ci sono `warnings`, `validation-status`, `route-type`,
`itinerary-type` e le scale di difficoltà, nessuna adatta — e su Drupal i 610 sentieri hanno solo
le attività. Il foglio stesso dice che l'elenco va «proposto da Webmapp e approvato da Forestas».
Non blocca: il campo è facoltativo e fino ad allora si accetta come arriva, dichiarando nella
documentazione che i valori sono in definizione.

**I soggetti arrivano come testo, e nessuno li aggancia agli enti.** I campi 47-58 entrano nel
istanza come dati SUS, e il collegamento con gli enti veri lo fa l'operatore a mano, come
già oggi: `entes` ha 93 righe e `enteables` 1349 associazioni con il campo `ruolo`, dove `gestore`,
`rilevatore` e `manutentore` sono già popolati su centinaia di tracce. Nessun aggancio automatico
fra la stringa del richiedente e una riga di `entes`. Il solo ruolo che non esiste ancora è
**`custode`**: lo introduce questo lavoro, ed è il primo valore di `ruolo` che nascerà da
un'istanza invece che dall'import.

**Il campo 72 ha la riga del format quasi vuota.** Obbligatorietà, formato e colonna del Catasto non
sono compilati: è la riga aggiunta in diretta durante la call del 22/10, ed Engineering l'ha
annotata con «Richiesto da FORESTAS (call del 22-10-2026)». Si procede con quanto dice la colonna di
esito — obbligatorio, PDF o ZIP — ed è un punto da riallineare alla prossima versione del foglio.

**Il campo 71 si contraddice dentro il foglio**: la colonna formale dice obbligatorio, l'esito dice
«Confermato facoltativo» ed Engineering scrive «Il campo diventa facoltativo». Prevale l'esito.

**I valori delle select sono parte del contratto.** `soggetto_gestore`, `soggetto_rilevatore` e
`soggetto_manutentore` governano l'obbligatorietà dei campi che li seguono: se il SUS mandasse
l'etichetta italiana invece dell'identifier, un ritocco alla dicitura del form disattiverebbe in
silenzio quell'obbligatorietà, senza nessun errore. Gli identifier vanno quindi consegnati a
Engineering con la loro label, e la validazione li controlla contro l'elenco.

**Su jsonb non si filtra come su una colonna.** Se domani servisse ordinare o filtrare l'elenco
delle istanze su un dato dichiarato — per esempio tutte le domande di un comune — servirebbe un
indice GIN o una colonna generata. Si aggiunge allora, per il campo che serve davvero.

## Out of scope

- Gli **endpoint** che ricevono tutto questo: creazione della domanda, calcolo sincrono dei dati
  tecnici, chiamata di upload e chiamata di conferma stanno nel ticket della compilazione (macro
  area 2 del tag 709).
- I **vincoli di formato e dimensione** degli allegati come regole di validazione dell'endpoint di
  upload (macro area 6): qui si stabilisce quali allegati esistono e quali sono obbligatori, non
  cosa l'endpoint rifiuta.
- La **prevalidazione del tracciato** (macro area 3) e l'**endpoint di stato** con il numero del
  sentiero (macro area 7).
- La **proposta dell'elenco dei temi** a Forestas, e più in generale la consegna delle tassonomie a
  Engineering con identificatore e label: è la macro area 4. Lì va presa anche la decisione se
  uniformare gli identifier dei termini di difficoltà, oggi divisi fra parlanti
  (`inclusivity-difficulty-at`) e importati da Drupal (`sardegnasentieri:tid:4908`), con
  `renameIdentifiers()` di `ConfigureTaxonomyTermsCommand` che esiste già per farlo. Qui si espone
  ciò che c'è adesso.
- Lo **storico dei passaggi** di un'istanza: proposto nella call interna del 10/11 e lasciato
  cadere, con rimando alla call di approfondimento del 16.
- **Come si chiude un'istanza in compilazione che il cittadino abbandona.** Oggi non si può: le
  autorizzazioni permettono solo `UnderReview`, `authorizedToDelete()` è `false`
  incondizionatamente, e il respingi — l'unico che rilascia il numero — ha la stessa guardia. Una
  domanda aperta e mai conclusa resta quindi nell'elenco dell'operatore con il suo numero riservato.
  Su UAT non si vede, perché il reset notturno tronca `trail_applications`, ma quel reset è
  destinato a sparire. Rimandato a una decisione del dev.
- I **numeri occupati da domande mai concluse**: una bozza che nessuno completerà tiene riservato il
  suo numero. Misurato sui dati locali, il settore più affollato (`ZNUB4`) ha 61 numeri occupati su
  100 e i volumi attesi sono trenta domande al mese distribuite su 44 settori, quindi il problema
  maturerebbe in anni; il rimedio, quando servisse, è liberare i codici riservati da istanze ferme
  oltre una certa soglia, con `release()` e una causa propria nella storia del codice.

## Moduli toccati

Tutto in `wm-package`, il submodule montato da forestas:

- `src/TrailRegistry/Models/TrailApplication.php` — `registerMediaCollections()`, l'elenco degli
  obbligatori, il docblock di classe da riscrivere.
- `src/TrailRegistry/Enums/TrailApplicationStatus.php` — il valore nuovo e il docblock.
- `src/TrailRegistry/Nova/TrailApplication.php` — `fields()`, `authorizedToUpdate()`, il campo di
  stato tradotto.
- `src/TrailRegistry/Policies/TrailApplicationPolicy.php` — da verificare, non da modificare: la
  whitelist copre già il caso nuovo.
- `src/TrailRegistry/Nova/Filters/TrailApplicationStatusFilter.php` — le opzioni del filtro, che
  oggi mostrano il valore grezzo.
- `src/TrailRegistry/Nova/Actions/ApproveTrailApplication.php` — `createTrack()` esclude `api_data`
  dalle properties copiate, `copyMedia()` si restringe alla sola `original_geometry`.
- `database/migrations/trail_registry/zz_2026_09_09_000001_create_trail_applications_table.php.stub`
  — il vincolo `CHECK (status IN …)` va esteso al valore nuovo. Nessuna colonna nuova.
- Nel repo **forestas**: `database/migrations/2026_09_10_073030_zz_2026_09_09_000001_create_trail_applications_table.php`,
  la copia già pubblicata, che porta lo stesso vincolo.
- `resources/lang/*.json` — le voci nuove nelle cinque lingue del package (de, en, es, fr, it).
- `tests/Unit/TrailRegistry/TrailRegistryEnumsTest.php` — il test che asserisce i tre stati, e il
  suo nome.
- `tests/Feature/TrailRegistry/` — i test che provano che un'istanza in `under_compilation` non si
  modifica, non si approva, non si respinge e non cambia numero.

**Fuori dal codice, da non dimenticare al rilascio:** la migration è già stata eseguita su tutti gli
ambienti, quindi modificare il file non cambia il vincolo esistente. Su locale, dev.forestas e UAT
serve un `ALTER TABLE trail_applications DROP CONSTRAINT trail_applications_status_check` seguito
dal nuovo `ADD CONSTRAINT`, eseguito a mano come per gli altri aggiornamenti di quegli ambienti.
Senza, la prima scrittura con `under_compilation` risponde `SQLSTATE[23514]`.

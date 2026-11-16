> Ticket: oc:8989
> Overview approvata il 2026-11-16 · impronta `07842754f4459759455a79a068015738302a8e95`

# Notes — Percorribilità dei sentieri nel dominio Catasto

## Modifiche ai requisiti

Nessuna finora.

## Divergenze dal piano, task per task

Nessuna finora: il piano non è ancora stato scritto.

## Bug trovati

Nessuno nel package. Su osm2cai2, letti durante lo studio e **non** da correggere in questo
ticket: in `IssueStatusPartition.php:48-50` manca il `break` dopo `case 'non percorribile'`, quindi
i non percorribili sono contati anche fra i parzialmente percorribili (stesso difetto in
`DashboardCardsHelper` e in `SectionSAL`); e in `CreateIssue.php:57` il fallback
`$hikingRoute->issues_user->name` chiama una relazione che sul modello si chiama `issueUser()`,
quindi quel ramo andrebbe in errore.

## Decisioni

### 2026-11-16 — Il dato si chiama «percorribilità», non `issues`

- **Decide:** dev
- **Perché:** in osm2cai2 il concetto ha tre nomi a seconda di dove lo si guarda — `issues_*` nelle
  cinque colonne di `hiking_routes` e nel tab Nova, `Accessibility` nelle etichette dei Field e come
  chiave nel geojson di `Region` e `Sector`, «Percorribilità» nei nomi delle metriche
  (`ClubSalPercorribilità`) e nel titolo della dashboard. Il nome strutturale è `issues`, ma la
  colonna contiene `percorribile`, `non percorribile`, `percorribile parzialmente`: sono stati del
  sentiero, non problemi. Che il nome descriva male il contenuto si vede dal fatto che la UI ha
  dovuto chiamarlo in altri due modi.
- **Scartato:** riusare `issues` come fa la fonte. Vale la regola che osm2cai detta anche il
  lessico, ma qui il nome non è ragionevole per una ragione che riguarda il nostro progetto: allo
  scrum del 29/10 è stato deciso che le interruzioni temporanee diventano un **campo avviso**
  separato — «aggiungere un nuovo campo… Avvisi su questo sentiero. Punto» (Piccioli). Quello sarà
  la vera segnalazione di un problema. Chiamando `issues` lo stato, il nome giusto resterebbe
  occupato dalla cosa sbagliata, e ci ritroveremmo con `issues` che sono stati e qualcos'altro che
  sono le segnalazioni.
- **Conseguenza:** «percorribilità» è il termine italiano con cui se ne parla ovunque — call,
  capitolato e `decisioni.md` di oc:8783 lo usano già. Gli identifier tecnici che ne discendono
  (famiglia di tassonomia, termini, tabella dello storico, Action, tipi di incongruenza) si
  decidono una riga per volta e non sono ancora fissati: `walkability`, che compare nell'overview
  del package in [R1](overview.md#r1) e [R4](overview.md#r4) e negli identifier scritti nel ticket
  oc:8989, è una proposta non ancora approvata.

### 2026-11-16 — La famiglia si chiama `walkability-status`, e «Sconosciuta» è un termine

- **Decide:** dev, su consiglio
- **Perché (nome):** segue la forma di `validation-status`, con cui compare sempre affiancata —
  `terms.*` in Elastic, `properties.taxonomies` in API, filtri della ricerca. Soprattutto, i due
  termini spostati conservano la seconda parte del nome: `validation-status-not-walkable` diventa
  `walkability-status-not-walkable`, e la corrispondenza si legge a occhio nel comando dei termini,
  nel mapping dell'import e nelle query di verifica.
- **Scartato (nome):** `walkability` secco, più corto ma senza quel parallelismo; `issues-status`
  dalla fonte, già escluso con la decisione sul nome del dato.
- **Perché (struttura):** «Sconosciuta» è un termine associato come gli altri, non l'assenza di
  legame. In osm2cai la colonna è NOT NULL con `default(IssuesStatusEnum::Unknown)`: la fonte
  considera «sconosciuto» un valore. Così la chiave in API c'è sempre e il sito legge un termine con
  nome tradotto e fascia del semaforo invece di gestire un caso in meno; le card contano con un solo
  `group by` invece di trattare a parte la categoria più numerosa (454 sentieri su 625 alla
  partenza); e la famiglia resta uniforme a `validation-status`, che è `single_choice`.
- **Scartato (struttura):** nessun legame per i sentieri senza informazione. Risparmia 454 righe nel
  pivot, ma obbliga frontend, card e query a ragionare sul vuoto.

### 2026-11-16 — Gli identifier dei termini sono `walkable` / `not-walkable` / `partially-walkable` / `unknown`

- **Decide:** dev, su consiglio
- **Perché:** `validation-status-not-walkable` esiste già nello shard ed è uno dei due termini che
  si spostano: tenendo la stessa seconda parte la migrazione è una rinomina che si vede, non un
  mapping da leggere. È la ragione per cui la famiglia si chiama `walkability-status`.
- **Scartato:** i nomi dei case di osm2cai, `Open` / `Closed` / `PartiallyClosed`. Sono la parte
  meno ragionevole di quell'enum, perché contraddicono i propri valori — `Open = 'percorribile'`,
  `PartiallyClosed = 'percorribile parzialmente'` — e guardano il sentiero dal lato opposto
  (chiusura invece di percorribilità) rispetto a come ne parlano il capitolato e le call.
- **Scartati** anche i valori italiani dell'enum (`'sconosciuto'`, `'percorribile'`, …): nel
  package gli identificatori sono in inglese per regola del repo, e i nomi italiani vivono come
  traduzione del termine.

### 2026-11-16 — I valori stanno in tabella, e la famiglia si configura con un default

- **Decide:** dev
- **Perché:** in osm2cai i valori sono case di `IssuesStatusEnum`, quindi aggiungerne uno è una
  migration più un deploy, e il codice confronta le stringhe (`case 'non percorribile'` nelle
  metriche). La scala della percorribilità non è stata decisa da nessuno e Forestas può cambiarla:
  con i termini in tabella è una riga nel comando dello shard.
- **Default:** il nome della famiglia sta in una chiave di configurazione del package con un
  default funzionante (`walkability-status`), non vuoto come `trail_registry.anomaly_types`: lì i
  tipi sono per forza dello shard, qui la famiglia serve sempre e un default assente romperebbe in
  silenzio un dominio acceso.
- **Scartato:** l'enum come nella fonte; e la chiave di configurazione senza default.

### 2026-11-16 — Le incongruenze catastali restano separate dalle anomalie

- **Decide:** dev
- **Perché:** tutti e undici i tipi di anomalia esistenti riguardano il **codice** del sentiero —
  i cinque fissi (`codice_gia_assegnato`, `settore_discordante`, `geometria_duplicata`,
  `codice_illeggibile`, `fuori_da_ogni_settore`, prodotti da `TrailRegistryNormalizeCommand`) e i
  sei che forestas aggiunge per il registro catastale (oc:8539, con `source` = `registro`). La
  percorribilità non appartiene a quel dominio.
- **Correzione:** in una prima formulazione avevo detto che `anomaly_types` è il meccanismo
  dell'import. Non è vero: è generico, e lo shard lo usa già per il registro catastale. La
  separazione resta, ma per il motivo scritto qui sopra.
- **Differenza di natura:** un'anomalia si corregge **alla fonte** e sparisce al giro dopo
  («una lista di lavoro e non uno storico», docblock di `TrailRegistryAnomalyType`); un'incongruenza
  catastale si risolve dentro Forestas, inserendo il dato che manca.

### 2026-11-16 — La data è il `created_at` dell'evento; in API esce come `last_update`

- **Decide:** dev, su consiglio
- **Perché:** con una tabella append-only la data della variazione è il `created_at` della riga, e
  «l'ultimo aggiornamento» è quello dell'ultima: una colonna a parte sarebbe un secondo posto dove
  scrivere la stessa cosa, con il rischio che diverga. `created_at` è anche il nome che
  `TrailRegistryCodeEvent` usa già nel dominio.
- **Timestamp, non `date`:** in osm2cai `issues_last_update` è `date` ma l'Action ci scrive
  `now()`, quindi due variazioni nello stesso giorno hanno la stessa data e non si ordinano. È
  nullable anche se ha senso solo insieme a uno stato, che invece è NOT NULL.
- **Il nome della fonte sopravvive in uscita:** la chiave nel JSON si chiama `last_update` come in
  osm2cai, così chi confronta i due sistemi ritrova la stessa parola dove la cerca.
- **Scartato:** una colonna `last_update` sul sentiero accanto allo storico.

### 2026-11-16 — L'autore è `user_id` sull'evento, mai il nome

- **Decide:** dev, su consiglio
- **Perché:** una fonte sola per l'autore. `user_id` nullable con `onDelete set null`, come
  `issues_user_id` in osm2cai e come `TrailRegistryCodeEvent` nel dominio; il nome si legge dalla
  relazione quando va mostrato, e se l'utente non c'è più la riga dice che è stato rimosso invece
  di esibire un nome non più verificabile.
- **Scartato:** copiare il nome nello storico, come fa osm2cai in `issues_chronology`
  (`'issues_user' => $user->name`). Lì le due scelte convivono — id nella colonna, nome nella
  cronologia — che è il caso peggiore: due fonti per lo stesso dato, che divergono appena qualcuno
  cambia nome. Il fallback di quella riga, `$hikingRoute->issues_user->name`, chiama per di più una
  relazione che sul modello si chiama `issueUser()`.

### 2026-11-16 — La nota si chiama `description` ed è `text`

- **Decide:** dev, su consiglio
- **Perché:** è il nome e il tipo della fonte (`issues_description`, `text` nullable): su questa
  riga non c'è ragione di scostarsi da osm2cai.
- **Scartato:** `reason` di `TrailRegistryCodeEvent`, il pattern di storico già nel dominio. Avrebbe
  portato con sé anche il tipo, `string` da 64 caratteri: basta per la causale di un cambio di
  codice, non per la frase tipica di questo dato — «albero caduto al km 3 dopo il ponte, passaggio
  possibile a monte con deviazione di 200 m» ne sono novanta. La differenza fra i due campi è
  giustificata: lì si annota perché un codice è cambiato, qui si descrive cosa c'è sul sentiero.
- **Da decidere in fase di piano:** se mettere un limite di lunghezza, che in osm2cai non c'è
  (`Textarea` con il solo `->nullable()`).

### 2026-11-16 — Superata: lo storico diventa una tabella per tutti gli stati del sentiero

Supera la voce qui sotto su `trail_walkability_events`. La tabella non è della percorribilità, è
dei **cambi di stato di un sentiero**, e una colonna dice di quale stato si parla.

- **Decide:** dev
- **Perché:** il dev vuole che anche i cambi di stato di validazione abbiano data e autore, come
  osm2cai li ha per l'accatastamento (`validation_date`, `validator_id`). I due assi hanno lo stesso
  soggetto e gli stessi valori — termini di una famiglia a scelta singola — quindi una tabella con
  una colonna per la famiglia li serve entrambi. La validazione entra in un ticket suo senza toccare
  lo schema.
- **Colonne:** `ec_track_id` NOT NULL FK; `taxonomy_id` NOT NULL FK verso la riga-famiglia (l'id,
  non l'identifier, che è configurabile); `from_term_id` nullable FK e `to_term_id` NOT NULL FK
  verso `taxonomy_terms`; `description` text nullable; `user_id` nullable FK; `created_at`. Tutte
  chiavi esterne vere, **senza cascata** sui termini: un termine con storia non si cancella, si
  marca ritirato, e `--fresh` fallisce invece di cancellare la storia in silenzio.
- **Nome:** `trail_status_events` / `TrailStatusEvent`, accanto a `trail_registry_code_events` che
  è lo stato del codice.
- **Scartato: inglobare anche `trail_registry_code_events`** con un soggetto polimorfo. Il dev ci
  teneva per avere una storia sola del sentiero, a partire dall'assegnazione del numero. Tre
  ragioni contro: per percorribilità e validazione il soggetto *è* la traccia, quindi la colonna
  polimorfa e `ec_track_id` direbbero la stessa cosa e potrebbero divergere; un riferimento
  polimorfo non è una chiave esterna, ed è la trappola già documentata per `enteables` e per il
  pivot dei termini — lo stub dei codici ha già deciso il contrario, «Due colonne distinte e non un
  riferimento polimorfico: […] sono chiavi esterne vere» (righe 48-53); e i vocabolari non
  combaciano, perché gli stati del codice sono `string(16)` con `CHECK IN ('reserved','assigned',
  'released')` e `reason` obbligatoria da 64 con codici macchina, mentre i nostri sono identifier
  di termini lunghi 36 con nota libera facoltativa. Su sei colonne ne combaciavano due.
- **La storia unica resta, come lettura:** un renderer legge gli eventi di stato del sentiero più
  gli eventi dei codici agganciati a quel sentiero (`trail_registry_codes.ec_track_id`, che non
  cambia mai dopo l'assegnazione) e ordina per data.

### 2026-11-16 — Lo storico è `trail_walkability_events` / `TrailWalkabilityEvent` *(superata)*

- **Decide:** dev, su consiglio
- **Struttura:** tabella append-only con `ec_track_id`, `from_status`, `to_status`, `description`,
  `user_id`, `created_at`. `from_status` e `to_status` ricalcano `TrailRegistryCodeEvent` e qui
  contengono l'identifier del termine invece di un valore di enum.
- **Perché il nome:** la forma è quella del dominio (`trail_*_events`) e la parola centrale è quella
  del dato. La somiglianza con l'identifier `walkability-status` è un pregio — chi vede i due nomi
  capisce che parlano della stessa cosa — e l'unico equivoco possibile, credere che cambiando la
  configurazione cambi anche la tabella, lo chiude un docblock sul modello: **il nome della famiglia
  è configurabile, questo no**.
- **Scartato:** una terza parola (`trail_condition_events` e simili) per evitare quell'equivoco:
  costerebbe un termine in più nel lessico, che è il problema che stiamo cercando di non avere.
  E `trail_issue_events`, già escluso con la decisione sul nome del dato.
- **Perché non il jsonb della fonte:** `issues_chronology` non si interroga («quanti sentieri sono
  diventati non percorribili a ottobre?» non è una query), due salvataggi simultanei si
  sovrascrivono perdendo una voce, e la struttura delle voci è garantita solo da chi scrive —
  infatti conserva il nome dell'utente mentre la colonna conserva l'id.

### 2026-11-16 — L'Action si chiama `UpdateWalkability`

- **Decide:** dev, su consiglio
- **Perché:** dice cosa fa dal punto di vista di chi la usa, resta nel lessico scelto alla riga 1 e
  non promette un oggetto «segnalazione» che da noi non esiste.
- **Scartati:** `CreateIssue` della fonte, perché «issue» è stato escluso e perché l'Action non crea
  una segnalazione, registra uno stato; e `CreateWalkabilityEvent`, vero tecnicamente ma che racconta
  il meccanismo interno invece dell'azione — chi sceglie la voce dal menu di Nova non sta pensando a
  un evento.
- **Dalla fonte si tiene:** `showInline()`, e i due campi del form, stato obbligatorio e nota
  facoltativa.
- **Dalla fonte non si tiene:** il doppio salvataggio. In `CreateIssue` il primo `save()` scatena
  l'Observer, che fa `UpdateEcTrackAwsJob::dispatch(...)` e rigenera il file pubblicato **prima**
  che `saveQuietly()` scriva la cronologia; il motivo non è commentato da nessuna parte. Da noi
  stato ed evento si scrivono in una transazione sola.

### 2026-11-16 — La cronologia in Nova ha un renderer suo, `WalkabilityHistoryRenderer`

- **Decide:** dev, su consiglio
- **Perché:** il nome segue la forma del dominio (`CodeHistoryRenderer`) e la parola del dato.
- **Scartato:** riusare `CodeHistoryRenderer` generalizzandolo. I due dati hanno la stessa forma —
  una sequenza di passaggi di stato con autore e data — ma colonne diverse (`reason` da 64 contro
  `description` testuale) e contenuti diversi: una storia mostra codici, l'altra termini di
  tassonomia con il loro nome tradotto. Generalizzarlo è un refactor fuori da questo ticket, su una
  parte del Catasto già in uso.
- **Dalla fonte non si tiene nulla:** osm2cai usa
  `Code::make(__('Accessibility History'), 'issues_chronology')->json()->onlyOnDetail()`, cioè il
  campo per il codice sorgente usato per mostrare dati: la detail view espone il JSON grezzo
  dell'array.

### 2026-11-16 — Il filtro non si scrive: è quello di oc:8988

- **Decide:** dev
- **Perché:** oc:8988 «Filtro Nova generico per termine di tassonomia» è in corso e fa esattamente
  questo — filtro unico e parametrico nel package, con identifier della tassonomia e modalità
  singola o multipla, che riusa il campo `Choice` perché il pannello dei filtri mostri le stesse
  icone dell'edit. Dice anche che `EcTrackValidationStatusFilter`, oggi lo stesso filtro con
  l'identifier cablato, «poi sparisce».
- **Conseguenza:** la percorribilità istanzia quel filtro con il proprio identifier e non scrive
  nessuna classe.
- **Dipendenza:** oc:8989 non va iniziato sulla parte dei filtri prima che oc:8988 sia finito,
  altrimenti si scrive una classe destinata a sparire.
- **Correzione:** avevo consigliato di estrarre il generico dentro questo ticket, e prima ancora
  avevo indicato `TermsByTaxonomyFilter` del package come candidato già pronto. È sbagliato: quel
  filtro applica `where('parent_id', $value)` e le sue opzioni sono le tassonomie — serve a filtrare
  l'elenco dei termini per famiglia, non le tracce per termine.

### 2026-11-16 — Due card, e «catastale» in inglese è `TrailRegistry`

- **Decide:** dev
- **Le card:** `WalkabilityDistributionCard` (i sentieri per stato di percorribilità, equivalente di
  `IssueStatusPartition` e insieme il semaforo della home visto da chi lo deve riempire) e
  `TrailRegistryInconsistenciesCard` (i due numeri delle incongruenze catastali, ciascuno che porta
  alla index filtrata). Il tipo si chiama di conseguenza `TrailRegistryInconsistencyType`,
  parallelo di `TrailRegistryAnomalyType`.
- **Scartato:** `Cadastral…`, che avevo proposto traducendo «catastale». Nel codice il dominio si
  chiama `TrailRegistry` — cartella, tabelle, enum — e un secondo nome inglese per la stessa cosa è
  l'errore evitato alla riga 1 con `issues`.
- **Scartato:** l'equivalente di `IssueLastUpdatePerMonth`, che misura il ritmo di lavoro degli
  operatori: interessante quando il dato è a regime, non quando 454 sentieri su 625 sono ancora da
  rilevare. Se servirà è una card in più su una dashboard che a quel punto esiste.
- **Dalla fonte non si porta** la struttura delle altre metriche: `ClubSalPercorribilità` e
  `SectionSALPercorribilitá` misurano l'avanzamento per club e per sezione CAI, concetti che qui non
  esistono. Tre delle quattro metriche di osm2cai contengono per di più il bug del `break` mancante.

### 2026-11-16 — In API un blocco solo, `properties.walkability`

- **Decide:** dev, su consiglio
- **Perché:** lo stato è un termine di tassonomia e esce da sé sotto
  `properties.taxonomies.walkability-status`, con nome tradotto e icona; data, autore e nota non
  sono tassonomia e stanno in un blocco accanto, con le chiavi `last_update` (nome conservato dalla
  fonte), `user` e `description`.
- **Scartato:** chiavi di primo livello come in osm2cai. Lì la stessa informazione esce in cinque
  punti con tre chiavi (`issues_status`, `issues_description`, `issues_last_update`), più un quarto
  nome (`accessibility`) nei geojson di `Region` e `Sector`. È così che si arriva a
  `HikingRoute.php:1285`, `$this->properties['sicai']['percorribilità'] ?? $this->issues_status`,
  dove due fonti si contendono lo stesso campo.
- **L'autore esce come nome**, non come id: è un JSON pubblico e l'id non serve a chi lo consuma.
- **Non esce** la cronologia completa (come in osm2cai), né il dato nei geojson di aree e settori:
  lì serve a `Region` e `Sector`, che qui non hanno equivalente nel perimetro del ticket.

### 2026-11-16 — L'import scrive sempre la percorribilità, senza eccezioni

- **Decide:** dev
- **Come:** lo smistamento vive in `SardegnaSentieriImportService`, nel mapping
  `VALIDATION_STATUS_TERMS` che già associa i tid di Drupal agli identifier: due tid smettono di
  puntare a `validation-status-*` e puntano a `walkability-status-*`. Nessun comando a sé, al
  contrario di osm2cai, dove `osm2cai:import-hiking-routes-issues` travasa i cinque campi dal
  database legacy.
- **Scartato:** scrivere solo al primo giro, per non sovrascrivere un valore inserito a mano in
  Forestas. È un distinguo senza effetti pratici finché Drupal è la fonte.
- **Da rivedere** quando Drupal verrà dismessa e la percorribilità diventerà un dato nativo di
  Forestas: a quel punto l'import non avrà più un valore da portare.

### 2026-11-16 — `walkability_before_validation` cade: resta solo `missing_walkability`

- **Decide:** dev
- **Effetto:** tolto da [R6](../../../../docs/features/8989-percorribilita-dei-sentieri-da-osm2cai-nel-dominio-catasto/overview.md) dello shard.
- **Perché:** confronta la data della percorribilità con quella della validazione, ma **una data di
  validazione non esiste**: il pivot ha `public $timestamps = false`, `validation-status` non ha
  storia e su `EcTrack` c'è solo la costante della famiglia. Su osm2cai quella data esiste
  (`validation_date`, `validator_id` accanto a `osm2cai_status`) e da noi si è persa quando lo stato
  è diventato una tassonomia.
- **Da dove veniva:** da me, in sessione, ragionando sulle combinazioni possibili fra i due assi.
  Non è nel verbale del 12/08, non è in `decisioni.md`, non è in nessuna call, e in osm2cai nessun
  codice confronta `issues_status` con `osm2cai_status`. L'ho proposto dando per scontato che la
  data ci fosse, senza verificarlo.
- **Torna** se e quando la validazione avrà i suoi eventi datati — ma a quel punto come proposta al
  cliente, non come decisione nostra.

### 2026-11-16 — Le incongruenze sono `missing_walkability` e `walkability_before_validation` *(la seconda poi caduta)*

- **Decide:** dev, su consiglio
- **Inglese puro con etichette tradotte**, come `TrailApplicationStatus`: `label()` con `match` e
  `__('…')`, voci in `resources/lang/{locale}.json`. Snake_case perché è la forma dei valori di
  tipo in quel dominio.
- **Perché non italiano:** gli undici tipi di anomalia esistenti sono italiani
  (`codice_gia_assegnato`, `registro_link_orfano`), ma le incongruenze sono una famiglia **nuova**,
  non un'aggiunta a quella: non deve allinearsi a una convenzione che il repo stesso considera
  un'eccezione alla regola degli identificatori in inglese. Tutto il resto deciso in questo ticket
  è inglese, e due valori italiani metterebbero il pastone dentro la famiglia nuova.
- **Scartati:** `missing-walkability` e `stale-walkability`, scritti nel ticket oc:8989 prima che
  si decidesse alcunché — col trattino invece dello snake_case, e il secondo dice «percorribilità
  stantia», che è un'interpretazione, non la condizione definita (anteriore alla validazione).
- **Il nome in inglese del dominio è `TrailRegistry`**, non `Cadastral`: vedi la decisione sulle
  card.

### 2026-11-16 — La fascia del semaforo non viaggia col termine

- **Decide:** dev
- **Perché:** il semaforo è un blocco della home del sito e vive in `wp-forestas`, già fuori scope.
  Come si colora uno stato è una scelta di presentazione: il sito mappa gli identifier sui tre
  colori, come fa oggi con i sette di `validation-status`. Il termine porta nome tradotto, icona e
  ordine; il colore no.
- **Scartato:** mettere la fascia in `properties` del termine perché il sito non si tocchi quando
  la scala cambia. Pagherebbe solo se un altro shard volesse lo stesso semaforo, e intanto
  obbligherebbe il comando dei termini a saper scrivere `properties`, cosa che oggi non fa: la
  tupla è `[nome, identifier, sort_order, icona]` e `align()` riceve `'properties' => null`
  (`ConfigureTaxonomyTermsCommand.php:209-217`).
- **In osm2cai** i colori non stanno da nessuna parte in particolare: sono copiati in tre file
  (`IssueStatusPartition.php:34-37`, `ClubSalPercorribilità.php:36-39`, `SectionSALPercorribilitá`),
  con le stringhe italiane dell'enum come chiavi. Sono gli stessi tre file che contengono il bug del
  `break`.

### 2026-11-16 — Accendere il dominio basta: niente passi manuali

- **Decide:** dev — «io accendo il dominio, il resto si accende da sé»
- **Cosa comprende:** creazione della famiglia e dei quattro termini, accensione della famiglia per
  le app esistenti sul modello delle tracce (tabella `app_taxonomy_term`, con `models` che
  restringe ai soli `EcTrack`), e `is_system` sul termine padre perché le app create dopo la
  ricevano da sé (`AppObserver::created`).
- **Perché non basta crearla:** `DetailTaxonomiesService` (righe 266-276) scarta ogni termine la cui
  famiglia non è accesa per quell'app, e lo stesso vale per l'indicizzazione Elastic. In Nova invece
  si vedrebbe lo stesso, perché `scopeForAppAndModel` (`TaxonomyTerm.php:249-267`) ha un fallback:
  «Una tassonomia che nessuna app accende resta visibile: il form di un'app nuova non deve nascere
  vuoto». Risultato senza questo requisito: tutto funziona in backoffice e il JSON non ha la chiave,
  senza errori né log.
- **Dove si aggancia:** alle migration del dominio, che sono già il passo obbligatorio per accendere
  il Catasto (passo 2 di `attivare-catasto-sentieri.md`). Nessun passo nuovo nella procedura.
- **Scartato:** un comando da lanciare a mano come ottavo passo; e farlo chiamare dal comando delle
  tassonomie dello shard — su forestas sarebbe gratis, perché l'import lo lancia già
  (`ImportSardegnaSentieriCommand.php:158`), ma uno shard che accende il dominio può non avere un
  comando proprio.
- **Rete di sicurezza:** se la famiglia manca, sollevare un'eccezione che nomina cosa lanciare,
  invece di restituire silenzio. Precedente nel package: `MissingDifficultyFamilyException`.

### 2026-11-16 — Ogni scrittura passa dal service, e l'evento dice da dove viene

- **Decide:** dev
- **Il problema:** l'import scrive il pivot direttamente
  (`SardegnaSentieriImportService.php:1082-1106`) ed è deciso che scriva sempre la percorribilità.
  Senza questo requisito lo stato cambierebbe a ogni giro orario senza lasciare una riga di storia,
  e [R2](overview.md#r2) e [R3](overview.md#r3) sarebbero falsi dal primo giorno — lo stesso difetto
  di osm2cai, ma su un processo che gira ventiquattro volte al giorno invece che su un campo usato
  di rado.
- **Come:** anche l'import passa dal service che scrive stato ed evento. Calcola già la differenza
  fra quello che c'è e quello che vuole (righe 1099-1106): l'evento nasce lì, senza lavoro in più.
- **La provenienza:** `user_id` resta nullable come in `TrailRegistryCodeEvent`, ma per noi gli
  eventi senza persona sono la maggioranza, non l'eccezione. Il renderer dei codici in quel caso
  scrive un trattino (`CodeHistoryRenderer.php:24`): qui una cronologia di trattini non
  distinguerebbe «non si sa chi» da «l'ha scritto l'import». Una colonna dice quindi la
  provenienza — persona, processo automatico con la sua sorgente, allineamento iniziale — e
  l'allineamento del primo giro, che scrive un evento per tutti e 625 i sentieri, non sembra un
  giorno in cui è successo qualcosa.
- **Scartato:** un utente tecnico «import» da mettere in `user_id`. Finirebbe fra gli utenti veri
  del sistema, nei filtri e nelle liste, e un giorno qualcuno proverebbe a dargli un ruolo.
- **La data resta `created_at`:** Drupal non espone una data dello stato — lo stato di validazione
  è una tassonomia sul nodo (`SardegnaSentieriImportService.php:1043`) e l'unica data disponibile è
  il `changed` del nodo, che cambia per qualsiasi modifica alla scheda. Usarla direbbe «la
  percorribilità è cambiata il 3 settembre» quando il 3 settembre è stata cambiata una foto: una
  data precisa ma falsa è peggio di una dichiaratamente approssimativa, perché nessuno la mette in
  dubbio.

### 2026-11-16 — In API l'autore è l'id, non il nome

- **Decide:** dev, dopo la challenge
- **Perché:** il dettaglio della traccia è un JSON pubblico, letto dal sito, dall'app e dal client
  SUS. Il nome di una persona che lavora per l'ente, accanto a un giudizio sul territorio e a una
  nota che ha scritto, una volta uscito non si ritira da dove è stato copiato o indicizzato.
- **Supera** la formulazione precedente di [R5](overview.md#r5), che faceva uscire il nome
  visualizzabile perché «l'id non serve a chi lo consuma».
- **Conseguenza da portare al ticket di `wp-forestas`:** il wireframe di oc:8783 chiede «Inserito
  da …» nella fascia del dettaglio, e con il solo id il sito non ha modo di risolvere il nome —
  non esiste un'API pubblica degli utenti. O quella riga mostra qualcosa di non nominativo, o il
  dato va chiesto in un'altra forma e concordato con Forestas.

### 2026-11-16 — I quattro valori vengono da osm2cai, non sono una scelta di qui

- **Verificato nel codice della fonte**, `osm2cai2/app/Enums/IssuesStatusEnum.php`: i case sono
  `Unknown` («sconosciuto»), `Open` («percorribile»), `Closed` («non percorribile») e
  `PartiallyClosed` («percorribile parzialmente»). La famiglia ne ha gli stessi quattro, con
  corrispondenza uno a uno; gli identifier seguono i nomi dei case, non i valori italiani.
- **Due scostamenti, solo sull'etichetta mostrata:** «Sconosciuta» al femminile, perché in Nova
  sta sotto «Percorribilità»; «Parzialmente percorribile» invece di «percorribile parzialmente».
- **Due dei quattro esistevano già su Drupal** dentro il campo unico `stato_di_validazione`
  (`percorribile`, tid 4187, e `non percorribile`, tid 4870). Gli altri due non hanno un
  corrispondente nella sorgente: l'import non li produce — «Sconosciuta» è lo stato di partenza,
  «Parzialmente percorribile» lo inserisce chi va sul posto.
- **Da correggere se ricompare:** in una risposta avevo detto che quei due valori li avevo
  aggiunti io. Non è così, e la differenza conta: una scala presa dalla fonte si discute con
  Forestas citando osm2cai, una inventata qui no.

## Follow-up

- Se in fase di piano `CodeHistoryRenderer` e `WalkabilityHistoryRenderer` risultassero più vicini
  del previsto, valutare di generalizzarne uno solo — come lavoro a sé, non dentro oc:8989.
- **Assegnare un numero a una traccia dal dettaglio in Nova — per il dopo-Drupal, non ora.** Oggi
  non si può: le tre Action del Catasto (`ApproveTrailApplication`, `RejectTrailApplication`,
  `ReplaceTrailCodeNumber`) stanno tutte sulla Resource `TrailApplication`, e un sentiero riceve un
  codice solo approvando un'istanza o facendoselo riconoscere dall'import, che legge il numero già
  scritto nel nome o nelle proprietà. **Non è un problema finché Drupal è la fonte:** i sentieri a
  cui l'import non riesce a dare un numero finiscono nelle anomalie, e il processo previsto è che
  il cliente sistemi la scheda in Drupal perché l'import funzioni — «il dato lo possiede la
  piattaforma di origine […] l'import successivo si porta via la riga» (docblock di
  `TrailRegistryAnomalyType`). Servirà quando Forestas diventerà la sorgente del dato: a quel punto
  un'Action sulla Resource delle tracce che proponga i numeri liberi del settore
  (`TrailRegistryService` lo sa già fare, oc:8489) e registri l'assegnazione.
- **`writeExistingCodeRow()` non scrive l'evento** (`TrailRegistryService.php:848-858`): crea il
  codice già `Assigned` senza passare da `recordEvent()`, che ha solo tre chiamanti (righe 555, 578,
  600). I codici riconosciuti dall'import non hanno quindi una riga di storia, e nella timeline del
  sentiero mancherebbe proprio l'assegnazione del numero.
- **La validazione con data e autore**, come osm2cai ha per l'accatastamento (`validation_date`,
  `validator_id`): usa la stessa tabella degli eventi di stato, cambia solo la famiglia. Ticket a
  sé, previsto dalla forma scelta qui.

> Ticket: oc:8968
> Stato: approvata il 2026-11-11

# La compilazione di un'istanza: gli endpoint con cui il SUS apre, riempie e trasmette un fascicolo

> Macro area 2 del tag 709 `[CALL][FORESTAS][integrazione API SUS] discussione finale`.
> Seguito diretto di oc:8960, che ha predisposto il modello e ha dichiarato questi endpoint fuori
> dal proprio perimetro.

**SUS** = Sportello Unico dei Servizi, il portale della Regione Sardegna realizzato da Engineering
su cui il cittadino presenta la domanda. **Catasto Sentieri** = il sistema di Forestas, realizzato
da Webmapp, dove i sentieri sono censiti. **FORES_02** = il procedimento di accatastamento di un
nuovo sentiero. **Fascicolo** = l'istanza con i suoi dati e i suoi documenti, cioè il modello
`TrailApplication`. **DEM** = il servizio Webmapp che dal tracciato calcola lunghezza, dislivello e
tempi di percorrenza.

## Cosa cambia

Oggi il Catasto sa descrivere un'istanza di accatastamento ma non sa riceverne una: sotto
`api/v1/sus/` esistono solo `auth/login`, `auth/refresh` e `ping`. Dopo questo lavoro il SUS ha tre
chiamate con cui porta una domanda dentro il Catasto:

1. **Crea l'istanza** — un POST con tutti i dati dichiarati nel form e il file della traccia. Il
   Catasto risponde con l'id dell'istanza e con i dati tecnici calcolati dal tracciato, che il
   cittadino vede accanto a quelli che ha dichiarato. L'istanza nasce nello stato
   `under_compilation`: esiste, ma non è ancora stata trasmessa.
2. **Carica un allegato** — un POST per ciascuno dei sei documenti previsti, perché gli allegati
   non viaggiano nella stessa chiamata che crea l'istanza.
3. **Chiude la compilazione** — un POST che verifica la completezza del fascicolo e porta l'istanza
   in `under_review`. Risponde con il risultato finale.

Il numero di sentiero **non si riserva qui: nasce con l'istanza**, come già prescrive il dominio.
E non esce mai dall'API finché l'istanza non è accettata.

Dentro al Catasto cambia una cosa sola oltre agli endpoint, e non riguarda Nova, che resta
intatta: lo schema dei 27 campi del format, che oggi serve solo a mostrarli nella detail view,
diventa **la fonte unica del canale**. Da lì derivano la validazione in ingresso, l'elenco dei
campi obbligatori e i Field di Nova.

## Perché

Senza questi endpoint l'integrazione non esiste: oc:8960 ha dato al fascicolo le colonne e le
collection per accogliere i dati del SUS, ma nessuna strada per farceli arrivare. È il lavoro che
rende utilizzabile tutto quello che il ticket precedente ha preparato.

Le tre chiamate seguono la forma concordata al tavolo tecnico. Che gli allegati viaggino uno per
chiamata è scritto nel format al campo 66, colonna «Esito validazione tavolo tecnico
Webmapp/Forestas» — «il SUS crea l'istanza, riceve indietro il suo ID e con quello carica un
allegato per chiamata» — ed Engineering ha risposto `Ok` nella propria colonna il 22/10, dopo un
«Da chiarire» il 21/10. Che i quattro dati tecnici siano comunque dichiarati dal cittadino e
trasmessi è deciso in call: Piccioli il 22/10, verificato sulla trascrizione, «i campi 32 33 34 e
35 devono essere tutti inviati, compilati e inviati», con Saba che conferma e Vaglini che
verbalizza in diretta.

Lo stato `under_compilation` serve alla finestra fra la creazione e la trasmissione. In quella
finestra l'istanza esiste ma i documenti non ci sono ancora tutti: senza uno stato che lo dichiari,
l'operatore Forestas aprirebbe un fascicolo a cui mancano metà degli allegati senza sapere se
mancano perché non sono stati mandati o perché non devono esserci.

## Punto di partenza

Lo stato del codice prima di questo lavoro, citato dai requisiti.

- `TrailGeometryReader::wktFrom()` riconosce già GPX e GeoJSON e lancia
  `InvalidTrailGeometryException`.
- Il file originale e la geometria lavorata sono già due cose distinte, nella collection
  `original_geometry` e nella colonna `geometry`.
- Il docblock del modello dichiara il limite di una-cinque fotografie, ma nessun codice lo impone e
  non lo copre nessun'altra macro area del tag: un client che manda venti foto distinte le scrive
  tutte.
- In `routes/sus.php` il limite di frequenza sta solo sul gruppo `auth` (`throttle:100,1`); il
  gruppo autenticato non ne ha.
- Il client HTTP del DEM non ha un timeout esplicito e eredita il default di Laravel.
- L'eccezione del client DEM porta nel messaggio il body della risposta del servizio.
- `booted()` accoda il job del DEM a ogni creazione senza condizioni, e
  `UpdateTrailApplicationDemJob::handle()` chiama il servizio verificando solo che la riga esista.
- I 27 campi del format sono descritti in due posti: `susDataSchema()` per come si mostrano,
  `requiredApiFields()` per quali sono obbligatori. Lo schema è un metodo `protected` della
  Resource Nova.
- `requiredApiFields()` esclude deliberatamente sei campi la cui obbligatorietà dipende da un altro
  campo. Il docblock lo spiega: il 58 dipende dal 56, e «fra gli obbligatori terrebbe
  quell'istanza per sempre lontana dalla conferma».

## Requisiti

### Gli endpoint

- <a id="r1"></a>**R1** — **Creazione** — `POST api/v1/sus/trail-applications`: riceve i dati
  dichiarati e il file della traccia, crea l'istanza in `under_compilation` e risponde con il suo
  id.
- <a id="r2"></a>**R2** — La geometria è **obbligatoria nella chiamata di creazione**, come nel form
  d'ufficio di Nova. Senza, tre cose si rompono: `booted()` accoda il calcolo del DEM a ogni
  `created` senza guardie, `UpdateTrailApplicationDemJob::handle()` chiama il servizio senza
  controllare che la geometria ci sia, e `needsDem()` restituisce `false` quando `geometry` è NULL,
  cioè il calcolo non si riaccoderebbe più da solo.
- <a id="r3"></a>**R3** — Il file della traccia si legge con `TrailGeometryReader::wktFrom()`:
  l'API lo riusa com'è, senza duplicarne la logica.
- <a id="r4"></a>**R4** — **Il file originale e la geometria lavorata restano due cose distinte**:
  il file va così com'è nella collection `original_geometry` e non si tocca più — è la prova di cosa
  il richiedente ha consegnato, e il docblock lo dichiara: «il file caricato, conservato tale e
  quale: la geometria riceve la quota del nostro DEM, e questo resta il riferimento di ciò che è
  stato dichiarato». Nella colonna `geometry` va la geometria letta dal file, che il calcolo
  sostituisce poi con quella tridimensionale restituita dal servizio. Se il DEM non risponde, in
  `geometry` resta la versione senza quote e il ricalcolo successivo la rimpiazza, come già accade
  per un'istanza creata in Nova.
- <a id="r5"></a>**R5** — **I dati si scrivono una volta sola, alla creazione.** Non esiste una
  chiamata che li corregga: se il richiedente cambia qualcosa, il SUS rifà l'istanza. È la regola
  già scritta in oc:8960, qui confermata — vale per i dati, non per gli allegati.
- <a id="r6"></a>**R6** — Il 422 **dice campo per campo quale regola ha fallito**, nella risposta al
  SUS e non solo nel log: Engineering vede dove sbaglia senza doverci telefonare. Negli stessi
  termini finisce nel log del canale — nomi dei campi e regola violata, **mai i valori**, perché il
  payload contiene nomi, e-mail e codici fiscali e il log di questo canale non scrive nemmeno il
  token.
- <a id="r7"></a>**R7** — Accanto al payload si conserva **la versione dello schema che l'ha
  accettato**, dentro `api_data`, con un nome che si riconosca come nostro e non si confonda con un
  campo del format. Serve quando il format evolverà: senza, fra sei mesi non si saprà con quali
  regole è stata accettata una riga vecchia, e il format è già cambiato una volta fra il 21 e il 22
  ottobre.
- <a id="r8"></a>**R8** — Quando la validazione passa, in `api_data` finisce il **payload intero**,
  chiavi sconosciute comprese: salvare i soli campi validati farebbe perdere ogni campo nuovo del
  format fino al giorno in cui qualcuno se ne accorge. Quando non passa, la risposta è un 422 col
  dettaglio e non resta nessuna riga — `writeApiData()` è un `UPDATE … WHERE id = ?` e richiede
  un'istanza che esista. Del contenuto rifiutato non si conserva traccia: il log del canale registra
  endpoint, metodo ed esito, non il body.
- <a id="r9"></a>**R9** — La creazione sta **in transazione**: la riga, il payload, il risultato
  del DEM e il file della traccia sono quattro scritture su strade diverse, e un errore a metà
  lascerebbe un'istanza che per regola non si può correggere e per completezza non si può
  confermare.
- <a id="r10"></a>**R10** — I valori numerici si validano **anche nel range**, non solo nel tipo.
  Le unità che viaggiano nel payload divergono dal format — chilometri invece di metri, minuti
  interi invece di `ORE:MINUTI` — e non sono ancora concordate con Engineering: senza un vincolo di
  plausibilità, una lunghezza mandata in metri passa come `numeric` e l'operatore si trova `12000`
  accanto a `12`.
- <a id="r11"></a>**R11** — **Upload** — `POST api/v1/sus/trail-applications/{id}/attachments`: un
  allegato per chiamata, nella collection media corrispondente fra le sei registrate da oc:8960.
  Accettato solo mentre l'istanza è in `under_compilation`.
- <a id="r12"></a>**R12** — La galleria fotografica usa `addPhoto()`, che riconosce dal contenuto
  una foto rimandata dopo un timeout invece di contarla due volte.
- <a id="r13"></a>**R13** — **Il limite di una-cinque fotografie si applica qui**, dopo la
  deduplicazione.
- <a id="r14"></a>**R14** — **Conferma** — `POST api/v1/sus/trail-applications/{id}/confirm`:
  verifica la completezza con `missingForConfirmation()`, e se manca qualcosa rifiuta **elencando
  cosa**, in forma strutturata — `{fields: [...], attachments: [...]}` — lasciando l'istanza in
  `under_compilation`.
- <a id="r15"></a>**R15** — Se la completezza è verificata, la conferma porta l'istanza in
  `under_review` e **risponde con il risultato finale completo**, dati tecnici compresi.
- <a id="r16"></a>**R16** — **La conferma è idempotente**: una seconda chiamata su un'istanza già
  `under_review` risponde con il risultato finale invece che con un errore. Senza, un timeout di
  rete dopo la scrittura lascia il cittadino convinto di non aver trasmesso mentre l'operatore vede
  il fascicolo in istruttoria.
- <a id="r17"></a>**R17** — **Gli allegati di un'istanza non escono dalla rotta pubblica dei
  media.** `GET api/media/{id}` non chiede autenticazione e gli id sono progressivi: una volta
  caricati, relazione, autorizzazioni, piano di manutenzione e fotografie sarebbero raggiungibili da
  chiunque. `MediaController::show` li nega guardando il **modello a cui il media appartiene**, non
  l'elenco delle collection: un elenco sarebbe una blacklist, e la settima collection che qualcuno
  aggiungerà sarebbe pubblica per difetto — lo stesso difetto che oc:8960 ha già annotato su di sé
  per le collection non copiate all'approvazione.
- <a id="r18"></a>**R18** — Ogni endpoint che prende un `{id}` verifica che quell'istanza sia **del
  canale API** e nello stato giusto, non solo che esista. Le istanze dal SUS nascono tutte sotto lo
  stesso utente programmatico e gli id sono progressivi: senza quel controllo un errore di indice a
  monte caricherebbe il documento di un richiedente sul fascicolo di un altro.
- <a id="r19"></a>**R19** — Le tre rotte portano un **throttle**: senza, gli endpoint più cari che
  avremmo — upload su S3 e chiamata sincrona al servizio DEM — nascerebbero senza limite di
  frequenza.
- <a id="r20"></a>**R20** — Tutte e tre le chiamate rispondono con `{error: string}` sugli errori,
  come gli endpoint SUS già esistenti, e portano le annotazioni Scramble (`#[Response]` per ogni
  status, `#[Group]` sulla classe) perché compaiano su `/docs/api/sus`, che pubblica le sole rotte
  con URI sotto `api/v1/sus/`.

### Il calcolo dei dati tecnici

- <a id="r21"></a>**R21** — **Il calcolo è sincrono rispetto alla chiamata di creazione**: la
  risposta contiene lunghezza, dislivello e i due tempi di percorrenza, calcolati con
  `EcTrackService::fetchDemTechData()`, che chiama il servizio e restituisce senza scrivere.
- <a id="r22"></a>**R22** — **Se il servizio DEM non risponde, la chiamata non fallisce**:
  l'istanza viene creata lo stesso e la risposta dichiara che il calcolo è in elaborazione. Il
  richiedente prosegue con i propri valori.
- <a id="r23"></a>**R23** — Lo stesso vale alla conferma: se il dato non c'è nemmeno lì, la
  risposta lo dichiara in elaborazione invece di tacere.
- <a id="r24"></a>**R24** — **«In elaborazione» si dichiara solo quando i valori mancano del
  tutto.** Se il servizio ne ha restituiti alcuni e altri no, si restituisce ciò che è arrivato
  senza promettere un completamento: `needsDem()` considera il dato presente appena `dem_data` non è
  vuoto, quindi su un risultato parziale nessun ricalcolo ripartirebbe. Non si tocca `needsDem()`,
  che vale anche per `EcTrack` e `UgcTrack` di ogni shard.
- <a id="r25"></a>**R25** — **La chiamata al servizio DEM porta un timeout esplicito di 3-5
  secondi, senza retry.** Con il default di Laravel un servizio muto terrebbe occupato un worker
  per mezzo minuto a ogni creazione, e un client che ritenta esaurirebbe i worker dello shard —
  cadrebbe tutto, Nova compresa, non il solo canale.
- <a id="r26"></a>**R26** — L'errore del servizio DEM **non raggiunge il chiamante**: il body della
  risposta del servizio, che l'eccezione porta nel messaggio, non deve finire in una risposta
  dell'API.
- <a id="r27"></a>**R27** — **Il calcolo non si fa due volte, e il controllo sta nel job.** Senza,
  dopo un calcolo sincrono il job rifarebbe la chiamata e sovrascriverebbe numeri già consegnati al
  SUS. Il job verifica quindi `needsDem()` prima di chiamare il servizio — il controllo esiste già e
  guarda esattamente dove il calcolo scrive, `properties['dem_data']`, e il job ricarica l'istanza
  dal database, quindi vede ciò che il percorso sincrono ha appena scritto. Vale per qualunque
  strada crei un'istanza, non solo per la nostra: nessuna variabile da impostare e rimettere a
  posto, che dimenticata acciecherebbe il calcolo per ogni istanza successiva nello stesso processo.
- <a id="r28"></a>**R28** — **Se il calcolo sincrono fallisce, il percorso asincrono deve restare
  percorribile.** `booted()` prende il lock del DEM per un'ora prima ancora di accodare, e
  `dispatchDemIfMissing()` riaccoda solo se riesce a prendere quel lock: un'istanza il cui calcolo
  sincrono è andato storto non deve restare un'ora senza nessuno che riprovi.
- <a id="r29"></a>**R29** — La scrittura del risultato segue la strada che il job usa già:
  `properties.dem_data` via `jsonb_set`, e la colonna `geometry` riscritta con la geometria 3D
  restituita dal servizio. Quella riscrittura non è un effetto collaterale da evitare: è la strada
  per cui il dato entra già.
- <a id="r30"></a>**R30** — **I valori dichiarati non finiscono in `manual_data`.** Restano fra i
  dati del fascicolo. `classifyField()` restituisce `MANUAL` appena `manual_data` non è vuoto,
  quindi copiarli lì li farebbe vincere sul calcolato e finire pubblicati sul sentiero e nelle tile
  senza che nessuno li abbia approvati. L'operatore li legge nel fascicolo e, se li ritiene buoni,
  li scrive lui nel tab DEM: allora sono una decisione. Conseguenza da dichiarare nella
  documentazione: la colonna «manuale» della tabellina resta vuota finché l'operatore non
  interviene.

### Lo schema dei campi, fonte unica

- <a id="r31"></a>**R31** — **I 27 campi del format sono descritti in un posto solo.** Ai due posti
  di partenza, scrivere le regole a mano nella Form Request ne aggiungerebbe un terzo. Tre copie
  della stessa tabella divergono alla prima revisione del foglio di Engineering, e il
  disallineamento non produce nessun errore: un campo semplicemente smette di essere validato.
- <a id="r32"></a>**R32** — Lo schema **esce dalla Resource Nova**. Finché sta lì, una Form Request
  che lo legge fa dipendere l'API dallo strato di presentazione.
- <a id="r33"></a>**R33** — **Ogni campo è un oggetto tipizzato**, non un array associativo, con un
  `toArray()` che dà a Nova la forma che `PropertiesPanel::jsonForm()` si aspetta già. Un array
  accetta in silenzio qualunque chiave nuova; una classe obbliga a dichiararla, e quella
  dichiarazione si vede in review. È la difesa del confine di [R40](#r40): una forma che non
  accetta, invece di un test che vieta.
- <a id="r34"></a>**R34** — Ogni campo acquisisce **l'obbligatorietà e una chiave `rules` con regole
  Laravel native** — `required_if:soggetto_gestore,third_party` compresa, per le tre dipendenze fra
  campi (47→48, 49→50 e 51, 53→54 e 55). Nessuna sintassi inventata da noi: chi legge lo schema
  legge regole di Laravel.
- <a id="r35"></a>**R35** — I casi che una stringa di regola non esprime diventano **Rule class
  referenziate dallo schema**: la difficoltà si valida come coppia attività → livello interrogando
  le famiglie di `taxonomy_terms`, perché `{"mtb": "EEA"}` è un errore anche se `EEA` esiste,
  appartenendo a un'altra scala.
- <a id="r36"></a>**R36** — Lo stesso campo può dire **due cose diverse in ingresso e in uscita**:
  `last_survey_date` si valida come data non futura ma si mostra come testo, perché — dice il
  docblock attuale — «un formato di data inatteso non deve impedire di leggere cosa è arrivato».
- <a id="r37"></a>**R37** — Il tab «SUS data» di Nova continua a funzionare esattamente come prima:
  diventa un consumatore dello schema, non più il suo proprietario.
- <a id="r38"></a>**R38** — **Lo schema distingue l'obbligatorio dal condizionale**, e
  `requiredApiFields()` deriva i soli campi obbligatori sempre. Una derivazione meccanica da una
  colonna «obbligatorio» reintrodurrebbe i sei campi condizionali che il metodo esclude, e nessuna
  istanza senza soggetto gestore terzo potrebbe più essere confermata.
- <a id="r39"></a>**R39** — **Lo schema è l'oggetto che lo shard ridefinisce.** Il package lo tiene
  come default e lo shard può sovrascriverlo.
- <a id="r40"></a>**R40** — **Lo schema descrive i campi, non il comportamento degli endpoint.** Non
  entra niente che parli di HTTP, di stati dell'istanza o di forma delle risposte: è il vincolo che
  impedisce allo schema di diventare il posto dove si scarica tutto.

### La transizione verso l'istruttoria

- <a id="r41"></a>**R41** — **Il numero si riserva alla creazione, da entrambe le origini**, perché
  è così che il dominio lo definisce: l'enum dichiara che in `under_compilation` «l'istanza esiste e
  il suo numero è già riservato», il docblock del modello che «ogni riga qui porta già il proprio
  codice riservato», e la Resource Nova che «la riserva del numero fa parte della creazione, non è
  un passo successivo». Conseguenza pratica: un tracciato fuori da ogni settore fa fallire la
  creazione, non la conferma — il richiedente lo scopre prima di caricare un solo documento, invece
  che dopo sei.
- <a id="r42"></a>**R42** — **Il numero non esce mai dall'API finché l'istanza non è accettata.**
  Esiste dalla creazione, ma non compare né nella risposta alla creazione né in quella della
  conferma. È un vincolo da scrivere, perché è il genere di campo che qualcuno aggiunge a una
  risposta per comodità.
- <a id="r43"></a>**R43** — La transizione dalla compilazione all'istruttoria — verifica la
  completezza, sposta lo stato — diventa **un metodo di `TrailRegistryService`**. Il chiamante è
  uno solo, l'endpoint di conferma, ma la regola descrive quando un'istanza smette di essere
  compilabile: è dominio, e il dominio sta nel package, non nel controller.
- <a id="r44"></a>**R44** — **Il comportamento di Nova non cambia, e il suo codice nemmeno.**
  L'istanza creata d'ufficio continua a nascere in `under_review` con il numero riservato, in un
  gesto solo. I dati del form del SUS esistono solo se l'istanza arriva da lì, quindi un'istanza
  d'ufficio non ne ha e la regola di completezza non la riguarda.
- <a id="r45"></a>**R45** — Il metodo gestisce `SectorNotFoundException` e
  `SectorExhaustedException` in modo che ciascun chiamante le traduca nella propria forma:
  `ValidationException` per Nova, risposta JSON con `{error: string}` per l'API.

### Il confine fra package e shard

- <a id="r46"></a>**R46** — Nel **`wm-package` sta il dominio, compresi i controller che lo
  espongono**: creare un fascicolo, aggiungergli un allegato, chiuderlo quando è completo non ha
  nulla di specifico di questo ente. Ci stanno i tre controller, lo schema dei campi, le Form
  Request che ne derivano, il metodo di service, il calcolo sincrono e il riuso di
  `TrailGeometryReader` fuori da Nova.
- <a id="r47"></a>**R47** — **Nel package il nome dello sportello non compare mai**, come già nella
  migration di oc:8960: «mai il nome dello sportello: questo codice servirà anche Lombardia e
  Toscana». I controller si chiamano per quello che fanno al fascicolo, non per chi li chiama.
- <a id="r48"></a>**R48** — Nello **shard `forestas` sta il canale dell'ente**: `routes/sus.php` con
  il prefisso `api/v1/sus`, i middleware `RestrictSusClient` e `LogSusRequest`, il client SUS, la
  pagina `/docs/api/sus`. È lì che il canale prende il nome del cliente.
- <a id="r49"></a>**R49** — Dove allo shard serve personalizzare, **estende**: i controller del
  package come già si fa con le Resource Nova, e lo schema dei campi il giorno in cui il format di
  Engineering divergesse. Finché non serve, forestas non scrive nemmeno la sottoclasse.
- <a id="r50"></a>**R50** — I 27 campi del format sono di questo cliente dentro codice condiviso, e
  la risposta è lo schema ridefinibile descritto in [R39](#r39): il package tiene il default, lo
  shard sovrascrive. Il giorno in cui un altro ente monterà il package non ci sarà niente da
  smontare.
- <a id="r51"></a>**R51** — Nella documentazione del package va dichiarato l'approccio: il package
  offre le operazioni del fascicolo — apri, aggiungi un allegato, chiudi — e lo shard ci monta sopra
  il canale del proprio ente. È un fatto che vale per chiunque monti il package, quindi si scrive lì.

### Documentazione e test

- <a id="r52"></a>**R52** — Ogni parametro documentato porta il **numero di campo del format**,
  nella forma «campo 29 del format — *Origine (da)*»; il link al foglio sta una volta sola
  nell'intestazione della pagina.
- <a id="r53"></a>**R53** — La documentazione dichiara le **unità che viaggiano nel payload** —
  `distance` in chilometri con un decimale, `duration_forward` e `duration_backward` in minuti
  interi — perché il format chiede metri e `ORE:MINUTI`. È una divergenza da segnalare a
  Engineering.
- <a id="r54"></a>**R54** — La documentazione dichiara in modo esplicito che **questa chiamata non
  si corregge, si rifà**: è il tipo di vincolo che chi integra scopre tardi.
- <a id="r55"></a>**R55** — I test seguono il pattern già in uso nel canale: sotto
  `tests/Feature/Sus/` esistono `SusAuthTest`, `SusPingTest`, `SusClientRestrictionTest`,
  `SusRequestLogTest`.
- <a id="r56"></a>**R56** — Il servizio DEM va fakato nei test: nessun test chiama il servizio vero.

## Rischi

Verificati sul codice dopo la challenge.

- **Su UAT il canale non è collaudabile finché esiste il reset notturno.** `--reset` tronca
  `trail_applications`, e la regola del repo lo dice esplicitamente: «finché il reset notturno
  esiste, un'istanza presentata su collaudo non sopravvive alla notte, e i codici `reserved` che
  aveva prenotato non tornano disponibili». Questo canale è fatto di chiamate distanti nel tempo:
  Engineering crea un'istanza il pomeriggio e la mattina dopo riceve 404 su un id che gli abbiamo
  dato noi, leggendolo come un bug della nostra API. Non è codice di questo ticket: è una
  condizione da soddisfare prima di consegnare l'ambiente, e va in mano a chi rilascia.
- **`needsDem()` non riconosce un calcolo parziale.** Considera il dato presente appena `dem_data`
  non è vuoto, con la sola eccezione delle quote tutte a zero. Se il servizio risponde con la
  lunghezza valorizzata ma le durate nulle, nessun ricalcolo riparte mai: in quel caso dichiarare
  il dato «in elaborazione» sarebbe falso, perché non arriverà.
- **Del payload rifiutato restano gli esiti, non il contenuto.** È una scelta: nel log finiscono i
  campi che hanno fallito e la regola violata, mai i valori, perché il payload porta nomi, e-mail e
  codici fiscali. Se un giorno il disaccordo con Engineering fosse proprio su *quale valore* è
  stato mandato, i nostri dati non lo chiuderanno.
- **Il contratto si congela al primo collaudo con Engineering, non al merge.** Da quel momento
  cambiare la forma di una risposta richiede un ciclo di coordinamento con un fornitore esterno,
  con tempi che non controlliamo. Conseguenza operativa: la divergenza sulle unità — chilometri e
  minuti interi contro i metri e gli `ORE:MINUTI` del format — va segnalata a Engineering **prima**
  di quel collaudo, non dopo.
- **Il calcolo sincrono mette un servizio esterno dentro il ciclo di richiesta.** È il rischio di
  disponibilità più grosso della feature, e il suo caso peggiore esce dal perimetro: un DEM muto
  con un client che ritenta esaurisce i worker e porta giù anche Nova. Mitigato dai requisiti —
  timeout basso, nessun retry, nessun fallimento della chiamata — ma resta da misurare quanto il
  calcolo allunghi davvero la creazione.
- **Il doppio calcolo che sovrascrive un numero già consegnato.** Se la guardia sul dispatch è
  imperfetta, il job riscrive `dem_data` e la geometria dopo che il SUS ha mostrato i valori al
  cittadino, e nessun log tiene traccia dei due numeri: la discrepanza emerge come «il portale
  diceva 12,4 km e il fascicolo dice 12,1», e si spiega, non si ricostruisce.
- **Lo schema unico attira responsabilità che non sono sue.** Un oggetto che descrive i campi e
  genera la validazione è il candidato naturale per ospitare, col tempo, anche la documentazione,
  le risposte e la logica degli stati — e a quel punto non è più leggibile da nessuno. Mitigato dal
  vincolo scritto fra i requisiti: nello schema non entra niente che parli di HTTP.
- **Gli allegati di un'istanza mai trasmessa restano su S3.** `MEDIA_DISK` vale `wmfe` di default;
  in locale non è impostato e Media Library ricade sul disco `public`. Un'istanza abbandonata in
  compilazione non si può né respingere né cancellare, quindi i suoi file restano. **Se ne andranno
  con l'istanza il giorno in cui si implementerà la cancellazione**: è il lavoro che dovrà
  occuparsene, ed è il genere di cosa che in una `delete()` si dimentica. Su UAT il reset notturno
  porta via le righe ma non i file, che restano nel bucket scollegati da qualunque record.
- **`GET api/media/{id}` consegna l'URL di qualunque media senza autenticazione**, con id
  progressivi. È un endpoint preesistente del package e non lo tocca questo ticket, ma è questo
  ticket che porta i documenti del cittadino dentro quel perimetro per via dell'API. La domanda è
  già registrata nel tag 709 verso Forestas.

## Out of scope

- **La prevalidazione del tracciato** come esito strutturato e vincolante alla conferma: è la macro
  area 3 del tag 709. Qui la traccia viene letta e, se illeggibile, rifiutata; non viene prodotto
  un esito di validità.
- **I vincoli di formato e dimensione degli allegati** — solo PDF, solo ZIP, immagini, 5 MB per
  file: sono le regole di validazione dell'endpoint di upload, macro area 6.
- **L'endpoint di stato** con cui il SUS rilegge un'istanza già trasmessa e ne ricava stato, codice
  riservato e codice regionale definitivo: macro area 7. Il numero non viene restituito dalla
  conferma.
- **La prosa esaustiva della pagina `/docs/api/sus`**: macro area 8. Qui si documenta ciò che si
  scrive.
- **Il form d'ufficio di Nova allineato al set di campi del SUS**: resta al tag. Oggi quei dati
  esistono solo se l'istanza arriva dal SUS.
- **Come si chiude un'istanza rimasta in compilazione e mai trasmessa**: registrato nel tag 709
  come domanda aperta verso Forestas.

## Moduli toccati

### `wm-package` (submodule montato da forestas)

- I **tre controller** — creazione, upload, conferma — accanto agli altri controller API del
  package, senza il nome dello sportello nel nome della classe.
- Le **Form Request** derivate dallo schema, e le **Rule class** dei casi che una stringa di regola
  non esprime.
- La **classe nuova dello schema** dei campi del canale, fuori da Nova e ridefinibile dallo shard.
- `src/TrailRegistry/TrailRegistryService.php` — il metodo della transizione verso l'istruttoria.
- `src/TrailRegistry/Models/TrailApplication.php` — `requiredApiFields()` derivato dallo schema; la
  guardia sul doppio calcolo e il lock in `booted()`.
- `src/TrailRegistry/Nova/TrailApplication.php` — `susDataSchema()` non tiene più lo schema: lo
  legge. Nient'altro cambia qui.
- `src/Services/Models/EcTrackService.php` e il client del DEM — il timeout esplicito e la
  traduzione dell'errore.
- `docs/knowledge/` — la pagina che dichiara il confine fra le operazioni del fascicolo e il canale
  dell'ente, più la riga nell'indice del `CLAUDE.md`.
- `tests/Unit/TrailRegistry/` e `tests/Feature/TrailRegistry/` — lo schema, il metodo di service, la
  guardia sul doppio calcolo e i tre endpoint.

### `forestas` (shard)

- `routes/sus.php` — le tre rotte nuove, con prefisso, middleware e throttle, che puntano ai
  controller del package.
- `tests/Feature/Sus/` — i test dei tre endpoint, accanto ai sei già presenti.

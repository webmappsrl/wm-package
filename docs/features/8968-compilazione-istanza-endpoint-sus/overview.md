> Ticket: oc:8968
> Stato: chiusa il 2026-11-12 · approvata il 2026-11-11

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

Prima di questo lavoro il Catasto sapeva descrivere un'istanza di accatastamento ma non riceverne
una: sotto `api/v1/sus/` esistevano solo `auth/login`, `auth/refresh` e `ping`. Dopo questo lavoro il SUS ha quattro
chiamate con cui porta una domanda dentro il Catasto e la rilegge:

1. **Crea l'istanza** — un POST con tutti i dati dichiarati nel form e il file della traccia. Il
   Catasto risponde con l'id dell'istanza e con i dati tecnici calcolati dal tracciato, che il
   cittadino vede accanto a quelli che ha dichiarato. L'istanza nasce nello stato
   `under_compilation`: esiste, ma non è ancora stata trasmessa.
2. **Carica un allegato** — un POST per ciascuno dei sei documenti previsti, perché gli allegati
   non viaggiano nella stessa chiamata che crea l'istanza.
3. **Chiude la compilazione** — un POST che verifica la completezza del fascicolo e porta l'istanza
   in `under_review`. Risponde con l'esito: lo stato e se la trasmissione è avvenuta adesso.
4. **Rilegge l'istanza** — un GET che restituisce lo stato, i dati tecnici e lo stato del loro
   calcolo; il codice del sentiero solo a domanda approvata, il motivo solo a domanda rifiutata.

Il numero di sentiero **non si riserva qui: nasce con l'istanza**, come già prescrive il dominio.
E non esce mai dall'API finché l'istanza non è accettata.

Dentro al Catasto cambia una cosa sola oltre agli endpoint: lo schema dei 27 campi del format, che prima serviva solo a mostrarli nella detail view,
diventa **la fonte unica del canale**. Da lì derivano la validazione in ingresso, l'elenco dei
campi obbligatori e i Field di Nova. Nova si comporta come prima; la detail view guadagna solo i
tab «Dati API» e «Allegati», in sola lettura ([R37](#r37), [R57](#r57)).

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
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `crea l istanza in compilazione e risponde con id e dati tecnici`
- <a id="r2"></a>**R2** — La geometria è **obbligatoria nella chiamata di creazione**, come nel form
  d'ufficio di Nova. Senza, tre cose si rompono: `booted()` accoda il calcolo del DEM a ogni
  `created` senza guardie, `UpdateTrailApplicationDemJob::handle()` chiama il servizio senza
  controllare che la geometria ci sia, e `needsDem()` restituisce `false` quando `geometry` è NULL,
  cioè il calcolo non si riaccoderebbe più da solo.
  - Verifica: [StoreApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationRequestTest.php) › `pretende nome e file della geometria`
- <a id="r3"></a>**R3** — Il file della traccia si legge con `TrailGeometryReader::wktFrom()`:
  l'API lo riusa com'è, senza duplicarne la logica.
  - Verifica: [StoreApplicationController.php](../../../src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php) › `$this->geometryReader->wktFrom(`
- <a id="r4"></a>**R4** — **Il file originale e la geometria lavorata restano due cose distinte**:
  il file va così com'è nella collection `original_geometry` e non si tocca più — è la prova di cosa
  il richiedente ha consegnato, e il docblock lo dichiara: «il file caricato, conservato tale e
  quale: la geometria riceve la quota del nostro DEM, e questo resta il riferimento di ciò che è
  stato dichiarato». Nella colonna `geometry` va la geometria letta dal file, che il calcolo
  sostituisce poi con quella tridimensionale restituita dal servizio. Se il DEM non risponde, in
  `geometry` resta la versione senza quote e il ricalcolo successivo la rimpiazza, come già accade
  per un'istanza creata in Nova.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `dichiara il calcolo in elaborazione quando il servizio non risponde, senza fallire`
- <a id="r5"></a>**R5** — **I dati si scrivono una volta sola, alla creazione.** Non esiste una
  chiamata che li corregga: se il richiedente cambia qualcosa, il SUS rifà l'istanza. È la regola
  già scritta in oc:8960, qui confermata — vale per i dati, non per gli allegati.
  - Verifica: a mano — agente, 2026-11-12: `php artisan route:list --path=api/v1/sus` in forestas
    elenca solo GET e POST; nessuna rotta PUT, PATCH o DELETE sulle domande
- <a id="r6"></a>**R6** — Il 422 **dice campo per campo quale regola ha fallito**, nella risposta al
  SUS e non solo nel log: Engineering vede dove sbaglia senza doverci telefonare. Negli stessi
  termini finisce nel log del canale — nomi dei campi e regola violata, **mai i valori**, perché il
  payload contiene nomi, e-mail e codici fiscali e il log di questo canale non scrive nemmeno il
  token.
  - Verifica: [StoreApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationRequestTest.php) › `dice campo per campo quale regola ha fallito`
- <a id="r7"></a>**R7** — Accanto al payload si conserva **la versione dello schema che l'ha
  accettato**, dentro `api_data`, con un nome che si riconosca come nostro e non si confonda con un
  campo del format. Serve quando il format evolverà: senza, fra sei mesi non si saprà con quali
  regole è stata accettata una riga vecchia, e il format è già cambiato una volta fra il 21 e il 22
  ottobre.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `la versione dello schema la stabilisce chi accetta, non chi consegna`
- <a id="r8"></a>**R8** — Quando la validazione passa, in `api_data` finisce il **payload intero**,
  chiavi sconosciute comprese: salvare i soli campi validati farebbe perdere ogni campo nuovo del
  format fino al giorno in cui qualcuno se ne accorge. Quando non passa, la risposta è un 422 col
  dettaglio e non resta nessuna riga — `writeApiData()` è un `UPDATE … WHERE id = ?` e richiede
  un'istanza che esista. Del contenuto rifiutato non si conserva traccia: il log del canale registra
  endpoint, metodo ed esito, non il body.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `conserva il payload intero piu la versione dello schema`
- <a id="r9"></a>**R9** — La creazione sta **in transazione**: la riga, il payload, il risultato
  del DEM e il file della traccia sono quattro scritture su strade diverse, e un errore a metà
  lascerebbe un'istanza che per regola non si può correggere e per completezza non si può
  confermare.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `non lascia righe se un passo della creazione fallisce a meta`
- <a id="r10"></a>**R10** — I valori numerici si validano **anche nel range**, non solo nel tipo.
  Le unità che viaggiano nel payload divergono dal format — chilometri invece di metri, minuti
  interi invece di `ORE:MINUTI` — e non sono ancora concordate con Engineering: senza un vincolo di
  plausibilità, una lunghezza mandata in metri passa come `numeric` e l'operatore si trova `12000`
  accanto a `12`.
  - Verifica: [StoreApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationRequestTest.php) › `rifiuta una lunghezza implausibile, non solo un valore non numerico`
- <a id="r11"></a>**R11** — **Upload** — `POST api/v1/sus/trail-applications/{id}/attachments`: un
  allegato per chiamata, nella collection media corrispondente fra le sei registrate da oc:8960,
  esclusa `original_geometry`: la traccia viaggia solo nella chiamata di creazione ([R2](#r2)).
  Accettato solo mentre l'istanza è in `under_compilation`.
  - Verifica: [StoreAttachmentTest.php](../../../tests/Feature/TrailRegistry/StoreAttachmentTest.php) › `rifiuta la traccia del sentiero come collection`
- <a id="r12"></a>**R12** — La galleria fotografica usa `addPhoto()`, che riconosce dal contenuto
  una foto rimandata dopo un timeout invece di contarla due volte.
  - Verifica: [StoreAttachmentTest.php](../../../tests/Feature/TrailRegistry/StoreAttachmentTest.php) › `non conta due volte la stessa fotografia rimandata dopo un timeout`
- <a id="r13"></a>**R13** — **Il limite di una-cinque fotografie si applica qui**, dopo la
  deduplicazione.
  - Verifica: [StoreAttachmentTest.php](../../../tests/Feature/TrailRegistry/StoreAttachmentTest.php) › `lascia passare il rinvio della quinta fotografia quando il limite e raggiunto`
- <a id="r14"></a>**R14** — **Conferma** — `POST api/v1/sus/trail-applications/{id}/confirm`:
  verifica la completezza con `missingForConfirmation()`, e se manca qualcosa rifiuta **elencando
  cosa**, in forma strutturata — `{fields: [...], attachments: [...]}` — lasciando l'istanza in
  `under_compilation`.
  - Verifica: [ConfirmApplicationEndpointTest.php](../../../tests/Feature/TrailRegistry/ConfirmApplicationEndpointTest.php) › `elenca cosa manca e lascia il fascicolo in compilazione`
- <a id="r15"></a>**R15** — Se la completezza è verificata, la conferma porta l'istanza in
  `under_review` e risponde con l'esito: `id`, `status` e `already_confirmed`, che dice se la
  transizione è avvenuta in quella chiamata. I dati tecnici si leggono con la lettura ([R58](#r58)).
  - Verifica: [ConfirmApplicationEndpointTest.php](../../../tests/Feature/TrailRegistry/ConfirmApplicationEndpointTest.php) › `trasmette un fascicolo completo e risponde solo com e andata`
- <a id="r16"></a>**R16** — **La conferma è idempotente**: una seconda chiamata su un'istanza già
  `under_review` risponde con l'esito, con `already_confirmed` vero, invece che con un errore.
  Senza, un timeout di rete dopo la scrittura lascia il cittadino convinto di non aver trasmesso
  mentre l'operatore vede il fascicolo in istruttoria.
  - Verifica: [ConfirmApplicationEndpointTest.php](../../../tests/Feature/TrailRegistry/ConfirmApplicationEndpointTest.php) › `alla seconda conferma risponde 200 e dice che era gia confermata`
- <a id="r17"></a>**R17** — **Gli allegati di un'istanza non escono dalla rotta pubblica dei
  media.** `GET api/media/{id}` non chiede autenticazione e gli id sono progressivi: una volta
  caricati, relazione, autorizzazioni, piano di manutenzione e fotografie sarebbero raggiungibili da
  chiunque. `MediaController::show` li nega guardando il **modello a cui il media appartiene**, non
  l'elenco delle collection: un elenco sarebbe una blacklist, e la settima collection che qualcuno
  aggiungerà sarebbe pubblica per difetto — lo stesso difetto che oc:8960 ha già annotato su di sé
  per le collection non copiate all'approvazione.
  - Verifica: [ApplicationMediaPrivacyTest.php](../../../tests/Feature/TrailRegistry/ApplicationMediaPrivacyTest.php) › `non consegna nessuna collection dell istanza, galleria compresa`
- <a id="r18"></a>**R18** — Ogni endpoint che prende un `{id}` verifica che quell'istanza sia **del
  canale API** e nello stato giusto, non solo che esista. Le istanze dal SUS nascono tutte sotto lo
  stesso utente programmatico e gli id sono progressivi: senza quel controllo un errore di indice a
  monte caricherebbe il documento di un richiedente sul fascicolo di un altro.
  - Verifica: [StoreAttachmentTest.php](../../../tests/Feature/TrailRegistry/StoreAttachmentTest.php) › `risponde come a una domanda inesistente per un istanza nata d ufficio`
- <a id="r19"></a>**R19** — Le quattro rotte portano un **throttle**: senza, gli endpoint più cari che avremmo — upload su S3
  e chiamata sincrona al servizio DEM — nascerebbero senza limite di frequenza.
  - Verifica: forestas tests/Feature/Sus/SusApplicationRoutesTest.php › `limita la frequenza delle chiamate su tutte e quattro le rotte`
- <a id="r20"></a>**R20** — Tutte e tre le chiamate rispondono con `{error: string}` sugli errori,
  come gli endpoint SUS già esistenti, e portano le annotazioni Scramble (`#[Response]` per ogni
  status, `#[Group]` sulla classe) perché compaiano su `/docs/api/sus`, che pubblica le sole rotte
  con URI sotto `api/v1/sus/`.
  - Verifica: [StoreApplicationController.php](../../../src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php) › `#[Group(`
- <a id="r58"></a>**R58** — **Lettura** — `GET api/v1/sus/trail-applications/{id}`: restituisce
  `id`, `status`, i dati tecnici (`dem_data`) e `dem_status` (`computed` o `processing`, con la
  regola di [R24](#r24)), il codice del sentiero solo quando la domanda è approvata e il motivo del
  rifiuto solo quando è respinta. Vale solo per le domande del canale API, come gli altri endpoint
  con `{id}` ([R18](#r18)).
  - Verifica: [ShowApplicationEndpointTest.php](../../../tests/Feature/TrailRegistry/ShowApplicationEndpointTest.php) › `legge una domanda in compilazione senza numero e senza motivo`
- <a id="r59"></a>**R59** — Il file della traccia, nella chiamata di creazione, non supera i 5 MB,
  il limite che il format fissa per ogni file del fascicolo: oltre, la risposta è un `422` sul campo
  `geometry`, e la documentazione del parametro lo dichiara.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `rifiuta una traccia oltre i 5 MB del format, prima di leggerla`

### Il calcolo dei dati tecnici

- <a id="r21"></a>**R21** — **Il calcolo è sincrono rispetto alla chiamata di creazione**: la
  risposta contiene lunghezza, dislivello e i due tempi di percorrenza, calcolati con
  `EcTrackService::fetchDemTechData()`, che chiama il servizio e restituisce senza scrivere.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `crea l istanza in compilazione e risponde con id e dati tecnici`
- <a id="r22"></a>**R22** — **Se il servizio DEM non risponde, la chiamata non fallisce**:
  l'istanza viene creata lo stesso e la risposta dichiara che il calcolo è in elaborazione. Il
  richiedente prosegue con i propri valori.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `dichiara il calcolo in elaborazione quando il servizio non risponde, senza fallire`
- <a id="r23"></a>**R23** — Lo stesso vale alla lettura della domanda: se il dato non c'è, la
  risposta lo dichiara in elaborazione invece di tacere.
  - Verifica: [ShowApplicationEndpointTest.php](../../../tests/Feature/TrailRegistry/ShowApplicationEndpointTest.php) › `dichiara il calcolo in elaborazione se i dati tecnici mancano e la geometria e valida`
- <a id="r24"></a>**R24** — **«In elaborazione» si dichiara solo quando i valori mancano del
  tutto.** Se il servizio ne ha restituiti alcuni e altri no, si restituisce ciò che è arrivato
  senza promettere un completamento: `needsDem()` considera il dato presente appena `dem_data` non è
  vuoto, quindi su un risultato parziale nessun ricalcolo ripartirebbe. Non si tocca `needsDem()`,
  che vale anche per `EcTrack` e `UgcTrack` di ogni shard.
  - Verifica: [ShowApplicationEndpointTest.php](../../../tests/Feature/TrailRegistry/ShowApplicationEndpointTest.php) › `su un risultato parziale restituisce cio che c e senza promettere il completamento`
- <a id="r25"></a>**R25** — **La chiamata al servizio DEM porta un timeout esplicito di 3-5
  secondi, senza retry.** Con il default di Laravel un servizio muto terrebbe occupato un worker
  per mezzo minuto a ogni creazione, e un client che ritenta esaurirebbe i worker dello shard —
  cadrebbe tutto, Nova compresa, non il solo canale.
  - Verifica: [ApplicationDemServiceTest.php](../../../tests/Feature/TrailRegistry/ApplicationDemServiceTest.php) › `il client applica il timeout senza retry: 5 secondi di default, o quello passato`
- <a id="r26"></a>**R26** — L'errore del servizio DEM **non raggiunge il chiamante**: il body della
  risposta del servizio, che l'eccezione porta nel messaggio, non deve finire in una risposta
  dell'API.
  - Verifica: [ApplicationDemServiceTest.php](../../../tests/Feature/TrailRegistry/ApplicationDemServiceTest.php) › `non lascia uscire il corpo della risposta del servizio, lo logga`
- <a id="r27"></a>**R27** — **Il calcolo non si fa due volte, e il controllo sta nel job.** Senza,
  dopo un calcolo sincrono il job rifarebbe la chiamata e sovrascriverebbe numeri già consegnati al
  SUS. Il job verifica quindi `needsDem()` prima di chiamare il servizio — il controllo esiste già e
  guarda esattamente dove il calcolo scrive, `properties['dem_data']`, e il job ricarica l'istanza
  dal database, quindi vede ciò che il percorso sincrono ha appena scritto. Vale per qualunque
  strada crei un'istanza, non solo per la nostra: nessuna variabile da impostare e rimettere a
  posto, che dimenticata acciecherebbe il calcolo per ogni istanza successiva nello stesso processo.
  - Verifica: [TrailApplicationDemJobTest.php](../../../tests/Feature/TrailRegistry/TrailApplicationDemJobTest.php) › `non chiama il servizio quando i dati tecnici ci sono gia`
- <a id="r28"></a>**R28** — **Se il calcolo sincrono fallisce, il percorso asincrono deve restare
  percorribile.** `booted()` prende il lock del DEM per un'ora prima ancora di accodare, e
  `dispatchDemIfMissing()` riaccoda solo se riesce a prendere quel lock: un'istanza il cui calcolo
  sincrono è andato storto non deve restare un'ora senza nessuno che riprovi.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `toglie il lock del ricalcolo quando il calcolo immediato non e riuscito`
- <a id="r29"></a>**R29** — La scrittura del risultato segue la strada che il job usa già:
  `properties.dem_data` via `jsonb_set`, e la colonna `geometry` riscritta con la geometria 3D
  restituita dal servizio. Quella riscrittura non è un effetto collaterale da evitare: è la strada
  per cui il dato entra già.
  - Verifica: [ApplicationDemServiceTest.php](../../../tests/Feature/TrailRegistry/ApplicationDemServiceTest.php) › `scrive i dati tecnici e la geometria tridimensionale`
- <a id="r30"></a>**R30** — **I valori dichiarati non finiscono in `manual_data`.** Restano fra i
  dati del fascicolo. `classifyField()` restituisce `MANUAL` appena `manual_data` non è vuoto,
  quindi copiarli lì li farebbe vincere sul calcolato e finire pubblicati sul sentiero e nelle tile
  senza che nessuno li abbia approvati. L'operatore li legge nel fascicolo e, se li ritiene buoni,
  li scrive lui nel tab DEM: allora sono una decisione. Conseguenza da dichiarare nella
  documentazione: la colonna «manuale» della tabellina resta vuota finché l'operatore non
  interviene.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `non scrive i valori dichiarati fra i valori manuali`

### Lo schema dei campi, fonte unica

- <a id="r31"></a>**R31** — **I 27 campi del format sono descritti in un posto solo.** Ai due posti
  di partenza, scrivere le regole a mano nella Form Request ne aggiungerebbe un terzo. Tre copie
  della stessa tabella divergono alla prima revisione del foglio di Engineering, e il
  disallineamento non produce nessun errore: un campo semplicemente smette di essere validato.
  - Verifica: [ApplicationSchema.php](../../../src/TrailRegistry/Schema/ApplicationSchema.php) › `public function fields(): array`
- <a id="r32"></a>**R32** — Lo schema **esce dalla Resource Nova**. Finché sta lì, una Form Request
  che lo legge fa dipendere l'API dallo strato di presentazione.
  - Verifica: [ApplicationSchema.php](../../../src/TrailRegistry/Schema/ApplicationSchema.php) › `namespace Wm\WmPackage\TrailRegistry\Schema;`
- <a id="r33"></a>**R33** — **Ogni campo è un oggetto tipizzato**, non un array associativo, con un
  `toArray()` che dà a Nova la forma che `PropertiesPanel::jsonForm()` si aspetta già. Un array
  accetta in silenzio qualunque chiave nuova; una classe obbliga a dichiararla, e quella
  dichiarazione si vede in review. È la difesa del confine di [R40](#r40): una forma che non
  accetta, invece di un test che vieta.
  - Verifica: [ApplicationField.php](../../../src/TrailRegistry/Schema/ApplicationField.php) › `final class ApplicationField`
- <a id="r34"></a>**R34** — Ogni campo acquisisce **l'obbligatorietà e una chiave `rules` con regole
  Laravel native** — `required_if:soggetto_gestore,third_party` compresa, per le tre dipendenze fra
  campi (47→48, 49→50 e 51, 53→54 e 55). Nessuna sintassi inventata da noi: chi legge lo schema
  legge regole di Laravel.
  - Verifica: [ApplicationSchemaTest.php](../../../tests/Unit/TrailRegistry/ApplicationSchemaTest.php) › `esprime le dipendenze fra campi come regole Laravel native`
- <a id="r35"></a>**R35** — I casi che una stringa di regola non esprime diventano **Rule class
  referenziate dallo schema**: la difficoltà si valida come coppia attività → livello interrogando
  le famiglie di `taxonomy_terms`, perché `{"mtb": "EEA"}` è un errore anche se `EEA` esiste,
  appartenendo a un'altra scala.
  - Verifica: [StoreApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationRequestTest.php) › `rifiuta un livello che non appartiene alla scala dell attivita`
- <a id="r36"></a>**R36** — Lo stesso campo può dire **due cose diverse in ingresso e in uscita**:
  `last_survey_date` si valida come data non futura ma si mostra come testo, perché — dice il
  docblock attuale — «un formato di data inatteso non deve impedire di leggere cosa è arrivato».
  - Verifica: [ApplicationSchemaTest.php](../../../tests/Unit/TrailRegistry/ApplicationSchemaTest.php) › `valida last_survey_date come data non futura ma la mostra come testo`
- <a id="r37"></a>**R37** — Il tab di Nova con i dati arrivati dal canale, che si chiama «Dati API»
  (era «Dati SUS»), continua a funzionare come prima e diventa un consumatore dello schema, non più
  il suo proprietario. Le select mostrano l'etichetta dei valori che lo schema ammette, e
  `soggetto_custode` si mostra come testo.
  - Verifica: [TrailApplicationApiDataFieldsTest.php](../../../tests/Feature/TrailRegistry/TrailApplicationApiDataFieldsTest.php) › `mostra l etichetta di una select, non il valore`
- <a id="r38"></a>**R38** — **Lo schema distingue l'obbligatorio dal condizionale**, e
  `requiredApiFields()` deriva i soli campi obbligatori sempre. Una derivazione meccanica da una
  colonna «obbligatorio» reintrodurrebbe i sei campi condizionali che il metodo esclude, e nessuna
  istanza senza soggetto gestore terzo potrebbe più essere confermata.
  - Verifica: [ApplicationSchemaTest.php](../../../tests/Unit/TrailRegistry/ApplicationSchemaTest.php) › `non mette fra gli obbligatori i campi la cui obbligatorieta dipende da un altro campo`
- <a id="r39"></a>**R39** — **Lo schema è l'oggetto che lo shard ridefinisce.** Il package lo tiene
  come default e lo shard può sovrascriverlo.
  - Verifica: [ApplicationSchemaTest.php](../../../tests/Unit/TrailRegistry/ApplicationSchemaTest.php) › `si lascia ridefinire dallo shard`
- <a id="r40"></a>**R40** — **Lo schema descrive i campi, non il comportamento degli endpoint.** Non
  entra niente che parli di HTTP, di stati dell'istanza o di forma delle risposte: è il vincolo che
  impedisce allo schema di diventare il posto dove si scarica tutto.
  - Verifica: [ApplicationField.php](../../../src/TrailRegistry/Schema/ApplicationField.php) › `public function __construct(`

### La transizione verso l'istruttoria

- <a id="r41"></a>**R41** — **Il numero si riserva alla creazione, da entrambe le origini**, perché
  è così che il dominio lo definisce: l'enum dichiara che in `under_compilation` «l'istanza esiste e
  il suo numero è già riservato», il docblock del modello che «ogni riga qui porta già il proprio
  codice riservato», e la Resource Nova che «la riserva del numero fa parte della creazione, non è
  un passo successivo». Conseguenza pratica: un tracciato fuori da ogni settore fa fallire la
  creazione, non la conferma — il richiedente lo scopre prima di caricare un solo documento, invece
  che dopo sei.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `rifiuta un tracciato fuori da ogni settore senza lasciare righe`
- <a id="r42"></a>**R42** — **Il numero non esce mai dall'API finché l'istanza non è accettata.**
  Esiste dalla creazione, ma non compare né nella risposta alla creazione né in quella della
  conferma. È un vincolo da scrivere, perché è il genere di campo che qualcuno aggiunge a una
  risposta per comodità.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `non restituisce il numero del sentiero, benche sia gia riservato`
- <a id="r43"></a>**R43** — La transizione dalla compilazione all'istruttoria — verifica la
  completezza, sposta lo stato — diventa **un metodo di `TrailRegistryService`**. Il chiamante è
  uno solo, l'endpoint di conferma, ma la regola descrive quando un'istanza smette di essere
  compilabile: è dominio, e il dominio sta nel package, non nel controller.
  - Verifica: [ConfirmCompilationTest.php](../../../tests/Feature/TrailRegistry/ConfirmCompilationTest.php) › `porta in istruttoria un fascicolo completo`
- <a id="r44"></a>**R44** — **Il comportamento di Nova non cambia.** L'istanza creata d'ufficio continua a nascere in
  `under_review` con il numero riservato, in un gesto solo. I dati del form del SUS esistono solo se
  l'istanza arriva da lì, quindi un'istanza d'ufficio non ne ha e la regola di completezza non la
  riguarda. Il codice di Nova cambia solo nella detail view, che mostra in sola lettura i tab «Dati
  API» e «Allegati» ([R37](#r37), [R57](#r57)).
  - Verifica: [TrailApplicationCreateFormTest.php](../../../tests/Feature/TrailRegistry/TrailApplicationCreateFormTest.php) › `creando un istanza da Nova la piattaforma scrive provenienza stato e geometria`
- <a id="r45"></a>**R45** — Il metodo gestisce `SectorNotFoundException` e
  `SectorExhaustedException` in modo che ciascun chiamante le traduca nella propria forma:
  `ValidationException` per Nova, risposta JSON con `{error: string}` per l'API.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `traduce un settore esaurito in una risposta comprensibile, senza il codice del settore`
- <a id="r57"></a>**R57** — Il fascicolo in Nova ha un tab «Allegati», in sola lettura come il resto
  di ciò che arriva dal canale, con i documenti di ogni collection — relazione, scheda dati del
  sentiero, autorizzazioni, piano di manutenzione, documentazione fotografica. Una collection vuota
  dice «Non trasmesso» invece di sparire.
  - Verifica: [TrailApplicationAttachmentFieldsTest.php](../../../tests/Feature/TrailRegistry/TrailApplicationAttachmentFieldsTest.php) › `ha un campo per ciascuna delle cinque collection di allegati`

### Il confine fra package e shard

- <a id="r46"></a>**R46** — Nel **`wm-package` sta il dominio, compresi i controller che lo
  espongono**: creare un fascicolo, aggiungergli un allegato, chiuderlo quando è completo non ha
  nulla di specifico di questo ente. Ci stanno i tre controller, lo schema dei campi, le Form
  Request che ne derivano, il metodo di service, il calcolo sincrono e il riuso di
  `TrailGeometryReader` fuori da Nova.
  - Verifica: [StoreApplicationController.php](../../../src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php) › `namespace Wm\WmPackage\Http\Controllers\Api\TrailRegistry;`
- <a id="r47"></a>**R47** — **Nel package il nome dello sportello non compare mai**, come già nella
  migration di oc:8960: «mai il nome dello sportello: questo codice servirà anche Lombardia e
  Toscana». I controller si chiamano per quello che fanno al fascicolo, non per chi li chiama.
  - Verifica: [StoreApplicationController.php](../../../src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php) › `class StoreApplicationController extends Controller`
- <a id="r48"></a>**R48** — Nello **shard `forestas` sta il canale dell'ente**: `routes/sus.php` con
  il prefisso `api/v1/sus`, i middleware `RestrictSusClient` e `LogSusRequest`, il client SUS, la
  pagina `/docs/api/sus`. È lì che il canale prende il nome del cliente.
  - Verifica: forestas routes/sus.php › `RoleMiddleware::class.':Sus'`
- <a id="r49"></a>**R49** — Dove allo shard serve personalizzare, **estende**: i controller del
  package come già si fa con le Resource Nova, e lo schema dei campi il giorno in cui il format di
  Engineering divergesse. Finché non serve, forestas non scrive nemmeno la sottoclasse.
  - Verifica: forestas routes/sus.php › `StoreApplicationController::class`
- <a id="r50"></a>**R50** — I 27 campi del format sono di questo cliente dentro codice condiviso, e
  la risposta è lo schema ridefinibile descritto in [R39](#r39): il package tiene il default, lo
  shard sovrascrive. Il giorno in cui un altro ente monterà il package non ci sarà niente da
  smontare.
  - Verifica: [ApplicationSchemaTest.php](../../../tests/Unit/TrailRegistry/ApplicationSchemaTest.php) › `si lascia ridefinire dallo shard`
- <a id="r51"></a>**R51** — Nella documentazione del package va dichiarato l'approccio: il package
  offre le operazioni del fascicolo — apri, aggiungi un allegato, chiudi — e lo shard ci monta sopra
  il canale del proprio ente. È un fatto che vale per chiunque monti il package, quindi si scrive lì.
  - Verifica: [operazioni-del-fascicolo-e-canale-dell-ente.md](../../knowledge/operazioni-del-fascicolo-e-canale-dell-ente.md) › `# Operazioni del fascicolo e canale dell'ente`

### Documentazione e test

- <a id="r52"></a>**R52** — Ogni parametro documentato porta il **numero di campo del format**,
  nella forma «campo 29 del format — *Origine (da)*»; il link al foglio sta una volta sola
  nell'intestazione della pagina.
  - Verifica: [StoreApplicationController.php](../../../src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php) › `Campo 29 del format — *Origine (da)*`
- <a id="r53"></a>**R53** — La documentazione dichiara le **unità che viaggiano nel payload** —
  `distance` in chilometri con un decimale, `duration_forward` e `duration_backward` in minuti
  interi — perché il format chiede metri e `ORE:MINUTI`. È una divergenza da segnalare a
  Engineering.
  - Verifica: [StoreApplicationController.php](../../../src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php) › `In chilometri, con un decimale`
- <a id="r54"></a>**R54** — La documentazione dichiara in modo esplicito che **questa chiamata non
  si corregge, si rifà**: è il tipo di vincolo che chi integra scopre tardi.
  - Verifica: [StoreApplicationController.php](../../../src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php) › `Questa chiamata non si corregge, si rifà`
- <a id="r55"></a>**R55** — I test seguono il pattern già in uso nel canale: sotto
  `tests/Feature/Sus/` esistono `SusAuthTest`, `SusPingTest`, `SusClientRestrictionTest`,
  `SusRequestLogTest`.
  - Verifica: forestas tests/Feature/Sus/SusApplicationRoutesTest.php › `espone le quattro rotte sotto il prefisso del canale`
- <a id="r56"></a>**R56** — Il servizio DEM va fakato nei test: nessun test chiama il servizio vero.
  - Verifica: [StoreApplicationTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationTest.php) › `function fakeDemResponding(): void`

## Rischi

Verificati sul codice dopo la challenge. Alla chiusura restano aperti i cinque qui sotto: sono
annotati fra i [follow-up delle note](notes.md#follow-up), ciascuno con la destinazione proposta.
Gli altri quattro sono superati dai requisiti: il calcolo parziale da [R24](#r24), il doppio
calcolo da [R27](#r27), lo schema che attira responsabilità da [R33](#r33) e [R40](#r40), gli
allegati esposti da `GET api/media/{id}` da [R17](#r17).

- **Su UAT il canale non è collaudabile finché esiste il reset notturno.** `--reset` tronca
  `trail_applications`, e la regola del repo lo dice esplicitamente: «finché il reset notturno
  esiste, un'istanza presentata su collaudo non sopravvive alla notte, e i codici `reserved` che
  aveva prenotato non tornano disponibili». Questo canale è fatto di chiamate distanti nel tempo:
  Engineering crea un'istanza il pomeriggio e la mattina dopo riceve 404 su un id che gli abbiamo
  dato noi, leggendolo come un bug della nostra API. Non è codice di questo ticket: è una
  condizione da soddisfare prima di consegnare l'ambiente, e va in mano a chi rilascia.
  Spostato fra i follow-up delle note.
- **Del payload rifiutato restano gli esiti, non il contenuto.** È una scelta: nel log finiscono i
  campi che hanno fallito e la regola violata, mai i valori, perché il payload porta nomi, e-mail e
  codici fiscali. Se un giorno il disaccordo con Engineering fosse proprio su *quale valore* è
  stato mandato, i nostri dati non lo chiuderanno. Spostato fra i follow-up delle note.
- **Il contratto si congela al primo collaudo con Engineering, non al merge.** Da quel momento
  cambiare la forma di una risposta richiede un ciclo di coordinamento con un fornitore esterno,
  con tempi che non controlliamo. Conseguenza operativa: la divergenza sulle unità — chilometri e
  minuti interi contro i metri e gli `ORE:MINUTI` del format — va segnalata a Engineering **prima**
  di quel collaudo, non dopo. Spostato fra i follow-up delle note.
- **Il calcolo sincrono mette un servizio esterno dentro il ciclo di richiesta.** È il rischio di
  disponibilità più grosso della feature, e il suo caso peggiore esce dal perimetro: un DEM muto
  con un client che ritenta esaurisce i worker e porta giù anche Nova. Mitigato dai requisiti —
  timeout basso, nessun retry, nessun fallimento della chiamata — ma resta da misurare quanto il
  calcolo allunghi davvero la creazione. Spostato fra i follow-up delle note.
- **Gli allegati di un'istanza mai trasmessa restano su S3.** `MEDIA_DISK` vale `wmfe` di default;
  in locale non è impostato e Media Library ricade sul disco `public`. Un'istanza abbandonata in
  compilazione non si può né respingere né cancellare, quindi i suoi file restano. **Se ne andranno
  con l'istanza il giorno in cui si implementerà la cancellazione**: è il lavoro che dovrà
  occuparsene, ed è il genere di cosa che in una `delete()` si dimentica. Su UAT il reset notturno
  porta via le righe ma non i file, che restano nel bucket scollegati da qualunque record.
  Spostato fra i follow-up delle note.

## Out of scope

- **La prevalidazione del tracciato** come esito strutturato e vincolante alla conferma: è la macro
  area 3 del tag 709. Qui la traccia viene letta e, se illeggibile, rifiutata; non viene prodotto
  un esito di validità.
- **I vincoli di formato e dimensione degli allegati** — solo PDF, solo ZIP, immagini, 5 MB per
  file: sono le regole di validazione dell'endpoint di upload, macro area 6.
- **Il codice riservato e il codice regionale definitivo nella lettura dell'istanza**: macro area
  7. La lettura è anticipata qui ([R58](#r58)), ma restituisce il codice solo a domanda approvata.
- **La prosa esaustiva della pagina `/docs/api/sus`**: macro area 8. Qui si documenta ciò che si
  scrive.
- **Il form d'ufficio di Nova allineato al set di campi del SUS**: resta al tag. Oggi quei dati
  esistono solo se l'istanza arriva dal SUS.
- **Come si chiude un'istanza rimasta in compilazione e mai trasmessa**: registrato nel tag 709
  come domanda aperta verso Forestas.

## Moduli toccati

Ricavati da git sul branch, esclusa la cartella della feature.

### `wm-package` (submodule montato da forestas)

- **Controller del canale API** — `src/Http/Controllers/Api/TrailRegistry/`:
  `StoreApplicationController.php`, `ShowApplicationController.php`,
  `StoreAttachmentController.php`, `ConfirmApplicationController.php`,
  `ResolvesConfiguredApplication.php`.
- **Form Request** — `src/Http/Requests/TrailRegistry/`: `StoreApplicationRequest.php`,
  `StoreAttachmentRequest.php`, `ConfirmApplicationRequest.php`, `ShowApplicationRequest.php`,
  `ApiChannelApplicationRequest.php`.
- **Schema dei campi e Rule** — `src/TrailRegistry/Schema/ApplicationField.php`,
  `src/TrailRegistry/Schema/ApplicationSchema.php`,
  `src/TrailRegistry/Rules/DifficultyMatchesActivities.php`.
- **Dominio** — `src/TrailRegistry/TrailRegistryService.php`,
  `src/TrailRegistry/Models/TrailApplication.php`,
  `src/TrailRegistry/Services/ApplicationDemService.php`,
  `src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php`,
  `src/TrailRegistry/Database/Factories/TrailApplicationFactory.php`, e le eccezioni in
  `src/TrailRegistry/Exceptions/`: `ApplicationIncompleteException.php`,
  `InvalidTrailApplicationTransitionException.php`, `InvalidTrailGeometryException.php`,
  `MissingDifficultyFamilyException.php`.
- **Nova** — `src/TrailRegistry/Nova/TrailApplication.php`.
- **DEM** — `src/Http/Clients/DemClient.php`, `src/Services/Models/EcTrackService.php`,
  `src/Jobs/Track/UpdateEcTrack3DDemJob.php`, `src/Models/Abstracts/MultiLineString.php`,
  `config/wm-package.php`.
- **Media** — `src/Http/Controllers/Api/MediaController.php`.
- **Traduzioni** — `resources/lang/{de,en,es,fr,it}.json`.
- **Documentazione e dipendenze** — `docs/knowledge/operazioni-del-fascicolo-e-canale-dell-ente.md`,
  `CLAUDE.md`, `composer.json`.
- **Test** — in `tests/Feature/TrailRegistry/`: `ApplicationDemServiceTest.php`,
  `ApplicationEndpointTranslationsTest.php`, `ApplicationMediaPrivacyTest.php`,
  `ApproveTrailApplicationTest.php`, `ConfirmApplicationEndpointTest.php`,
  `ConfirmCompilationTest.php`, `ShowApplicationEndpointTest.php`,
  `StoreApplicationRequestTest.php`, `StoreApplicationTest.php`, `StoreAttachmentTest.php`,
  `TrailApplicationApiDataFieldsTest.php`, `TrailApplicationAttachmentFieldsTest.php`,
  `TrailApplicationDemJobTest.php`, `Support/ShardTrailApplication.php`; in `tests/Unit/`:
  `TrailRegistry/ApplicationSchemaTest.php`, `TrailRegistry/DifficultyMatchesActivitiesTest.php`,
  `Services/EcTrackService/AbstractEcTrackServiceTest.php`.

### `forestas` (shard)

L'elenco è nell'overview dello shard,
`forestas/docs/features/8968-compilazione-istanza-endpoint-sus/overview.md`: rotte, middleware,
documentazione della pagina `/docs/api/sus`, traduzioni e i test di `tests/Feature/Sus/`.

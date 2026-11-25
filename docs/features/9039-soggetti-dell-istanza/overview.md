> Ticket: oc:9039
> Stato: chiusa il 2026-11-25 · approvata il 2026-11-25

# Soggetti dell'istanza: quattro liste di persone al posto delle select di categoria

## Cosa cambia

I campi dei soggetti smettono di descrivere *categorie* e cominciano a portare *persone*. Al posto
delle tre select di categoria e dei campi dei comuni, l'istanza porta quattro liste, una per ruolo,
ciascuna di oggetti con denominazione, e-mail e natura del soggetto:

```json
"managing_subjects": [
  { "name": "Comune di Oliena",     "email": "protocollo@comune.oliena.nu.it", "subject": "organization" },
  { "name": "CAI Sezione di Nuoro", "email": "nuoro@cai.it",                   "subject": "organization" }
],
"surveying_subjects":   [ { "name": "Mario Rossi", "email": null, "subject": "person" } ],
"maintaining_subjects": [ { "name": "Forestas",    "email": "…",  "subject": "organization" } ],
"keeping_subjects":     []
```

Gestore, rilevatore e manutentore hanno **almeno un soggetto** ciascuno; il **custode è
facoltativo**, cioè zero o più, e la sua lista può anche non essere passata.

**I nomi, decisi con il dev il 25/11/2026.** I quattro campi sono `managing_subjects`,
`surveying_subjects`, `maintaining_subjects`, `keeping_subjects`; le chiavi dell'oggetto sono
`name`, `email` e `subject`. `subject` vale `organization` oppure `person`: non un booleano, perché
tutte le altre scelte chiuse dello schema sono identifier scritti per esteso (`existing_signage`
vale `good_condition`, `dilapidated`, `absent`) e un `true`/`false` obbligherebbe a ricordare quale
dei due casi sia il vero; non `entity`, perché *entity* in inglese è «entità» in senso astratto e
non «ente pubblico», e perché `Ente` nel package è già un modello (`src/Models/Ente.php`, quello
dietro `api/v1/entes`) — proprio gli enti a cui questi soggetti verranno agganciati a mano dopo
l'approvazione; non `role`, perché il ruolo è già la lista in cui il soggetto sta.

**La e-mail dipende da `subject`**, deciso con il dev il 25/11/2026: obbligatoria e valida quando il
soggetto è un `organization`, facoltativa quando è una `person` — il SUS può non passarla o passare
`null`. È il punto in cui questo ticket tocca quello sul segnaposto di privacy, che arriva dopo.

Escono dallo scambio, senza sostituti: le select `soggetto_gestore` (campo 47),
`soggetto_rilevatore` (49), `soggetto_manutentore` (53) con i rispettivi `soggetto_gestore_other`
(48), `soggetto_rilevatore_other` (50), `soggetto_rilevatore_email` (51),
`soggetto_manutentore_other` (54), `soggetto_manutentore_cf` (55); la coppia
`soggetto_custode_presente` (56) e `soggetto_custode` (58); e i campi dei comuni
`proposed_managing_municipalities` (42), `municipality_email` (43), `municipality_email_alt` (44).

Dopo questo lavoro **tutti i nomi dei campi dello schema sono in inglese**: i dieci nomi italiani
dello schema precedente erano esattamente i `soggetto_*` che spariscono qui.

## Perché

Il modello precedente non reggeva quello che Forestas ha dichiarato. Alessio Saba, mail del 20/11/2026 al
quesito 18: «per ciascuno dei ruoli ci può essere più di un unico soggetto (ente o persona
fisica)… in particolare, questo è il caso tipico per il GESTORE: è sempre ALMENO UN COMUNE… ma se
il sentiero ne tocca più di uno, i soggetti sono vari: i comuni toccati dal sentiero, ed
eventualmente Forestas, CAI… Quindi, ricapitolando, possono essere multipli, o anche singoli
soggetti, per ciascuno dei ruoli. SEMPRE ALMENO UNO».

Alessio Piccioli ha tradotto la regola in forma tecnica davanti a Engineering, nella call di
chiusura del 23/11/2026: «ruolo uno, ruolo due, ruolo tre, ruolo quattro, quindi gestore,
manutentore, ricognitore, custode; per ciascuno di quelli un array di oggetti, ciascun oggetto è
composto da tre campi, nominativo, email, ente o privato».

**Perché i campi dell'oggetto sono tre e non quattro**, cioè perché non c'è il codice fiscale. Nella
stessa call è Engineering a proporlo. Moreno Santucci: «invece di passare in una stringa il ruolo
che gestisce il sentiero, dobbiamo andare a risolvere questo ruolo in dei dati identificativi,
quindi il nome, la mail e il codice fiscale». Alessio Piccioli: «No, codice fiscale no, perché
rientra poi nel discorso privacy eccetera, solo per distinguerci ci dovete dire se è un ente o un
privato». Moreno Santucci: «OK». È la stessa frase che motiva il terzo campo: `subject` sta lì *al
posto* del codice fiscale, per distinguere ente da privato senza portarsi dietro un dato personale.
Per questo il campo 55 (`soggetto_manutentore_cf`), che raccoglieva i codici fiscali dei
manutentori, sparisce senza sostituti.

Due conseguenze dette nella stessa call. Il **richiedente esce dallo scambio**: «al richiedente ce
l'avevamo già un campo apposito, però secondo me non serve neanche che ce lo dobbiate passare,
perché lato nostro non lo teniamo». E i **comuni confluiscono nei ruoli**: un comune gestore è
semplicemente un soggetto del ruolo gestore, mentre il vincolo «almeno un comune fra i gestori» lo
valida il SUS e non noi — «in più ho aggiunto una cosa che dovreste gestire lato vostro, che almeno
uno dei gestori deve essere un comune». Il controllo che quei comuni siano davvero fra quelli
attraversati resta nostro e resta successivo: «il check che quel comune lì sia effettivamente uno
dei comuni attraversati lo facciamo ex post lato catasto».

## Punto di partenza

Letto sul codice il 25/11/2026, a oc:9038 già eseguito. I numeri di riga sono un aiuto alla ricerca,
non un'àncora: quello che conta è il contenuto descritto accanto.

**Lo schema.** Tutti i campi dei soggetti stanno in
`src/TrailRegistry/Schema/ApplicationSchema.php`, nel metodo `fields()` (righe 97-123). Ogni campo è
un `ApplicationField`, un oggetto tipizzato con `name`, `type`, `label`, `values` (l'elenco di una
select, se fissato), `required` (solo l'obbligatorietà incondizionata), `rules` (le regole Laravel) e
`displayType`. Oggi: i comuni sono `proposed_managing_municipalities` di tipo `json` con
`['required','array','min:1']`, più `municipality_email` (`['required','email']`) e
`municipality_email_alt` (`['nullable','email']`); i tre ruoli sono `select` con `in:` sui loro
identifier; i campi condizionati portano `required_if`; `soggetto_manutentore_other` e
`soggetto_manutentore_cf` sono di tipo `json` con regola `array`, cioè già oggi liste — il
manutentore è l'unico ruolo in cui più soggetti viaggiano, con i loro codici fiscali al posto delle
e-mail.

**I campi sono 28, e le `label` inglesi dei quattro ruoli esistono già**: `__('Manager')` sul
campo 47, `__('Surveyor')` sul 49, `__('Maintainer')` sul 53, `__('Keeper')` sul 58. Dei 28 nomi, 18
sono in inglese e 10 in italiano, e i 10 italiani sono tutti e soli i `soggetto_*`.

**La versione dello schema** è `'2'`, restituita da `ApplicationSchema::version()` (riga 50): va
alzata, perché cambia ciò che la consegna deve contenere.

**Le regole annidate non vanno inventate.** `itemRules()` (righe 135-143) dichiara oggi quattro voci
— `activities.*`, `proposed_managing_municipalities.*`, `soggetto_manutentore_other.*`,
`soggetto_manutentore_cf.*` — e `rules()` le somma a quelle dei campi
(`return $rules + $this->itemRules();`). **Nessuna di queste è una lista di oggetti**: sono tutte
regole scalari su liste di stringhe, e `campo.*.chiave` sarebbe la prima volta. `difficulty` è una
mappa attività→livello, cioè un oggetto chiave-valore, e viene validata da una Rule object e non da
regole annidate.

**L'arrivo del payload non va toccato.** `StoreApplicationRequest::prepareForValidation()` (righe
80-113) scorre i campi dello schema e, per quelli di tipo `json`, fa `json_decode` del valore se è
una stringa. Una lista di oggetti spedita in `multipart/form-data` come stringa JSON viene quindi
decodificata senza alcuna modifica, esattamente come succede oggi all'elenco dei comuni.

**I messaggi di errore portano già ruolo e posizione, verificato.** `attributes()` (righe 213-221)
riduce ogni chiave di `rules()` al primo segmento, quindi per le chiavi annidate non produce alcuna
voce e Laravel usa il nome del campo così com'è. Provato con il validator reale sulle regole e sugli
attributi di oggi: un payload con tre gestori, il terzo con e-mail malformata e `subject` fuori
elenco, produce «Il campo managing_subjects.2.email deve essere un indirizzo e-mail valido» e «Il
valore selezionato per managing_subjects.2.subject non è valido», con chiavi in errore
`managing_subjects.2.email` e `managing_subjects.2.subject`. Non c'è nulla da costruire: va fissato
con un test e documentato, avvertendo che **la posizione parte da zero**.

**La completezza prima della conferma.** `TrailApplication::missingForConfirmation()` (righe
285-302) scarta i campi di `requiredApiFields()` che sono assenti, uguali a `''` o uguali a `[]`:
una lista vuota conta quindi già come mancante, senza modifiche. `requiredApiFields()` (255-258)
delega a `ApplicationSchema::requiredFieldNames()`, che raccoglie i campi con `required = true`: i
tre ruoli obbligatori vi entrano dichiarandoli così, il custode no. **Attenzione:** una lista non
vuota con dentro oggetti malformati supera quel controllo, quindi la forma degli oggetti va espressa
nelle regole dello schema, non qui. Il docblock sopra `requiredApiFields()` (righe 237-254) elenca
per nome i campi condizionati, `soggetto_manutentore_cf` compreso: va riscritto.

**In Nova non c'è niente da dichiarare a mano.** La Resource non nomina questi campi: il tab «API
data» è costruito da `PropertiesPanel::jsonForm()` a partire da `toNovaSchema()`, con editabilità
disattivata e `onlyOnDetail()` su ogni campo. Un campo di tipo `json` cade in `case 'json'` (riga
531) e viene reso con `Code::make($label, $jsonPath)->json()`. Le quattro liste si vedranno quindi
come JSON grezzo: **è noto ed è fuori perimetro**, perché riguarda tutti i campi `json`
dell'istanza e non solo i soggetti.

**Le regole bastano, non serve una Rule object.** Provato con il validator reale dentro
`php-forestas` il 25/11/2026: `required_if:managing_subjects.*.subject,organization` risolve
l'indice fratello, e con un ente senza e-mail e una persona senza e-mail l'errore esce solo per il
primo — «Il campo managing_subjects.0.email è obbligatorio quando managing_subjects.0.subject è
organization». Lo stesso meccanismo porta ruolo e posizione nel messaggio, cioè copre anche
[R5](#r5). La Rule object del dominio,
`src/TrailRegistry/Rules/DifficultyMatchesActivities.php`, resta il modello da seguire solo se
emergesse un vincolo che guarda la lista nel suo insieme: oggi non ce n'è, perché «almeno uno» lo
esprime `min:1` e «almeno un comune fra i gestori» è fuori perimetro. Va però ricordato che una
Rule agganciata al campo produce un errore sulla chiave `managing_subjects`, **senza indice**, e
quindi contraddirebbe [R5](#r5).

**Cosa il validator rifiuta già, provato.** Una lista di stringhe (`["Comune di Oliena"]`) viene
rifiutata perché i wildcard non trovano `name` e `subject`; un oggetto singolo al posto della lista
viene rifiutato anch'esso, ma con chiavi d'errore illeggibili (`managing_subjects.name.name`). Una
`"email": ""` su una `person` viene invece **accettata** e depositata com'è: `nullable` non la
intercetta e `required_if` non scatta.

**La documentazione sta in quattro punti, non in uno.** Nel package: gli attributi
`#[BodyParameter]` di `StoreApplicationController` (righe 163-176), uno per campo, ciascuno aperto
dal numero del campo nel modulo FORES_02 — «Campo 47 del format — *Soggetto gestore*» — e l'esempio
`curl` nel docblock dell'endpoint (righe 122-127). oc:9038 ha introdotto la forma per un campo che
nel modulo non esiste: `primary_activity` dice «Non ha un numero nel format.» Nello shard
`forestas` stanno gli altri due punti, descritti nella sua overview.

**I test.** Sette file del package nominano questi campi, e **non esiste un payload valido
condiviso**: ciascuno se lo costruisce, a volte come array e a volte come stringa JSON. Occorrenze:
`Feature/TrailRegistry/StoreApplicationRequestTest.php` (31),
`Unit/TrailRegistry/ApplicationSchemaTest.php` (18),
`Feature/TrailRegistry/TrailApplicationApiDataTest.php` (11), `StoreApplicationTest.php` (8),
`ApproveTrailApplicationTest.php` (3), `TrailApplicationApiDataFieldsTest.php` (2),
`ShowApplicationEndpointTest.php` (2).

**Le `label`** stanno in `resources/lang/it.json` (righe 648-660) e in `en.json`. `de.json`,
`es.json` e `fr.json` non hanno queste chiavi: lì non c'è niente da fare.

## Requisiti

- <a id="r1"></a>**R1** — L'API accetta per ogni ruolo una lista di soggetti, ciascuno con
  denominazione, e-mail e la natura del soggetto, che vale `organization` o `person`. I quattro
  campi si chiamano `managing_subjects`, `surveying_subjects`, `maintaining_subjects`,
  `keeping_subjects`; le chiavi dell'oggetto `name`, `email`, `subject`.
  *Fonte: Piccioli, call del 23/11/2026 — «per ciascuno di quelli un array di oggetti, ciascun
  oggetto è composto da tre campi, nominativo, email, ente o privato». I nomi li ha decisi il dev
  il 25/11/2026.*
  - Verifica: [tests/Unit/TrailRegistry/ApplicationSchemaTest.php](../../../tests/Unit/TrailRegistry/ApplicationSchemaTest.php) › `dichiara le quattro liste di soggetti e non piu le select di categoria`
- <a id="r2"></a>**R2** — Un'istanza senza almeno un gestore, un rilevatore o un manutentore viene
  rifiutata; un'istanza senza custode viene accettata, sia con la lista vuota sia senza la chiave.
  *Fonte: Saba, mail del 20/11/2026 — «possono essere multipli, o anche singoli soggetti, per
  ciascuno dei ruoli. SEMPRE ALMENO UNO»; il custode facoltativo è decisione del dev sul quesito
  18.*
  - Verifica: [tests/Unit/TrailRegistry/ApplicationSchemaTest.php](../../../tests/Unit/TrailRegistry/ApplicationSchemaTest.php) › `chiede almeno un soggetto per i tre ruoli obbligatori e nessuno per il custode`
  - Verifica: [tests/Feature/TrailRegistry/StoreApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationRequestTest.php) › `rifiuta un ruolo obbligatorio con la lista vuota`
- <a id="r3"></a>**R3** — La e-mail di un soggetto `organization` è obbligatoria e deve essere un
  indirizzo valido; quella di un soggetto `person` può mancare o valere `null`.
  *Fonte: decisione del dev del 25/11/2026, presa sapendo che il ticket sul segnaposto di privacy
  arriva dopo questo.*
  - Verifica: [tests/Feature/TrailRegistry/StoreApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationRequestTest.php) › `chiede la e-mail a un ente e non a una persona`
- <a id="r4"></a>**R4** — I campi delle categorie e dei comuni spariscono dallo schema: i campi 42,
  43, 44, 47, 48, 49, 50, 51, 53, 54, 55, 56 e 58 non sono più dichiarati, e un payload che li
  contenga viene rifiutato con un errore che li nomina, come ogni altra chiave non dichiarata
  (vedi [R8](#r8)).
  *Fonte: Piccioli, call del 23/11/2026, sul richiedente — «non serve neanche che ce lo dobbiate
  passare, perché lato nostro non lo teniamo» — e sul codice fiscale — «No, codice fiscale no,
  perché rientra poi nel discorso privacy».*
  - Verifica: [tests/Feature/TrailRegistry/StoreApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationRequestTest.php) › `rifiuta i tredici campi della forma vecchia, uno per uno`
  - Verifica: [tests/Unit/TrailRegistry/ApplicationSchemaTest.php](../../../tests/Unit/TrailRegistry/ApplicationSchemaTest.php) › `dichiara le quattro liste di soggetti e non piu le select di categoria`
- <a id="r5"></a>**R5** — Quando un soggetto è rifiutato, l'errore dice quale ruolo e quale
  posizione nella lista. Il comportamento c'è già: va fissato con un test perché non si perda.
  *Fonte: il dev in call con Engineering il 19/11/2026 — «io vi torno l'identifier che ha generato
  l'errore con l'errore»; Ivan Vignola: «OK, perfetto».*
  - Verifica: [tests/Feature/TrailRegistry/StoreApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationRequestTest.php) › `dice nell errore quale ruolo e quale posizione, contando da zero`
- <a id="r6"></a>**R6** — La verifica di completezza prima della conferma considera incompleta
  un'istanza a cui manca uno dei tre ruoli obbligatori, lista vuota compresa.
  - Verifica: [tests/Feature/TrailRegistry/TrailApplicationApiDataTest.php](../../../tests/Feature/TrailRegistry/TrailApplicationApiDataTest.php) › `considera incompleta un istanza a cui manca un ruolo obbligatorio`
- <a id="r7"></a>**R7** — Gli attributi `#[BodyParameter]` e l'esempio `curl` dell'endpoint
  descrivono le quattro liste, quanti soggetti servono per ruolo, il significato di `organization` e
  `person`, la regola della e-mail e il fatto che la posizione nell'errore parte da zero. Ogni campo
  nuovo porta il numero del campo del modulo FORES_02 da cui proviene — 47 gestore, 49 rilevatore,
  53 manutentore, 58 custode — dicendo che la forma è cambiata.
  *Fonte: decisione del dev del 25/11/2026 sui numeri di format.*
  - Verifica: forestas tests/Feature/Sus/SusApiDocumentationTest.php › `spiega le quattro liste di soggetti, custode compreso` e `pubblica la forma vera degli elementi di una lista di soggetti`

- <a id="r8"></a>**R8** — Una chiamata che porta una chiave non dichiarata dallo schema viene
  rifiutata con un errore che nomina la chiave, sia al primo livello del payload sia dentro un
  campo strutturato. Per un soggetto: «Il campo `managing_subjects.0` non ammette la chiave `cf`:
  un soggetto ha solo name, email e subject». Lo stesso vale per un valore fuori dall'elenco
  ammesso.
  *Fonte: il dev, 25/11/2026 — «ogni cosa che manda il SUS deve essere validata e se mette una
  chiave o un valore non concordato deve andare in errore con la spiegazione».*
  - Verifica: [tests/Unit/Rules/OnlyAllowedKeysTest.php](../../../tests/Unit/Rules/OnlyAllowedKeysTest.php) › `rifiuta una chiave non concordata nominandola`
  - Verifica: [tests/Feature/TrailRegistry/StoreApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationRequestTest.php) › `rifiuta una chiave del payload che lo schema non dichiara, nominandola`

La parte di documentazione che vive nello shard è in
[forestas/docs/features/9039-soggetti-dell-istanza/overview.md](../../../../docs/features/9039-soggetti-dell-istanza/overview.md).

## Rischi

**Il campo `subject` è il perno del ticket sulla privacy**, che va pianificato subito dopo. La
regola della e-mail decisa qui — valida per gli enti, facoltativa per le persone — è già pensata per
reggere quel ticket, ma se la decisione sul segnaposto sarà diversa (per esempio una stringa fissa
al posto di `null`) la regola di `person` va rivista. Nel codice del package non c'è nessuna
traccia di segnaposto di privacy: cercato in `src`, `docs`, `resources`, file di lingua e messaggi
di commit. **Resta aperto**: vive nel ticket sulla privacy, che dipende da questo.

**Il manutentore perde un dato, e chi lo usava non è ancora stato avvisato.** Il campo 55
raccoglieva i codici fiscali dei manutentori, l'unico identificativo forte che ricevevamo. È sparito
per decisione presa in call, non per svista. **Resta aperto**: l'avviso a chi in Forestas lo usava va
dato con la comunicazione del rilascio a Engineering, insieme alla rottura del contratto qui sotto.

**Rottura del contratto in corso, da comunicare.** Un payload con i campi vecchi riceve `422`, e
dopo [R8](#r8) lo riceve anche un payload con una chiave in più o un valore non concordato. **Resta
aperto**: va comunicato a Engineering con il rilascio, come per oc:9038, perché stanno integrando
adesso. La pagina delle API lo dice già nella sezione «Campi e valori concordati».

## Out of scope

**La leggibilità di `api_data` in Nova**: le liste si vedranno come JSON grezzo, come ogni altro
campo `json` dell'istanza. Ha un ticket suo, deciso dal dev il 25/11/2026, perché il problema è di
tutti i campi e non dei soggetti.

**Il segnaposto di privacy per i soggetti `person`**, che ha un ticket suo e dipende da questo.

**Il vincolo «almeno un comune fra i gestori»**, che lo valida il SUS: noi riceviamo denominazioni e
non sappiamo quali siano comuni.

**La verifica che i comuni gestori siano fra quelli attraversati**, che resta nostra e resta
successiva, fatta sulla geometria dall'operatore Forestas.

**L'associazione dei soggetti agli enti del Catasto**, che Forestas fa a mano dopo l'approvazione:
«questa gestione lato catasto viene fatta poi manualmente».

**L'elenco dei comuni proposti senza alcun ruolo**: con la forma nuova non arriva più, e Forestas ha
confermato che non serve.

## Moduli toccati

Quasi tutto nel package, con cinque file nello shard `forestas`.

- `src/TrailRegistry/Schema/ApplicationSchema.php` — le quattro liste in `fields()`, le regole
  annidate e la costante `THEMES` in `itemRules()`, `SUBJECT_KEYS`, la versione a `'3'`, i limiti
  di lunghezza
- `src/Rules/OnlyAllowedKeys.php` — **cartella nuova**: la Rule generica che rifiuta le chiavi non
  concordate nominandole
- `src/Http/Requests/TrailRegistry/StoreApplicationRequest.php` — il rifiuto delle chiavi non
  dichiarate al primo livello e il messaggio di `in:` che elenca i valori concordati
- `src/TrailRegistry/Models/TrailApplication.php` — il docblock di `requiredApiFields()`
- `src/TrailRegistry/Database/Factories/TrailApplicationFactory.php` — `complete()` deduce il
  payload dalle regole dello schema
- `src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php` — gli attributi
  `#[BodyParameter]` e l'esempio `curl`
- `resources/lang/{it,en,de,es,fr}.json` — le `label` dei campi e i messaggi nuovi, in tutte e
  cinque le lingue
- i test: `tests/Unit/Rules/OnlyAllowedKeysTest.php` (**cartella nuova**),
  `tests/Unit/TrailRegistry/ApplicationSchemaTest.php`,
  `tests/Feature/TrailRegistry/{StoreApplicationRequest,StoreApplication,TrailApplicationApiData,TrailApplicationApiDataFields,ApproveTrailApplication,ShowApplicationEndpoint}Test.php`

Nello shard: `config/scramble.php`, `resources/views/vendor/scramble/docs.blade.php`,
`app/Providers/AppServiceProvider.php`, `app/Http/Middleware/LogSusRequest.php` e i due test del
canale. Il dettaglio è nella sua overview.

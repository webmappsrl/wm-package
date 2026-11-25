> Ticket: oc:9038
> Overview approvata il 2026-11-24 · impronta `79558eb2f0ae0fc2e88f1cc5fff0cefd775dba92`

# Notes — Attività primaria del sentiero

## Modifiche ai requisiti

### 2026-11-25 — la primaria è obbligatoria anche nel modale di approvazione
- **Requisito:** [R3](overview.md#r3)
- **Effetto:** modificato
- **Decide:** dev, dopo la prova a mano
- **Perché:** l'overview approvata chiedeva che l'operatore scegliesse la primaria con le select
  prefillate, ma non diceva che senza di essa l'approvazione dovesse fermarsi: l'obbligatorietà,
  [R1](overview.md#r1), riguardava il payload del canale SUS. Provando su Nova il sentiero è nato
  senza primaria con tre clic naturali — vedi «L'approvazione passava con la primaria vuota» fra i
  bug.
- **Testo nuovo:**
  > Approvando un'istanza, l'operatore Forestas sceglie le attività del sentiero e quale di esse è
  > la primaria. Le scelte si presentano già compilate con quanto proposto dal SUS, quando c'è, e
  > quello che l'operatore conferma prevale sulla proposta. La primaria è obbligatoria: senza,
  > l'approvazione non parte.

## Divergenze dal piano, task per task

### Task 3 — un Select per famiglia di difficoltà, non «un Select per il livello»
Il piano diceva «un `Select` per il livello, con le opzioni lette dai termini figli delle famiglie
di difficoltà». È un difetto del piano: la difficoltà è una **mappa** attività→livello, che un campo
solo non esprime. L'implementatore si è fermato a chiedere invece di indovinare, e la decisione presa
col dev è stata **un campo per ciascuna delle sei famiglie** (`hiking-difficulty`, `mtb-difficulty`,
`horse-difficulty`, `inclusivity-difficulty`, `nordic-walking-difficulty`,
`educational-trails-difficulty`; `soft-mobility-difficulty` esiste a database ma non è nella mappa e
resta fuori). Il caso che decide: `hiking` e `trekking` condividono la scala CAI, quindi un payload
può proporre `E` per uno e `EEA` per l'altro mentre sul sentiero ne entra uno solo — con un campo per
famiglia quel conflitto lo risolve l'operatore invece che noi in silenzio, e la select resta vuota.

### Task 3 — il contratto su `activities` non si ottiene con `saveAsJSON()`
Il `Multiselect` di Outl1ne consegna a un `ActionFields` una **stringa JSON** invece di un array,
perché `shouldSaveAsJson()` richiede `getCasts()`, che un `Fluent` non ha. La correzione che sembrava
ovvia — il flag `->saveAsJSON()` — **non funziona**, perché il flag è letto dentro quel metodo, che
esce prima. Si è usato `->fillUsing()`, con un test che passa da `fillInto()` e fissa il contratto
per il Task 4.

### Task 4 — il test sull'indicizzazione del piano non avrebbe rilevato nulla
Il piano proponeva `Mockery::spy(EcTrackObserver::class)`. Non intercetta il percorso vero: si è
usato un engine Scout spiato tramite il singleton di `EngineManager`, verificato togliendo il
wrapper `withoutSyncingToSearch()` — senza, il test fallisce.

### Task 7 — un paragrafo invece di una riga nella tabella delle dipendenze
La tabella «I campi che diventano obbligatori» ha l'intestazione «Se… allora sono obbligatori», che
non si adatta a un vincolo **sui valori**. Il vincolo `primary_activity` ∈ `activities` è stato messo
in un paragrafo subito sotto, invece di forzare una riga con una semantica diversa.

## Bug trovati

### L'approvazione passava con la primaria vuota
Trovato provando a mano su Nova, dopo la review finale. Il campo `primary_activity` dell'Action non
aveva `->rules('required')`: l'operatore poteva approvare senza scegliere la primaria, e il sentiero
nasceva senza. Non e' un caso di laboratorio, e' la sequenza naturale — le opzioni della primaria
sono le attivita' selezionate, quindi togliendo dalle attivita' quella arrivata prefillata come
primaria (nella prova: `mtb`) il browser scarta la selezione e la select resta vuota, senza dire
niente. L'istanza di prova e' finita a database con `approved_primary_activity: null`.

La regola sta sul campo e non nel service: una decisione senza primaria resta **rappresentabile** —
`ShowApplicationController` documenta `primary_activity` a `null` come decisione presa, non come dato
mancante, e il canale di prova dello shard non passa sempre scelte complete — ma un operatore non
deve poterla prendere per distrazione. Verifica: `ApproveTrailApplicationFieldsTest.php` ›
`esige la primaria: senza, l approvazione non parte`.

### Sette test di forestas contavano le famiglie di tassonomia a mano
La famiglia nuova fa tredici dove `ConfigureTaxonomyTermsCommandTest`, `TaxonomiesPerAppTest` e
`ImportResetGuardsTest` ne contavano dodici, e otto righe in piu' dove ne contavano
cinquantacinque. Non e' un difetto del lavoro: quei test misurano cosa fa il comando delle
tassonomie dello shard, e la nostra famiglia la installa la migration del dominio, come gia'
`walkability-status` — che infatti era gia' esclusa dai conteggi. Aggiunta `primary-activity`
accanto a lei nelle esclusioni, e portato a 14 il solo conteggio che le famiglie dei domini le
include apposta.

**Li ha trovati la CI, non io**: avevo lanciato solo `tests/Feature/Sus`, cioe' i test del ticket,
e non la suite dello shard. Un dato come «quante famiglie esistono» non e' del dominio che lo
cambia: va cercato in tutto il repo prima di committare.

### Il payload del pulsante «Prova una domanda completa» non aveva il campo nuovo
`forestas/resources/views/vendor/scramble/docs.blade.php` contiene il payload che la pagina delle API
invia quando si prova una creazione. Non includeva `primary_activity`: con il campo diventato
obbligatorio, **ogni prova fatta da Engineering dalla pagina avrebbe risposto `422`**, cioè proprio
dal posto da cui si prova che l'integrazione funziona. Aggiunto `primary_activity: 'hiking'`,
coerente con le `activities` dello stesso payload.

### Il canale di prova dello shard non passava le scelte all'approvazione
`forestas/app/Http/Controllers/Api/Sus/SusTestDecisionController.php` chiamava `approve()` senza il
terzo parametro: approvando da lì — ed è la rotta con cui Engineering prova il flusso intero —
`approved_data` sarebbe uscito sempre vuoto e il sentiero sarebbe nato senza primaria, facendo
sembrare rotto il campo appena consegnato. Il docblock della classe prometteva inoltre «la stessa
decisione delle Action Nova», che da questo lavoro non sarebbe più stato vero. Trovato dalla review
finale sull'intero branch, non dalle review per task: nessuna di quelle guardava quel file.

### Un test preesistente rotto dalle traduzioni aggiunte
`AppConfigTaxonomyLabelsTest:49` asseriva che l'etichetta della tassonomia storica `activity` avesse
le sole lingue `it` ed `en`. Aggiungendo `"Activities"` in `de`, `es` e `fr` — come la regola del
repo impone — quella chiave, **condivisa** con l'etichetta nel `config.json` delle app, ne ha
acquisite tre. Le traduzioni non si tolgono: è il test a essere stato allineato, con `toMatchArray`,
restando significativo su italiano, inglese e tab.

### Due run di `pest` in parallelo falsano la suite
Vedi la decisione del 24/11: 32 fallimenti su un run sovrapposto contro 1 su quello isolato.

## Decisioni

### 2026-11-24 — il dato è una relazione del sentiero, non una forma del payload
- **Decide:** dev
- **Perché:** la prima stesura dell'overview, scritta in tag-mode, progettava il lavoro come una
  fusione di due campi di `api_data`. Il dev ha corretto l'impianto: `api_data` è il grezzo che il
  SUS consegna, l'istanza è in sostanza una bozza di `EcTrack`, e il dato va modellato sul sentiero.
- **Scartato:** fondere `activities` e `difficulty` in un array di oggetti con un flag `primary`
  (la forma descritta nel ticket fino al 24/11), perché avrebbe reso il payload il luogo del dato.

### 2026-11-24 — la primaria è una famiglia di tassonomia, non un flag sul pivot
- **Decide:** dev
- **Perché:** le colonne del pivot `taxonomy_activityables` non sono raggiungibili da nessuna
  schermata di Nova — `duration_forward` e `duration_backward` ci stanno da sempre e nessuna Resource
  le scrive — e il pannello di attach delle attività è stato tolto di proposito. Una famiglia di
  `TaxonomyTerm` a scelta singola usa invece il `Choice` già generato per ogni famiglia.
- **Scartato:** una colonna `is_primary` sul pivot, un `order` con la convenzione «il primo è il
  primario», una colonna FK su `ec_tracks`.

### 2026-11-24 — niente storico dei cambiamenti
- **Decide:** dev
- **Perché:** `trail_status_events` reggerebbe la primaria senza modifiche, ma registrare chi la
  cambia avrebbe senso solo facendolo per ogni tassonomia del sentiero: la primaria non è più
  speciale delle altre. Cadono con questa decisione l'Action di cambio e il tab dedicati, che il
  precedente della percorribilità ha.
- **Scartato:** riusare `TrailStatusService::set()` come punto unico di scrittura.

### 2026-11-24 — l'installer copia i nomi dalle attività esistenti
- **Decide:** dev
- **Perché:** in forestas le attività non si chiamano come il loro identifier (`hiking` è «Alte
  vie»): nomi fissi nel package darebbero al termine della primaria un nome diverso da quello che la
  stessa traccia mostra sotto le attività.

### 2026-11-24 — `primary_activity` è obbligatorio nel payload
- **Decide:** dev, su fonte verificata
- **Perché:** la mail di Alessio Saba del 20/11/2026 risponde alla domanda di Engineering — posta al
  negativo, «confermi che NON è prevista una priorità» — con «si, ogni sentiero **ha** una
  percorribilità PRIMARIA, e poi possono esserne aggiunte altre». Non «può avere».
- **Scartato:** campo facoltativo, che avrebbe evitato il 422 sui client a metà strada ma
  disatteso la richiesta.

### 2026-11-24 — la stima non si scrive su Orchestrator
- **Decide:** dev
- **Perché:** il dev ha scelto di saltare la fase. La stima dell'agente cieco era comunque
  **2,72h misurate di pianificazione + 4,1h stimate = 6,8h**, e resta qui come riferimento:
  `estimated_hours` del ticket non viene valorizzato.

### 2026-11-24 — un solo run di pest alla volta
- **Decide:** agente, durante l'esecuzione
- **Perché:** due run di `pest` sovrapposti sul database `wm_package` — uno in background e uno
  lanciato da chi stava scrivendo i test — producono `QueryException` e `DeadlockException` su test
  che non c'entrano nulla. Nel run della cartella `TrailRegistry` sono risultati 5 fallimenti, di cui
  **uno solo reale**. Il database di test è uno, e le transazioni dei due run si bloccano a vicenda.
- **Come si evita:** le suite lunghe si lanciano in background, ma una per volta, e nessun altro
  deve lanciare test finché quella non è finita.

### 2026-11-24 — le scale di difficoltà in pagina ci sono già, ma non sono confermate
- **Decide:** agente, su verifica
- **Perché:** l'overview dice fra le cose fuori scope che «l'elenco dei valori nella documentazione
  resta incompleto finché la tabella non arriva». Scrivendo il Task 7 è emerso che non è così: la
  prosa di `config/scramble.php` elenca già i livelli di mountain bike (`TC`, `MC`, `BC`, `OC`, `EC`)
  e di ippovia (`easy`, `medium`, `hard`), e coincidono con i termini a database. Quello che manca
  non è l'elenco, ma la **conferma** di quei valori nel nuovo regolamento, che Alessio Saba aveva
  promesso e non ha mandato. La frase dell'overview va letta così, e alla chiusura si corregge.

### 2026-11-24 — le istanze già in compilazione non si potranno più trasmettere
- **Decide:** da comunicare al dev e al cliente, non risolvibile nel codice
- **Perché:** `primary_activity` entra fra i campi obbligatori, e quindi in `requiredFieldNames()`,
  che `TrailApplication::missingForConfirmation()` usa per dire cosa manca. Ma `api_data` si scrive
  **una volta sola, alla creazione** (`writeApiData()`, chiamato solo da `StoreApplicationController`).
  Un'istanza creata prima del rilascio e rimasta `under_compilation` riceverà quindi `422` con
  `missing: ["primary_activity"]` per sempre: l'unica via è ricrearla.
- **Perché non si risolve qui:** su forestas non esiste produzione e dev/UAT si azzerano, quindi non
  blocca nessuno. Scriverlo nel messaggio di rilascio a Engineering costa una riga; scoprirlo da soli
  costa a loro mezza giornata.

## Follow-up

- **`attachTrailType()` fallisce in silenzio e la sua variabile d'ambiente ha un nome fuorviante.**
  Quando `WM_TRAIL_REGISTRY_TRAIL_TYPE_IDENTIFIER` non è configurata o il termine non esiste, logga
  un warning e prosegue: in locale la variabile non c'è e i sentieri approvati nascono senza tipo di
  tracciato, quindi fuori da ogni layer, senza che nessuno se ne accorga. Il nome inoltre dice
  «tipo» mentre il valore è un'attività, ripete `trail` due volte e tace sull'effetto vero, che è
  l'appartenenza al layer. Merita un ticket suo.
- **`WalkabilityTaxonomyInstaller::enableForExistingApps()` accende per le tracce anche le famiglie
  che erano accese solo per i POI.** Legge tutte le tassonomie dell'app (`$already`) e le ripassa a
  `syncTaxonomiesForModel('EcTrack', …)`: una famiglia `["EcPoi"]` arriva così a due modelli su due e
  il pivot salva `models = null`, cioè «tutti i contenuti». In locale non si manifesta — non
  esistono famiglie solo-POI — ma su un altro shard sì, e riguarda oc:8989.
  **Attenzione a come si corregge:** passare il solo id della famiglia nuova, che è la correzione
  che sembra ovvia, è molto peggio del difetto. `syncTaxonomiesForModel` toglie il modello da ogni
  tassonomia dell'app e lo rimette solo a quelle passate; una tassonomia che resta con l'elenco
  vuoto viene esclusa dal `sync` e quindi **staccata**. Simulato sui dati locali: su forestas
  staccherebbe tutte e dodici le famiglie accese per le tracce. La regola giusta è passare le
  tassonomie che hanno già `EcTrack` o `models` nullo, più la famiglia nuova — è quella adottata qui
  in `PrimaryActivityTaxonomyInstaller`, con tre test che coprono i tre casi.
- **Un commento che dice il falso in `WalkabilityTaxonomyInstaller.php:66`:** «`is_system` non e'
  fillable: un `fill()` lo scarterebbe in silenzio». In realtà `is_system` **è** in `$fillable`
  (`src/Models/TaxonomyTerm.php:51`). L'assegnazione separata funziona lo stesso, ma la motivazione è
  inventata, ed era stata copiata anche qui — corretta nel nostro installer, dove ora dice il motivo
  vero (senza `is_system` le app create dopo non ricevono la famiglia, perché `AppObserver` accende
  solo quelle di sistema). Nel precedente resta: riguarda oc:8989.
- **Tre nomi diversi per la stessa attività, in tre posti.** L'identifier `hiking` è «Alte vie»
  nella `TaxonomyActivity` a database (quindi in Nova), «Escursionismo» nella tabella del campo 36
  della pagina delle API (`forestas/config/scramble.php`), e «Camminate giornaliere» nei filtri del
  sito WordPress (`wp-forestas/themes/forestas-child/inc/search.php`, dove un commento li dichiara
  «provvisori finché non arrivano con oc:8932»). Lo stesso vale per le altre attività. Tutto
  preesistente a questo lavoro, che non le riallinea: cambiare la tabella della pagina cambia ciò che
  Engineering legge, ed è una decisione da prendere col cliente.
- **Le app create dopo accendono le famiglie di sistema per tutti i contenuti**, POI compresi:
  `AppObserver` chiama `syncWithoutDetaching` senza `models`. Vale già per `walkability-status`,
  quindi non è introdotto qui, ma su uno shard con più app si vedrà.

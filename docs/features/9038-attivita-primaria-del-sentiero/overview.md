> Ticket: oc:9038
> Stato: chiusa il 2026-11-24 · approvata il 2026-11-24

# Attività primaria del sentiero, decisa all'approvazione dell'istanza

## Cosa cambia

Un sentiero acquista una **attività primaria**: una sola fra le sette attività, scelta da un
operatore Forestas nel momento in cui approva l'istanza e il sentiero nasce.

Il percorso del dato attraversa tre stati distinti, che non si sovrappongono:

| stato | dove vive | chi lo scrive | cosa significa |
|---|---|---|---|
| **proposta** | `trail_applications.api_data` | il SUS, alla creazione | cosa ha dichiarato il richiedente |
| **decisione** | `trail_applications.properties` | l'Action di approvazione | cosa ha deciso Forestas, e quando |
| **stato corrente** | relazioni dell'`EcTrack` | l'approvazione, poi Nova | cosa vale adesso, e cambia nel tempo |

Nel payload del canale SUS compare un campo nuovo, `primary_activity`, che porta l'identifier di
una delle attività dichiarate:

```
activities: ["hiking", "mtb"]
difficulty: {"hiking": "E", "mtb": "MC"}
primary_activity: "hiking"
```

All'approvazione, l'Action mostra all'operatore le select delle attività — primaria e difficoltà —
**prefillate** con quanto proposto dal SUS, e lui conferma o cambia. Quello che sceglie diventa una
relazione del sentiero e resta fissato nelle `properties` dell'istanza insieme a `approved_at`.

L'attività primaria nasce come **famiglia di tassonomia** `primary-activity`, a scelta singola, con
un termine per attività (`primary-activity-hiking`, `primary-activity-mtb`, …).

## Perché

Forestas lo chiede. Alessio Saba, e-mail del 20/11/2026 in risposta al quesito 13 di Engineering:
«si, ogni sentiero ha una percorribilità PRIMARIA, e poi possono esserne aggiunte altre». Alessio
Piccioli l'ha confermato a Engineering nella call di chiusura del 23/11/2026: «ci dovete dire qual è
l'attività primaria», e sulla forma «faremo una modifica alle API in modo tale che […] ci possa
essere una e una sola delle attività che vengono passate».

Engineering sta implementando la maschera del SUS a valle di questa API, e il campo è uno dei punti
che attendono la versione definitiva della documentazione.

Il dato però **non è del SUS**: quello che il canale consegna è una proposta del richiedente, e
Forestas in istruttoria può decidere diversamente. Per questo la decisione si prende
all'approvazione e non alla ricezione.

## Punto di partenza

**Il concetto non esiste.** Una ricerca di `primary|principale|is_main` nel dominio `TrailRegistry`
non dà alcun risultato, e non c'è in tutto il package un attributo di pivot che marchi un elemento
come principale: gli unici attributi esistenti sono `order` su `ec_poi_ec_track`,
`duration_forward`/`duration_backward` su `taxonomy_activityables` e `parent_id`/`properties` su
`taxonomy_termables`.

**Le attività dell'istanza stanno solo nel payload.** `ApplicationSchema.php:86-87` dichiara due
campi JSON senza gerarchia: `activities` con `['required','array','min:1']` e `difficulty` con
`['nullable','array', new DifficultyMatchesActivities]`. Le sette attività ammesse sono in
`ApplicationSchema::ACTIVITIES`. Il controllo incrociato fra i due campi — nessuna regola di Laravel
vede due campi insieme — è in `StoreApplicationRequest::withValidator():148-161`.

**`DifficultyMatchesActivities` è il precedente da imitare.** La famiglia di difficoltà di
un'attività non si ricava dal nome ma da una mappa dichiarata, `ACTIVITY_FAMILIES:55-63`, perché due
righe non seguono lo schema: `trekking` e `hiking` condividono `hiking-difficulty`, e
`accessible-trails` punta a `inclusivity-difficulty`. I livelli non sono nel codice: sono termini
figli con identifier `<famiglia>-<livello>`, e la Rule verifica il legame cercando quell'identifier
*e* controllando che il padre sia la famiglia (`levelBelongsToFamily():114-119`).

**Il sentiero porta già attività e difficoltà, in due tabelle diverse.** `EcTrack` usa
`TaxonomyAbleModel`: le attività sono `taxonomyActivities()`, un `morphToMany(TaxonomyActivity)` su
`taxonomy_activityables`; le difficoltà sono termini in `taxonomy_termables`. Sul database locale:
761 `EcTrack`, tutti con almeno un'attività, **663 con più di una** (fino a 5); 750 con almeno una
difficoltà, 103 con più di una. Il legame fra le due cose passa dalla famiglia del termine, non da
una riga che le unisce.

**Le attività dell'istanza non arrivano al sentiero.** `createTrackFromApplication():1110` copia
nome, app, utente, `properties` e geometria; `api_data` è escluso di proposito. L'unica attività che
il sentiero riceve è quella di `attachTrailType():1148`, che non è una delle dichiarate ma il tipo
di tracciato — `trail`, `itinerary` o `walk`, le tre righe di `taxonomy_activities` che attività non
sono e che decidono in quale layer il sentiero finisce (tracce con `trail`: 610; tracce nel layer
«Sentieri»: 610).

**In Nova le tassonomie si governano con un campo `Choice`** generato da `BuildsTaxonomyFields`, uno
per famiglia, con la molteplicità presa da `single_choice`. Il suo `fillUsing` fa solo attach e
detach delle differenze e non tocca attributi di pivot: infatti `duration_forward` e
`duration_backward`, che sul pivot ci sono da sempre, **non sono raggiungibili da nessuna schermata
di Nova**. Il pannello di attach delle attività è stato tolto di proposito
(`src/Nova/EcTrack.php:92`).

**L'Action di approvazione esiste e non ha campi.** `ApproveTrailApplication::fields()` restituisce
`[]`. Nella stessa cartella c'è però il precedente che serve: `ReplaceTrailCodeNumber` risale
all'istanza selezionata dentro `fields()` con
`$request->resourceId ?? $this->applicationIdFromResources($request)`, e usa
`Select::…->dependsOn('number', fn (…) => $field->options(…))` per far dipendere una select da
un'altra.

**La famiglia di tassonomia si installa da una migration del dominio.**
`WalkabilityTaxonomyInstaller` (oc:8989) crea famiglia e termini con `single_choice = true`,
`is_system = true` e `properties.tab`, ed è idempotente. Il suo docblock avverte che creare la
famiglia non basta: senza il legame in `app_taxonomy_term` il dato «si inserisce, si vede in Nova e
non esce in API, senza un errore da nessuna parte», e per questo chiama `enableForExistingApps()`.

**La cardinalità non è un vincolo del database.** L'unico indice unique su `taxonomy_termables` è
`(taxonomy_term_id, termable_type, termable_id)`: impedisce di legare due volte lo *stesso* termine,
non due termini *diversi* della stessa famiglia. Lo dice il docblock di `TrailStatusService:23-25` —
«`single_choice` è una convenzione applicativa, non un vincolo del database» — e chi la fa rispettare
è il `fillUsing` del `Choice`, con `->when($singleChoice, fn ($ids) => $ids->take(1))`
(`BuildsTaxonomyFields:573`). Sul database locale nessun `EcTrack` viola la convenzione; l'unico
contenuto con più termini di una famiglia a scelta singola è un `Layer`, dove la scelta multipla è
voluta perché un layer deve poter cercare più valori.

**Una data di approvazione non esiste.** `ec_tracks` ha solo `created_at` e `updated_at`;
`trail_applications` ha `id, user_id, source, status, name, properties, geometry, created_at,
updated_at, api_data, rejection_reason`. Dentro `properties` oggi ci sono `dem_data`, `manual_data` e
`app_id`; `dem_status` non è scritto da nessuna parte, è calcolato in risposta.

## Requisiti

- <a id="r1"></a>**R1** — Il canale SUS esige nel payload l'attività primaria proposta — è un campo
  obbligatorio — e rifiuta una proposta in cui quell'attività non è fra quelle dichiarate, o non è
  fra le sette ammesse.
  - Verifica: [StoreApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/StoreApplicationRequestTest.php) › `rifiuta una primaria che non e fra le attivita dichiarate`
  - Verifica: [ApplicationSchemaTest.php](../../../tests/Unit/TrailRegistry/ApplicationSchemaTest.php) › `esige la attivita primaria`
- <a id="r2"></a>**R2** — Un sentiero porta una e una sola attività primaria. Il vincolo è lo stesso
  che vale per ogni famiglia a scelta singola del package — una convenzione applicativa, fatta
  rispettare dal campo che la scrive — non un vincolo del database.
  - Verifica: [PrimaryActivityTaxonomyInstallerTest.php](../../../tests/Feature/TrailRegistry/PrimaryActivityTaxonomyInstallerTest.php) › `crea la famiglia a scelta singola nel tab delle attività`
  - Verifica: [ApproveTrailApplicationTest.php](../../../tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php) › `lascia una sola primaria anche se la si riscrive`
- <a id="r3"></a>**R3** — Approvando un'istanza, l'operatore Forestas sceglie le attività del
  sentiero e quale di esse è la primaria. Le scelte si presentano già compilate con quanto proposto
  dal SUS, quando c'è, e quello che l'operatore conferma prevale sulla proposta. La primaria è
  obbligatoria: senza, l'approvazione non parte.
  - Verifica: [ApproveTrailApplicationFieldsTest.php](../../../tests/Feature/TrailRegistry/ApproveTrailApplicationFieldsTest.php) › `esige la primaria: senza, l approvazione non parte`
  - Verifica: [ApproveTrailApplicationFieldsTest.php](../../../tests/Feature/TrailRegistry/ApproveTrailApplicationFieldsTest.php) › `prefilla le select con quanto il SUS ha proposto`
  - Verifica: [ApproveTrailApplicationFieldsTest.php](../../../tests/Feature/TrailRegistry/ApproveTrailApplicationFieldsTest.php) › `prefilla il livello di ogni famiglia traducendo l attivita`
  - Verifica: [ApproveTrailApplicationTest.php](../../../tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php) › `non tocca la proposta ricevuta`
- <a id="r9"></a>**R9** — Il sentiero creato dall'approvazione porta le attività confermate
  dall'operatore, non solo la primaria: non nasce con una primaria che non è fra le sue attività.
  - Verifica: [ApproveTrailApplicationTest.php](../../../tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php) › `porta sul sentiero le attivita confermate e la primaria`
  - Verifica: [ApproveTrailApplicationTest.php](../../../tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php) › `rifiuta una primaria che non e fra le attivita confermate`
- <a id="r4"></a>**R4** — L'istanza conserva la decisione presa in approvazione e la data in cui è
  stata presa, distinte dalla proposta ricevuta, che resta leggibile e immutata.
  - Verifica: [ApproveTrailApplicationTest.php](../../../tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php) › `fissa nella domanda la decisione e il momento in cui e stata presa`
  - Verifica: [ApproveTrailApplicationTest.php](../../../tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php) › `la decisione resta sulla domanda e non finisce nelle properties del sentiero`
- <a id="r5"></a>**R5** — Il sentiero creato dall'approvazione porta l'attività primaria come dato
  proprio, che un operatore può cambiare in seguito dall'interfaccia del sentiero, come ogni altra
  tassonomia.
  - Verifica: [PrimaryActivityTaxonomyInstallerTest.php](../../../tests/Feature/TrailRegistry/PrimaryActivityTaxonomyInstallerTest.php) › `accende la famiglia sulle app esistenti solo per le tracce`
  - Verifica: a mano — agente, 2026-11-24: migration eseguita sul database locale; la famiglia compare con `tab = Activities`, `single_choice`, e il `Choice` nasce dal meccanismo generico di `BuildsTaxonomyFields`
- <a id="r6"></a>**R6** — Il SUS, rileggendo l'istanza dopo l'approvazione, riceve sia la proposta
  che aveva inviato sia la decisione di Forestas, in due campi distinti.
  - Verifica: [ShowApplicationEndpointTest.php](../../../tests/Feature/TrailRegistry/ShowApplicationEndpointTest.php) › `legge dall endpoint la decisione scritta dall approvazione vera, con le sue chiavi`
- <a id="r7"></a>**R7** — La documentazione delle API descrive il campo nuovo, i valori che accetta
  e la regola che lo lega alle attività dichiarate.
  - Verifica: forestas [tests/Feature/Sus/SusApiDocumentationTest.php](../../../../tests/Feature/Sus/SusApiDocumentationTest.php) › `spiega la attivita primaria: obbligatoria e fra quelle dichiarate in activities`
- <a id="r8"></a>**R8** — Su uno shard che accende il Catasto Sentieri, migrare basta: la famiglia
  dell'attività primaria e i suoi termini nascono con il dominio, e il dato esce in API senza
  passaggi manuali.
  - Verifica: [PrimaryActivityTaxonomyInstallerTest.php](../../../tests/Feature/TrailRegistry/PrimaryActivityTaxonomyInstallerTest.php) › `è idempotente: due installazioni non duplicano nulla`
  - Verifica: a mano — agente, 2026-11-24: migration pubblicata ed eseguita su forestas; confronto prima/dopo delle famiglie accese, una sola riga aggiunta e nessuna delle 13 esistenti toccata; `wm-package:publish-missing-migrations --dry-run` esce 0

## Rischi

**Una traduzione che fallisce in silenzio.** `attachTrailType()` è l'esempio da non imitare: quando
l'identifier non è configurato o il termine non esiste, logga un warning e prosegue. In locale è
quello che accade — `WM_TRAIL_REGISTRY_TRAIL_TYPE_IDENTIFIER` non è nel `.env` e i sentieri
approvati nascono senza tipo, quindi fuori da ogni layer, senza che nessuno se ne accorga. La
scrittura della primaria all'approvazione non deve comportarsi così. Mitigazione: la validazione in
ingresso di [R1](#r1) garantisce che il valore sia una delle sette, e l'installer di [R8](#r8)
garantisce che il termine esista; se nonostante questo non si trova, l'approvazione deve fermarsi
invece di creare un sentiero monco.

**La famiglia accesa per il modello sbagliato.** `App::syncTaxonomiesForModel()` salva
`models = null` — che significa «tutti i contenuti» — quando il numero di modelli passati eguaglia
`$everyModel = ['EcTrack', 'EcPoi']`. Accendere la famiglia per due modelli la farebbe comparire
anche sui POI. Mitigazione: si accende solo per `EcTrack`, come fa l'installer della percorribilità.
Resta scoperto il caso delle app **create dopo**: con `is_system = true` l'`AppObserver` accende le
famiglie di sistema senza `models`, cioè per tutti i contenuti. Vale già oggi per
`walkability-status`, quindi non è un difetto introdotto qui, ma su uno shard con più app si vedrà.

**Il campo è obbligatorio, quindi la documentazione esce prima.** `primary_activity` entra fra i
campi richiesti — e quindi in `requiredFieldNames()`, che il canale espone — perché Alessio Saba ha
scritto che «ogni sentiero **ha** una percorribilità PRIMARIA», non che può averla. Dal rilascio in
poi una domanda senza quel campo viene rifiutata con 422, ed Engineering sta scrivendo la maschera
adesso: la pagina delle API ([R7](#r7)) va quindi pubblicata **prima** che la validazione arrivi su
un ambiente che il SUS interroga. È l'unica mitigazione possibile, perché accettare il vecchio
formato e il nuovo insieme vorrebbe dire rendere il campo facoltativo, cioè disattendere la
richiesta.

**Due nomi per la stessa cosa nella stessa scheda.** In forestas le attività non si chiamano come il
loro identifier: `hiking` è «Alte vie», `trekking` è «Dorsali escursionistiche a tappe (per
esperti)», `accessible-trails` è «Sentieri (accessibili) per tutti». Un installer che scrivesse nomi
fissi darebbe al termine della primaria un nome diverso da quello che la stessa traccia mostra sotto
le attività. Mitigazione: l'installer copia i nomi dalle `TaxonomyActivity` già presenti invece di
scriverli nel codice, così combaciano per costruzione su ogni shard.

**Le attività-tipo nelle select.** `taxonomy_activities` contiene anche `trail`, `itinerary` e
`walk`, che attività non sono: servono a decidere in quale layer il sentiero entra, e tutte le 761
tracce ne hanno una. Vanno escluse dalle select dell'Action, come il sito già fa a mano nei suoi
filtri.

**`properties` dell'istanza esce in API.** Viene copiata per intero sul sentiero, e il sentiero serve
le sue `properties` per intero nell'API e nel GeoJSON (oc:8960). Quanto scritto per [R4](#r4)
comparirà quindi anche lì. Per la data di approvazione è accettabile — è un'informazione pubblica —
ma vale la pena saperlo prima, non dopo.

**Il prefill dipende da un meccanismo non ovvio.** `fields()` di un'Action non riceve il modello:
l'istanza va risolta dalla request. Il precedente funzionante è `ReplaceTrailCodeNumber`, che
gestisce anche il caso in cui `resources` valga la stringa `"all"` — selezione multipla dall'index,
dove una singola istanza non c'è. Senza quella guardia, un `find()` su `"all"` fallisce.

### Rischi superati durante il lavoro

Il rischio sull'accensione della famiglia **si è avverato nella forma opposta a quella prevista**, ed
è chiuso: la correzione che sembrava ovvia — passare a `syncTaxonomiesForModel()` il solo id della
famiglia nuova — avrebbe **staccato dall'app tutte e dodici le famiglie accese per le tracce**,
perché quel metodo toglie il modello a ciascuna e il `sync` esclude quelle rimaste senza. La regola
corretta, con tre test e la verifica sui dati, è «quelle che hanno già `EcTrack` o `models` nullo,
più la famiglia nuova». Dettaglio e avvertenza in [notes.md](notes.md).

Il rischio sui **filtri dell'app** è invece risultato infondato ed è stato tolto: accendere una
famiglia non la rende un criterio di ricerca, perché i filtri sono elenchi scritti a mano in ogni
frontend.

### Rischi ancora aperti, e dove vivono adesso

- **Le istanze già in compilazione non si potranno più trasmettere**, perché `api_data` si scrive una
  volta sola alla creazione: va comunicato a Engineering nel messaggio di rilascio. Sta in
  [notes.md](notes.md), fra le decisioni.
- **`attachTrailType()` fallisce in silenzio** e la sua variabile d'ambiente ha un nome fuorviante:
  follow-up in [notes.md](notes.md), merita un ticket suo.
- **`WalkabilityTaxonomyInstaller::enableForExistingApps()` ha il difetto da cui siamo partiti**, con
  l'avvertenza su come *non* correggerlo: follow-up in [notes.md](notes.md), riguarda oc:8989.

## Out of scope

**Le scale di difficoltà di mountain bike e ippovia.** Per l'escursionismo vale la scala del Club
Alpino Italiano (T, E, EE, EEA); per le altre due la tabella del nuovo regolamento non è ancora
arrivata. I livelli continuano a leggersi dalle tassonomie, quindi la struttura regge comunque, ma
l'elenco dei valori nella documentazione resta incompleto finché la tabella non arriva.

**L'attività primaria sui 663 sentieri già accatastati** che hanno più di una attività: qui nasce il
dato, non si popola il pregresso.

**Il prefill degli altri campi dell'istanza.** Vale la regola che ogni dato ricevuto dal SUS con un
corrispondente sul sentiero vada proposto in approvazione, ma questo ticket porta solo i campi suoi:
attività, difficoltà e primaria. Restano ad altri ticket `from`/`to` (`properties->from`,
`properties->to`), `intermediate_stop` (`properties->via`), `last_survey_date` (la colonna
`forestas_survey_date`, che esiste solo sullo shard), i soggetti verso la relazione `enti` con il suo
`ruolo`, e `themes` verso `taxonomyThemes()`, oggi senza alcun termine a database. Restano fuori
anche i valori che il DEM calcola — distanza, dislivello e durate — che sul sentiero continuano a
venire dalla geometria e non da quanto dichiarato.

**Lo storico dei cambiamenti della primaria.** `trail_status_events` lo reggerebbe, ma registrare chi
cambia la primaria e quando avrebbe senso solo se lo si facesse per ogni tassonomia del sentiero: la
primaria non è più speciale delle altre, e non si introduce per lei sola un trattamento che le altre
non hanno. Per questo la primaria si cambia dal `Choice` come ogni altra famiglia, senza Action né
tab dedicati.

**Il difetto di `attachTrailType()`** — fallimento silenzioso e nome fuorviante della variabile
d'ambiente — che diventa un ticket suo.

## Moduli toccati

### Package `wm-package`

**Creati**
- `src/TrailRegistry/Services/PrimaryActivityTaxonomyInstaller.php` — crea la famiglia e i sette
  termini, con i nomi copiati dalle `TaxonomyActivity`, e la accende sulle app esistenti
- `src/TrailRegistry/Exceptions/InvalidTrailChoicesException.php` — ferma l'approvazione quando un
  termine manca o la primaria non è fra le attività confermate
- `database/migrations/trail_registry/zz_2026_11_24_000001_install_primary_activity_taxonomy.php.stub`
- `tests/Feature/TrailRegistry/PrimaryActivityTaxonomyInstallerTest.php` (10 test)
- `tests/Feature/TrailRegistry/ApproveTrailApplicationFieldsTest.php` (17 test)

**Modificati**
- `src/TrailRegistry/Schema/ApplicationSchema.php` — il campo `primary_activity` e `version()` a `'2'`
- `src/Http/Requests/TrailRegistry/StoreApplicationRequest.php` — il controllo incrociato
- `src/TrailRegistry/Nova/Actions/ApproveTrailApplication.php` — i campi prefillati e il passaggio
  delle scelte al service
- `src/TrailRegistry/TrailRegistryService.php` — `approve()` con il terzo parametro, `attachChoices()`
  e `withApprovalDecision()` dentro la transazione
- `src/TrailRegistry/Rules/DifficultyMatchesActivities.php` — `activityFamilies()` reso pubblico,
  perché la mappa attività→famiglia non si duplica
- `src/TrailRegistry/Models/TrailApplication.php` — l'annotazione della proprietà `properties`
- `src/Http/Controllers/Api/TrailRegistry/ShowApplicationController.php` — `approved_data`
- `src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php` — `#[BodyParameter]` ed esempi
- `config/wm-package.php` — `primary_activity_taxonomy`
- `docs/resources/TrailRegistry.md` — la documentazione d'uso del dominio
- `resources/lang/{de,en,es,fr,it}.json`
- quattro file di test del dominio, più `tests/Feature/AppConfigTaxonomyLabelsTest.php`, allineato
  perché le traduzioni nuove arricchiscono le etichette del `config.json`

### Shard `forestas`

- `app/Http/Controllers/Api/Sus/SusTestDecisionController.php` — il canale di prova passa le scelte
- `config/scramble.php` — la sezione del campo e quella su `submitted_data`/`approved_data`
- `resources/views/vendor/scramble/docs.blade.php` — il payload del pulsante di prova
- `database/migrations/2026_11_24_162631_zz_2026_11_24_000001_install_primary_activity_taxonomy.php`
  — pubblicata dal comando del package, identica allo stub
- `tests/Feature/Sus/SusApiDocumentationTest.php` (nuovo) e `tests/Feature/Sus/SusTestDecisionTest.php`

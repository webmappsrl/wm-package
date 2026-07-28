> Ticket: oc:8289

# Rendere parametrica l'action di traduzione automatica per includere il campo Not Accessible Message

## Come funziona oggi (as-is)

`TranslateModelAction` (`wm-package/src/Nova/Actions/TranslateModelAction.php`) è un'action Nova installata su 4 risorse (`SiHikingRoute`, `SiMTBRoute` in osm2cai2; `EcTrack`, `EcPoi` in wm-package). Flusso attuale:

1. L'admin seleziona una o più righe nell'Index Nova e lancia l'action "Translate Descriptions & Names".
2. `Action::handle()` itera i modelli selezionati; per ciascuno legge `properties` e calcola le lingue mancanti (`getMissingLocales()`) confrontando i valori già presenti su `description`/`name` contro le 4 lingue target (`en`, `de`, `fr`, `es`), **hardcoded** sia nell'array dei campi (`['description', 'name']`) sia nel `PROMPT` (regole scritte esplicitamente solo per questi due campi).
3. Se non manca nulla per nessuna delle due, il modello viene saltato (`skipped++`), altrimenti viene dispatchato `TranslateModelJob` con il modello e le lingue mancanti.
4. `TranslateModelJob::handle()` **traduce solo ciò che manca**: per ogni lingua ancora mancante fa una sola chiamata OpenAI, solo con i campi ancora vuoti per quella lingua (mai i campi già tradotti). Applica le traduzioni e fa `saveQuietly()`.

Questo comportamento di "traduci solo il mancante" **esiste già** — non è una modifica di questo ciclo. È il punto chiave emerso rileggendo insieme il codice in revisione: elimina la necessità di qualunque selezione manuale a runtime (vedi sotto).

## Cosa cambia

`name` e `description` **restano hardcoded** nel codice (comuni a quasi tutti i modelli, nessun motivo per parametrizzarli). Cambia solo la possibilità di aggiungere **campi extra** oltre a questi due, senza toccare il codice della action/job ogni volta:

- Il costruttore di `TranslateModelAction` accetta un nuovo parametro — un dizionario `campo => regola di traduzione` (nome proposto: `$additionalFieldRules`, da confermare in review; esclusi nomi generici tipo "Fields") — **default `[]`** (comportamento identico a oggi per chi non lo passa).
- `TranslateModelJob` riceve lo stesso dizionario e lo tratta esattamente come `description` (letto/scritto sotto `properties->{campo}`, mai via Spatie `getTranslation()`/`setTranslation()`, riservato solo a `name`). Per ogni campo extra selezionato applica la sua regola specifica nel prompt; se il campo non ha una regola propria, vale il fallback generico ("traduzione libera preservando tono e significato" — la stessa regola già usata oggi per `description`).
- **Nessuna checkbox o UI aggiuntiva in Nova.** La UI a checkbox opt-in proposta in una bozza precedente di questo overview è stata scartata in revisione: dato che il job traduce già solo il mancante, un toggle manuale per-lancio non aggiungerebbe nessuna garanzia in più (nessun rischio di "ritradurre per errore" da evitare) — solo complessità e superficie di test.
- L'action va rinominata (label Nova, non la classe): da "Translate Descriptions & Names" a un nome **dinamico per risorsa**, "Translate {Label} Contents". **Precisazione tecnica** (emersa in Fase: challenge — la formulazione "risolto dal modello selezionato" della bozza precedente era tecnicamente errata): `name()` in Nova viene chiamato senza parametri, quando Nova serializza il menu delle azioni disponibili, **prima** che l'admin selezioni una riga — non esiste quindi un modello da cui leggere la label a runtime. Il meccanismo corretto: ogni file Resource passa esplicitamente la **classe** della risorsa (FQCN, non un'istanza/modello) al costruttore, che chiama staticamente `::singularLabel()` una volta, al momento della registrazione dell'azione in quel file. Risultato concreto, uguale per ogni file:
  - `wm-package/src/Nova/EcTrack.php`: `new TranslateModelAction(EcTrack::class)` → "Translate EC Track Contents"
  - `wm-package/src/Nova/EcPoi.php`: `new TranslateModelAction(EcPoi::class)` → "Translate EC Poi Contents"
  - `app/Nova/SiHikingRoute.php`: `new TranslateModelAction(SiHikingRoute::class, ['not_accessible_message' => <regola-default>])` → "Translate Route Contents"
  - `app/Nova/SiMTBRoute.php`: `new TranslateModelAction(SiMTBRoute::class, ['not_accessible_message' => <regola-default>])` → "Translate Cycle Touring Contents"

  Deciso esplicitamente in call: **non va hardcodato "EC"** come stringa fissa (l'action potrebbe girare in futuro anche su tassonomie o altri modelli non-EC) — passare la classe e leggerne `singularLabel()` risolve il problema per qualunque modello futuro senza toccare il codice dell'action.
- Separatamente, il "cosa" viene tradotto (i campi, non il modello) va nel **confirmText**: un messaggio custom (nuovo, non il default condiviso da tutte le action Nova) costruito nel costruttore concatenando una frase base tradotta ("Verranno aggiornate le traduzioni **mancanti** per i seguenti campi:") con l'`implode()` dei campi effettivamente configurati per quella risorsa (`name`, `description` + chiavi del dizionario extra) — usando il **nome del campo così com'è nel DB** (es. `description`, `not_accessible_message`), non un'etichetta umanizzata per campo: esplicitamente richiesto di non perdere tempo lì. Nome del modello (nel titolo dell'action) e lista campi (nel confirmText) sono due informazioni distinte, non alternative.

Per questo ciclo, `not_accessible_message` è l'unico campo extra abilitato, con la regola di fallback generica (nessuna regola dedicata). Abilitato su `SiHikingRoute`/`SiMTBRoute` (`app/Nova/SiHikingRoute.php`, `app/Nova/SiMTBRoute.php`, la richiesta originale del cliente) **e anche su `wm-package/src/Nova/EcTrack.php`** (decisione presa in esecuzione, non nella bozza originale di questo overview): `EcTrack` ha lo stesso campo `properties->not_accessible_message` dichiarato `$translatable` (`wm-package/src/Models/EcTrack.php:75`), quindi tecnicamente idoneo, ed estendere anche lì evita che EC Track e SiHikingRoute (che condividono lo stesso dato sullo stesso record in osm2cai2) abbiano un comportamento di traduzione automatica disallineato. **Non** esteso a `wm-package/src/Nova/EcPoi.php`, che resta sul comportamento di default (nessun campo extra) — nessuna richiesta cliente lo riguarda.

## Perché

Richiesta di Eleonora (cliente): l'action su `SiHikingRoute` deve tradurre automaticamente anche "Not Accessible Message" (l'alert rosso mostrato in app/sito quando un percorso non è accessibile), oggi tradotto manualmente lingua per lingua.

In revisione (call con Giuseppe/Rubens) è stato corretto tre volte l'approccio:

1. Prima correzione (call SCRUM 22/07): non limitarsi ad aggiungere `not_accessible_message` alla lista hardcoded — risolverebbe il sintomo mantenendo il debito (ogni futuro campo richiesto = nuova PR).
2. Seconda correzione (call di revisione overview, round 1): la prima bozza di questo documento proponeva di rendere parametrici **anche** `name`/`description` con selezione a checkbox runtime. Rileggendo insieme il codice di `TranslateModelJob`, il team ha verificato che la skip-logic su lingue mancanti **esiste già** — quindi non c'è bisogno di una UI di selezione: basta un parametro al costruttore che dichiara quali campi extra quella risorsa può tradurre, con le rispettive regole. `name`/`description` restano hardcoded perché comuni a (quasi) tutti i modelli.
3. Terza correzione (call di revisione overview, round 2): scartato solo l'hardcoding letterale di "EC" nel nome dell'action (es. "Translate EC Contents") — l'action potrebbe girare in futuro anche su tassonomie o altri modelli non-EC. Confermato invece il nome dinamico costruito da `Resource::singularLabel()` (già esistente su ogni risorsa Nova), che generalizza automaticamente a qualunque modello futuro senza hardcoding. Separatamente, il dettaglio di quali *campi* vengono tradotti va nel `confirmText`, con i nomi presi letteralmente dal DB (niente etichette umanizzate per campo, per non perdere tempo su un dettaglio non richiesto) — informazione distinta dal nome dell'action, non alternativa ad essa.

## Requisiti

- [ ] `TranslateModelAction::__construct()` accetta la **FQCN della Resource** (es. `SiHikingRoute::class`) come primo parametro — usata per chiamare staticamente `::singularLabel()` e costruire `name()`, mai un'istanza di modello — più il dizionario (`campo => regola`) per i campi extra oltre a `name`/`description`; dizionario default `[]` (comportamento di traduzione identico a oggi per chi non lo passa)
- [ ] Il parametro esistente sulle lingue target (default 4 lingue) resta invariato, non va rotto dall'aggiunta del nuovo parametro
- [ ] `TranslateModelAction::handle()` calcola le lingue mancanti anche sui campi extra (stessa logica già usata per `description`/`name`, generalizzata)
- [ ] `TranslateModelJob` riceve il dizionario campi-extra e lo passa al job dispatchato; tratta ogni campo extra come `properties->{campo}` (mai Spatie `getTranslation`/`setTranslation`, riservato a `name`)
- [ ] Il `PROMPT` diventa costruito dinamicamente: regola hardcoded per `name`, regola hardcoded per `description`, regola specifica (o fallback generico) per ciascun campo extra effettivamente da tradurre in quella chiamata
- [ ] `not_accessible_message` usa il fallback generico (nessuna regola dedicata)
- [ ] Il costruttore lancia un'eccezione esplicita se il dizionario dei campi extra contiene una chiave `name` o `description` (collisione con i campi hardcoded) — fail-fast in fase di costruzione, non un fallimento silenzioso a runtime
- [ ] `TranslateModelAction::name()` ritorna "Translate {Label} Contents", dove `{Label}` è `$resourceClass::singularLabel()` (chiamata statica sulla FQCN passata al costruttore, risolta una volta alla registrazione dell'action, non a runtime sul modello selezionato) — mai una stringa hardcoded tipo "EC", generalizza a qualunque modello futuro
- [ ] Il costruttore costruisce un `confirmText` **custom** (nuovo testo specifico per questa action, non il default condiviso `Are you sure you want to run this action?` usato da altre action Nova) concatenando una frase base tradotta con `implode(', ', $campi)`, dove `$campi` è l'unione di `['name', 'description']` + le chiavi del dizionario extra, usando i nomi campo così come sono nel DB (nessuna etichetta umanizzata per campo)
- [ ] Aggiungere le chiavi di traduzione it/en mancanti per tutte le stringhe dell'action (il template del nome dinamico, la frase base del `confirmText`, i due messaggi `Action::message(...)`) in `wm-package/resources/lang/{it,en}.json` — debito preesistente, risolto in questo ciclo perché la label viene comunque cambiata
- [ ] `app/Nova/SiHikingRoute.php` e `app/Nova/SiMTBRoute.php` istanziano `new TranslateModelAction(static::class, ['not_accessible_message' => <regola-default>])` → "Translate Route Contents" / "Translate Cycle Touring Contents"
- [ ] `wm-package/src/Nova/EcTrack.php` istanzia `new TranslateModelAction(static::class, ['not_accessible_message' => TranslateModelJob::DEFAULT_FIELD_RULE])` (stesso campo extra di `SiHikingRoute`/`SiMTBRoute`, deciso in esecuzione) → "Translate EC Track Contents"
- [ ] `wm-package/src/Nova/EcPoi.php` istanzia `new TranslateModelAction(static::class)` (nessun campo extra → comportamento di traduzione identico a oggi) → "Translate EC Poi Contents"
- [ ] Test nuovi (nessuna copertura pregressa trovata): Feature test sull'Action (campo extra passato → job dispatchato con dizionario corretto) + Unit test sul Job con `Http::fake()` (pattern già in uso in `wm-package/tests/Unit/AnalyticsServiceTest.php`), nessuna chiamata reale a OpenAI

## Rischi

- **Contenuto di sicurezza tradotto senza revisione umana (accettato).** `not_accessible_message` è un messaggio di sicurezza (alert rosso "percorso non accessibile" su app/sito), tradotto con la regola generica di fallback e pubblicato subito via `saveQuietly()` — nessuna revisione umana, nessun controllo semantico oltre `looksLikeRefusal()` (intercetta solo rifiuti espliciti del modello, non una traduzione plausibile ma sbagliata), nessun flag di provenienza AI, nessun rate-limiting per il primo lancio bulk (fino a 4 chiamate OpenAI sincrone per record, nessun retry/backoff nel job). **Mitigazione**: nessuna in questo ciclo — questo comportamento esiste già oggi per `name`/`description`, non è introdotto da oc:8289, solo esteso a un terzo campo. Fuori scope costruire uno step di revisione/flag/rate-limiting (refactor ben più ampio del job, non richiesto da cliente o team). Rischio accettato e documentato qui per visibilità in review.

- **Cambio silenzioso su repo condivisi (maphub, camminiditalia) — aggravato in esecuzione.** `TranslateModelAction`/`TranslateModelJob` vivono in `wm-package` (submodule condiviso), usati anche da `maphub` e `camminiditalia` su `EcTrack`/`EcPoi` (oggi pinnati allo stesso commit di osm2cai2). Cambiare `name()`/`confirmText` su queste classi condivise cambia il testo mostrato in produzione su quei due prodotti alla prossima loro `git submodule update` — una richiesta cliente su osm2cai2 si propaga silenziosamente ad altri prodotti. **In esecuzione questo rischio si è concretizzato oltre il testo**: `EcTrack.php` ora abilita anche la *traduzione automatica reale* di `not_accessible_message` (non solo un cambio di label), quindi al prossimo bump `maphub`/`camminiditalia` vedranno tradotto automaticamente via OpenAI un campo prima escluso, non solo un nome di action diverso. **Mitigazione**: nessuna azione di codice possibile da qui (non controlliamo il timing dei loro bump); segnalare esplicitamente a Rubens/Giuseppe nella PR — ora con enfasi maggiore, dato che l'impatto include comportamento (traduzione automatica di un nuovo campo), non solo testo — perché venga comunicato ai team maphub/camminiditalia al momento del prossimo bump.

- **Race condition su lanci ravvicinati dello stesso modello (accettato).** Se lo stesso record viene incluso in due lanci ravvicinati dell'action (doppio click, o due admin su selezioni sovrapposte), due `TranslateModelJob` per lo stesso modello leggono lo stesso stato "mancante", chiamano OpenAI in parallelo e fanno `saveQuietly()` in sequenza non coordinata — l'ultimo che scrive vince, perdendo silenziosamente le traduzioni dell'altro job (nessun lock ottimistico/pessimistico). **Mitigazione**: nessuna in questo ciclo — comportamento preesistente (nessun campo ha mai avuto un lock), non introdotto da oc:8289; cambierebbe la natura del job, fuori scope per un refactor mirato. Candidato per un ticket dedicato se in futuro si osservano traduzioni perse in produzione (da annotare in Fase: notes).

- **Rollback dei dati non banale (accettato).** Nessuna migration coinvolta (tutto in JSON `properties`), quindi nessun rollback di schema — ma proprio per questo non c'è un "prima" da ripristinare: i valori scritti da OpenAI sostituiscono un vuoto. Un rollback dei dati significa cancellare manualmente `properties->not_accessible_message->{en,fr,de,es}` record per record, senza modo di distinguere una traduzione AI da una corretta a mano nel frattempo (stesso problema di provenienza del rischio "sicurezza senza revisione" sopra). Nessun test e2e esiste oggi su questa action, quindi una decisione di rollback si baserebbe solo su segnalazioni manuali, non su CI che fallisce. **Mitigazione**: nessuna — conseguenza diretta delle altre decisioni già accettate in questa sezione, non un problema nuovo.

## Out of scope

- Abilitare `excerpt`/`difficulty` (altri 2 campi `$translatable` di `HikingRoute`/`EcTrack` oltre ai 3 già coperti) — escluso esplicitamente: solo i campi citati nella trascrizione (`name`, `description`, `not_accessible_message`) sono abilitati in questo ciclo. Si abilitano in futuro con una riga in più nel dizionario passato al costruttore, se richiesti
- Modifiche a `wm-package/src/Nova/EcPoi.php` — resta sul comportamento di default esistente (nessuna richiesta cliente lo riguarda)
- Rendere parametrici anche `name`/`description` — scartato esplicitamente in revisione, restano hardcoded
- Qualunque UI Nova di selezione campi a runtime (checkbox) — scartata in revisione: la skip-logic su lingue mancanti già esistente la rende superflua
- Meccanismo di "force retranslate" per sovrascrivere una traduzione già presente per una lingua già completa — non richiesto, la skip-logic su "locale mancante" resta invariata
- Rename concettuale del file/classe (es. per riflettere "traduce solo il mancante", tipo `TranslateMissingContentsAction`) — discusso ma esplicitamente non richiesto in questo ciclo ("non ve lo chiedo di farlo"); cambia solo la label Nova (`name()`), non il nome del file/classe
- Hardcoding letterale di "EC" (o di qualunque altro nome modello fisso) nel nome dell'action — scartato: il nome usa sempre `Resource::singularLabel()` dinamico, mai una stringa fissa

## Moduli toccati

- `wm-package/src/Nova/Actions/TranslateModelAction.php` — costruttore con dizionario campi-extra, `name()` dinamico via `singularLabel()`, `confirmText` custom via implode campi
- `wm-package/src/Jobs/TranslateModelJob.php` — prompt dinamico, gestione campi extra come `properties->{campo}`
- `wm-package/resources/lang/it.json`, `wm-package/resources/lang/en.json` — chiavi mancanti per le stringhe dell'action
- `wm-package/src/Nova/EcTrack.php` — istanzia l'action con `not_accessible_message` (deciso in esecuzione, non nella bozza originale)
- `app/Nova/SiHikingRoute.php` — istanzia l'action con `not_accessible_message`
- `app/Nova/SiMTBRoute.php` — istanzia l'action con `not_accessible_message`
- `wm-package/tests/Feature/Nova/Actions/TranslateModelActionTest.php` (nuovo)
- `wm-package/tests/Unit/Jobs/TranslateModelJobTest.php` (nuovo)

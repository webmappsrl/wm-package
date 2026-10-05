> Ticket: oc:8700

# Catasto Sentieri visibile solo ad Administrator ed Editor

> Questa overview copre la parte del **wm-package**. La Resource delle righe del registro
> catastale, che è specifica di forestas, è descritta nell'overview dello stesso ticket nel repo
> forestas: `docs/features/8700-resource-nova-filtrabile-per-le-righe-del-registro-catastale/overview.md`.

## Cosa cambia

Le Resource Nova del Catasto Sentieri — Istanze (`TrailApplication`), Registro dei codici
(`TrailRegistryCode`), Anomalie (`TrailRegistryAnomaly`) — diventano visibili e utilizzabili solo
dagli utenti con ruolo **Administrator** o **Editor**, in ogni shard che accende il dominio
`trail_registry`.

Per gli altri ruoli che entrano in Nova (Validator, Contributor) le voci spariscono dal menu
Catasto, e index, detail, modifica e Action rifiutano l'accesso anche conoscendo l'URL.

La regola sta in una **Policy** registrata dal package per i tre modelli del Catasto. Il trait
`HidesWhenTrailRegistryDisabled` resta com'è: continua a nascondere le Resource a dominio spento.

## Perché

Oggi le Resource del Catasto non hanno policy: l'unico filtro è il gate `viewNova` dello shard, e
quello di forestas esclude solo Guest e Sus. Tutti gli altri ruoli vedono il Catasto. Il Catasto
deve essere consultabile solo da chi lo gestisce, e la regola deve valere per chiunque monti il
Catasto, non solo per forestas.

**Perché una Policy e non il trait** (challenge di oc:8700). Il trait ridefinisce i metodi booleani
`authorizedTo*()`, che Nova usa per menu, index e pulsanti. Gli endpoint invece passano da altri
metodi, che senza una policy non controllano nulla:

- detail: `DetailViewResource.php:84` → `authorizeToView()` → `authorizeTo()`, che controlla solo
  `if (static::authorizable())`, vero solo se il modello ha una policy (`Authorizable.php:396-403`);
- modifica: `ResourceUpdateController.php:36` → `authorizeToUpdate()`, stessa strada;
- Action: con un `canRun()` presente Nova controlla solo quello e salta l'autorizzazione della
  Resource (`ActionModelCollection.php:29-31`); Approva, Respingi e Sostituisci numero hanno tutte
  un `canRun()` (`TrailApplication.php:312-319`).

Con la sola regola nel trait un Contributor potrebbe aprire il detail di un'istanza per URL e
approvarla con un POST sull'Action, creando l'EcTrack e rendendo definitivo il codice. Con una
Policy registrata `authorizable()` diventa vero e Nova applica la regola a index, detail e modifica. Le Action con `canRun()` restano fuori anche così: la regola va ripetuta nel loro `canRun()`.

Le API non ne risentono: né le API del package né quelle SUS di forestas chiamano `authorize()`,
`can()` o `Gate::` sui modelli del Catasto.

Mettere `canSee` sulla sezione di menu «Catasto» nello shard non basterebbe:
`injectMenuSectionItems()` ricostruisce la sezione da zero copiando solo nome, icona e stato
richiuso (`src/WmPackageServiceProvider.php:325-339`), e il `canSee` andrebbe perso.

## Requisiti

- [ ] Una Policy del package per `TrailApplication`, `TrailRegistryCode` e `TrailRegistryAnomaly` (modelli risolti con `TrailRegistryClasses`, così vale anche per le sottoclassi degli shard), registrata sempre, anche a dominio spento: `viewAny`, `view`, `update` e `runAction` consentiti solo ad Administrator ed Editor. La condizione sui ruoli sta in un metodo solo, pubblico e statico (es. `TrailRegistryPolicy::allows(User $user): bool`), che le Policy dei tre modelli usano e che gli shard richiamano per le proprie Resource del Catasto (forestas: `RegistroCatastaleRow`), così la regola non si duplica.
- [ ] **La Policy si registra sempre**, non solo a dominio acceso. Il flag `trail_registry` si legge a runtime, mentre le policy si registrano una volta all'avvio: legarle al flag lascerebbe le Resource senza Policy se il dominio si accende senza riavvio (o in un test). La registrazione non tocca il database né applica migration: aggiunge solo l'associazione modello → Policy, e `TrailRegistryClasses` legge solo la configurazione. A dominio spento le Resource restano nascoste dal trait, che mette il dominio in AND con la Policy.
- [ ] **Le Action del Catasto ripetono la regola dei ruoli nel proprio `canRun()` e `canSee()`**, chiamando `TrailRegistryPolicy::allows()`. Con un `canRun()` Nova esegue l'Action guardando solo quello (`Action::authorizedToRun()`, `Actions/Action.php:480-483`; `ActionModelCollection.php:29-31`) e non consulta né la Resource né la Policy: senza questa ripetizione un Validator approverebbe un'istanza con un POST all'Action. Lo stesso vale per `TrailApplication::authorizedToUpdate()`, che ridefinisce il metodo senza `parent::`.
- [ ] Le restrizioni esistenti restano: codici e anomalie in sola lettura, istanze modificabili solo in istruttoria, cancellazione negata, Action solo nello stato previsto.
- [ ] Il trait `HidesWhenTrailRegistryDisabled` continua a nascondere tutto a dominio spento, e funziona insieme alla Policy (i suoi `parent::` passano ora dalla Policy).
- [ ] `injectMenuSectionItems()`, ricostruendo la sezione, ne conserva anche il `canSee` dichiarato dallo shard (`$seeCallback`, proprietà pubblica di `AuthorizedToSee`), come già fa con icona e stato richiuso.
- [ ] La sezione ricostruita è visibile solo se almeno una delle sue voci è visibile all'utente (e, se lo shard ha messo un `canSee`, se vale anche quello). I ruoli restano scritti solo nella Policy: il menu li ricava dalle voci. Nova da solo non nasconde una sezione di primo livello vuota (`Menu::jsonSerialize()`, `MenuCollection::withoutEmptyItems()` toglie solo gruppi e liste interni).
- [ ] Le API non cambiano: la Policy non è usata fuori da Nova.
- [ ] Test con richieste HTTP a `/nova-api/...`, non chiamando i metodi della Resource: per ciascuna Resource del Catasto, Administrator ed Editor ottengono index e detail; Validator e Contributor ricevono 403 su index, detail, modifica e Action (Approva compresa); a dominio spento nessuno. I test esistenti che chiamano `authorizedTo*()` senza utente vanno adeguati.
- [ ] `docs/resources/TrailRegistry.md` documenta la regola dei ruoli e le trappole: una restrizione messa solo negli `authorizedTo*()` di una Resource senza policy non protegge detail, modifica e Action; un'Action con `canRun()` salta Resource e Policy, quindi la regola dei ruoli va ripetuta nel suo `canRun()`.

## Rischi

- **Validator e Contributor perdono l'accesso al Catasto in ogni shard che lo accende.** È l'effetto
  voluto. Su UAT forestas oggi non ci sono utenti con quei ruoli (verificato in sola lettura: un
  Administrator e l'account `forestas`, Editor).
- **Con la Policy registrata cambia il comportamento dei `parent::` del trait**, che oggi
  restituiscono sempre vero. Un metodo della Policy mancante o sbagliato può negare qualcosa che
  oggi funziona, per esempio la modifica di un'istanza in istruttoria: lo coprono i test HTTP per
  Administrator ed Editor.

## Out of scope

- Ruoli configurabili per shard: la regola è fissa, Administrator ed Editor.

## Moduli toccati

Tutti in **wm-package**:

- `src/TrailRegistry/Policies/` — **nuova** Policy del Catasto
- `src/WmPackageServiceProvider.php` — registrazione della Policy; sezione Catasto
  vuota in `injectMenuSectionItems()`
- `src/TrailRegistry/Nova/TrailApplication.php` — regola dei ruoli in `actions()` (`canRun`, `canSee`) e in `authorizedToUpdate()`
- `src/TrailRegistry/Nova/HidesWhenTrailRegistryDisabled.php` — solo docblock, se cambia il rapporto con i `parent::`
- `tests/Feature/TrailRegistry/` — test HTTP di visibilità per ruolo, adeguamento di `TrailRegistryShardResourcesTest`
- `docs/resources/TrailRegistry.md` — regola e trappola

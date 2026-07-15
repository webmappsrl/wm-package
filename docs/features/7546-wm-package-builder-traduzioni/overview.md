> Ticket: oc:7546

# [wm-package] Builder traduzioni

## Cosa cambia

Il tab "translations" della risorsa Nova `App` (`wm-package/src/Nova/App.php::translations_tab()`) sostituisce gli attuali due field `Code::make(...)->language('json')` legati a `translations_it`/`translations_en` con un **componente Nova custom** che permette di gestire le traduzioni chiave-valore dell'app senza editare JSON grezzo:

- **Ricerca** di una traduzione per chiave.
- **Aggiunta** di una singola coppia chiave-valore, propagata automaticamente su tutte le lingue gestite.
- **Modifica** di una traduzione esistente, stesso comportamento multi-lingua.
- **Caricamento JSON** disponibile solo come inizializzazione una tantum, finché `translations_it`/`translations_en` sono vuoti.

Prima di costruire un componente Vue custom, va valutato se un field nativo Nova (es. `KeyValue`) copre già il caso d'uso: solo se insufficiente si procede con un componente custom (esplicitamente autorizzato come "vibe coding" dal ticket, essendo puramente presentazionale).

## Perché

Le traduzioni di contenuto di un'app (mostrate agli utenti finali via `config.json` → sezione `TRANSLATIONS`, generata da `AppConfigService::config_section_translations()` a partire da `apps.translations_it`/`translations_en`) sono oggi editate tramite un campo `Code` JSON grezzo in Nova — editabile solo da chi ha dimestichezza con JSON, alto rischio di errori di sintassi (es. virgole mancanti). L'obiettivo è permettere a **Davide Nanna**, utente finale non-developer che gestisce i contenuti testuali di un'app, di gestire le traduzioni in autonomia, senza intervento di un developer e senza mai toccare direttamente il JSON dopo l'inizializzazione iniziale.

## Requisiti

- [ ] **[BLOCCANTE, scoperto in Fase: challenge]** Fix di un bug preesistente in `AppConfigService::config_section_translations()`: chiama `json_decode($this->app->translations_it, true)` su un attributo già castato ad `array` da Eloquent (`App.php`) — verificato empiricamente via tinker, lancia `TypeError: json_decode(): Argument #1 ($json) must be of type string, array given`. Oggi questo crasha **qualunque salvataggio di `App`** con `translations_it`/`translations_en` non-null (propagazione non gestita: `AppObserver::saved()` → `writeAppConfigOnAws()` → `config()`, nessun try/catch), il che spiega perché questi campi sono `NULL` su Forestas — probabilmente nessuno è mai riuscito a salvarli. Senza questo fix la feature non può funzionare: fix = rimuovere il `json_decode()`, il valore è già un array.
- [ ] Componente Nova custom che sostituisce i field `Code::make()` in `translations_tab()`
- [ ] Valutazione preliminare dei field nativi Nova (es. `KeyValue`) prima di costruire un componente custom
- [ ] Azione "Aggiungi traduzione" — form chiave + valore per ogni lingua gestita, scrive su `translations_it` e `translations_en` contemporaneamente. Si assume che i JSON caricati inizialmente non contengano duplicati al loro interno. Se la chiave inserita esiste già, mostrare un messaggio che informa l'utente e chiedere conferma se vuole modificarne il valore esistente invece di crearne una nuova
- [ ] Azione "Modifica traduzione" — stesso comportamento multi-lingua dell'aggiunta
- [ ] Ricerca per chiave
- [ ] Parametro `langs: array<string>`, default `["it"]` se vuoto — pensato per rendere il componente riutilizzabile in futuro su altre lingue/app; le lingue sono incorporate nello stesso componente (non componenti separati sopra/sotto). **Nota implementativa**: oggi il modello `App` ha solo due colonne fisse (`translations_it`, `translations_en`) — il componente v1 gestisce queste due, non un numero arbitrario di lingue a DB; estendere a una terza lingua richiederà comunque una nuova colonna (`translations_fr`, ecc.), il parametro `langs` serve a rendere il componente Vue già pronto per quel giorno senza riscriverlo
- [ ] Azione "Carica JSON" disponibile **solo come inizializzazione una tantum** — criterio "già inizializzato" valutato **per singola lingua**, non come toggle unico: il bottone per l'italiano si disabilita solo quando `translations_it` è non vuoto, indipendentemente dallo stato di `translations_en` (e viceversa). Evita che un caricamento iniziale parziale (solo IT) costringa a inserire l'intera seconda lingua a mano, chiave per chiave. Verificato sul DB locale: per l'app Forestas (id 1) entrambe sono `NULL` oggi, quindi entrambi i bottoni risulteranno correttamente disponibili al primo rilascio
- [ ] Persistenza: aggiornamento standard Eloquent sulle colonne `translations_it`/`translations_en` di `App` — nessuna scrittura su file, nessun problema di commit/submodule (confermato: non sono coinvolti file del filesystem)

## Rischi

I due punti inizialmente sollevati (lingue fisse a DB, rimozione dei field `Code::make()`) sono risultati decisioni già prese esplicitamente in call da Giuseppe/team, non incertezze — vedi Out of scope. Dalla Fase: challenge sono emersi altri punti, valutati e **accettati come limiti noti di questo ciclo** (non bloccanti, a differenza del bug in Requisiti):

- **Concorrenza su read-modify-write del blob JSON**: "Aggiungi/Modifica traduzione" legge l'intero array di `translations_it`/`translations_en`, modifica una chiave e riscrive tutta la colonna — due editor concorrenti rischiano un last-write-wins silenzioso. Accettato per v1: l'utente reale (Davide Nanna) è singolo, nessun lock/versioning introdotto in questo ciclo.
- **Blast radius su tutti i consumer del package**: rimuovendo il fallback JSON grezzo, un bug nel componente Vue custom non ha più via di fuga (prima si poteva sempre editare il JSON a mano) — e `wm-package` è condiviso da più progetti, non solo Forestas. Mitigazione: test manuale accurato prima del merge (nessun test automatico e2e su `config.json` generato, vedi nota sotto).
- **Nessuna validazione di parità dei placeholder tra lingue** (es. `:name`, `%s`) — un edit che rimuove un placeholder usato dal frontend non viene segnalato. Accettato per v1.
- **Controllo anti-duplicato solo lato Vue, non server-side** — un'azione Nova scriptata o una chiamata API diretta può bypassarlo. Accettato per v1: non è un vincolo di sicurezza, solo di UX.
- **Nessun test automatico verifica che `config.json` rifletta davvero le traduzioni salvate** dopo il fix del bug in Requisiti — raccomandato almeno un test manuale end-to-end (salva traduzione in Nova → verifica `config.json` generato) prima di considerare il ticket concluso.

## Out of scope

- Editing/gestione delle traduzioni di **contenuto POI/tracce** (nomi, descrizioni — gestite via Spatie `HasTranslations` su colonne DB dedicate) — fuori dal perimetro di questo ticket, che riguarda solo `apps.translations_it`/`translations_en`.
- Le stringhe UI interne di wm-package (`wm-package/resources/lang/it.json`/`en.json`, usate per label/help dei field Nova) — meccanismo distinto, non toccato da questo ciclo (ipotesi iniziale scartata dopo verifica).
- Estensione dello schema `App` a colonne `translations_<lang>` oltre `it`/`en` — il parametro `langs` predispone il componente ma non introduce nuove colonne in questo ciclo.
- Editing di JSON grezzo ripetibile nel tempo — i field `Code::make()` esistenti vengono rimossi/sostituiti: dopo l'inizializzazione una tantum, la gestione passa esclusivamente dal componente (aggiungi/modifica/cerca), per design esplicito del ticket.
- Automazione del trigger di rigenerazione `config.json` — il meccanismo (`AppObserver::saved()`) resta invariato; viene corretto solo il bug interno di `config_section_translations()` (vedi Requisiti), non il meccanismo di trigger.
- Azione di **eliminazione** di una chiave di traduzione — il brief e la call menzionano solo ricerca/aggiungi/modifica; una chiave obsoleta resta come cruft in entrambi i JSON, rimovibile solo con intervento diretto sul DB. Gap noto, non richiesto esplicitamente in questo ciclo.
- Filament — non utilizzato in questo progetto (refuso nel brief iniziale, confermato che si tratta di Nova).

## Moduli toccati

- `wm-package/src/Nova/App.php` — sostituzione dei field `Code::make()` in `translations_tab()` con il nuovo componente
- `wm-package/src/Nova/Fields/<NomeCampo>/**` (nome indicativo) — nuovo Nova Field custom (composer.json + package.json + risorse Vue), seguendo il pattern esistente di `IconSelect`/`GeoReferenceField`
- `wm-package/src/Services/Models/App/AppConfigService.php` — fix del bug in `config_section_translations()` (rimozione del `json_decode()` errato su valore già `array`)
- Nessuna modifica a `StorageService` o `AppObserver` — il meccanismo di trigger/persistenza di `config.json` resta invariato, solo il bug interno viene corretto

Nessun file toccato nel repo principale `forestas` — feature interamente confinata al submodule `wm-package`.

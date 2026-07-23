> Ticket: oc:7546

# [wm-package] Builder traduzioni

**Correzione (revisione team, 2026-07-20):** in call, Alessandro ha fatto notare che l'overview lasciava intendere che il caricamento JSON fosse l'**unico** modo di inizializzare le traduzioni, come se manuale e file fossero mutuamente esclusivi. Giuseppe ha chiarito: il caricamento di un file JSON **non è un'operazione esclusiva di inizializzazione** — le due modalità (form manuale e caricamento file) convivono sempre. La logica corretta è un **upsert per chiave**: per ogni chiave nel JSON caricato, se la traduzione esiste già la sovrascrive, altrimenti la aggiunge — usando esattamente le stesse funzioni di aggiunta/modifica dell'azione manuale (cambia solo l'input: una coppia chiave-valore da form vs. un intero blocco da file). Il gate "già inizializzato per lingua" descritto nella prima stesura di questo documento è quindi **rimosso**: vedi sezioni aggiornate sotto.

## Cosa cambia

Il tab "translations" della risorsa Nova `App` (`wm-package/src/Nova/App.php::translations_tab()`) sostituisce gli attuali due field `Code::make(...)->language('json')` legati a `translations_it`/`translations_en` con un **componente Nova custom** che permette di gestire le traduzioni chiave-valore dell'app senza editare JSON grezzo:

- **Ricerca** di una traduzione per chiave.
- **Aggiunta** di una singola coppia chiave-valore, propagata automaticamente su tutte le lingue gestite.
- **Modifica** di una traduzione esistente, stesso comportamento multi-lingua.
- **Caricamento JSON** disponibile in **qualsiasi momento**, non solo per l'inizializzazione: è un'operazione di bulk upsert — per ogni chiave nel file caricato, se la traduzione esiste già la sovrascrive, altrimenti la aggiunge — riusando le stesse funzioni di aggiunta/modifica dell'azione manuale a singola chiave.

Prima di costruire un componente Vue custom, va valutato se un field nativo Nova (es. `KeyValue`) copre già il caso d'uso: solo se insufficiente si procede con un componente custom (esplicitamente autorizzato come "vibe coding" dal ticket, essendo puramente presentazionale).

## Perché

Le traduzioni di contenuto di un'app (mostrate agli utenti finali via `config.json` → sezione `TRANSLATIONS`, generata da `AppConfigService::config_section_translations()` a partire da `apps.translations_it`/`translations_en`) sono oggi editate tramite un campo `Code` JSON grezzo in Nova — editabile solo da chi ha dimestichezza con JSON, alto rischio di errori di sintassi (es. virgole mancanti). L'obiettivo è permettere a **Davide Nanna**, utente finale non-developer che gestisce i contenuti testuali di un'app, di gestire le traduzioni in autonomia, senza intervento di un developer e senza mai dover editare a mano la sintassi del JSON grezzo — il caricamento di un file JSON resta comunque disponibile in qualsiasi momento come strumento di bulk-upsert, non solo per l'inizializzazione (vedi correzione in testa al documento).

## Requisiti

- [ ] **[BLOCCANTE, scoperto in Fase: challenge]** Fix di un bug preesistente in `AppConfigService::config_section_translations()`: chiama `json_decode($this->app->translations_it, true)` su un attributo già castato ad `array` da Eloquent (`App.php`) — verificato empiricamente via tinker, lancia `TypeError: json_decode(): Argument #1 ($json) must be of type string, array given`. Oggi questo crasha **qualunque salvataggio di `App`** con `translations_it`/`translations_en` non-null (propagazione non gestita: `AppObserver::saved()` → `writeAppConfigOnAws()` → `config()`, nessun try/catch), il che spiega perché questi campi sono `NULL` su Forestas — probabilmente nessuno è mai riuscito a salvarli. Senza questo fix la feature non può funzionare: fix = rimuovere il `json_decode()`, il valore è già un array.
- [ ] Componente Nova custom che sostituisce i field `Code::make()` in `translations_tab()`
- [ ] Valutazione preliminare dei field nativi Nova (es. `KeyValue`) prima di costruire un componente custom
- [ ] Azione "Aggiungi/Modifica traduzione" — **un'unica coppia di funzioni condivise** (`addTranslation`/`modifyTranslation`, nome indicativo) che scrivono su `translations_it` e `translations_en` contemporaneamente, chiamate da **entrambi** i punti di ingresso descritti sotto (form manuale e caricamento file) — cambia solo l'input (una singola coppia chiave-valore vs. un intero blocco da file), non la logica di scrittura
  - **Form manuale** (una chiave alla volta): se la chiave inserita esiste già, mostrare un messaggio che informa l'utente e chiedere conferma prima di sovrascriverne il valore esistente — interazione sensata per una singola chiave
  - **Caricamento file** (bulk): per ogni chiave nel JSON caricato, upsert automatico senza conferma per-chiave (chiedere conferma per ognuna delle N chiavi di un file non è praticabile) — se la chiave esiste già la sovrascrive, altrimenti la aggiunge, riusando le stesse funzioni del form manuale
- [ ] Ricerca per chiave
- [ ] Parametro `langs: array<string>`, default `["it"]` se vuoto — pensato per rendere il componente riutilizzabile in futuro su altre lingue/app; le lingue sono incorporate nello stesso componente (non componenti separati sopra/sotto). **Nota implementativa**: oggi il modello `App` ha solo due colonne fisse (`translations_it`, `translations_en`) — il componente v1 gestisce queste due, non un numero arbitrario di lingue a DB; estendere a una terza lingua richiederà comunque una nuova colonna (`translations_fr`, ecc.), il parametro `langs` serve a rendere il componente Vue già pronto per quel giorno senza riscriverlo
- [ ] Azione "Carica JSON" — **disponibile sempre, non solo in assenza di traduzioni esistenti** (nessun gate "già inizializzato": corregge l'assunzione errata della prima stesura di questo documento, vedi nota in testa). Il file caricato viene fuso (merge) con le traduzioni già presenti per quella lingua tramite le stesse funzioni di upsert del form manuale — utilizzabile sia per il primissimo caricamento (oggi entrambe `translations_it`/`translations_en` sono `NULL` su Forestas app id 1) sia in un secondo momento per importare/aggiornare in blocco un sottoinsieme di chiavi
- [ ] **[aggiunto in revisione, mitigazione rischio blast radius]** Prima di applicare un import file: mostrare un riepilogo aggregato ("N chiavi verranno aggiunte, M sovrascritte") con conferma esplicita — non per-chiave (impraticabile su un file con molte chiavi), un solo conteggio per l'intero import
- [ ] **[aggiunto in revisione, mitigazione rischio blast radius]** Bottone "Scarica traduzioni attuali" (per lingua o per l'intero set), disponibile prima di un import — serializza lo stato corrente come file JSON scaricabile, per dare una via di rollback manuale ora che l'editing diretto del JSON grezzo non è più possibile
- [ ] Persistenza: aggiornamento standard Eloquent sulle colonne `translations_it`/`translations_en` di `App` — nessuna scrittura su file, nessun problema di commit/submodule (confermato: non sono coinvolti file del filesystem)
- [ ] **[aggiunto in revisione, upgrade da test manuale ad automatico]** Test Feature che simula un salvataggio Nova reale (richiesta verso `nova-api` con payload del campo) e verifica che `AppConfigService::config_section_translations()` rifletta i valori salvati — chiude il gap descritto nei Rischi sotto
- [ ] **[aggiunto in `wm-skills:wm-review-ticket`, 2026-07-23]** Azione "Elimina" per una chiave di traduzione — rimuove la chiave da **tutte** le lingue gestite in un'unica azione (coerente con la propagazione multi-lingua dell'aggiunta), disponibile dal modale di modifica (click su una riga esistente), con conferma esplicita (`window.confirm`) essendo un'operazione distruttiva senza undo. Corregge la decisione "Out of scope" della prima stesura di questo documento: in uso reale, senza questa azione, ogni chiave inserita per errore o obsoleta resta cruft permanente removibile solo intervenendo sul DB.

## Rischi

I due punti inizialmente sollevati (lingue fisse a DB, rimozione dei field `Code::make()`) sono risultati decisioni già prese esplicitamente in call da Giuseppe/team, non incertezze — vedi Out of scope. Dalla Fase: challenge sono emersi altri punti, valutati e **accettati come limiti noti di questo ciclo** (non bloccanti, a differenza del bug in Requisiti):

- **Concorrenza su read-modify-write del blob JSON**: "Aggiungi/Modifica traduzione" legge l'intero array di `translations_it`/`translations_en`, modifica una chiave e riscrive tutta la colonna — due editor concorrenti rischiano un last-write-wins silenzioso. **Nota aggiunta in revisione**: con la correzione sulla semantica del caricamento JSON (sempre disponibile, non solo all'init), questa finestra di rischio si apre più spesso di quanto stimato nella prima stesura — non più un evento raro di inizializzazione, ma un'operazione ripetibile nel tempo. Resta comunque accettato per v1: l'utente reale (Davide Nanna) è singolo, nessun lock/versioning introdotto in questo ciclo.
- **Blast radius su tutti i consumer del package**: rimuovendo il fallback JSON grezzo, un bug nel componente Vue custom non ha più via di fuga (prima si poteva sempre editare il JSON a mano) — e `wm-package` è condiviso da più progetti, non solo Forestas. **Aggravato dalla correzione sul caricamento file**: un import bulk applica l'upsert a un numero arbitrario di chiavi in un'unica azione senza conferma per-chiave (per design, vedi Requisiti) — un bug nella funzione di upsert condivisa può quindi corrompere in silenzio molte traduzioni contemporaneamente, non più una sola per volta come nella prima stesura del documento. **Mitigazioni aggiunte in revisione** (vedi Requisiti): riepilogo aggregato con conferma prima di ogni import, bottone di backup/download dello stato corrente prima di procedere. Nessun test automatico sul componente Vue resta comunque un limite accettato (fuori scope per gli altri campi custom del package).
- **Nessuna validazione di parità dei placeholder tra lingue** (es. `:name`, `%s`) — un edit che rimuove un placeholder usato dal frontend non viene segnalato. Accettato per v1, nessuna modifica in questa revisione.
- **Conferma di sovrascrittura solo lato Vue, non server-side** (solo per il form manuale a singola chiave — il caricamento file sovrascrive intenzionalmente senza conferma per-chiave, per design, vedi Requisiti) — un'azione Nova scriptata o una chiamata API diretta al form manuale può bypassare il dialogo di conferma. Accettato per v1: non è un vincolo di sicurezza, solo di UX.
- **Nessun test automatico verifica che `config.json` rifletta davvero le traduzioni salvate** dopo il fix del bug in Requisiti — **risolto in revisione**: aggiunto un requisito esplicito per un test Feature automatico che copre questo percorso end-to-end (vedi Requisiti), non più solo raccomandazione di test manuale.

## Out of scope

- Editing/gestione delle traduzioni di **contenuto POI/tracce** (nomi, descrizioni — gestite via Spatie `HasTranslations` su colonne DB dedicate) — fuori dal perimetro di questo ticket, che riguarda solo `apps.translations_it`/`translations_en`.
- Le stringhe UI interne di wm-package (`wm-package/resources/lang/it.json`/`en.json`, usate per label/help dei field Nova) — meccanismo distinto, non toccato da questo ciclo (ipotesi iniziale scartata dopo verifica).
- Estensione dello schema `App` a colonne `translations_<lang>` oltre `it`/`en` — il parametro `langs` predispone il componente ma non introduce nuove colonne in questo ciclo.
- Editing manuale della sintassi JSON grezza — i field `Code::make()` esistenti vengono rimossi/sostituiti: la gestione passa esclusivamente dal componente (ricerca/aggiungi/modifica/carica file), per design esplicito del ticket. Il caricamento di un file JSON resta disponibile in qualsiasi momento (non solo all'inizializzazione, vedi correzione in testa al documento e Requisiti) ma sempre tramite il componente, mai editando a mano la stringa JSON.
- Automazione del trigger di rigenerazione `config.json` — il meccanismo (`AppObserver::saved()`) resta invariato; viene corretto solo il bug interno di `config_section_translations()` (vedi Requisiti), non il meccanismo di trigger.
- Filament — non utilizzato in questo progetto (refuso nel brief iniziale, confermato che si tratta di Nova).

## Moduli toccati

- `wm-package/src/Nova/App.php` — sostituzione dei field `Code::make()` in `translations_tab()` con il nuovo componente
- `wm-package/src/Nova/Fields/TranslationsBuilder/**` — nuovo Nova Field custom (composer.json + package.json + risorse Vue), seguendo il pattern esistente di `IconSelect`
- `wm-package/src/Nova/Fields/_shared/**` — componenti Vue e utility condivisi tra campi custom (Button, SelectInput, TextInput, collectAllKeys), introdotti in questo ciclo per la prima volta nel package
- `wm-package/src/Services/Models/App/AppConfigService.php` — fix del bug in `config_section_translations()` (rimozione del `json_decode()` errato su valore già `array`)
- `wm-package/src/WmPackageServiceProvider.php` — registrazione del `FieldServiceProvider` del nuovo campo
- `wm-package/composer.json` — nuova voce autoload PSR-4 per il namespace del campo
- Nessuna modifica a `StorageService` o `AppObserver` — il meccanismo di trigger/persistenza di `config.json` resta invariato, solo il bug interno viene corretto

Nessun file toccato nel repo principale `forestas` — feature interamente confinata al submodule `wm-package`.

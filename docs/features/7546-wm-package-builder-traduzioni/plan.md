> Ticket: oc:7546

# Piano implementativo — Builder traduzioni

Riferimento: `overview.md` (approvata, commit `b1e011d8` + due round di revisione 2026-07-20, non ancora committati). Stima approvata: **4h**.

**Nota sulle correzioni recepite**:
1. Il caricamento JSON **non** è un gate "una tantum per lingua" — è un'operazione di bulk upsert disponibile sempre, che riusa le stesse funzioni di aggiunta/modifica del form manuale (upsert automatico senza conferma per-chiave sul bulk; conferma esplicita solo sul form manuale a singola chiave).
2. Analisi dei rischi in overview: aggiunte due mitigazioni per il blast radius amplificato dal bulk import (riepilogo aggregato di conferma + bottone di backup/download, Task 7) e upgrade del test e2e da "raccomandazione manuale" a task automatico (Task 11).

I task sotto sono stati riscritti di conseguenza rispetto alla prima stesura del piano.

## Task 1 — Valutazione field nativo Nova (KeyValue) — decisione documentata

**Nessuna modifica di codice, solo verifica.**

Letto `vendor/laravel/nova/src/Fields/KeyValue.php`: gestisce un solo blob JSON per istanza (un `KeyValue::make()` per `translations_it`, un altro per `translations_en`), senza:
- ricerca per chiave integrata
- propagazione automatica di una nuova chiave su entrambe le lingue in un'unica azione
- rilevamento/conferma di chiave duplicata
- vincolo che le due lingue siano "incorporate nello stesso componente" (requisito esplicito del ticket)

**Decisione**: il field nativo non copre i requisiti — si procede con un componente Nova custom, come già anticipato in overview.

## Task 2 — Fix bug bloccante in AppConfigService

File: `wm-package/src/Services/Models/App/AppConfigService.php`

- `config_section_translations()` (righe ~178-190): rimuovere `json_decode($this->app->translations_it, true)` / `json_decode($this->app->translations_en, true)` — `translations_it`/`translations_en` sono già castate `array` in `App.php` (righe 38-39), `json_decode()` su un array lancia `TypeError`
- Sostituire con lettura diretta: `$this->app->translations_it` / `$this->app->translations_en` (già array o null)
- Verificato che questo è l'unico punto del file che tratta questi due attributi come stringa JSON

## Task 3 — Test di regressione per il bug

File: `wm-package/tests/Unit/Services/Models/App/AppConfigServiceTranslationsTest.php` (nuovo)

- `test_config_section_translations_does_not_throw_on_populated_array()` — `App::factory()->create(['translations_it' => ['key' => 'valore'], 'translations_en' => ['key' => 'value']])`, chiamare il metodo (via reflection o passando dal metodo pubblico che lo aggrega), assert nessuna eccezione e struttura `['TRANSLATIONS' => ['it' => [...], 'en' => [...]]]` corretta
- `test_config_section_translations_handles_null_languages()` — entrambe le colonne `null`, assert `['TRANSLATIONS' => []]`
- `test_config_section_translations_handles_single_language_populated()` — solo `translations_it` valorizzato, assert che la chiave `en` sia assente da `TRANSLATIONS`

## Task 4 — Scaffold del campo Nova custom

Nome scelto: `TranslationsBuilder` (namespace `Wm\WmPackage\Nova\Fields\TranslationsBuilder`, componente Vue `translations-builder-field`). Pattern ricalcato da `wm-package/src/Nova/Fields/IconSelect/` (unico campo custom completo disponibile su questo branch, `GeoReferenceField` non ancora mergiato qui).

Struttura da creare in `wm-package/src/Nova/Fields/TranslationsBuilder/`:
- `src/TranslationsBuilder.php` — classe field
- `src/FieldServiceProvider.php` — provider che registra le view/asset Vue (mirror di `IconSelect/src/FieldServiceProvider.php`)
- `resources/js/field.js` — entry point che registra il componente Nova (`Nova.booting(...)`)
- `resources/js/components/FormField.vue`
- `resources/js/components/DetailField.vue` (sola lettura, tabella semplice chiave/it/en)
- `resources/js/components/IndexField.vue` (badge/riepilogo, es. "N traduzioni")
- `webpack.mix.js`, `package.json`, `package-lock.json` (copiati/adattati da `IconSelect`)
- `postcss.config.js` (`module.exports = {}` — obbligatorio per ogni nuovo campo custom con build CSS, vedi `wm-package/CLAUDE.md` decisione oc:8241, altrimenti `npm run prod` risale l'albero e trova il `postcss.config.js` ESM della root consumer)
- `composer.json` del campo (se il pattern IconSelect ne ha uno proprio, verificare; altrimenti solo l'entry PSR-4 nel composer.json root)

Modifiche a file esistenti:
- `wm-package/composer.json`: aggiungere `"Wm\\WmPackage\\Nova\\Fields\\TranslationsBuilder\\": "src/Nova/Fields/TranslationsBuilder/src/"` nel blocco `autoload.psr-4` (mirror riga 74, `IconSelect`)
- `wm-package/src/WmPackageServiceProvider.php`: aggiungere `$this->app->register(\Wm\WmPackage\Nova\Fields\TranslationsBuilder\FieldServiceProvider::class);` (mirror riga 65)

## Task 5 — Classe PHP del campo

File: `wm-package/src/Nova/Fields/TranslationsBuilder/src/TranslationsBuilder.php`

- Attributo virtuale (es. `translations_builder`), non mappato 1:1 a una colonna — il field scrive **due** attributi reali (`translations_it`, `translations_en`) via `fillAttributeFromRequest()` overridden, pattern identico a `GeoReferenceField` (oc:8241) che scrive `poi_id`/`track_id` da un solo field virtuale
- Metodo pubblico `langs(array $langs = ['it'])` per configurare il parametro richiesto dal ticket — passato al componente Vue via `meta()`. Default `['it']` se non specificato o array vuoto (requisito esplicito)
- `resolve()`: legge `translations_it`/`translations_en` dal model (già `array` grazie al cast Eloquent) e li serializza in un'unica struttura per il componente Vue, es. `['langs' => ['it','en'], 'values' => ['it' => [...], 'en' => [...]]]`
- `fillAttributeFromRequest()`: il Vue invia **sempre lo stato finale già mergiato** (il merge/upsert avviene lato Vue nel Task 6/7, sia per l'edit di una singola chiave sia per l'import di un file — stesse funzioni JS, vedi overview "Requisiti"). Il PHP si limita a decodificare il payload `{it: {...}, en: {...}}` e scrivere `forceFill(['translations_it' => ..., 'translations_en' => ...])` sul model — nessuna logica di merge duplicata lato server. **Nessuna validazione di parità chiavi tra lingue** (accettato come rischio noto in overview)

## Task 6 — Componente Vue: tabella unificata + ricerca + funzione di upsert condivisa

File: `wm-package/src/Nova/Fields/TranslationsBuilder/resources/js/components/FormField.vue`

- Stato reattivo in memoria: l'intera struttura `{it: {...}, en: {...}}` risolta dal PHP (Task 5), tenuta come "source of truth" del componente fino al salvataggio della risorsa
- **Funzione condivisa `upsertTranslation(key, valuesByLang)`** (nome indicativo): per ogni lingua in `langs`, se `key` esiste già la sovrascrive, altrimenti la aggiunge — **unico punto del codice** che scrive nello stato in memoria. Sia l'azione manuale (Task 6) sia l'import file (Task 7) chiamano solo questa funzione, mai una scrittura diretta allo stato — è il requisito esplicito dell'overview ("stesse funzioni add/modify, cambia solo l'input")
- Tabella con colonne: chiave, una colonna per ogni lingua in `langs` (in memoria, non richiede reload per aggiungere lingue in futuro oltre `it`/`en` — la persistenza a 2 colonne fisse resta un limite noto documentato in overview)
- Barra di ricerca che filtra le righe per sottostringa sulla chiave (client-side, dataset già interamente in memoria — nessuna chiamata AJAX, stesso pattern di `GeoReferenceField`/`IconSelect`)
- Azione "Aggiungi/Modifica traduzione" (form manuale, una chiave alla volta): campo chiave + un campo valore per lingua configurata
  - Se la chiave inserita esiste già in almeno una lingua: mostrare un dialogo di conferma ("La chiave esiste già, vuoi sovrascriverla?") **prima** di chiamare `upsertTranslation()` — conferma sensata per una singola chiave, gestita qui e non dentro la funzione condivisa
  - Click su una riga esistente apre lo stesso form pre-compilato (azione "Modifica"), stesso comportamento multi-lingua e stessa funzione condivisa
- Nessuna azione di eliminazione (fuori scope, per decisione esplicita in overview)
- `fill()`: serializza l'intero stato in memoria (già aggiornato da `upsertTranslation()`, sia via form manuale sia via import file) come JSON nel campo virtuale, letto da `TranslationsBuilder::fillAttributeFromRequest()` (Task 5) — il PHP non fa merge, riceve solo lo stato finale

## Task 7 — Componente Vue: import file JSON (bulk upsert, sempre disponibile)

Stesso file di Task 6 (o sotto-componente dedicato)

- Bottone "Carica file JSON" **sempre visibile per ogni lingua in `langs`**, indipendentemente dal fatto che quella lingua abbia già traduzioni — **nessun gate "già inizializzato"** (corregge l'assunzione errata della prima stesura dell'overview)
- Textarea/file-input per incollare/caricare il JSON grezzo, validazione client-side che sia JSON valido prima di abilitare "Conferma"
- **Riepilogo aggregato prima di applicare l'import** (mitigazione blast radius, aggiunta in revisione): calcolare, confrontando le chiavi del file con lo stato in memoria, quante chiavi sono nuove (N) e quante già esistenti verrebbero sovrascritte (M) — mostrare "Verranno aggiunte N chiavi e sovrascritte M chiavi esistenti. Procedi?" con conferma esplicita, un solo dialogo per l'intero import, non per singola chiave
- **Bottone "Scarica traduzioni attuali"** (mitigazione blast radius, aggiunta in revisione): prima di procedere con un import, permettere di scaricare lo stato corrente (`values[lang]`) come file JSON — semplice `JSON.stringify` + download client-side, nessuna infrastruttura server necessaria, dà una via di rollback manuale
- Al conferma dell'import: itera ogni coppia chiave-valore del file caricato e chiama `upsertTranslation(key, {[lang]: value})` (Task 6) **una volta per chiave, senza dialogo di conferma per-chiave** — chiedere conferma per ognuna delle N chiavi di un file non è praticabile, per design esplicito dell'overview (il riepilogo aggregato sopra copre la sicurezza a livello di batch). Le chiavi già presenti vengono sovrascritte, quelle nuove aggiunte — nessuna traduzione esistente per altre chiavi viene toccata o rimossa
- Utilizzabile sia sul primissimo caricamento (oggi `translations_it`/`translations_en` sono `NULL` su Forestas app id 1) sia in un secondo momento per importare/aggiornare in blocco un sottoinsieme di chiavi, senza perdere le traduzioni già presenti

## Task 8 — Sostituzione dei field in App.php

File: `wm-package/src/Nova/App.php`

- `translations_tab()` (righe 489-503): rimuovere i due `Code::make(...)->language('json')`, sostituire con un'unica istanza `TranslationsBuilder::make(__('Translations'), 'translations_builder')->langs(['it', 'en'])`
- Verificare che `hideFromIndex()` resti applicato (comportamento invariato rispetto ai field `Code::make()` originali, che avevano `->hideFromIndex()`)

## Task 9 — Test PHP del nuovo campo

File: `wm-package/tests/Unit/Nova/Fields/TranslationsBuilderTest.php` (nuovo)

- `test_langs_defaults_to_italian_when_empty()` — `TranslationsBuilder::make(...)->langs([])` risolve a `['it']` nel meta esposto al Vue
- `test_resolve_reads_both_language_columns()` — model con `translations_it`/`translations_en` valorizzati, assert che il valore risolto contenga entrambe le lingue
- `test_fill_writes_both_language_columns()` — payload JSON simulato `{it: {...}, en: {...}}`, assert che `forceFill` scriva entrambe le colonne sul model
- `test_fill_handles_missing_language_key_in_payload()` — payload con solo `it`, assert comportamento su `en` (probabilmente invariato/non toccato, da verificare in implementazione — documentare la scelta in notes.md se emerge un caso ambiguo)
- **Nessun test automatico sul componente Vue** (fuori scope, coerente con gli altri campi custom del package — vedi `IconSelect`, `GeoReferenceField`) — questo include `upsertTranslation()` (Task 6/7), il cuore della logica di merge/upsert condivisa tra form manuale e import file: **non ha copertura automatica**, solo verifica manuale (Task 10). Da registrare esplicitamente in `notes.md` in fase di esecuzione come rischio noto: se un bug si annida in `upsertTranslation()`, non verrebbe intercettato dalla suite Pest. Mitigazione minima da valutare in implementazione (non bloccante per questo piano): se ragionevolmente isolabile, estrarre `upsertTranslation()` come funzione pura testabile (es. Vitest, se già configurato nel package) invece di lasciarla annidata nel componente

## Task 10 — Build dist e verifica manuale

- `npm run prod` dentro `wm-package/src/Nova/Fields/TranslationsBuilder/`
- Verificare che il `dist/js/field.js` compilato non contenga proprietà spurie rispetto alla sorgente Vue (prassi da `wm-package/CLAUDE.md`, decisione oc:8093)
- **Verifica manuale in Nova** (checklist):
  - Tab "translations" mostra il nuovo componente al posto dei due `Code::make()`
  - Su un'app con entrambe le lingue vuote (es. Forestas id 1): bottone "Carica file JSON" visibile per entrambe le lingue (nessun gate, sempre disponibile)
  - Import file JSON su una lingua vuota (es. solo it) → chiavi importate compaiono in tabella; il bottone "Carica file JSON" **resta visibile** anche dopo (non è un'azione one-time)
  - **Import file JSON su una lingua che ha già traduzioni esistenti** (es. import di un secondo file dopo il primo, o su un'app con traduzioni già presenti) → le chiavi del file sovrascrivono quelle omonime già presenti, le chiavi nuove si aggiungono, **le chiavi esistenti non presenti nel file importato restano invariate** (nessuna cancellazione implicita) — verifica esplicita della correzione Giuseppe/Alessandro, il test manuale più importante di questo ciclo
  - Aggiunta manuale di una nuova chiave (form) → compare in entrambe le lingue configurate
  - Aggiunta manuale di una chiave già esistente (form) → dialogo di conferma prima di sovrascrivere (il gate di conferma è specifico del form manuale, l'import file non lo mostra — verificare che non appaia per errore durante un import)
  - **Import file → riepilogo aggregato mostra i conteggi corretti** (N nuove, M sovrascritte) prima di applicare, un solo dialogo per l'intero import (non per-chiave)
  - **Bottone "Scarica traduzioni attuali" produce un file JSON scaricabile** con lo stato corrente prima di un import
  - Ricerca per chiave filtra correttamente la tabella
  - Salvataggio della risorsa App **non lancia più l'eccezione** del bug in Task 2 — verificare aprendo `config.json` generato (via `AppConfigService`) e confermare che la sezione `TRANSLATIONS` rifletta i valori salvati

## Task 11 — Test Feature end-to-end (upgrade da test manuale ad automatico)

File: `wm-package/tests/Feature/Nova/App/TranslationsBuilderSaveTest.php` (nuovo)

- Simula un salvataggio Nova reale della risorsa `App` con un payload per il campo `translations_builder` (richiesta verso l'endpoint `nova-api` di update, pattern già usato altrove nel package per test Feature su risorse Nova — es. `AbstractUserResourceRoleGuardTest.php`, oc:8072)
- Dopo il salvataggio, chiama `AppConfigService::config()` (o il metodo pubblico che aggrega le sezioni) sul model ricaricato e verifica che `TRANSLATIONS.it`/`TRANSLATIONS.en` riflettano esattamente i valori inviati
- Chiude il gap descritto in overview (Rischi): non più solo un test manuale raccomandato, un test automatico copre il percorso end-to-end "salvataggio Nova → config.json corretto" e fa anche da regressione permanente per il bug del Task 2

## Commit (da eseguire manualmente dopo review, non automatici)

1. `fix(oc:7546): remove erroneous json_decode on already-cast translations array`
2. `test(oc:7546): cover AppConfigService translations section against the array cast bug`
3. `feat(oc:7546): scaffold TranslationsBuilder custom Nova field`
4. `feat(oc:7546): add key search, manual add/edit with duplicate-key confirmation`
5. `feat(oc:7546): add JSON file bulk upsert, always available, sharing upsert logic with manual add`
6. `feat(oc:7546): add aggregate confirm summary and download backup before file import`
7. `feat(oc:7546): replace Code json fields with TranslationsBuilder in App translations tab`
8. `test(oc:7546): cover TranslationsBuilder langs default, resolve and fill`
9. `test(oc:7546): add end-to-end feature test for Nova save through config.json`

## Note per l'esecuzione

- Applicare `wm-skills:our-code-style` durante la scrittura del codice (se disponibile nell'ambiente di esecuzione)
- Nessun commit o branch automatico: i commit sopra sono istruzioni testuali per lo sviluppatore, non azioni eseguite autonomamente
- Deviazioni dal piano, bug trovati, decisioni on-the-fly vanno registrate in `notes.md` (Fase: notes)

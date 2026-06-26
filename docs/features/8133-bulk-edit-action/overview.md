> Ticket: oc:8133

# BulkEditAction: bulk edit dinamico da Nova Resource

## Cosa cambia

Viene aggiunta in wm-package la classe `BulkEditAction` — una Nova Action parametrica che permette la modifica in blocco dei campi di qualsiasi Resource, costruendo dinamicamente il form del modale a partire dai campi già definiti nella Resource stessa.

## Perché

Gli amministratori devono poter modificare in blocco i campi dei modelli (es. EcPoi, EcTrack) direttamente dall'index Nova, senza aprire ogni record singolarmente. La definizione dei campi non deve essere duplicata: `BulkEditAction` legge i campi dalla Resource e li espone nel modale di bulk edit.

## Requisiti

- [ ] La classe `BulkEditAction` è in `src/Nova/Actions/BulkEditAction.php` nel namespace `Wm\WmPackage\Nova\Actions`
- [ ] Il costruttore accetta `string $novaResource` (FQCN della Nova Resource), `array $fields = []` (nomi degli attributi da esporre; array vuoto = tutti), `array $exclude = ['name', 'geometry', 'description']` (attributi sempre esclusi per sicurezza)
- [ ] `fields(NovaRequest $request)` istanzia la Resource con `$novaResource::newModel()`, chiama `fields($request)` e appiattisce Tab/Panel al primo livello (tramite `$field->data` se il campo è un `FieldMergeValue`)
- [ ] Vengono esclusi: campi `ID`, campi che implementano `RelatableField` (BelongsTo, HasMany, MorphTo, …) **o** `ListableField` (BelongsToMany, MorphToMany), campi con `readonly` **statico** (`readonlyCallback === true`), campi con `showOnUpdate === false`, campi senza proprietà `attribute`
- [ ] I campi con `readonly` **dinamico** (closure) vengono inclusi ma il loro callback viene azzerato con `->readonly(false)`, rendendoli sempre editabili nel modale bulk
- [ ] Se `$fields` è non vuoto, vengono mantenuti solo i campi il cui `attribute` è presente nell'array
- [ ] I campi il cui `attribute` è presente in `$exclude` vengono sempre rimossi (applicato dopo il filtro `$fields`); default `['name', 'geometry', 'description']` per proteggere nome, geometria e descrizione da bulk edit accidentale
- [ ] Tutti i campi restituiti sono resi `->nullable()` per renderli opzionali nel modale
- [ ] `handle(ActionFields $fields, Collection $models)` aggiorna ogni modello solo sui campi compilati: i cambiamenti si calcolano via `resolveChanges()` ciclando sui campi definiti in `fields()` (NON sulle chiavi top-level di `ActionFields`, perché Nova serializza i `properties->*` come oggetto annidato sotto `properties`); skip se il valore è `=== null`, `=== ''` o array/collection vuoto; i Boolean `false` e i valori `0` vengono salvati
- [ ] `resolveChanges()` legge il valore di ogni campo con doppia strategia: chiave letterale flat (`array_key_exists`) per attributi piatti e test, altrimenti `data_get()` con notazione dot per la struttura annidata reale di Nova
- [ ] Per attributi piatti (es. `global`) l'assegnazione usa `$model->forceFill([$attribute => $value])`
- [ ] Per path arrow notation (es. `properties->contact_email`) `handle()` fa merge esplicito: legge l'array corrente della colonna JSON, imposta solo il path modificato con `Arr::set()`, riassegna l'intero array — i sibling non toccati restano invariati
- [ ] `handle()` chiama `$model->saveQuietly()` dopo aver aggiornato i campi — bypassa observer ed eventi Eloquent per evitare side effect indesiderati e saturazione delle code su selezioni grandi
- [ ] L'intero loop in `handle()` è wrappato in `DB::transaction()` — se qualsiasi `saveQuietly()` lancia un'eccezione, tutte le modifiche vengono rollbackate atomicamente
- [ ] L'action non imposta `$shouldQueue = true` (esecuzione sincrona): Nova passa tutti i record selezionati in un'unica chiamata a `handle()`, garantendo che la transazione copra l'intera selezione senza limiti artificiali al numero di record
- [ ] Unit test per la logica di `fields()`: esclusione ID, esclusione RelatableField, esclusione readonly statico, esclusione showOnUpdate false, inclusione readonly dinamico (con callback azzerato), appiattimento Tab/Panel al primo livello, filtro per array `$fields`, esclusione default `name` e `geometry`, override `$exclude = []`
- [ ] Feature test per `handle()`: salva solo i campi compilati, preserva `false` boolean, skippa null e stringa vuota, preserva sibling `properties->*` non modificati, rollback su eccezione
- [ ] `app/Nova/EcPoi.php` in camminiditalia aggiornato con `BulkEditAction` per il campo `global`

## Rischi

- **Closure readonly su modello vuoto** — risolto: i campi con readonly dinamico vengono inclusi ma il loro callback viene azzerato con `->readonly(false)` prima di restituirli, evitando che il modello vuoto (senza media, senza ID) faccia apparire il campo disabilitato per tutti i modelli selezionati.
- **Arrow notation in `handle()`** — risolto: per i path `properties->*` il merge è esplicito (legge l'array corrente, `Arr::set()` sul solo path, riassegna la colonna). Per gli attributi piatti resta `forceFill()`.

## Out of scope

- Modifica o rimozione della classe `EditFields` esistente
- Appiattimento ricorsivo di Tab/Panel annidati
- Supporto a campi con `attribute` in dot/arrow notation (`properties->*`) nel filtro `$fields`
- Validazione dei valori inseriti nel modale (le `->rules()` definite nella Resource vengono ignorate — comportamento coerente con il riferimento `EditStories`)

## Moduli toccati

**wm-package (unico repo coinvolto):**
- `src/Nova/Actions/BulkEditAction.php` — nuovo file
- `tests/Unit/Nova/Actions/BulkEditActionTest.php` — nuovo file (Unit)
- `tests/Feature/Nova/Actions/BulkEditActionFeatureTest.php` — nuovo file (Feature)

**camminiditalia:**
- `app/Nova/EcPoi.php` — aggiunta `new BulkEditAction(\App\Nova\EcPoi::class, ['global'])` in `actions()` con `canSee`/`canRun` limitati ad Administrator

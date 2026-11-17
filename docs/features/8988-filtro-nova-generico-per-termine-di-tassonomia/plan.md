> Ticket: oc:8988

# Piano — Filtro Nova generico per termine di tassonomia (wm-package)

Fonte dei requisiti: [overview.md](overview.md). Repo: `wm-package` (submodule montato in
forestas). Ordine di rilascio: questo repo si mergia per primo, poi forestas bumpa il puntatore.

**Commit:** i commit indicati qui sono istruzioni per la dev, da eseguire solo su sua richiesta.
Scope `oc:8988`.

**Test:** la suite del package usa il database `wm_package` (`phpunit.xml.dist`). Prima di lanciarla
verificare che non esista un `phpunit.xml` locale senza le righe `DB_*` (regola in cima al
`CLAUDE.md` del package). Si lancia nel container `php-forestas`, da `wm-package/`.

---

## Task 1 — `TaxonomyTermOptions`: le opzioni dei termini in una classe sola

File:
- nuovo `src/Support/TaxonomyTermOptions.php`
- `src/Nova/Concerns/BuildsTaxonomyFields.php`
- nuovo `tests/Feature/Support/TaxonomyTermOptionsTest.php` (o la cartella dei test di `Support`
  già esistente, se c'è)

Passi:
1. Prima del refactor, scrivere il test che fissa l'output **attuale** di `termOptions()`: tassonomia
   con tre termini in `sort_order` diverso, uno con icona e uno senza, uno senza traduzione nella
   lingua corrente (deve ripiegare sulla prima traduzione e poi sull'identifier). Il test chiama il
   form vero (`makeTermFieldsForTaxonomies()` su una Resource di prova, letto da `->meta['options']`
   o dove il campo `Choice` le espone), non il metodo privato.
2. Creare `TaxonomyTermOptions::for(TaxonomyTerm $taxonomy, string $valueKey = 'id'): array`:
   - per ogni figlio, nell'ordine di `$taxonomy->children` (oggi già ordinato come lo legge il
     trait: verificarlo, e se l'ordine viene dalla relazione, non riordinare);
   - `value` = `(string) $term->{$valueKey}`;
   - `label` = `TaxonomyTermLabel::for($term)`;
   - `svg` solo se `IconSvgService` restituisce l'icona, come oggi (`iconSvg()` del trait: portarla
     nella classe nuova o richiamarla, senza duplicarla).
3. Nel trait, sostituire le chiamate a `self::termOptions($taxonomy)` con
   `TaxonomyTermOptions::for($taxonomy)`, e togliere `termOptions()` e `termLabel()`. Cercare ogni
   altro uso di `termLabel(` nel trait prima di toglierlo e passarlo a `TaxonomyTermLabel::for()`.
4. Il test del passo 1 resta verde senza modifiche. Aggiungere un caso `'identifier'` che restituisce
   gli identifier come `value`.
5. Lanciare anche `TaxonomyTermResourceTest` e `LayerFilterZonesTabTest`.

Commit: `refactor(oc:8988): opzioni dei termini delle tassonomie in TaxonomyTermOptions`

## Task 2 — `ChoiceFilter`, il filtro di base

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2--currentvalue-convertito-nel-componente)

File:
- nuovo `src/Nova/Filters/ChoiceFilter.php`
- nuovo `tests/Feature/Nova/Filters/ChoiceFilterTest.php`

Passi:
1. Classe astratta che estende `Laravel\Nova\Filters\Filter`:
   - `public $component = 'choice-filter';` (nome del componente registrato al Task 5)
   - costruttore o setter per la modalità: `protected bool $multiple = false;` con un metodo
     `multiple(bool $multiple = true): static`
   - `searchPlaceholder(string $placeholder): static`, salvato in una proprietà
   - `abstract protected function choiceOptions(NovaRequest $request): array;` — le opzioni nel
     formato `{value, label, svg?}`, fornite dalla sottoclasse
   - `abstract protected function applyChoice(NovaRequest $request, Builder $query, array|string $value): Builder;`
2. `apply()` (final): se il valore è vuoto (`''`, `null`, `[]`) restituisce la query intatta;
   altrimenti chiama `applyChoice()`. In multiple normalizza il valore ad array, in single a stringa.
3. `options()` restituisce `choiceOptions()` con ogni `value` convertito in stringa.
4. `default()`: `[]` in multiple, `''` in single.
5. `jsonSerialize()`: aggiunge `multiple`, `searchPlaceholder` e, se il filtro ha già un valore,
   `currentValue` convertito in stringa (singolo o array). Verificare nel sorgente di
   `Laravel\Nova\Filters\Filter::jsonSerialize()` come Nova espone `currentValue` e `options`, e
   adattare la conversione al punto in cui il valore arriva.
6. `key()` non ridefinita: resta il nome della classe.
7. Test con una sottoclasse di prova nel file di test (opzioni con id interi, query su una tabella
   qualsiasi del package):
   - opzioni con `value` intero → stringa;
   - `currentValue` da un link con `[12, 13]` interi → `['12', '13']`;
   - valore vuoto (`''`, `[]`, `null`) non tocca la query;
   - `default()` per modalità;
   - `searchPlaceholder` e `multiple` nel JSON;
   - `key()` = nome della classe.

Commit: `feat(oc:8988): ChoiceFilter, filtro Nova di base con il selettore di Choice`

## Task 3 — `TaxonomyTermFilter`

File:
- nuovo `src/Nova/Filters/TaxonomyTermFilter.php`
- nuovo `tests/Feature/Nova/Filters/TaxonomyTermFilterTest.php`

Passi:
1. `class TaxonomyTermFilter extends ChoiceFilter`, costruttore
   `__construct(string $taxonomyIdentifier, bool $multiple = false)`.
2. Tassonomia trovata una volta per istanza (memorizzata in una proprietà) con
   `TaxonomyTerm::query()->taxonomies()->where('identifier', $this->taxonomyIdentifier)->first()`.
3. `choiceOptions()`: `[]` se la tassonomia non c'è, altrimenti
   `TaxonomyTermOptions::for($taxonomy, 'identifier')`.
4. `applyChoice()`: tiene solo i valori che sono identifier di figli di quella tassonomia; se non ne
   resta nessuno, `whereRaw('1 = 0')` (risultato vuoto, mai allargato); altrimenti
   `whereHas('taxonomyTerms', fn ($q) => $q->whereIn('taxonomy_terms.identifier', $valid))`.
5. `key()`: derivata dall'identifier, per esempio `'taxonomy_term_'.$this->taxonomyIdentifier`.
6. `name()`: `TaxonomyTermLabel::for($taxonomy)` se la tassonomia esiste, altrimenti l'identifier.
7. Test:
   - opzioni in ordine di `sort_order`, `value` = identifier;
   - single: un termine → solo le tracce con quel termine;
   - multiple: due termini → OR;
   - due istanze con identifier diversi sulla stessa query → AND, `key()` diverse;
   - tassonomia inesistente → nessuna opzione, nessun errore;
   - identifier di un'altra tassonomia ignorato;
   - solo valori non validi → risultato vuoto;
   - titolo tradotto in it e en, con ripiego sull'identifier.

Commit: `feat(oc:8988): TaxonomyTermFilter, filtro per i termini di una tassonomia`

## Task 4 — `ChoicePicker.vue` estratto da `FormField.vue`

File:
- nuovo `src/Nova/Fields/Choice/resources/js/components/ChoicePicker.vue`
- `src/Nova/Fields/Choice/resources/js/components/FormField.vue`

Passi:
1. Spostare in `ChoicePicker.vue` input di ricerca, chip e dropdown (template righe 5-50) con lo stato
   che servono (`searchQuery`, `showDropdown`, `highlightedIndex`, `filteredOptions`) e i metodi
   `filterOptions`, `selectOption`, `removeItem`, `handleKeydown`, `handleClickOutside`.
2. Props di `ChoicePicker`: `options`, `multiple`, `maxItems`, `searchPlaceholder`, `iconClass`,
   `inputId`, `inputClass`, `modelValue` (array degli **oggetti** opzione scelti, come oggi
   `selectedItems`). Emette `update:modelValue` a ogni scelta o rimozione.
3. `FormField.vue` tiene identici `setInitialValue()`, `formValue`, `fill()`, `annunciaCambio()`, il
   campo nascosto e `DefaultField`; usa `<ChoicePicker v-model="selectedItems" …>` e chiama
   `annunciaCambio()` sull'evento di aggiornamento.
4. Nessun cambio di comportamento: il confronto `===`, la ricerca su `label` e `value`, la chiusura
   al clic fuori restano come sono.

Commit: insieme al Task 6 (il `dist/` va nello stesso commit dei sorgenti).

## Task 5 — `FilterField.vue` e registrazione

File:
- nuovo `src/Nova/Fields/Choice/resources/js/components/FilterField.vue`
- `src/Nova/Fields/Choice/resources/js/field.js`

Passi:
1. `FilterField.vue` segue `SelectFilter.vue` di Nova
   (`vendor/laravel/nova/resources/js/components/Filters/SelectFilter.vue`): `FilterContainer`, props
   `resourceName`, `filterKey`, `lens`; `filter` letto dallo store
   (`this.$store.getters[`${this.resourceName}/getFilter`](this.filterKey)`, come fa Nova).
2. Stato: `selectedItems` = opzioni del filtro il cui `value` è in `currentValue` (array in multiple,
   stringa in single), ricostruito alla creazione e su `filter-reset`.
3. A ogni cambio di `selectedItems` (watch, con debounce come `SelectFilter`): commit
   `updateFilterState` con valore array di `value` in multiple, stringa o `''` in single, poi
   `$emit('change')`.
4. `<ChoicePicker>` dentro il filtro, con uno stile solo di `FilterField.vue` che mette il dropdown
   nel flusso (`position: static`, niente `absolute`). Niente `<teleport>`.
5. In `field.js` registrare `app.component('choice-filter', FilterField)`.

## Task 6 — Build del `dist/`

Passi:
1. Seguire alla lettera `src/Nova/Fields/Choice/README.md`: `laravel-nova-devtool` dal repository
   privato di Nova, dentro il container, `laravel/nova` 5.7.6, `webpack` 5.103.0,
   `externals: { vue: 'Vue' }`.
2. `npm run prod` **dentro** `src/Nova/Fields/Choice/`, mai dalla root.
3. Controllare il diff del `dist/` (niente prop spurie) e `git status` (niente file fuori dal lavoro).

Commit: `feat(oc:8988): selettore di Choice condiviso fra form e filtri`

## Task 7 — README di `Choice`

File: `src/Nova/Fields/Choice/README.md`

Due esempi d'uso:
- `new TaxonomyTermFilter('route-type', multiple: true)` in `filters()` di una Resource;
- un filtro che estende `ChoiceFilter` con `choiceOptions()` e `applyChoice()` propri.

Commit: `docs(oc:8988): esempi d'uso dei filtri di Choice`

## Task 8 — Prova a mano (in forestas, dopo il Task 6)

1. `sardegnasentieri:configure-taxonomy-terms` con `--dry-run`, poi senza `--fresh`.
2. Form: una traccia con termini in una tassonomia single e in una multiple. Salvare senza modifiche
   → termini invariati. Cambiare un termine → cambia solo quello. Stessa prova su un campo
   `IconSelect`.
3. Filtro (con la parte forestas del piano): scegliere più valori di fila in un filtro multiple senza
   che il dropdown o il menu si chiudano; dropdown visibile per intero con tutti i filtri aperti;
   ricerca con le aree catastali (id interi); togliere l'ultima chip → contatore a zero; «Reset
   filters» → chip sparite; pagina aperta con il filtro già nell'URL → chip presenti.

## Chiusura

- `composer analyse` (PHPStan) e `composer format` limitato ai file del lavoro (controllare
  `git status` dopo).
- Pagina di conoscenza: aggiornare `docs/knowledge/campi-nova-custom-e-build.md` con i filtri di
  `Choice` (componente condiviso, `ChoiceFilter`, conversione in stringa, niente teleport).

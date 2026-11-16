> Ticket: oc:8988

# Filtro Nova generico per termine di tassonomia — parte wm-package

## Cosa cambia

Il package offre un Filter Nova nuovo, **`TaxonomyTermFilter`**, che chi monta il package richiama
nelle sue Resource. Riceve l'identifier di una tassonomia di `taxonomy_terms` e la modalità di
scelta, single (default) o multiple; propone come opzioni i termini figli della tassonomia, con la
loro icona, e filtra il modello sui termini scelti. Si scrive una volta e si richiama n volte con
identifier diversi, per esempio `new TaxonomyTermFilter('route-type', multiple: true)`.

Le opzioni si mostrano con il selettore del campo `Choice` (ricerca, icone, chip dei valori scelti):
il selettore oggi dentro `FormField.vue` si estrae in un componente comune (`ChoicePicker.vue`),
usato sia dal `FormField.vue` sia da un nuovo `FilterField.vue`, registrato nello stesso `dist/`.

## Perché

I filtri dei contenuti per classificazione si appoggiavano ai layer o erano scritti uno per
tassonomia con l'identifier cablato (`EcTrackValidationStatusFilter` in Forestas). Con la tabella
unica `taxonomy_terms` un solo filtro parametrico copre tutte le tassonomie (scrum 16/11, Giuseppe:
«il filtro deve essere uno che funziona per tutti»), e il selettore deve essere lo stesso dei campi
delle tassonomie: «sia i filtri sia la tassonomia […] usano lo stesso codice» (call 16/11 10:25).

## Requisiti

- [ ] `TaxonomyTermFilter(string $taxonomyIdentifier, bool $multiple = false)`, con `$component`
      puntato al nuovo `FilterField.vue`:
  - [ ] trova la tassonomia con `TaxonomyTerm::query()->taxonomies()->where('identifier', …)`;
        se non esiste, nessuna opzione e nessun errore
  - [ ] opzioni = termini figli nell'ordine di `sort_order`, nel formato del `Choice`
        `{value, label, svg?}`: `value` = **identifier** del termine (stabile fra ambienti,
        finisce nell'URL dell'index), `label` = nome tradotto, `svg` = icona del termine
  - [ ] le opzioni si costruiscono con la stessa funzione del form delle tassonomie,
        `BuildsTaxonomyFields::termOptions()`, resa richiamabile con la chiave del valore
        (`'id'` per il form, che resta identico; `'identifier'` per il filtro): etichetta e icona
        di un termine hanno una fonte sola
  - [ ] in single il valore applicato è una stringa, in multiple un array di stringhe; un valore
        vuoto non applica il filtro
  - [ ] query: `whereHas('taxonomyTerms', …)` con `whereIn('taxonomy_terms.identifier', $values)`,
        accettando solo identifier che sono figli di quella tassonomia; OR fra i valori dello
        stesso filtro
  - [ ] se arrivano solo identifier estranei o inesistenti (link salvato con un termine
        rinominato o ritirato), il risultato è vuoto: un filtro non allarga mai i risultati
  - [ ] più istanze sulla stessa Resource si combinano in AND: ognuna ha una `key()` propria,
        derivata dall'identifier, altrimenti Nova le confonde
  - [ ] titolo (`name()`) = nome della tassonomia nella lingua corrente, con ripiego sull'identifier
  - [ ] `default()` restituisce `[]` in multiple e `''` in single: Nova conta un filtro attivo
        confrontando `JSON.stringify` di `currentValue` con l'originale
        (`store/resources.js:57-70`), e un `[]` contro `''` lascerebbe «1 filtro attivo» dopo aver
        tolto l'ultima chip
- [ ] Vue: `ChoicePicker.vue` estratto da `FormField.vue` senza cambiarne il comportamento. Il picker
      gestisce solo l'array dei valori scelti con `v-model`; `formValue()`, `fill()` e il formato
      `"12,13"` restano in `FormField.vue` identici a oggi. Il salvataggio
      (`BuildsTaxonomyFields.php:520-550`) scarta senza errore ciò che non riconosce e riallinea i
      termini alla lista ricevuta: un formato cambiato cancellerebbe in silenzio i termini delle
      tracce salvate, in ogni shard
- [ ] Vue: `FilterField.vue` usa `ChoicePicker` e segue `SelectFilter.vue` di Nova: `filterKey` e
      `resourceName` dalle props, stato letto dallo store e cambio segnalato con
      `updateFilterState` e `change`, emette il valore vuoto della sua modalità, ascolta
      `filter-reset` e risincronizza le chip da `currentValue`, anche all'apertura di una pagina
      con il filtro già nell'URL; mostra le stesse icone dell'edit
- [ ] `dist/` ricompilato con la toolchain del README di `Choice` (`laravel-nova-devtool`, Nova
      5.7.6, `webpack` 5.103.0, `externals: { vue: 'Vue' }`)
- [ ] Test Pest: opzioni, single, multiple (OR), due istanze in AND, tassonomia inesistente,
      identifier di un'altra tassonomia ignorato, solo valori non validi → risultato vuoto,
      titolo tradotto, `default()` per modalità
- [ ] Prova a mano: form di `Choice` (edit di una traccia con termini in una tassonomia single e in
      una multiple, salvataggio senza modifiche → termini invariati; un termine cambiato → cambia
      solo quello) e di `IconSelect`, che estende `Choice`; nel menu dei filtri, se l'elenco delle
      opzioni risulta tagliato dal contenitore (`ScrollWrap` con `max-height: 350px`), si porta
      fuori con `<teleport to="body">`
- [ ] Esempio d'uso del filtro nel README di `Choice`

## Rischi

- **Refactor di `termOptions()` e di `FormField.vue`**: il form delle tassonomie e `IconSelect`
  usano `Choice` in tutti gli shard. Mitigazione: il form continua a ricevere `value` = `id` e lo
  stesso formato; test esistenti del package (`TaxonomyTermResourceTest`,
  `LayerFilterZonesTabTest`) e prova a mano del salvataggio prima del merge.
- **Build del `dist/`**: richiede le credenziali del repository privato di Nova e versioni pinnate;
  un `dist/` compilato male rompe il campo ovunque. Mitigazione: seguire il README alla lettera.

## Out of scope

- Usare `TaxonomyTermFilter` sulle Resource del package: il package lo offre, sono gli shard a
  richiamarlo.
- Un filtro di base per opzioni che non vengono da `taxonomy_terms` (per esempio le aree
  catastali di Forestas, che restano sul loro componente): dipende dalla domanda aperta
  nell'overview di Forestas
  (`docs/features/8988-filtro-nova-generico-per-termine-di-tassonomia/overview.md`).

## Moduli toccati

Tutti nel repo `wm-package`:

- `src/Nova/Filters/TaxonomyTermFilter.php` (nuovo)
- `src/Nova/Concerns/BuildsTaxonomyFields.php` (`termOptions()` richiamabile con la chiave del valore)
- `src/Nova/Fields/Choice/resources/js/components/ChoicePicker.vue` (nuovo)
- `src/Nova/Fields/Choice/resources/js/components/FormField.vue`
- `src/Nova/Fields/Choice/resources/js/components/FilterField.vue` (nuovo)
- `src/Nova/Fields/Choice/resources/js/field.js`
- `src/Nova/Fields/Choice/dist/`
- `src/Nova/Fields/Choice/README.md`
- `tests/Feature/Nova/` (test nuovi)

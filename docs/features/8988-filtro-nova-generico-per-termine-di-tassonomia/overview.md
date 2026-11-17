> Ticket: oc:8988

# Filtro Nova generico per termine di tassonomia — parte wm-package

## Cosa cambia

Il package offre due Filter Nova nuovi, che chi monta il package richiama o estende nelle sue
Resource:

- **`ChoiceFilter`**, il filtro di base: mostra le opzioni con il selettore del campo `Choice`
  (ricerca, icone, chip dei valori scelti), in modalità single o multiple. Non sa da dove vengono
  le opzioni: le fornisce chi lo estende, nel formato del `Choice` `{value, label, svg?}`, insieme
  alla query. Serve anche per opzioni che non sono termini di tassonomia (in Forestas, le aree
  catastali).
- **`TaxonomyTermFilter`**, che estende `ChoiceFilter`: riceve l'identifier di una tassonomia di
  `taxonomy_terms` e la modalità di scelta, single (default) o multiple; propone come opzioni i
  termini figli della tassonomia, con la loro icona, e filtra il modello sui termini scelti. Si
  scrive una volta e si richiama n volte con identifier diversi, per esempio
  `new TaxonomyTermFilter('route-type', multiple: true)`.

Il selettore oggi dentro `FormField.vue` si estrae in un componente comune (`ChoicePicker.vue`),
usato sia dal `FormField.vue` sia da un nuovo `FilterField.vue`, registrato nello stesso `dist/`.
`FilterField.vue` è il componente di `ChoiceFilter`, quindi di tutti e due i filtri.

## Perché

I filtri dei contenuti per classificazione si appoggiavano ai layer o erano scritti uno per
tassonomia con l'identifier cablato (`EcTrackValidationStatusFilter` in Forestas). Con la tabella
unica `taxonomy_terms` un solo filtro parametrico copre tutte le tassonomie (scrum 16/11, Giuseppe:
«il filtro deve essere uno che funziona per tutti»), e il selettore deve essere lo stesso dei campi
delle tassonomie: «sia i filtri sia la tassonomia […] usano lo stesso codice» (call 16/11 10:25).

Nella stessa call, sul componente outline usato nei filtri (`outl1ne/nova-multiselect-filter`):
«smettiamo di usarlo, cioè là dove c'è da fare una select si usa il choice field». Un filtro con
opzioni che non vengono da `taxonomy_terms` non può usare `TaxonomyTermFilter`: per questo la parte
che non dipende dalle tassonomie (componente, modalità, valore vuoto) sta in un filtro di base a sé,
`ChoiceFilter`, che Forestas usa per le aree catastali (review del 17/11).

## Requisiti

- [ ] `ChoiceFilter`, filtro astratto di base, con `$component` puntato al nuovo `FilterField.vue`:
  - [ ] modalità single (default) o multiple, scelta da chi lo usa
  - [ ] le opzioni le fornisce la sottoclasse, nel formato del `Choice` `{value, label, svg?}`
  - [ ] i valori viaggiano come stringhe: il selettore lavora solo con stringhe (la ricerca fa
        `option.value.toLowerCase()`, `FormField.vue:162`; le chip si ricostruiscono con
        `values.includes(option.value)`, cioè `===`, `FormField.vue:124`). Con un `value` intero la
        ricerca va in `TypeError` alla prima lettera, e un link salvato con id interi filtra senza
        mostrare le chip. `ChoiceFilter` converte in stringa il `value` delle opzioni e il valore
        che arriva ad `apply()`; i valori letti dall'URL li converte `FilterField.vue`, perché Nova
        li scrive nello store nel browser senza ripassare dal PHP (`Filter::jsonSerialize()` manda
        sempre `default()` come `currentValue`). Non nelle sottoclassi, così vale per chiunque
        estenda il filtro, e non in `ChoicePicker`, che resta lo stesso del form
  - [ ] `apply()` lo scrive la sottoclasse; `ChoiceFilter` non applica nulla quando il valore è
        vuoto (stringa vuota, `null`, array vuoto)
  - [ ] in single il valore applicato è un valore solo, in multiple un array
  - [ ] `default()` restituisce `[]` in multiple e `''` in single: Nova conta un filtro attivo
        confrontando `JSON.stringify` di `currentValue` con l'originale
        (`store/resources.js:57-70`), e un `[]` contro `''` lascerebbe «1 filtro attivo» dopo aver
        tolto l'ultima chip
  - [ ] placeholder della ricerca configurabile, come `searchPlaceholder()` del campo `Choice`
  - [ ] `key()` resta quella di default di Nova (il nome della classe) se la sottoclasse non la
        ridefinisce: chi migra un filtro esistente su `ChoiceFilter` conserva i link salvati
- [ ] `TaxonomyTermFilter(string $taxonomyIdentifier, bool $multiple = false)`, che estende
      `ChoiceFilter`:
  - [ ] trova la tassonomia con `TaxonomyTerm::query()->taxonomies()->where('identifier', …)`;
        se non esiste, nessuna opzione e nessun errore
  - [ ] opzioni = termini figli nell'ordine di `sort_order`: `value` = **identifier** del termine
        (stabile fra ambienti, finisce nell'URL dell'index), `label` = nome tradotto, `svg` = icona
        del termine
  - [ ] le opzioni si costruiscono con la stessa funzione del form delle tassonomie, vedi il
        requisito su `TaxonomyTermOptions`
  - [ ] in single il valore applicato è una stringa, in multiple un array di stringhe
  - [ ] query: `whereHas('taxonomyTerms', …)` con `whereIn('taxonomy_terms.identifier', $values)`,
        accettando solo identifier che sono figli di quella tassonomia; OR fra i valori dello
        stesso filtro
  - [ ] se arrivano solo identifier estranei o inesistenti (link salvato con un termine
        rinominato o ritirato), il risultato è vuoto: un filtro non allarga mai i risultati
  - [ ] più istanze sulla stessa Resource si combinano in AND: ognuna ha una `key()` propria,
        derivata dall'identifier, altrimenti Nova le confonde
  - [ ] titolo (`name()`) = nome della tassonomia nella lingua corrente, con ripiego sull'identifier
- [ ] Opzioni dei termini in una classe di supporto, `Wm\WmPackage\Support\TaxonomyTermOptions`
      (per esempio `for(TaxonomyTerm $taxonomy, string $valueKey = 'id')`), usata dal form e dal
      filtro:
  - [ ] oggi la funzione è `termOptions()`, `private static` nel trait `BuildsTaxonomyFields`
        (riga 795): un filtro non la può chiamare senza usare il trait, che porta con sé i metodi
        delle Resource, e chiamarla direttamente sul trait è deprecato da PHP 8.1
  - [ ] l'etichetta viene da `TaxonomyTermLabel::for()`: oggi la stessa logica è scritta due
        volte, in `termLabel()` del trait (righe 822-830) e in `TaxonomyTermLabel`, e deve restare
        una
  - [ ] il trait chiama `TaxonomyTermOptions` con `'id'`; `termOptions()` e `termLabel()` del trait
        si tolgono. Il filtro la chiama con `'identifier'`
  - [ ] test: per una tassonomia con termini, con e senza icona e con una traduzione mancante, le
        opzioni del form sono identiche a quelle di prima del refactor
- [ ] Vue: `ChoicePicker.vue` estratto da `FormField.vue` senza cambiarne il comportamento. Il picker
      gestisce solo l'array dei valori scelti con `v-model`; `formValue()`, `fill()` e il formato
      `"12,13"` restano in `FormField.vue` identici a oggi. Il salvataggio (`fillUsing` di
      `makeTermFieldsForTaxonomies`, `BuildsTaxonomyFields.php:569-605`) scarta senza errore ciò
      che non riconosce e riallinea i termini alla lista ricevuta: un formato cambiato
      cancellerebbe in silenzio i termini delle tracce salvate, in ogni shard
- [ ] Vue: `FilterField.vue` usa `ChoicePicker` e segue `SelectFilter.vue` di Nova: `filterKey` e
      `resourceName` dalle props, stato letto dallo store e cambio segnalato con
      `updateFilterState` e `change`, emette il valore vuoto della sua modalità, ascolta
      `filter-reset` e risincronizza le chip da `currentValue`, anche all'apertura di una pagina
      con il filtro già nell'URL, convertendo in stringa i valori che trova; mostra le icone quando le opzioni le hanno. Non sa nulla di
      tassonomie: lo usano tutti i `ChoiceFilter`
- [ ] Vue: dentro `FilterField.vue`, con uno stile suo, il dropdown del picker sta nel flusso
      (`position: static`) invece che sovrapposto (`absolute z-50`, `FormField.vue:32-34`): nel menu
      dei filtri di Nova, scrollabile e alto 350px, un dropdown sovrapposto resta tagliato, e lo
      scroll del menu lo mostra intero solo se occupa spazio. Niente `<teleport to="body">`: il
      picker chiude il dropdown sui clic fuori dal proprio elemento (`FormField.vue:218-219`), e con
      le opzioni portate su `body` si chiuderebbe a ogni scelta, insieme al menu di Nova.
      `ChoicePicker` e il form non cambiano
- [ ] `dist/` ricompilato con la toolchain del README di `Choice` (`laravel-nova-devtool`, Nova
      5.7.6, `webpack` 5.103.0, `externals: { vue: 'Vue' }`)
- [ ] Test Pest di `ChoiceFilter`, con una sottoclasse di prova: opzioni con `value` intero
      convertite in stringa, valore di un link con `[12, 13]` interi passato alla sottoclasse come
      `['12', '13']`, valore vuoto non applica il filtro, `default()` per modalità, placeholder,
      `key()` = nome della classe
- [ ] Test Pest di `TaxonomyTermFilter`: opzioni, single, multiple (OR), due istanze in AND,
      tassonomia inesistente, identifier di un'altra tassonomia ignorato, solo valori non validi →
      risultato vuoto, titolo tradotto
- [ ] Prova a mano: form di `Choice` (edit di una traccia con termini in una tassonomia single e in
      una multiple, salvataggio senza modifiche → termini invariati; un termine cambiato → cambia
      solo quello) e di `IconSelect`, che estende `Choice`; nel menu dei filtri, scelta di più
      valori di fila in un filtro multiple senza che il dropdown o il menu si chiudano, e dropdown
      visibile per intero anche con tutti i filtri aperti; una pagina aperta da un link con id
      interi mostra le chip (è l'unico controllo della conversione in `FilterField.vue`)
- [ ] Esempi d'uso nel README di `Choice`: `TaxonomyTermFilter` richiamato su una Resource, e un
      filtro che estende `ChoiceFilter` con opzioni proprie

## Rischi

- **Refactor di `termOptions()` e di `FormField.vue`**: il form delle tassonomie e `IconSelect`
  usano `Choice` in tutti gli shard. Se dopo l'estrazione di `ChoicePicker` il form inviasse un
  formato diverso da `"12,13"`, il salvataggio (`BuildsTaxonomyFields.php:569-605`) toglierebbe
  alla traccia i termini che non riconosce, al primo salvataggio anche senza modifiche.
  Mitigazione: le opzioni del form sono coperte dal test di `TaxonomyTermOptions`; il formato
  inviato dal Vue invece nessun test PHP lo esegue (`TaxonomyTermResourceTest` e
  `LayerFilterZonesTabTest` non toccano il Vue), quindi la protezione è la prova a mano dei
  requisiti, prima del merge: salvataggio senza modifiche → termini invariati.
- **Build del `dist/`**: richiede le credenziali del repository privato di Nova e versioni pinnate;
  un `dist/` compilato male rompe il campo ovunque. Mitigazione: seguire il README alla lettera.

## Out of scope

- Usare `TaxonomyTermFilter` o `ChoiceFilter` sulle Resource del package: il package li offre, sono
  gli shard a richiamarli.
- Migrare a `ChoiceFilter` gli altri filtri del package (`src/Nova/Filters/`): si fa quando servirà.
- `outl1ne/nova-multiselect-field`: è nel `composer.json` del package ed è usato da
  `src/Nova/App.php` (sette `Multiselect::make`); sostituirlo con `Choice` è un lavoro a sé, che
  riguarda tutti gli shard.

## Moduli toccati

Tutti nel repo `wm-package`:

- `src/Nova/Filters/ChoiceFilter.php` (nuovo)
- `src/Nova/Filters/TaxonomyTermFilter.php` (nuovo)
- `src/Support/TaxonomyTermOptions.php` (nuovo)
- `src/Nova/Concerns/BuildsTaxonomyFields.php` (usa `TaxonomyTermOptions`; via `termOptions()` e
  `termLabel()`)
- `src/Nova/Fields/Choice/resources/js/components/ChoicePicker.vue` (nuovo)
- `src/Nova/Fields/Choice/resources/js/components/FormField.vue`
- `src/Nova/Fields/Choice/resources/js/components/FilterField.vue` (nuovo)
- `src/Nova/Fields/Choice/resources/js/field.js`
- `src/Nova/Fields/Choice/dist/`
- `src/Nova/Fields/Choice/README.md`
- `tests/Feature/Nova/` (test nuovi)

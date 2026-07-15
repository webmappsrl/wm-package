> Ticket: oc:8241

# Plan — Campo Nova custom Poi/Traccia (Model + Search filtrata)

**Revisione 3**: sostituisce il plan "due Select sempre visibili" per il box Geo. Riferimento: `overview.md` (revisione 3, approvata). Le tassonomie sono fuori scope e già ripristinate all'originale.

Tutti i file sono in `wm-package/` (submodule, branch `oc_8241`).

---

## Task 1 — Scaffold del campo Nova custom

File: `wm-package/src/Nova/Fields/GeoReferenceField/` (nuovo, mirror della struttura di `IconSelect`)

- `src/GeoReferenceField.php` — classe `Field`, `$component = 'geo-reference-field'`
  - `poiOptions(array $options): self` / `trackOptions(array $options): self` — passano le opzioni via `withMeta()`
  - Override `resolveAttribute($resource, $attribute)` (o `resolve()`): legge `poi_id`/`track_id` dalla riga, restituisce `['type' => 'poi'|'track'|null, 'id' => int|null]` come valore del campo
  - Override `fillAttributeFromRequest()`/`fillInto()`: decodifica il valore submitted (`{type, id}`) e scrive esplicitamente `poi_id`/`track_id` sul Fluent (`$model->poi_id = ...`, `$model->track_id = ...`), azzerando l'altro
- `src/FieldServiceProvider.php` — mirror di `IconSelect/src/FieldServiceProvider.php`: `Nova::mix('geo-reference-field', __DIR__.'/../dist/mix-manifest.json')` in `boot()`
- `composer.json` del sotto-pacchetto (se il pattern esistente lo richiede — verificare come `IconSelect`/`BboxField` vengono auto-discovered) o registrazione esplicita in `WmPackageServiceProvider`

## Task 2 — Componente Vue

File: `wm-package/src/Nova/Fields/GeoReferenceField/resources/js/`

- `field.js` — registra il componente, mirror di `IconSelect/resources/js/field.js`
- `components/FormField.vue`:
  - Stato locale: `modelType` ('poi'|'track', inizializzato da `field.value.type`), `selectedId` (da `field.value.id`)
  - Due opzioni Model (bottoni o radio) — cambiano `modelType`
  - Una select (HTML nativa o componente semplice, no vera ricerca AJAX — coerente con `Select::searchable()` già usato altrove nel package) le cui opzioni sono un `computed` che filtra `field.meta.poiOptions`/`field.meta.trackOptions` in base a `modelType`
  - Emette il valore come JSON `{type: modelType, id: selectedId}` sull'attributo del campo
  - Help text esplicito (statico, non serve più la validazione "uno dei due" lato utente — il widget stesso impedisce di valorizzare entrambi)
- `components/DetailField.vue` / `components/IndexField.vue` — mostrano il tipo + nome risolto (label), mirror semplificato di `IconSelect`

## Task 3 — Build

- `package.json` / `webpack.mix.js` — mirror di `IconSelect`
- `npm install && npm run prod` dentro `wm-package/src/Nova/Fields/GeoReferenceField/`
- Verificare `dist/js/field.js`/`dist/mix-manifest.json` generati, nessuna proprietà spuria (prassi da CLAUDE.md, decisione oc:8093)

## Task 4 — Integrazione in `HorizontalScrollGeoItemRepeatable`

File: `wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollGeoItemRepeatable.php`

- Sostituire i due `Select::make(__('Poi'), 'poi_id')`/`Select::make(__('Track'), 'track_id')` con un solo `GeoReferenceField::make(__('Reference'), 'geo_ref')->poiOptions($this->modelOptions(EcPoiModel::class, ...))->trackOptions($this->modelOptions(EcTrackModel::class, ...))`
- L'attributo Nova (`geo_ref`) è **virtuale**: non esiste nello shape JSON finale, serve solo come chiave del campo nel form — il `fillInto()` del campo scrive direttamente `poi_id`/`track_id` sul Fluent, bypassando la necessità che `geo_ref` stesso compaia nell'output
- Rimuovere `exactlyOneOfRule()` per poi_id/track_id lato Repeatable (non più necessaria: un solo widget non può valorizzare entrambi) — la guardia server-side nel resolver resta com'è

## Task 5 — Verifica resolver (nessuna modifica prevista)

- `ConfigHomeResolver::fromGeoRepeaterItems()`/`extractGeoRepeaterFields()`/`toGeoRepeaterItems()` leggono/scrivono `poi_id`/`track_id` — shape identica, **nessuna modifica di codice attesa**
- Verificare comunque con un test end-to-end che il nuovo campo produca lo stesso payload atteso da questi metodi (via `Repeater\Presets\JSON::set()`, che chiama `fillInto()` di ogni field e scrive gli attributi risultanti su `$model->setAttribute("{$attribute}->{$itemIndex}->fields->{$k}", $v)`)

## Task 6 — Test

- Aggiornare `HorizontalScrollGeoItemRepeatableTest.php`: rimuovere i test sui due Select separati (`poi_id`/`track_id` come campi Nova distinti), sostituire con test sul nuovo campo (opzioni popolate correttamente, scoping `app_id` invariato)
- Nessun test automatico del componente Vue (fuori scope, coerente con gli altri campi custom del package)
- **Verifica manuale in browser obbligatoria** prima di considerare il task concluso: primo utilizzo di un campo custom dentro un Repeater annidato in un Flexible, nessun precedente diretto nel package

## Commit (da eseguire manualmente dopo review, non automatici)

1. `feat(oc:8241): add GeoReferenceField custom Nova field with Vue component`
2. `feat(oc:8241): integrate GeoReferenceField into HorizontalScrollGeoItemRepeatable`
3. `test(oc:8241): update tests for the custom geo reference field`

---

# Revisione 5 — Follow-up post-testing

Riferimento: `overview.md` (sezione "Revisione 5", approvata). Tutti i file restano in `wm-package/` (branch `oc_8241`), nessun file custom Forestas coinvolto. Nessuna modifica prevista a `ConfigHomeResolver.php` — la logica di ereditarietà `mergeItemTitle()`/`mergeItemImage()` gestisce già correttamente un campo `title` assente dal payload.

## Task 7 — Titolo: da editabile a readonly

File: `wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollGeoItemRepeatable.php`

- Rimuovere il blocco `foreach ($this->translatableFields(__('Title'), 'title') as $field) { ... }` (il `KeyValue` editabile multilingua)
- Aggiungere un nuovo metodo privato `resolveInheritedTitle($resource): string`:
  - Legge `$resource->poi_id` / `$resource->track_id` dal Fluent della riga corrente
  - Se valorizzato, carica il modello (`EcPoiModel`/`EcTrackModel`) e restituisce il nome cascade `it → en → prima lingua disponibile` (vedi Task 9, metodo condiviso)
  - Se l'item non ha ancora né `poi_id` né `track_id` (riga nuova, non salvata) o il modello non è trovato, restituisce stringa vuota
- Sostituire con `Text::make(__('Title'), 'title')->readonly()->resolveUsing(fn ($value, $resource) => $this->resolveInheritedTitle($resource))->help(__('Automatically inherited from the linked Poi/Track. Read-only, shown after the item has been saved.'))`
- **Non serve nessuna modifica a `ConfigHomeResolver`**: il campo essendo `readonly()` non viene incluso da Nova nel payload inviato al salvataggio, quindi `$fields['title']` risulta assente lato resolver esattamente come oggi con un `title` vuoto — `mergeItemTitle()` eredita già interamente dal modello in questo caso (verificato in Fase: challenge)

## Task 8 — Terminologia: label "Reference" → "Model"

File: `wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollGeoItemRepeatable.php`

- `GeoReferenceField::make(__('Reference'), 'geo_ref')` → `GeoReferenceField::make(__('Model'), 'geo_ref')`
- Nessuna nuova voce di traduzione: la chiave `"Model"` esiste già in `resources/lang/it.json`/`en.json` (riga 99)
- Non toccare: nome classe `GeoReferenceField`, attributo `geo_ref`, namespace, componente Vue `geo-reference-field` (fuori scope per decisione esplicita)

## Task 9 — Fallback multilingua coerente (cascade condiviso)

File: `wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollGeoItemRepeatable.php`

- Estrarre un nuovo metodo privato condiviso `cascadeTranslation(array $translations): string` — prova `it`, poi `en`, poi la prima entry non vuota tra le restanti; stringa vuota se nessuna traduzione è valorizzata (stesso pattern già in uso in `LayerAnalyticsCard`, oc:7648, citato in `wm-package/CLAUDE.md`)
- Riusare `cascadeTranslation()` sia in `resolveInheritedTitle()` (Task 7) sia in `modelLabel()` (oggi cascade limitata a `it → en → '#id'`, ignora `fr`/`es`/`de`) — `modelLabel()` chiama `cascadeTranslation($name)` e ricade su `'#'.$fallbackId` solo se il risultato è vuoto

## Task 10 — Fix bug perdita silenziosa dell'item al cambio Model

File: `wm-package/src/Nova/Fields/GeoReferenceField/resources/js/components/FormField.vue`, `wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollGeoItemRepeatable.php`

- **Vue** (`FormField.vue::fill()`): appendere il JSON `{type, id}` **solo se** `this.selectedId` è valorizzato; se l'admin ha cambiato Model (`selectModelType()` azzera `selectedId`) senza selezionare un nuovo valore, non appendere nulla per `this.fieldAttribute` (il campo risulta "assente" nella request, non `{type:null,id:null}`)
- **PHP** (`HorizontalScrollGeoItemRepeatable::fields()`): aggiungere `->rules('required')` al `GeoReferenceField::make(...)`, cosi un item con Model cambiato ma nessuna nuova selezione blocca il salvataggio con un errore di validazione Nova invece di far sparire silenziosamente l'item (oggi: `ConfigHomeResolver::fromGeoRepeaterItems()` scarta silenziosamente un item con né `poi_id` né `track_id` valorizzati)
- **Verifica manuale obbligatoria**: confermare in browser che Nova propaga e mostra l'errore di validazione per un campo custom dentro un Repeater annidato in un Flexible — nessun precedente diretto nel package per questa combinazione (stessa cautela già nota per `dependsOn()` in questo box)

## Task 11 — Test

File: `wm-package/tests/Unit/Nova/Flexible/HorizontalScrollGeoItemRepeatableTest.php`

- `test_model_field_has_updated_label()` — label del `GeoReferenceField` è `"Model"`, non più `"Reference"`
- `test_model_field_has_required_rule()` — il `GeoReferenceField` ha la regola `required` tra le sue `rules`
- `test_readonly_title_field_falls_back_through_locales()` — per un item con `poi_id`/`track_id` valorizzato, il campo `title` risolve il nome cascade `it→en→prima disponibile` a seconda di quali traduzioni sono valorizzate sul modello collegato (coprire almeno: solo `it`, solo `en`, solo una terza lingua, nessuna)
- `test_readonly_title_field_is_empty_for_new_item()` — per una riga senza `poi_id`/`track_id` (item nuovo, non salvato), il campo `title` risolve a stringa vuota
- `test_model_options_label_falls_back_through_all_configured_locales()` — `modelOptions()`/`modelLabel()` usano lo stesso cascade `it→en→prima disponibile` (non più limitato a `it→en→#id`)
- Nessun test automatico del componente Vue (fuori scope, coerente con la decisione originale)

## Task 12 — Build dist e verifica manuale

- `npm run prod` dentro `wm-package/src/Nova/Fields/GeoReferenceField/` (solo `FormField.vue` è cambiato in questo round) — **attenzione**: nel ciclo originale la build era bloccata da un problema di licenza Nova nell'ambiente (`notes.md`, "Blocco ambientale"), verificare che sia ancora un problema o sia stato risolto nel frattempo
- Verificare che il `dist/js/field.js` ricompilato non contenga proprietà spurie rispetto alla sorgente (prassi da CLAUDE.md, decisione oc:8093)
- **Verifica manuale in Nova** (checklist):
  - Titolo: non visibile su un item nuovo non salvato; readonly con il nome corretto dopo il salvataggio; help text visibile
  - Label "Model" al posto di "Reference"
  - Cambiare Model su un item esistente senza riselezionare un valore, salvare → errore di validazione visibile, item **non** sparisce
  - Immagine: comportamento invariato (già editabile, nessuna modifica in questo round)

## Notes da aggiornare

- Documentare in `notes.md` i rischi emersi in Fase: challenge **non risolti in questo ciclo** (fuori scope, per decisione esplicita): riferimenti orfani mostrati come campo vuoto, preload dataset senza paginazione, duplicati non impediti tra righe del repeater
- Documentare la stima approvata dal dev (1h, esplicitamente sotto la stima proposta di 6h) e la decisione di non aggiornarla su Orchestrator

## Commit (da eseguire manualmente dopo review, non automatici)

4. `fix(oc:8241): make title read-only and inherited from linked Poi/Track`
5. `feat(oc:8241): rename Reference field label to Model`
6. `fix(oc:8241): require Model selection to prevent silent item loss on toggle`
7. `fix(oc:8241): align model option labels to the it→en→fallback locale cascade`
8. `test(oc:8241): cover read-only title, required validation and locale fallback`

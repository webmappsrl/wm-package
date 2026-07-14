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

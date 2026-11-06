> Ticket: oc:8944

# Notes — Copia codice web component sulla detail view della EcTrack (wm-package)

## Divergenze dal piano, task per task

### Task 3: Field sulla Resource EcTrack

- Il piano calcolava i layer una volta all'inizio di `fields()`. Ma su edit e update Nova risolve e autorizza i Field prima di scartare quelli `onlyOnDetail` (`ResolvesFields::resolveFields()`), quindi la query sarebbe partita e il risultato buttato. Il calcolo è pigro e `canSee` vale solo su `isResourceDetailRequest()`; test `it does not query the layers outside the detail view`. (Una prima versione escludeva la index, che però non era a rischio: lì i Field `onlyOnDetail` vengono scartati prima di `canSee`. Corretto dopo la review finale.)
- Il metodo che costruisce il Field è `protected webComponentField(NovaRequest $request): Text`, non previsto dal piano.

### Task 4: Verifica manuale nel browser

- L'estensione Chrome non era collegata: HTML verificato lato server con tinker dallo shard camminiditalia (EcTrack 522 con i layer 47 e 63; Layer 47 con lo snippet di prima, senza `track-id`). Il comportamento nel browser (select, copia di #63, sezioni tassonomie nascoste, Layer ed EcPoi) l'ha poi verificato a mano il dev, con esito positivo (06/11/2026).

## Bug trovati

- `wm-package/tests/Feature/LayerWebComponentCopyButtonTest.php` non gira in nessuna suite: usa `Tests\TestCase` e `App\Nova\Layer` (classi dello shard), e dal package fallisce con `Class "Tests\TestCase" not found`; la suite di camminiditalia legge solo `tests/`. Il contenuto è ricoperto dal nuovo `tests/Feature/Nova/LayerMapWebComponentServiceTest.php` del package.

## Decisioni

- Cleanup dopo `wm-review-ticket` (06/11/2026, richiesti dal dev): il Field del Layer chiama direttamente `LayerMapWebComponentService` (`isEnabledFor()`, `snippet()`, `renderCopyButton()`); tolti `shouldShowLayerWebComponentCopyButton()` (doppione del controllo del flag) e i wrapper privati. `getLayerAppProperties()` resta, lo usano le card analytics. `tests/Feature/LayerWebComponentCopyButtonTest.php` riscritto col `TestCase` del package (prima non girava in nessuna suite e interrogava i metodi tolti). Docblock di `App::renderCopyLinkButton()` riallineato.

- PHPStan: i 3 errori nuovi sui file toccati (proprietà magica `appOwner`, nullsafe inutile, tipo di ritorno `Eloquent\Collection`) sono corretti con `getRelationValue('appOwner')` + `instanceof App` e `->toBase()`; gli errori preesistenti su `App.php` (28) e `Layer.php` (6) sono invariati.

- Branch da `origin/develop` (`2ae05e07`), che contiene oc:8910: integrazione nello shard camminiditalia dentro oc:8944, decisa col dev.
- Il flag resta `layer_web_component_enabled`; label e help diventano generici («layers and tracks» / «layer e tracce»), perché la Resource App è condivisa da tutti gli shard.
- Aggiunta la chiave di traduzione `Layer` (aria-label della select) nelle 5 lingue.
- La costante dello script URL passa da `private const` di `Layer` a `public const DEFAULT_SCRIPT_URL` di `LayerMapWebComponentService`, con lo stesso commento di oc:8590.

## Follow-up

- Label dell'option con `getStringName()` (sempre la stessa lingua): segnalata in review, lasciata così.


> Ticket: oc:8944

# Copia codice web component sulla detail view della EcTrack — piano wm-package

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** la detail view della Resource EcTrack mostra il bottone «Copia codice web component» con lo snippet `wm-layer-map` del layer più `track-id`, e una select del layer quando i layer proponibili sono più di uno.

**Architecture:** la generazione dello snippet e del markup del bottone esce da `src/Nova/Layer.php` e va in un service condiviso, `LayerMapWebComponentService`. La Resource Layer lo usa con un'option sola (comportamento invariato), la Resource EcTrack con una o più option. Ogni option porta il proprio snippet in `data-snippet` e il bottone copia quello dell'option selezionata al click. L'insieme dei layer proponibili passa da un metodo `protected` della Resource EcTrack, che i consumer sovrascrivono.

**Tech Stack:** Laravel, Nova 5, Pest (suite del package).

**Base:** branch da `origin/develop` (`2ae05e07`), che include oc:8910; PR verso `develop`.

**Spec:** [overview.md](overview.md)

## Global Constraints

- La chiave del flag resta `properties->layer_web_component_enabled`: nessuna migrazione.
- Lingue: `resources/lang/{de,en,es,fr,it}.json`, base inglese; nessuna chiave mancante in nessun file.
- Label del Boolean: en «Enable Web Component copy (layers and tracks)», it «Abilita copia Web Component (layer e tracce)»; help en «Shows a button in layer and track details to copy the wm-layer-map web component snippet», it «Mostra nel dettaglio di layer e tracce un bottone per copiare il codice del web component wm-layer-map».
- Option della select: testo `<getStringName()> (#<id>)`, ordinate per id crescente, prima preselezionata.
- Testo dell'utente (nome del layer) in output solo con `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`; snippet solo in attributi `data-snippet` escapati; nessuna stringa JS costruita con testo utente.
- Identificatori in inglese; commit `feat(oc:8944): ...`, eseguiti solo dopo l'approvazione del dev.
- Comando dei test: `docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest <file>` (dalla root di camminiditalia). I test nuovi usano il `TestCase` del package (Pest.php), non `Tests\TestCase`. Le EcTrack da factory si creano con `'osmid' => null` (`.claude/rules/test.md`, oc:8543); le App con `native_app_deep_link_enabled` mai a `true`.

## Review Focus

- Nome di layer con apostrofo e `<`: la detail view non si rompe e il nome compare escapato — test in Task 3.
- Layer della traccia la cui App ha il flag spento: non compare tra le option — test in Task 3.
- Traccia con due layer, il secondo scelto nella select: viene copiato lo snippet del secondo — `data-snippet` per option in Task 3, comportamento JS verificato a mano in Task 4.
- Layer senza `appOwner` (App cancellata): escluso, nessun errore — test in Task 1.
- Snippet del Layer invariato dopo l'estrazione (stessi attributi, nessun `track-id`) — test in Task 1.

---

### Task 1: Service condiviso per snippet e bottone

**Files:**
- Create: `src/Services/LayerMapWebComponentService.php`
- Modify: `src/Nova/Layer.php:57` (costante), `:231` (Field), `:326-450` (metodi privati)
- Test: `tests/Feature/Nova/LayerMapWebComponentServiceTest.php`

**Interfaces:**
- Produces:
  - `public const DEFAULT_SCRIPT_URL = 'https://cdn.jsdelivr.net/gh/webmappsrl/wm-elements@dist/wm-layer-map/wm-layer-map.js'` (spostata da `Layer`, stesso commento)
  - `public function isEnabledFor(LayerModel $layer): bool` — `appOwner?->properties['layer_web_component_enabled']`, false se manca l'App
  - `public function snippet(LayerModel $layer, ?int $trackId = null): string` — come l'attuale `buildLayerWebComponentSnippet`, con la riga `  track-id="<id>"` subito dopo `layer-id` solo se `$trackId !== null`
  - `public function renderCopyButton(array $options): string` — `$options` = `list<array{label: string, snippet: string}>`; con 1 option nessuna `<select>`, il bottone porta `data-snippet`; con 2+ una `<select>` con un'`<option data-snippet="…">` per elemento, la prima `selected`
- I metodi privati di `Layer` (`shouldShowLayerWebComponentCopyButton`, `buildLayerWebComponentSnippet`, `renderLayerWebComponentCopyButton`) restano con lo stesso nome e delegano al service; `renderLayerWebComponentCopyButton` passa una sola option.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('builds the layer snippet without track-id', function () {
    // App con flag acceso, Layer senza track
    expect($svc->snippet($layer))
        ->toContain('<wm-layer-map')->toContain('layer-id="'.$layer->id.'"')
        ->toContain('app-id="'.$layer->app_id.'"')
        ->toContain(LayerMapWebComponentService::DEFAULT_SCRIPT_URL)
        ->not->toContain('track-id');
});
it('adds track-id after layer-id when given', fn () => expect($svc->snippet($layer, 1186))->toContain("layer-id=\"{$layer->id}\"\n  track-id=\"1186\""));
it('is disabled when the layer has no app owner', fn () => expect($svc->isEnabledFor($layerWithoutApp))->toBeFalse());
it('renders no select with a single option', fn () => expect($svc->renderCopyButton([['label' => 'A', 'snippet' => 'x']]))->not->toContain('<select')->toContain('data-snippet="x"'));
it('renders one option per layer, first selected', function () {
    $html = $svc->renderCopyButton([['label' => 'A', 'snippet' => 's1'], ['label' => 'B', 'snippet' => 's2']]);
    expect($html)->toContain('<select')->toMatch('/<option[^>]*data-snippet="s1"[^>]*selected/')->toContain('data-snippet="s2"');
});
```

- [ ] **Step 2: Esegui e verifica che fallisca** — Expected: FAIL, classe `LayerMapWebComponentService` non trovata.

- [ ] **Step 3: Implementa il service e fai delegare `Layer`**

Il JS dell'`onclick` legge il testo così: se nel contenitore del bottone c'è una `<select>`, `select.selectedOptions[0].dataset.snippet`, altrimenti `button.dataset.snippet`. Il resto del JS (clipboard, fallback `execCommand`, label di successo/errore, helper link) resta identico a oggi. Le label passano ancora da `json_encode(JSON_HEX_*)` + `htmlspecialchars`.

- [ ] **Step 4: Esegui e verifica che passi** — Expected: 5 passed.

- [ ] **Step 5: Commit** (dopo approvazione del dev) — `refactor(oc:8944): generatore dello snippet wm-layer-map in un service condiviso`

### Task 2: Label e help del flag, traduzioni

**Files:**
- Modify: `src/Nova/App.php:363`
- Modify: `resources/lang/{de,en,es,fr,it}.json`

- [ ] **Step 1:** in `App.php` il Boolean usa `__('Enable Web Component copy (layers and tracks)')` e l'help `__('Shows a button in layer and track details to copy the wm-layer-map web component snippet')`; stesso attributo `properties->layer_web_component_enabled`.
- [ ] **Step 2:** nei 5 file di lingua: aggiungi le due chiavi nuove (testi en/it dai Global Constraints, de/es/fr tradotti), togli le due chiavi vecchie (`Enable Layer Web Component`, `Shows a button in layer details…`) se nessun altro file le usa (`grep -rn` su `src/`), e completa in de/es/fr `Web Component`, `Copy web component code`, `Code copied`, `Copy error`, `Test the copied code by pasting it into`.
- [ ] **Step 3: Verifica** — `for f in resources/lang/*.json; do jq empty $f && echo ok $f; done` (5 `ok`) e, per ognuna delle 7 chiavi, `grep -c` che dia 1 in ogni file.
- [ ] **Step 4: Commit** (dopo approvazione) — `feat(oc:8944): flag del web component con label per layer e tracce`

### Task 3: Field sulla Resource EcTrack

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-field-sulla-resource-ectrack)

**Files:**
- Modify: `src/Nova/EcTrack.php` (`fields()`, `Tab::group` Details alla riga 73; nuovo metodo)
- Test: `tests/Feature/Nova/EcTrackWebComponentCopyButtonTest.php`

**Interfaces:**
- Consumes: `LayerMapWebComponentService` (Task 1).
- Produces: `protected function webComponentLayers(NovaRequest $request): \Illuminate\Support\Collection` — layer di `$this->resource->layers()` con `appOwner` caricato, ordinati per `layers.id`, filtrati con `isEnabledFor()`. È il metodo che camminiditalia sovrascrive.

- [ ] **Step 1: Scrivi i test che falliscono** (Pest, `DatabaseTransactions`, `Queue::fake()` + `Http::fake()` come in `EcTrackEcPoiConfigDetailTest`; App con `properties.layer_web_component_enabled`, layer e traccia da factory, `$track->layers()->attach(...)`; i metodi si invocano via `ReflectionMethod`)

```php
it('offers only layers whose app has the flag on, ordered by id');      // 2 layer App ON + 1 layer App OFF → ids ON in ordine crescente
it('returns no layers for a track without layers');                     // → collection vuota, canSee del Field false
it('renders a snippet with layer-id and track-id for each option');      // html contiene data-snippet con layer-id="L1" … track-id="T" e uno con L2
it('renders no select when only one layer is offered');                 // not->toContain('<select')
it('escapes the layer name in the option text');                        // name it 'Cammino dell\'Alta Via <b>' → toContain('Cammino dell&#039;Alta Via &lt;b&gt; (#'.$id.')') e not->toContain('<b>')
```

- [ ] **Step 2: Esegui e verifica che fallisca** — Expected: FAIL, metodo `webComponentLayers` inesistente.

- [ ] **Step 3: Implementa**
  - `webComponentLayers()` come nell'Interfaces.
  - In `fields()`, subito dopo il `Tab::group` Details: `Text::make(__('Web Component'), fn () => $service->renderCopyButton($options))->asHtml()->onlyOnDetail()->canSee(fn () => $layers->isNotEmpty())`, con `$layers = $this->webComponentLayers($request)` calcolato una volta (solo se `$this->resource->exists`) e `$options` = per ogni layer `['label' => $layer->getStringName().' (#'.$layer->id.')', 'snippet' => $service->snippet($layer, $this->resource->id)]`. L'escaping della label lo fa `renderCopyButton`.
- [ ] **Step 4: Esegui e verifica che passi** — Expected: 5 passed; poi rilancia `LayerMapWebComponentServiceTest.php` (5 passed).
- [ ] **Step 5: Commit** (dopo approvazione) — `feat(oc:8944): bottone copia web component sulla detail view della EcTrack`

### Task 4: Verifica manuale nel browser

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-verifica-manuale-nel-browser)

- [ ] In Nova locale, come Administrator, apri la detail view della EcTrack 522 (layer 47 e 63): la select mostra «Cammino dei Tre Villaggi (#47)» preselezionato e «(#63)». Scegli #63, clicca il bottone, incolla: lo snippet ha `layer-id="63"` e `track-id="522"`.
- [ ] Apri una EcTrack con un solo layer: nessuna select, lo snippet copiato ha `track-id`.
- [ ] Apri la detail view di un Layer: il bottone copia lo stesso snippet di prima (nessun `track-id`).
- [ ] Console del browser senza errori nuovi al click.

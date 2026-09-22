> Ticket: oc:8590

# Fix URL script embed wm-layer-map Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Far sì che lo snippet embed generato da Nova\Layer punti sempre al widget corretto e mantenuto (`webmappsrl/wm-elements`), eliminando il meccanismo di fetch live ormai privo di un bersaglio funzionante e la sua duplicazione di fallback.

**Architecture:** Un'unica costante di classe (`Wm\WmPackage\Nova\Layer::DEFAULT_LAYER_MAP_SCRIPT_URL`) diventa la sola fonte di verità per l'URL corretto, referenziata sia dal fallback hardcoded in PHP sia dal config del package. `resolveLayerMapComponentConfig()` perde ogni logica di rete/cache e delega sempre a `getFallbackLayerMapComponentConfig()`.

**Tech Stack:** Laravel/Nova (PHP 8.1+), PHPUnit, container Docker `laravel-camminiditalia`.

**Spec:** `wm-package/docs/features/8590-embed-online-viewer-logo-poi-mancanti/overview.md`

## Global Constraints

- Nessuna modifica al repo principale camminiditalia in questo piano (tutto il codice è in `wm-package`).
- Nessuna modifica ai repository esterni `wm-elements`/`wm-layer-map` (non submodule, fuori scope).
- `tag_name` (`wm-layer-map`) e `default_style` (`display:block;width:100%;height:600px`) restano invariati.
- Il nuovo URL corretto è **esattamente**: `https://cdn.jsdelivr.net/gh/webmappsrl/wm-elements@dist/wm-layer-map/wm-layer-map.js` — deve esistere in un solo posto nel codice sorgente (nessuna stringa duplicata).
- Tutti i comandi PHP/artisan/test vanno eseguiti con `docker exec laravel-camminiditalia ...`.
- **Il test coinvolto (`wm-package/tests/Feature/LayerWebComponentCopyButtonTest.php`) NON è incluso nel testsuite di default** (`phpunit.xml` di camminiditalia scansiona solo `tests/Feature`/`tests/Unit` nella root del repo principale, non `wm-package/tests/Feature`). Verificato in questa sessione: `php artisan test --filter=LayerWebComponentCopyButtonTest` → "No tests found"; `php artisan test wm-package/tests/Feature/LayerWebComponentCopyButtonTest.php` (path esplicito) → gira e passa. **Ogni step di questo piano che esegue questo test deve usare il path esplicito**, mai `--filter` o il comando `test` senza argomenti. Questo è un gap pre-esistente (non introdotto da questo ticket) — va annotato in `notes.md` come follow-up, non corretto qui (toccare `phpunit.xml` per includere `wm-package/tests/Feature` rischia di far girare anche altri test del package incompatibili con l'ambiente camminiditalia, fuori scope).

## Review Focus

- Config con la chiave `fallback.script_url` esplicitamente sovrascritta (es. un consumer con `config/wm-package.php` locale che ridefinisce solo `fallback.script_url` e non le altre due) → deve continuare a rispettare l'override, non forzare sempre la costante.
- Layer senza `appOwner`/`app_id` (bug di dati, non di codice) → non è responsabilità di questo fix, ma lo snippet non deve fatalizzare: già gestito dal codice esistente (nessuna modifica necessaria, verificato che `buildLayerWebComponentSnippet` non richiede l'owner).
- Rimozione degli import `Cache`/`Http`/`Log` da `Layer.php` senza lasciare `use` morti che phpstan/pint segnalerebbero.
- Il config `wm-package.php` del repo principale camminiditalia (override locale) non definisce affatto la chiave `web_components`, quindi il default del package si applica invariato — nessun rischio di doppia fonte lì, ma va ri-verificato dopo l'edit (nessuna modifica attesa in quel file).
- Il test riscritto deve continuare a passare quando eseguito con il path esplicito (vedi Global Constraints) — un errore di battitura nel path lo farebbe apparire "verde" solo perché non trovato, non perché passato.

---

## Task 1: Costante condivisa + rimozione del meccanismo di fetch live (`wm-package/src/Nova/Layer.php`)

**Files:**
- Modify: `wm-package/src/Nova/Layer.php:9-11` (rimozione import), `:41` (nuova costante), `:349-425` (semplificazione/rimozione metodi)
- Test: `wm-package/tests/Feature/LayerWebComponentCopyButtonTest.php`

**Interfaces:**
- Produce: `Wm\WmPackage\Nova\Layer::DEFAULT_LAYER_MAP_SCRIPT_URL` (constante pubblica, stringa) — usata da Task 2 nel config del package.
- Consuma: nessuna interfaccia da task precedenti (primo task del piano).

- [ ] **Step 1: Riscrivere `wm-package/tests/Feature/LayerWebComponentCopyButtonTest.php` per il comportamento statico atteso**

Sostituisci l'intero contenuto del file con:

```php
<?php

namespace Tests\Feature;

use App\Nova\Layer as NovaLayer;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer as LayerModel;

class LayerWebComponentCopyButtonTest extends TestCase
{
    private const EXPECTED_SCRIPT_URL = 'https://cdn.jsdelivr.net/gh/webmappsrl/wm-elements@dist/wm-layer-map/wm-layer-map.js';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wm-package.shard_name' => 'camminiditalia',
            'wm-package.web_components.layer_map' => [
                'fallback' => [
                    'tag_name' => 'wm-layer-map',
                    'script_url' => self::EXPECTED_SCRIPT_URL,
                    'default_style' => 'display:block;width:100%;height:600px',
                ],
            ],
        ]);
    }

    public function test_copy_button_is_visible_only_when_frontend_flag_is_enabled(): void
    {
        $enabledLayer = $this->makeUnsavedLayerWithAppOwner(true, 101, 201);

        $enabledResource = new NovaLayer($enabledLayer);

        $this->assertTrue(
            $this->invokeProtected($enabledResource, 'shouldShowLayerWebComponentCopyButton', [$enabledLayer])
        );

        $disabledLayer = $this->makeUnsavedLayerWithAppOwner(false, 102, 202);

        $disabledResource = new NovaLayer($disabledLayer);

        $this->assertFalse(
            $this->invokeProtected($disabledResource, 'shouldShowLayerWebComponentCopyButton', [$disabledLayer])
        );
    }

    public function test_snippet_always_uses_static_wm_elements_script_url(): void
    {
        $layer = $this->makeUnsavedLayerWithAppOwner(true, 103, 203);

        $resource = new NovaLayer($layer);
        $snippet = $this->invokeProtected($resource, 'buildLayerWebComponentSnippet', [$layer]);

        $this->assertStringContainsString('<wm-layer-map', $snippet);
        $this->assertStringContainsString('shard="camminiditalia"', $snippet);
        $this->assertStringContainsString('app-id="'.$layer->app_id.'"', $snippet);
        $this->assertStringContainsString('layer-id="'.$layer->id.'"', $snippet);
        $this->assertStringContainsString('src="'.self::EXPECTED_SCRIPT_URL.'"', $snippet);
    }

    public function test_rendered_button_contains_helper_and_uses_static_script_url(): void
    {
        $layer = $this->makeUnsavedLayerWithAppOwner(true, 104, 204);

        $resource = new NovaLayer($layer);
        $buttonHtml = $this->invokeProtected($resource, 'renderLayerWebComponentCopyButton', [$layer]);
        $snippet = $this->invokeProtected($resource, 'buildLayerWebComponentSnippet', [$layer]);

        $this->assertStringContainsString((string) __('Copy web component code'), $buttonHtml);
        $this->assertStringContainsString((string) __('Test the copied code by pasting it into'), $buttonHtml);
        $this->assertStringContainsString('https://html.onlineviewer.net/', $buttonHtml);
        $this->assertStringContainsString('src="'.self::EXPECTED_SCRIPT_URL.'"', $snippet);
    }

    public function test_fallback_uses_default_url_when_config_key_is_missing(): void
    {
        config(['wm-package.web_components.layer_map' => []]);

        $layer = $this->makeUnsavedLayerWithAppOwner(true, 105, 205);

        $resource = new NovaLayer($layer);
        $snippet = $this->invokeProtected($resource, 'buildLayerWebComponentSnippet', [$layer]);

        $this->assertStringContainsString(
            'src="'.\Wm\WmPackage\Nova\Layer::DEFAULT_LAYER_MAP_SCRIPT_URL.'"',
            $snippet
        );
    }

    private function invokeProtected(object $object, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    private function makeUnsavedLayerWithAppOwner(bool $enabled, int $appId, int $layerId): LayerModel
    {
        $app = new App;
        $app->id = $appId;
        $app->properties = ['layer_web_component_enabled' => $enabled];

        $layer = new LayerModel;
        $layer->id = $layerId;
        $layer->app_id = $appId;
        $layer->setRelation('appOwner', $app);

        return $layer;
    }
}
```

Nota: `test_fallback_uses_default_url_when_config_key_is_missing` è un nuovo test aggiunto rispetto all'overview — copre esplicitamente lo scenario "fallback-del-fallback" emerso in Challenge (config assente → deve comunque risolvere alla stessa costante, mai al vecchio URL).

- [ ] **Step 2: Eseguire il test per verificare che fallisca (RED)**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/LayerWebComponentCopyButtonTest.php`

Expected: FAIL. Il codice attuale di `Layer.php` tenta ancora il fetch live (chiama `Http::timeout(...)->get($exampleUrl)` verso `config('wm-package.web_components.layer_map.example_url')`, che ora nel test non è impostato → stringa vuota → il codice attuale ripiega comunque sul fallback, MA il fallback preso da `config(['wm-package.web_components.layer_map' => [...]])` nel test coincide già col nuovo URL per i primi tre test — quindi ci si aspetta che siano già verdi tranne `test_fallback_uses_default_url_when_config_key_is_missing`, che fallisce perché il default hardcoded in `getFallbackLayerMapComponentConfig()` (riga 383) punta ancora al vecchio repo `wm-layer-map@refs/heads/main`, non alla nuova costante (che non esiste ancora). Verifica che il fallimento sia specificamente su questo test, con messaggio che mostra l'URL vecchio nello snippet generato.

- [ ] **Step 3: Aggiungere la costante e rimuovere gli import inutilizzati**

In `wm-package/src/Nova/Layer.php`, sostituisci le righe 9-11:

```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
```

con: (rimuovile del tutto, nessuna riga sostitutiva — non sono più usate altrove nel file)

Poi, subito dopo `public static $with = ['ecTracks', 'ecPois', 'appOwner', 'associatedApps'];` (riga 41), aggiungi:

```php

    /**
     * URL statico del bootstrap del web component wm-layer-map, pubblicato da
     * webmappsrl/wm-elements (branch `dist`). Unica fonte di verità: referenziata
     * sia qui (fallback via codice) sia da config/wm-package.php (fallback via
     * config) — non duplicare questa stringa altrove (oc:8590).
     */
    public const DEFAULT_LAYER_MAP_SCRIPT_URL = 'https://cdn.jsdelivr.net/gh/webmappsrl/wm-elements@dist/wm-layer-map/wm-layer-map.js';
```

- [ ] **Step 4: Semplificare `resolveLayerMapComponentConfig()` e rimuovere `fetchLayerMapExampleConfig()`**

Sostituisci il blocco da `resolveLayerMapComponentConfig()` a `fetchLayerMapExampleConfig()` incluso (righe ~349-424 prima delle modifiche) con:

```php
    /**
     * @return array{tag_name: string, script_url: string, default_style: string}
     */
    private function resolveLayerMapComponentConfig(): array
    {
        $config = config('wm-package.web_components.layer_map', []);

        return $this->getFallbackLayerMapComponentConfig($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{tag_name: string, script_url: string, default_style: string}
     */
    private function getFallbackLayerMapComponentConfig(array $config): array
    {
        $fallback = $config['fallback'] ?? [];

        return [
            'tag_name' => (string) ($fallback['tag_name'] ?? 'wm-layer-map'),
            'script_url' => (string) ($fallback['script_url'] ?? self::DEFAULT_LAYER_MAP_SCRIPT_URL),
            'default_style' => (string) ($fallback['default_style'] ?? 'display:block;width:100%;height:600px'),
        ];
    }
```

Questo rimuove interamente `fetchLayerMapExampleConfig()` e la chiamata `Cache::remember(...)`: nessuna richiesta HTTP viene più eseguita in questo metodo.

- [ ] **Step 5: Eseguire il test per verificare che passi (GREEN)**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/LayerWebComponentCopyButtonTest.php`

Expected: PASS — tutti e 4 i test verdi (13+ assertions).

- [ ] **Step 6: Lint (Pint) sul solo file toccato**

Run: `docker exec laravel-camminiditalia composer format -- --path=src/Nova/Layer.php` (se lo script `composer format` di wm-package non accetta `--path`, usa `docker exec laravel-camminiditalia ./vendor/bin/pint src/Nova/Layer.php` dalla working dir `wm-package/`). Verifica con `git status`/`git diff` che non siano stati toccati altri file — Pint senza scope riformatta l'intero repo (trappola nota, vedi `wm-package/CLAUDE.md`).

- [ ] **Step 7: Commit**

```bash
cd wm-package
git add src/Nova/Layer.php tests/Feature/LayerWebComponentCopyButtonTest.php
git commit -m "fix(oc:8590): punta l'embed wm-layer-map a wm-elements e rimuove il fetch live"
```

---

## Task 2: Allineare il config del package alla costante condivisa (`wm-package/config/wm-package.php`)

**Files:**
- Modify: `wm-package/config/wm-package.php:1-6` (import), `:46-55` (chiave `web_components.layer_map`)

**Interfaces:**
- Consuma: `Wm\WmPackage\Nova\Layer::DEFAULT_LAYER_MAP_SCRIPT_URL` (da Task 1).
- Produce: nessuna nuova interfaccia — è la config di default letta da `resolveLayerMapComponentConfig()` (Task 1) quando un consumer non sovrascrive `web_components.layer_map`.

- [ ] **Step 1: Aggiungere l'import della classe `Layer` in cima al file**

In `wm-package/config/wm-package.php`, dopo le `use` esistenti (righe 3-6):

```php
use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode;
```

aggiungi subito sotto:

```php
use Wm\WmPackage\Nova\Layer;
```

- [ ] **Step 2: Sostituire la chiave `layer_map`**

Sostituisci il blocco (righe ~46-55):

```php
        'layer_map' => [
            'example_url' => 'https://raw.githubusercontent.com/webmappsrl/wm-layer-map/refs/heads/main/test/index.html',
            'cache_ttl' => 1800,
            'timeout' => 10,
            'fallback' => [
                'tag_name' => 'wm-layer-map',
                'script_url' => 'https://cdn.jsdelivr.net/gh/webmappsrl/wm-layer-map@refs/heads/main/src/wm-layer-map.js',
                'default_style' => 'display:block;width:100%;height:600px',
            ],
        ],
```

con:

```php
        'layer_map' => [
            'fallback' => [
                'tag_name' => 'wm-layer-map',
                'script_url' => Layer::DEFAULT_LAYER_MAP_SCRIPT_URL,
                'default_style' => 'display:block;width:100%;height:600px',
            ],
        ],
```

`example_url`, `cache_ttl` e `timeout` sono rimosse: non più lette da nessun punto del codice dopo il Task 1 (verificato al Task 1 Step 4 — `resolveLayerMapComponentConfig()` non legge più queste chiavi).

- [ ] **Step 3: Verificare che il config si carichi senza errori**

Run: `docker exec laravel-camminiditalia php artisan tinker --execute="var_dump(config('wm-package.web_components.layer_map'));"`

Expected: array con `fallback.script_url` uguale a `https://cdn.jsdelivr.net/gh/webmappsrl/wm-elements@dist/wm-layer-map/wm-layer-map.js`, nessun errore di classe non trovata (conferma che l'autoload di `Wm\WmPackage\Nova\Layer` dentro un file di config funziona in questo ambiente — pattern già usato nello stesso file per le classi `TrailRegistry\Nova\*`).

- [ ] **Step 4: Rieseguire l'intera suite del test coinvolto**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/LayerWebComponentCopyButtonTest.php`

Expected: PASS (4 test, invariato rispetto al Task 1 — questo step conferma che il nuovo config di default produce lo stesso risultato del config esplicito usato nel test, che comunque sovrascrive `web_components.layer_map` in `setUp()`).

- [ ] **Step 5: Commit**

```bash
cd wm-package
git add config/wm-package.php
git commit -m "fix(oc:8590): allinea il default di config al nuovo URL wm-elements"
```

---

## Task 3: Verifica manuale end-to-end (non automatizzabile)

**Files:** nessuno (solo verifica, nessun file toccato).

**Interfaces:**
- Consuma: l'output di `buildLayerWebComponentSnippet()` (Task 1+2) generato per un layer reale via Nova.

- [ ] **Step 1: Generare lo snippet reale per il layer del ticket**

Da Nova (`/nova/resources/layers/130` o l'equivalente per "Anello di Teodelapio"/il layer citato nel ticket), usa il pulsante "Copy web component code" per copiare lo snippet negli appunti. In alternativa, via tinker:

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="
\$layer = \Wm\WmPackage\Models\Layer::find(130);
\$resource = new \App\Nova\Layer(\$layer);
\$reflection = new ReflectionMethod(\$resource, 'buildLayerWebComponentSnippet');
\$reflection->setAccessible(true);
echo \$reflection->invoke(\$resource, \$layer);
"
```

Expected: lo snippet stampato contiene `src="https://cdn.jsdelivr.net/gh/webmappsrl/wm-elements@dist/wm-layer-map/wm-layer-map.js"` e non contiene più `wm-layer-map@refs/heads/main`.

- [ ] **Step 2: Verifica visiva su html.onlineviewer.net**

Incolla lo snippet ottenuto su https://html.onlineviewer.net/. Verifica che compaiano:
- Il logo del cammino (badge in alto, non solo il nome testuale)
- I POI sulla mappa
- I badge store Android/iOS in basso (dato che l'App "Cammini di Italia" ha `ios_store_link`/`android_store_link` già popolati, verificato in questa sessione via tinker)

Se uno di questi manca, non è un problema risolvibile in questo ciclo tramite il codice di questo piano (vedi "Rischi" e "Out of scope" in `overview.md`): va segnalato in `notes.md` come follow-up, non trattato come blocco del commit.

- [ ] **Step 3: Annotare l'esito in notes.md**

Aggiorna `wm-package/docs/features/8590-embed-online-viewer-logo-poi-mancanti/notes.md` con l'esito della verifica visiva (screenshot non necessario, basta l'elenco di cosa è comparso/non comparso) e con la nota sul gap di discovery del test (Global Constraints) come follow-up.

Nessun commit in questo task (nessun file di codice toccato).

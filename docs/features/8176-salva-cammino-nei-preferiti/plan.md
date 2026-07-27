# Salva cammino nei preferiti — wm-package Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> Ticket: oc:8176

**Goal:** Rendere `Layer` favoritabile (stesso meccanismo già in produzione per `EcTrack`) ed esporre il flag `show_favorites` (spostato in `properties` JSON, nessuna migration) che condiziona la UI frontend.

**Architecture:** Mirror 1:1 del pattern `EcTrackController`/`ChristianKuri\LaravelFavorite` già esistente, riusando la tabella `favorites` polimorfica già presente. Nessuna nuova migration in tutto il piano.

**Tech Stack:** Laravel, `christiankuri/laravel-favorite` (già installato), Pest (test), Nova (campo Boolean).

## Global Constraints

- Nessuna migration in nessun task di questo piano (tabella `favorites` già esiste; `show_favorites` va in `properties` JSON, non in una colonna)
- Nessuno scoping/verifica ownership `app_id` sugli endpoint `layer/favorite/*` — decisione esplicita del developer (Layer↔App, non Layer↔Utente), coerente col comportamento già esistente di `EcTrackController`
- Guard di autenticazione: `middleware('auth:api')`, risoluzione utente via `auth('api')->id()`/`auth('api')->user()` — mai il guard web di default
- Test in stile Pest (funzioni `it(...)`), `Wm\WmPackage\Tests\TestCase` applicato globalmente da `tests/Pest.php` — nessun `uses()` esplicito necessario nei nuovi file
- Il response shape del toggle deve restare `{'favorite': bool}`, identico a `EcTrackController::toggleFavorite` — il frontend (wm-core) fa un aggiornamento ottimistico basato su questo valore

---

### Task 1: Trait `Favoriteable` su `Layer`

**Files:**
- Modify: `src/Models/Layer.php:1-25` (namespace `use` + dichiarazione `use` dei trait sulla classe)
- Test: `tests/Feature/LayerFavoriteModelTest.php` (nuovo)

**Interfaces:**
- Consumes: nessuno (primo task)
- Produces: `Layer::addFavorite($userId)`, `Layer::removeFavorite($userId)`, `Layer::toggleFavorite($userId)`, `Layer::isFavorited($userId)` — metodi del trait `ChristianKuri\LaravelFavorite\Traits\Favoriteable`, usati dai Task 2 e 3

- [ ] **Step 1: Scrivi il test che verifica il trait**

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

it('allows a user to favorite and unfavorite a layer', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $layer = Layer::factory()->create(['app_id' => $app->id]);

    expect($layer->isFavorited($user->id))->toBeFalse();

    $layer->toggleFavorite($user->id);
    expect($layer->fresh()->isFavorited($user->id))->toBeTrue();

    $layer->toggleFavorite($user->id);
    expect($layer->fresh()->isFavorited($user->id))->toBeFalse();
});
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

Run: `vendor/bin/pest tests/Feature/LayerFavoriteModelTest.php`
Expected: FAIL — `Call to undefined method Wm\WmPackage\Models\Layer::isFavorited()`

- [ ] **Step 3: Aggiungi il trait a `Layer`**

In `src/Models/Layer.php`, aggiungi l'import subito dopo `use App\Models\User;`:

```php
use ChristianKuri\LaravelFavorite\Traits\Favoriteable;
```

Poi aggiorna la dichiarazione `use` della classe (riga `use FeatureCollectionMapTrait, HasPackageFactory, HasTranslations, NormalizesHexColor, TaxonomyAbleModel, TaxonomyWhereAbleModel;`):

```php
use Favoriteable, FeatureCollectionMapTrait, HasPackageFactory, HasTranslations, NormalizesHexColor, TaxonomyAbleModel, TaxonomyWhereAbleModel;
```

- [ ] **Step 4: Esegui il test per verificare che passi**

Run: `vendor/bin/pest tests/Feature/LayerFavoriteModelTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Models/Layer.php tests/Feature/LayerFavoriteModelTest.php
git commit -m "feat(oc:8176): add Favoriteable trait to Layer model"
```

---

### Task 2: Endpoint `add`/`remove`/`toggle` preferito layer

**Files:**
- Create: `src/Http/Controllers/Api/LayerFavoriteController.php`
- Modify: `routes/api.php` (nuovo blocco route, vicino al blocco esistente `ec/track/favorite/*` righe 126-134)
- Test: `tests/Feature/LayerFavoriteControllerTest.php` (nuovo)

**Interfaces:**
- Consumes: `Layer::addFavorite()`/`removeFavorite()`/`toggleFavorite()`/`isFavorited()` (Task 1)
- Produces: `POST /api/layer/favorite/add/{layer}`, `POST /api/layer/favorite/remove/{layer}`, `POST /api/layer/favorite/toggle/{layer}` — tutte rispondono `{'favorite': bool}`, consumato dal frontend wm-core per l'aggiornamento ottimistico

- [ ] **Step 1: Scrivi i test che verificano i tre endpoint**

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

it('rejects an unauthenticated toggle request', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->create(['app_id' => $app->id]);

    $response = $this->postJson("/api/layer/favorite/toggle/{$layer->id}");

    $response->assertStatus(401);
});

it('adds a layer to favorites and is idempotent on repeated add', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $layer = Layer::factory()->create(['app_id' => $app->id]);
    $this->actingAs($user, 'api');

    $response = $this->postJson("/api/layer/favorite/add/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => true]);

    $response = $this->postJson("/api/layer/favorite/add/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => true]);

    expect($layer->fresh()->isFavorited($user->id))->toBeTrue();
});

it('removes a layer from favorites and is idempotent on repeated remove', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $layer = Layer::factory()->create(['app_id' => $app->id]);
    $layer->toggleFavorite($user->id);
    $this->actingAs($user, 'api');

    $response = $this->postJson("/api/layer/favorite/remove/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => false]);

    $response = $this->postJson("/api/layer/favorite/remove/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => false]);

    expect($layer->fresh()->isFavorited($user->id))->toBeFalse();
});

it('toggles a layer favorite state back and forth', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $layer = Layer::factory()->create(['app_id' => $app->id]);
    $this->actingAs($user, 'api');

    $response = $this->postJson("/api/layer/favorite/toggle/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => true]);

    $response = $this->postJson("/api/layer/favorite/toggle/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => false]);
});
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

Run: `vendor/bin/pest tests/Feature/LayerFavoriteControllerTest.php`
Expected: FAIL — 404 Not Found (route inesistente)

- [ ] **Step 3: Crea il controller**

```php
<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

class LayerFavoriteController extends Controller
{
    /**
     * Add the given layer to the authenticated user's favorites (idempotent).
     */
    public function addFavorite(Request $request, Layer $layer): JsonResponse
    {
        $userId = auth('api')->id();
        if (! $layer->isFavorited($userId)) {
            $layer->toggleFavorite($userId);
        }

        return response()->json(['favorite' => $layer->isFavorited($userId)]);
    }

    /**
     * Remove the given layer from the authenticated user's favorites (idempotent).
     */
    public function removeFavorite(Request $request, Layer $layer): JsonResponse
    {
        $userId = auth('api')->id();
        if ($layer->isFavorited($userId)) {
            $layer->toggleFavorite($userId);
        }

        return response()->json(['favorite' => $layer->isFavorited($userId)]);
    }

    /**
     * Toggle the favorite state of the given layer for the authenticated user.
     */
    public function toggleFavorite(Request $request, Layer $layer): JsonResponse
    {
        $userId = auth('api')->id();
        $layer->toggleFavorite($userId);

        return response()->json(['favorite' => $layer->isFavorited($userId)]);
    }
}
```

- [ ] **Step 4: Aggiungi le route**

In `routes/api.php`, subito dopo il blocco esistente (righe 126-134, dentro `Route::prefix('track')`), aggiungi un nuovo blocco fratello al prefisso `ec` esistente (fuori dal gruppo `ec/track`, come nuovo top-level `layer`):

```php
Route::prefix('layer')->name('layer.')->group(function () {
    Route::middleware('auth:api')
        ->prefix('favorite')->name('favorite.')->group(function () {
            Route::post('/add/{layer}', [LayerFavoriteController::class, 'addFavorite'])->name('add');
            Route::post('/remove/{layer}', [LayerFavoriteController::class, 'removeFavorite'])->name('remove');
            Route::post('/toggle/{layer}', [LayerFavoriteController::class, 'toggleFavorite'])->name('toggle');
        });
});
```

Aggiungi l'import in cima al file: `use Wm\WmPackage\Http\Controllers\Api\LayerFavoriteController;`

- [ ] **Step 5: Esegui i test per verificare che passino**

Run: `vendor/bin/pest tests/Feature/LayerFavoriteControllerTest.php`
Expected: PASS (4 test)

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/Api/LayerFavoriteController.php routes/api.php tests/Feature/LayerFavoriteControllerTest.php
git commit -m "feat(oc:8176): add layer favorite add/remove/toggle endpoints"
```

---

### Task 3: Endpoint `list` (layer preferiti, payload leggero)

**Files:**
- Modify: `src/Http/Controllers/Api/LayerFavoriteController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/LayerFavoriteControllerTest.php`

**Interfaces:**
- Consumes: `User::favorite(Layer::class)` (da `ChristianKuri\LaravelFavorite\Traits\Favoriteability`, già presente su `User`), `Layer::getTranslations('name')`, `Layer::getFirstMediaUrl('default')`, `Layer::logo_image`, `Layer::getStrokeColorHex()`
- Produces: `GET /api/layer/favorite/list` → `{'favorites': [{id, title, feature_image, logo_image, style: {color}}, ...]}` — consumato sia dal cuoricino (check `is_favorited`) sia dal tab "Cammini" in `FavouritesPage` (webmapp-app)

- [ ] **Step 1: Scrivi il test per l'endpoint list**

```php
it('lists the authenticated user favorite layers with a lightweight payload', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $favorited = Layer::factory()->create(['app_id' => $app->id, 'name' => ['it' => 'Cammino preferito', 'en' => 'Favorite path']]);
    $notFavorited = Layer::factory()->create(['app_id' => $app->id]);
    $favorited->toggleFavorite($user->id);
    $this->actingAs($user, 'api');

    $response = $this->getJson('/api/layer/favorite/list');

    $response->assertOk();
    $favorites = $response->json('favorites');
    expect($favorites)->toHaveCount(1);
    expect($favorites[0]['id'])->toBe($favorited->id);
    expect($favorites[0]['title']['it'])->toBe('Cammino preferito');
    expect($favorites[0])->toHaveKeys(['id', 'title', 'feature_image', 'logo_image', 'style']);
    expect(collect($favorites)->pluck('id'))->not->toContain($notFavorited->id);
});
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

Run: `vendor/bin/pest tests/Feature/LayerFavoriteControllerTest.php`
Expected: FAIL — 404 Not Found (route `list` inesistente)

- [ ] **Step 3: Aggiungi il metodo `list` al controller**

In `src/Http/Controllers/Api/LayerFavoriteController.php`, aggiungi:

```php
    /**
     * List the authenticated user's favorite layers with a lightweight payload
     * (only the fields consumed by wm-layer-box: id, title, feature_image, logo_image, style.color).
     */
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        $layers = $user->favorite((new Layer)->getMorphClass())
            ->map(function (Layer $layer) {
                return [
                    'id' => $layer->id,
                    'title' => $layer->getTranslations('name'),
                    'feature_image' => $layer->getFirstMediaUrl('default'),
                    'logo_image' => $layer->logo_image,
                    'style' => ['color' => $layer->getStrokeColorHex()],
                ];
            })
            ->values();

        return response()->json(['favorites' => $layers]);
    }
```

- [ ] **Step 4: Aggiungi la route**

In `routes/api.php`, dentro il gruppo `Route::prefix('layer')->prefix('favorite')` creato al Task 2:

```php
            Route::get('/list', [LayerFavoriteController::class, 'list'])->name('list');
```

- [ ] **Step 5: Esegui i test per verificare che passino**

Run: `vendor/bin/pest tests/Feature/LayerFavoriteControllerTest.php`
Expected: PASS (5 test)

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/Api/LayerFavoriteController.php routes/api.php tests/Feature/LayerFavoriteControllerTest.php
git commit -m "feat(oc:8176): add lightweight layer favorites list endpoint"
```

---

### Task 4: Flag `show_favorites` in `properties` JSON dell'App

**Files:**
- Modify: `src/Services/Models/App/AppConfigService.php:745`
- Modify: `src/Nova/App.php` (tab `home_tab()`, vicino al campo `show_search`)
- Test: `tests/Feature/AppConfigShowFavoritesTest.php` (nuovo)

**Interfaces:**
- Consumes: `$this->app->properties` (array, già castato su `App`)
- Produces: `config.json → OPTIONS.show_favorites: bool`, consumato dal frontend (`confOPTIONSShowFavorites`, wm-core — fuori scope di questo repo)

- [ ] **Step 1: Scrivi il test per il valore di default e per il valore impostato**

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

it('defaults show_favorites to false when not set in properties', function () {
    $app = App::factory()->createQuietly(['properties' => []]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS']['show_favorites'])->toBeFalse();
});

it('reads show_favorites true from properties', function () {
    $app = App::factory()->createQuietly(['properties' => ['show_favorites' => true]]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS']['show_favorites'])->toBeTrue();
});
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

Run: `vendor/bin/pest tests/Feature/AppConfigShowFavoritesTest.php`
Expected: FAIL — `show_favorites` risulta `null` invece di `false`/`true` (legge ancora la colonna inesistente)

- [ ] **Step 3: Aggiorna `AppConfigService::config_section_options()`**

In `src/Services/Models/App/AppConfigService.php`, sostituisci la riga 745:

```php
        $data['OPTIONS']['show_favorites'] = $this->app->show_favorites;
```

con:

```php
        $data['OPTIONS']['show_favorites'] = (bool) ($this->app->properties['show_favorites'] ?? false);
```

- [ ] **Step 4: Esegui i test per verificare che passino**

Run: `vendor/bin/pest tests/Feature/AppConfigShowFavoritesTest.php`
Expected: PASS

- [ ] **Step 5: Aggiungi il campo Nova**

In `src/Nova/App.php`, nel metodo `home_tab()`, subito dopo il campo `Boolean::make(__('Show searchbar'), 'show_search')...`:

```php
            Boolean::make(__('Show favorites'), 'properties->show_favorites')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Activate to show the favorite heart on layers and the "My favorites" section')),
```

- [ ] **Step 6: Commit**

```bash
git add src/Services/Models/App/AppConfigService.php src/Nova/App.php tests/Feature/AppConfigShowFavoritesTest.php
git commit -m "feat(oc:8176): read show_favorites from app properties instead of missing column"
```

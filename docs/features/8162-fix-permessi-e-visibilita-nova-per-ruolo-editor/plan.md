> Ticket: oc:8162

# Fix permessi e visibilità Nova per ruolo Editor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **⚠️ Vincolo Webmapp — nessun commit/branch automatico:** i blocchi `git commit`/`git checkout -b` in questo piano sono istruzioni testuali per lo sviluppatore/skill di esecuzione, NON comandi da eseguire autonomamente senza conferma esplicita dell'utente per ciascuno. Vedi `wm-plan` → `Fase: execution`.
>
> **⚠️ Ordine di merge vincolante tra repo:** i Task 1-9 (wm-package) vanno mergiati e deployati **prima** del Task 10 (Maphub). Il Task 10 chiama `$user->hasUgcEnabled()`, definito nel Task 1 — se il bump del pointer submodule in Maphub avviene senza che wm-package sia già stato aggiornato, o viceversa in un rollback parziale, `NovaServiceProvider` lancia un errore fatale sul rendering dell'intero menu Nova per ogni utente Editor (non solo sulla sezione UGC). Stesso pattern di incidente già capitato in oc:8348 (vedi `CLAUDE.md` Maphub, sezione "Import EcPoi da OSM").

**Goal:** Correggere le Policy Nova di wm-package (Taxonomy, Layer, Media, User) che concedono permessi non coerenti col ruolo Editor, e nascondere la sezione menu UGC in Nova quando l'Editor non ha UGC abilitati sulla propria app.

**Architecture:** Fix puntuali su Policy Laravel esistenti (nessuna migrazione DB, nessuna nuova tabella). Un nuovo metodo su `User` (wm-package) mirror di due metodi già esistenti (`hasDashboardShow()`/`hasClassificationShow()`) alimenta un nuovo `canSee()` su una `MenuSection` Nova esistente (Maphub). Tutte le policy si affidano all'auto-discovery Laravel per convenzione di namespace (`Wm\WmPackage\Models\X` → `Wm\WmPackage\Policies\XPolicy`), verificata esplicitamente nei Task 7-8 per le due policy nuove.

**Tech Stack:** Laravel 12, PHP 8.4, Nova 5, Pest (stile funzionale `it(...)`), PostgreSQL+PostGIS, Docker (container `php-maphub`).

**Spec:**
- `wm-package/docs/features/8162-fix-permessi-e-visibilita-nova-per-ruolo-editor/overview.md`
- `docs/features/8162-fix-permessi-e-visibilita-nova-per-ruolo-editor/overview.md`

## Global Constraints

- Ogni test Pest per una Policy deve verificare SIA il path positivo (un ruolo che PUÒ) SIA il path negativo (un ruolo che NON PUÒ) — un test che verifica solo il positivo non rileva una regressione futura di un bypass (vedi Challenge, overview wm-package).
- **[Superato dal Task 13, vedi nota nel Task 9]** ~~Nessuna modifica a `UgcPoiPolicy`/`UgcTrackPolicy` oltre al commento TODO~~ — dopo l'estensione dello scoping per-app (decisione post-approvazione, vedi overview aggiornata), `before()` viene corretto per davvero (Administrator o Validator), non solo commentato.
- **[Superato dal Task 11/13]** ~~Nessuno scoping automatico della query Nova per EC/UGC in base all'app dell'Editor~~ — richiesto esplicitamente dall'estensione: `AbstractEcResource`/`AbstractUgcResource::indexQuery()` ora filtrano per `app_id`.
- Editor **non** può creare/modificare/eliminare UGC (solo visualizzare quelle della propria app) — deciso esplicitamente durante l'estensione, per non espandere ulteriormente lo scope oltre la visibilità richiesta.
- Il criterio EC passa da `user_id` (autore) ad `app_id` (appartenenza app) — **sostituzione**, non aggiunta in OR — per Editor **e** Validator (non solo Editor). Il criterio UGC invece resta diversificato per ruolo: Editor scopato per `app_id` in sola lettura, Administrator/Validator senza restrizioni (bypass via `before()`).
- I test nuovi in `wm-package/tests/` che tipizzano `App\Models\User` (o dipendono da `RolesAndPermissionsService`/gate `viewNova` di Maphub) vanno eseguiti passando il path esplicito a Pest da Maphub (`vendor/bin/pest wm-package/tests/Unit/Policies/...`), non con `vendor/bin/pest` senza argomenti — stesso comportamento già osservato per i test consumer-oriented esistenti in questa cartella (es. `wm-package/tests/Unit/ImpersonationAuthorizationTest.php`), dovuto a `phpunit.xml` di Maphub che dichiara solo la testsuite `Unit` → `tests/Unit` e non include esplicitamente `wm-package/tests`. Questo è un limite pre-esistente del progetto, non introdotto da questo piano — non tentare di risolverlo qui.
- Tutti i comandi `docker exec` in questo piano assumono il container `php-maphub` già attivo (verificato attivo in Fase: environment-setup) e cwd `/var/www/html/maphub` dentro il container.

---

## Task 1: `User::hasUgcEnabled()` (wm-package)

**Files:**
- Modify: `wm-package/src/Models/User.php:234` (subito dopo la chiusura di `hasClassificationShow()`)
- Test: `wm-package/tests/Unit/Models/UserHasUgcEnabledTest.php` (nuovo)

**Interfaces:**
- Produces: `Wm\WmPackage\Models\User::hasUgcEnabled(?int $app_id = null): bool` — itera `$this->apps` (già esistente, `HasMany` su `apps.user_id`); ritorna `true` se almeno un'app posseduta ha `auth_show_at_startup == true && geolocation_record_enable == true`; con `$app_id` valorizzato, considera solo l'app con quell'id tra quelle possedute. Ritorna sempre `false` (mai eccezione) se `$this->apps` è vuota.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea la cartella se non esiste e il file `wm-package/tests/Unit/Models/UserHasUgcEnabledTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;
use App\Models\User;

// `Tests\TestCase` (Maphub) invece del default `Wm\WmPackage\Tests\TestCase` — stesso
// motivo documentato in wm-package/tests/Unit/ImpersonationAuthorizationTest.php.
uses(Tests\TestCase::class, DatabaseTransactions::class);

it('returns true when the user owns an app with UGC enabled', function () {
    $user = User::factory()->create();
    App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => true,
    ]);

    expect($user->hasUgcEnabled())->toBeTrue();
});

it('returns false when the user owns an app with UGC not fully enabled', function () {
    $user = User::factory()->create();
    App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => false,
    ]);

    expect($user->hasUgcEnabled())->toBeFalse();
});

it('returns false when the user has no app at all, without throwing', function () {
    $user = User::factory()->create();

    expect(fn () => $user->hasUgcEnabled())->not->toThrow(Throwable::class);
    expect($user->hasUgcEnabled())->toBeFalse();
});

it('checks only the given app_id when provided, ignoring the user\'s other apps', function () {
    $user = User::factory()->create();
    App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => true,
    ]);
    $disabledApp = App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => false,
        'geolocation_record_enable' => false,
    ]);

    expect($user->hasUgcEnabled($disabledApp->id))->toBeFalse();
});

it('returns true when at least one of multiple owned apps has UGC enabled (OR criterion)', function () {
    $user = User::factory()->create();
    App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => false,
        'geolocation_record_enable' => false,
    ]);
    App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => true,
    ]);

    expect($user->hasUgcEnabled())->toBeTrue();
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Models/UserHasUgcEnabledTest.php"
```

Atteso: FAIL — `Call to undefined method Wm\WmPackage\Models\User::hasUgcEnabled()`.

- [ ] **Step 3: Implementa il metodo**

In `wm-package/src/Models/User.php`, subito dopo la chiusura di `hasClassificationShow()` (riga 234, prima del commento `/**\n     * Determine if the user can impersonate another user.` a riga 236), aggiungi:

```php
    /**
     * defines whether at least one app owned by the user has UGC registration
     * enabled (auth_show_at_startup AND geolocation_record_enable both true)
     *
     * @param  int|null  $app_id  limit the check to a single owned app
     */
    public function hasUgcEnabled($app_id = null): bool
    {
        $apps = $this->apps;
        $result = false;

        if ($app_id) {
            foreach ($apps as $app) {
                if ($app->id == $app_id) {
                    if ($app->auth_show_at_startup == true && $app->geolocation_record_enable == true) {
                        $result = true;
                    }
                }
            }

            return $result;
        }

        foreach ($apps as $app) {
            if ($app->auth_show_at_startup == true && $app->geolocation_record_enable == true) {
                $result = true;
            }
        }

        return $result;
    }
```

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Models/UserHasUgcEnabledTest.php"
```

Atteso: PASS (5 test).

- [ ] **Step 5: Commit** (istruzione testuale — chiedi conferma esplicita prima di eseguire)

```bash
cd wm-package
git add src/Models/User.php tests/Unit/Models/UserHasUgcEnabledTest.php
git commit -m "feat(oc:8162): add User::hasUgcEnabled() for per-app UGC capability check"
```

---

## Task 2: `LayerPolicy::delete()` (wm-package)

**Files:**
- Modify: `wm-package/src/Policies/LayerPolicy.php:52-58`
- Test: `wm-package/tests/Unit/Policies/LayerPolicyDeleteTest.php` (nuovo)

**Interfaces:**
- Consumes: nessuna dipendenza da altri task.
- Produces: nessuna interfaccia nuova (solo comportamento della policy).

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Unit/Policies/LayerPolicyDeleteTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use App\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Tests\TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
    App::factory()->createQuietly();
});

it('allows Administrator to delete a layer', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $layer = Layer::factory()->createQuietly();

    expect(Gate::forUser($admin)->allows('delete', $layer))->toBeTrue();
});

it('denies Editor from deleting a layer', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $layer = Layer::factory()->createQuietly();

    expect(Gate::forUser($editor)->allows('delete', $layer))->toBeFalse();
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/LayerPolicyDeleteTest.php"
```

Atteso: FAIL sul secondo test (`denies Editor from deleting a layer`) — oggi `delete()` ritorna sempre `true`.

- [ ] **Step 3: Correggi `LayerPolicy::delete()`**

In `wm-package/src/Policies/LayerPolicy.php`, sostituisci:

```php
    public function delete(User $user, Layer $layer)
    {
        return true;
    }
```

con:

```php
    public function delete(User $user, Layer $layer)
    {
        if ($user->hasRole('Editor')) {
            return false;
        }

        return true;
    }
```

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/LayerPolicyDeleteTest.php"
```

Atteso: PASS (2 test).

- [ ] **Step 5: Commit**

```bash
cd wm-package
git add src/Policies/LayerPolicy.php tests/Unit/Policies/LayerPolicyDeleteTest.php
git commit -m "fix(oc:8162): deny Editor from deleting Layer resources"
```

---

## Task 3: `MediaPolicy::before()` (wm-package)

**Files:**
- Modify: `wm-package/src/Policies/MediaPolicy.php:14-27`
- Test: `wm-package/tests/Unit/Policies/MediaPolicyTest.php` (nuovo)

**Interfaces:**
- Consumes: nessuna.
- Produces: nessuna interfaccia nuova.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Unit/Policies/MediaPolicyTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\Media;
use App\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Tests\TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('allows Administrator to create, update and delete Media', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    // 'geometry' esplicito: la colonna è geography(PointZ,4326) (richiede 3 coordinate),
    // il default della factory genera solo un Point 2D e fallisce con
    // "Column has Z dimension but geometry does not" (verificato).
    $media = Media::factory()->createQuietly([
        'geometry' => \DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[10.4,43.7,0]}')"),
    ]);

    expect(Gate::forUser($admin)->allows('create', Media::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $media))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $media))->toBeTrue();
});

it('denies Editor from creating, updating or deleting Media', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    // 'geometry' esplicito: la colonna è geography(PointZ,4326) (richiede 3 coordinate),
    // il default della factory genera solo un Point 2D e fallisce con
    // "Column has Z dimension but geometry does not" (verificato).
    $media = Media::factory()->createQuietly([
        'geometry' => \DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[10.4,43.7,0]}')"),
    ]);

    expect(Gate::forUser($editor)->allows('create', Media::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $media))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $media))->toBeFalse();
});

it('keeps Editor viewAny/view allowed only when hasDashboardShow is true (unchanged behaviour)', function () {
    $editorWithDashboard = User::factory()->create();
    $editorWithDashboard->assignRole('Editor');
    \Wm\WmPackage\Models\App::factory()->for($editorWithDashboard, 'author')->createQuietly(['dashboard_show' => true]);

    $editorWithoutDashboard = User::factory()->create();
    $editorWithoutDashboard->assignRole('Editor');

    expect(Gate::forUser($editorWithDashboard)->allows('viewAny', Media::class))->toBeTrue()
        ->and(Gate::forUser($editorWithoutDashboard)->allows('viewAny', Media::class))->toBeFalse();
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/MediaPolicyTest.php"
```

Atteso: FAIL sul secondo test (`denies Editor from creating...`) — oggi `before()` ritorna sempre `true`, quindi anche l'Editor può tutto.

- [ ] **Step 3: Correggi `MediaPolicy::before()`**

In `wm-package/src/Policies/MediaPolicy.php`, sostituisci:

```php
    public function before(User $user, $ability)
    {
        // if ($user->hasRole('Admin')) {
        //     return true;
        // }
        // if ($user->hasRole('Author') || $user->hasRole('Contributor')) {
        //     return false;
        // }

        return true;
    }
```

con:

```php
    public function before(User $user, $ability)
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }
    }
```

Nessun'altra modifica al file: `viewAny()`/`view()` restano invariati (Editor con `hasDashboardShow()`); `create()`/`update()`/`delete()`/`restore()`/`forceDelete()` restano con il corpo vuoto (`//`, quindi `null` → negato per chiunque non sia Administrator, che ora è coperto dal nuovo `before()`).

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/MediaPolicyTest.php"
```

Atteso: PASS (3 test).

- [ ] **Step 5: Commit**

```bash
cd wm-package
git add src/Policies/MediaPolicy.php tests/Unit/Policies/MediaPolicyTest.php
git commit -m "fix(oc:8162): restrict MediaPolicy::before() bypass to Administrator only"
```

---

## Task 4: Rimozione dead code da `UserPolicy`, `TaxonomyTargetPolicy`, `TaxonomyWhenPolicy` (wm-package)

Nota: questo task è una rimozione di codice puramente inerte (verificato in overview: `Admin`/`Author` sono ruoli inesistenti, `Contributor` esiste ma non riceve mai il permesso `access-nova` quindi non raggiunge mai queste policy, tutte usate solo da risorse Nova dietro al gate `viewNova`). Non c'è un comportamento osservabile da cambiare con un test rosso→verde: il passo di verifica è che i test **preesistenti/di regressione** per i ruoli reali continuino a passare dopo la rimozione, non un nuovo assert.

**Files:**
- Modify: `wm-package/src/Policies/UserPolicy.php:16-24`
- Modify: `wm-package/src/Policies/TaxonomyTargetPolicy.php:16-24`
- Modify: `wm-package/src/Policies/TaxonomyWhenPolicy.php:16-24`

**Interfaces:** nessuna.

- [ ] **Step 1: Rimuovi il metodo `before()` da `UserPolicy`**

In `wm-package/src/Policies/UserPolicy.php`, elimina interamente:

```php
    /**
     * Perform pre-authorization checks.
     *
     * @param  string  $ability
     * @return void|bool
     */
    public function before(User $user, $ability)
    {
        if ($user->hasRole('Admin')) {
            return true;
        }
        if ($user->hasRole('Author') || $user->hasRole('Contributor')) {
            return false;
        }
    }

```

(il resto della classe, `viewAny()` in poi, resta invariato).

- [ ] **Step 2: Rimuovi il metodo `before()` da `TaxonomyTargetPolicy`**

In `wm-package/src/Policies/TaxonomyTargetPolicy.php`, elimina interamente:

```php
    /**
     * Perform pre-authorization checks.
     *
     * @param  string  $ability
     * @return void|bool
     */
    public function before(User $user, $ability)
    {
        if ($user->hasRole('Admin')) {
            return true;
        }
        if ($user->hasRole('Author') || $user->hasRole('Contributor')) {
            return false;
        }
    }

```

- [ ] **Step 3: Rimuovi il metodo `before()` da `TaxonomyWhenPolicy`**

In `wm-package/src/Policies/TaxonomyWhenPolicy.php`, elimina lo stesso identico blocco (stesso codice del punto precedente, sostituendo solo il tipo del parametro se presente — verifica che il resto della classe, `viewAny()` in poi, resti invariato).

- [ ] **Step 4: Verifica di non-regressione**

Esegui i test già scritti nei Task 1-3 (che esercitano ruoli reali Administrator/Editor su altre policy) per assicurarti che la rimozione non abbia effetti collaterali su binding globali (`before()` non è ereditato da una classe base condivisa, quindi questo passo è una verifica di cautela):

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Models/UserHasUgcEnabledTest.php wm-package/tests/Unit/Policies/LayerPolicyDeleteTest.php wm-package/tests/Unit/Policies/MediaPolicyTest.php"
```

Atteso: PASS (tutti i test dei task precedenti, invariati).

- [ ] **Step 5: Commit**

```bash
cd wm-package
git add src/Policies/UserPolicy.php src/Policies/TaxonomyTargetPolicy.php src/Policies/TaxonomyWhenPolicy.php
git commit -m "fix(oc:8162): remove dead role checks (Admin/Author/Contributor) from before() hooks"
```

---

## Task 5: `TaxonomyPoiTypePolicy` — bypass ristretto ad Administrator (wm-package)

**Files:**
- Modify: `wm-package/src/Policies/TaxonomyPoiTypePolicy.php` (intero file)
- Test: `wm-package/tests/Unit/Policies/TaxonomyPoiTypePolicyTest.php` (nuovo)

**Interfaces:** nessuna.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Unit/Policies/TaxonomyPoiTypePolicyTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\TaxonomyPoiType;
use App\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Tests\TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('allows Administrator to view, create, update and delete taxonomy poi types', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $taxonomy = new TaxonomyPoiType(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($admin)->allows('viewAny', TaxonomyPoiType::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', TaxonomyPoiType::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $taxonomy))->toBeTrue();
});

it('allows Editor to view but denies create/update/delete on taxonomy poi types', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $taxonomy = new TaxonomyPoiType(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($editor)->allows('viewAny', TaxonomyPoiType::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', TaxonomyPoiType::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $taxonomy))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $taxonomy))->toBeFalse();
});

it('denies Validator from creating, updating or deleting taxonomy poi types', function () {
    $validator = User::factory()->create();
    $validator->assignRole('Validator');
    $taxonomy = new TaxonomyPoiType(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($validator)->allows('create', TaxonomyPoiType::class))->toBeFalse()
        ->and(Gate::forUser($validator)->allows('update', $taxonomy))->toBeFalse()
        ->and(Gate::forUser($validator)->allows('delete', $taxonomy))->toBeFalse();
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/TaxonomyPoiTypePolicyTest.php"
```

Atteso: FAIL sul secondo e terzo test — oggi `before()` ritorna sempre `true`, quindi Editor e Validator possono creare/modificare/eliminare.

- [ ] **Step 3: Riscrivi `TaxonomyPoiTypePolicy`**

Sostituisci l'intero contenuto di `wm-package/src/Policies/TaxonomyPoiTypePolicy.php` con:

```php
<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\TaxonomyPoiType;

class TaxonomyPoiTypePolicy
{
    use HandlesAuthorization;

    /**
     * Perform pre-authorization checks.
     *
     * @return void|bool
     */
    public function before(User $user, string $ability)
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }
    }

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return Response|bool
     */
    public function view(User $user, TaxonomyPoiType $taxonomyPoiType)
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, TaxonomyPoiType $taxonomyPoiType)
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, TaxonomyPoiType $taxonomyPoiType)
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore(User $user, TaxonomyPoiType $taxonomyPoiType)
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete(User $user, TaxonomyPoiType $taxonomyPoiType)
    {
        return false;
    }
}
```

Nota: `viewAny`/`view` ritornano `true` esplicito per chiunque (Editor/Validator inclusi, invece del precedente `if ($user->hasRole('Editor')) return true;` che lasciava `null`/negato per Validator — allineato al requisito "Editor e Validator restano in sola visualizzazione").

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/TaxonomyPoiTypePolicyTest.php"
```

Atteso: PASS (3 test).

- [ ] **Step 5: Commit**

```bash
cd wm-package
git add src/Policies/TaxonomyPoiTypePolicy.php tests/Unit/Policies/TaxonomyPoiTypePolicyTest.php
git commit -m "fix(oc:8162): restrict TaxonomyPoiType write abilities to Administrator only"
```

---

## Task 6: `TaxonomyActivityPolicy` — write abilities ristrette ad Administrator (wm-package)

**Files:**
- Modify: `wm-package/src/Policies/TaxonomyActivityPolicy.php` (intero file)
- Test: `wm-package/tests/Unit/Policies/TaxonomyActivityPolicyTest.php` (nuovo)

**Interfaces:** nessuna.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Unit/Policies/TaxonomyActivityPolicyTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\TaxonomyActivity;
use App\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Tests\TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('allows Administrator to create, update and delete taxonomy activities', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $taxonomy = new TaxonomyActivity(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($admin)->allows('create', TaxonomyActivity::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $taxonomy))->toBeTrue();
});

it('allows Editor to view but denies create/update/delete on taxonomy activities', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $taxonomy = new TaxonomyActivity(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($editor)->allows('viewAny', TaxonomyActivity::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', TaxonomyActivity::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $taxonomy))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $taxonomy))->toBeFalse();
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/TaxonomyActivityPolicyTest.php"
```

Atteso: FAIL sul secondo test — oggi `create()`/`update()`/`delete()` ritornano sempre `true`.

- [ ] **Step 3: Correggi `TaxonomyActivityPolicy`**

Sostituisci l'intero contenuto di `wm-package/src/Policies/TaxonomyActivityPolicy.php` con:

```php
<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\TaxonomyActivity;

class TaxonomyActivityPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return Response|bool
     */
    public function view(User $user, TaxonomyActivity $taxonomyActivity)
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, TaxonomyActivity $taxonomyActivity)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, TaxonomyActivity $taxonomyActivity)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore(User $user, TaxonomyActivity $taxonomyActivity)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete(User $user, TaxonomyActivity $taxonomyActivity)
    {
        return $user->hasRole('Administrator');
    }
}
```

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/TaxonomyActivityPolicyTest.php"
```

Atteso: PASS (2 test).

- [ ] **Step 5: Commit**

```bash
cd wm-package
git add src/Policies/TaxonomyActivityPolicy.php tests/Unit/Policies/TaxonomyActivityPolicyTest.php
git commit -m "fix(oc:8162): restrict TaxonomyActivity write abilities to Administrator only"
```

---

## Task 7: `TaxonomyThemePolicy` — nuova policy (wm-package)

**Files:**
- Create: `wm-package/src/Policies/TaxonomyThemePolicy.php`
- Test: `wm-package/tests/Unit/Policies/TaxonomyThemePolicyTest.php` (nuovo)

**Interfaces:**
- Consumes: nessuna.
- Produces: `Wm\WmPackage\Policies\TaxonomyThemePolicy` — risolta automaticamente da Laravel per `Wm\WmPackage\Models\TaxonomyTheme` via auto-discovery di namespace (nessuna `Gate::policy()` esplicita necessaria, verificato dal test `Gate::getPolicyFor()` sotto).

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Unit/Policies/TaxonomyThemePolicyTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\TaxonomyTheme;
use App\Models\User;
use Wm\WmPackage\Policies\TaxonomyThemePolicy;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Tests\TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('resolves TaxonomyThemePolicy via Laravel auto-discovery', function () {
    expect(Gate::getPolicyFor(TaxonomyTheme::class))->toBeInstanceOf(TaxonomyThemePolicy::class);
});

it('allows Administrator to view, create, update and delete taxonomy themes', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $taxonomy = new TaxonomyTheme(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($admin)->allows('viewAny', TaxonomyTheme::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', TaxonomyTheme::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $taxonomy))->toBeTrue();
});

it('allows Editor to view but denies create/update/delete on taxonomy themes', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $taxonomy = new TaxonomyTheme(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($editor)->allows('viewAny', TaxonomyTheme::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', TaxonomyTheme::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $taxonomy))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $taxonomy))->toBeFalse();
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/TaxonomyThemePolicyTest.php"
```

Atteso: FAIL — `Gate::getPolicyFor()` ritorna `null` (nessuna policy oggi), tutti i test falliscono.

- [ ] **Step 3: Crea `TaxonomyThemePolicy`**

Crea `wm-package/src/Policies/TaxonomyThemePolicy.php`:

```php
<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\TaxonomyTheme;

class TaxonomyThemePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return Response|bool
     */
    public function view(User $user, TaxonomyTheme $taxonomyTheme)
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, TaxonomyTheme $taxonomyTheme)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, TaxonomyTheme $taxonomyTheme)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore(User $user, TaxonomyTheme $taxonomyTheme)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete(User $user, TaxonomyTheme $taxonomyTheme)
    {
        return $user->hasRole('Administrator');
    }
}
```

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/TaxonomyThemePolicyTest.php"
```

Atteso: PASS (3 test). Se il primo test (`resolves ... via Laravel auto-discovery`) fallisse nonostante il file sia stato creato correttamente, l'auto-discovery per questo modello non funziona come negli altri (vedi Rischi in overview) — in tal caso registra esplicitamente `Gate::policy(TaxonomyTheme::class, TaxonomyThemePolicy::class);` in `wm-package/src/WmPackageServiceProvider.php::boot()` (accanto a `Gate::policy(AppModel::class, AppPolicy::class);`) e ripeti questo step.

- [ ] **Step 5: Commit**

```bash
cd wm-package
git add src/Policies/TaxonomyThemePolicy.php tests/Unit/Policies/TaxonomyThemePolicyTest.php
git commit -m "feat(oc:8162): add TaxonomyThemePolicy restricting write access to Administrator"
```

---

## Task 8: `TaxonomyWherePolicy` — nuova policy (wm-package)

**Files:**
- Create: `wm-package/src/Policies/TaxonomyWherePolicy.php`
- Test: `wm-package/tests/Unit/Policies/TaxonomyWherePolicyTest.php` (nuovo)

**Interfaces:**
- Consumes: nessuna.
- Produces: `Wm\WmPackage\Policies\TaxonomyWherePolicy`, stesso meccanismo del Task 7.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Unit/Policies/TaxonomyWherePolicyTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\TaxonomyWhere;
use App\Models\User;
use Wm\WmPackage\Policies\TaxonomyWherePolicy;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Tests\TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('resolves TaxonomyWherePolicy via Laravel auto-discovery', function () {
    expect(Gate::getPolicyFor(TaxonomyWhere::class))->toBeInstanceOf(TaxonomyWherePolicy::class);
});

it('allows Administrator to view, create, update and delete taxonomy wheres', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $taxonomy = new TaxonomyWhere(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($admin)->allows('viewAny', TaxonomyWhere::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', TaxonomyWhere::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $taxonomy))->toBeTrue();
});

it('allows Editor to view but denies create/update/delete on taxonomy wheres', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $taxonomy = new TaxonomyWhere(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($editor)->allows('viewAny', TaxonomyWhere::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', TaxonomyWhere::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $taxonomy))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $taxonomy))->toBeFalse();
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/TaxonomyWherePolicyTest.php"
```

Atteso: FAIL — nessuna policy oggi per `TaxonomyWhere`.

- [ ] **Step 3: Crea `TaxonomyWherePolicy`**

Crea `wm-package/src/Policies/TaxonomyWherePolicy.php`:

```php
<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\TaxonomyWhere;

class TaxonomyWherePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return Response|bool
     */
    public function view(User $user, TaxonomyWhere $taxonomyWhere)
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, TaxonomyWhere $taxonomyWhere)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, TaxonomyWhere $taxonomyWhere)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore(User $user, TaxonomyWhere $taxonomyWhere)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete(User $user, TaxonomyWhere $taxonomyWhere)
    {
        return $user->hasRole('Administrator');
    }
}
```

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/TaxonomyWherePolicyTest.php"
```

Atteso: PASS (3 test). Stessa nota del Task 7 Step 4 se il test di auto-discovery fallisse: registra `Gate::policy(TaxonomyWhere::class, TaxonomyWherePolicy::class);` in `WmPackageServiceProvider::boot()`.

- [ ] **Step 5: Commit**

```bash
cd wm-package
git add src/Policies/TaxonomyWherePolicy.php tests/Unit/Policies/TaxonomyWherePolicyTest.php
git commit -m "feat(oc:8162): add TaxonomyWherePolicy restricting write access to Administrator"
```

---

## Task 9: Commento TODO su `UgcPoiPolicy`/`UgcTrackPolicy` (wm-package)

> **⚠️ Superseded in parte dal Task 13.** Dopo l'approvazione iniziale del piano è stato deciso di estendere lo scoping per-app anche a EC e UGC (vedi aggiornamento overview wm-package, sezione "Estensione"). Perché lo scoping UGC funzioni, `before()` deve diventare un fix reale (Administrator **o** Validator), non solo un commento — il Task 13 sovrascrive di nuovo `before()` con la logica definitiva. Eseguire comunque questo Task 9 prima del Task 13 non causa danni (il commento viene semplicemente rimpiazzato), ma se si esegue il piano da zero si può saltare direttamente al Task 13 per `before()` ed eseguire da questo Task 9 solo l'eventuale documentazione residua non coperta lì.

Nessuna modifica funzionale — solo un commento che rende visibile un bug noto, già escluso dallo scope di questo ciclo (vedi overview, sezione Rischi), per evitare che un futuro sviluppatore copi il pattern `before() { return true; }` pensando sia lo standard del progetto. Nessun test necessario (nessun comportamento cambia).

**Files:**
- Modify: `wm-package/src/Policies/UgcPoiPolicy.php:14-27`
- Modify: `wm-package/src/Policies/UgcTrackPolicy.php:14-27`

- [ ] **Step 1: Aggiungi il commento a `UgcPoiPolicy::before()`**

In `wm-package/src/Policies/UgcPoiPolicy.php`, sostituisci:

```php
    public function before(User $user, $ability)
    {
        // if ($user->hasRole('Admin')) {
        //     return true;
        // }
        // if ($user->hasRole('Author') || $user->hasRole('Contributor')) {
        //     return false;
        // }

        return true;
    }
```

con:

```php
    public function before(User $user, $ability)
    {
        // TODO oc:8162: bypass identico a quello corretto in MediaPolicy::before() —
        // qualsiasi utente autenticato può oggi vedere/creare/modificare/eliminare
        // qualsiasi UGC di qualsiasi app. Non risolto in questo ciclo (fuori scope,
        // vedi wm-package/docs/features/8162-.../overview.md, sezione Rischi).
        // NON copiare questo pattern in nuove policy.
        return true;
    }
```

- [ ] **Step 2: Aggiungi lo stesso commento a `UgcTrackPolicy::before()`**

In `wm-package/src/Policies/UgcTrackPolicy.php`, applica la stessa modifica (stesso blocco commentato, stesso `return true;` finale, stesso TODO).

- [ ] **Step 3: Commit**

```bash
cd wm-package
git add src/Policies/UgcPoiPolicy.php src/Policies/UgcTrackPolicy.php
git commit -m "docs(oc:8162): flag known before() bypass in UgcPoiPolicy/UgcTrackPolicy as out of scope"
```

---

## Task 10: `canSee()` sezione UGC nel menu Nova (Maphub)

**Files:**
- Modify: `app/Providers/NovaServiceProvider.php:60-63`
- Modify: `wm-package` (submodule pointer) — bump al commit che include i Task 1-9
- Test: `tests/Feature/Nova/UgcMenuVisibilityTest.php` (nuovo)

**Interfaces:**
- Consumes: `Wm\WmPackage\Models\User::hasUgcEnabled(?int $app_id = null): bool` (Task 1).

**⚠️ Precondizione:** questo task presuppone che i Task 1-9 siano già stati mergiati in wm-package. Il bump del submodule pointer e questa modifica a `NovaServiceProvider.php` vanno nello **stesso commit** — vedi nota in testa al piano.

- [ ] **Step 1: Aggiorna il submodule pointer**

```bash
cd wm-package && git pull origin main && cd ..
git status
```

Verifica che `wm-package` risulti come modifica staged/unstaged nel repo principale (nuovo commit del submodule).

- [ ] **Step 2: Scrivi il test che fallisce**

Crea `tests/Feature/Nova/UgcMenuVisibilityTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Nova;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

function ugcSectionVisibleFor(User $user): bool
{
    $request = \Illuminate\Http\Request::create('/nova');
    $request->setUserResolver(fn () => $user);

    // Nova::resolveMainMenu() esegue il callback passato a Nova::mainMenu() in
    // NovaServiceProvider e ne ritorna l'array grezzo di MenuSection (verificato in
    // vendor/laravel/nova/src/Nova.php — non richiede boot completo di Nova).
    $menu = collect(Nova::resolveMainMenu($request));
    // MenuSection::$name è una proprietà pubblica (constructor-promoted), non un metodo
    // (verificato in vendor/laravel/nova/src/Menu/MenuSection.php).
    $ugcSection = $menu->first(fn ($section) => property_exists($section, 'name') && (string) $section->name === 'UGC');

    return $ugcSection !== null && $ugcSection->authorizedToSee($request);
}

it('always shows the UGC section to Administrator', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    expect(ugcSectionVisibleFor($admin))->toBeTrue();
});

it('always shows the UGC section to Validator, even without any UGC-enabled app', function () {
    $validator = User::factory()->create();
    $validator->assignRole('Validator');

    expect(ugcSectionVisibleFor($validator))->toBeTrue();
});

it('shows the UGC section to an Editor whose app has UGC enabled', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    App::factory()->for($editor, 'author')->createQuietly([
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => true,
    ]);

    expect(ugcSectionVisibleFor($editor))->toBeTrue();
});

it('hides the UGC section from an Editor whose app does not have UGC enabled', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    App::factory()->for($editor, 'author')->createQuietly([
        'auth_show_at_startup' => false,
        'geolocation_record_enable' => false,
    ]);

    expect(ugcSectionVisibleFor($editor))->toBeFalse();
});

it('hides the UGC section from an Editor with no app at all, without throwing', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    expect(fn () => ugcSectionVisibleFor($editor))->not->toThrow(Throwable::class);
    expect(ugcSectionVisibleFor($editor))->toBeFalse();
});

it('shows the UGC section to an Editor with multiple apps if at least one has UGC enabled', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    App::factory()->for($editor, 'author')->createQuietly([
        'auth_show_at_startup' => false,
        'geolocation_record_enable' => false,
    ]);
    App::factory()->for($editor, 'author')->createQuietly([
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => true,
    ]);

    expect(ugcSectionVisibleFor($editor))->toBeTrue();
});
```

- [ ] **Step 3: Esegui il test e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest tests/Feature/Nova/UgcMenuVisibilityTest.php"
```

Atteso: FAIL sui test con Editor senza UGC abilitati (oggi la sezione UGC non ha `canSee()`, quindi è sempre visibile).

- [ ] **Step 4: Aggiungi `canSee()` alla sezione UGC**

In `app/Providers/NovaServiceProvider.php`, sostituisci:

```php
                MenuSection::make('UGC', [
                    MenuItem::resource(UgcPoi::class),
                    MenuItem::resource(UgcTrack::class),
                ])->icon('document'),
```

con:

```php
                MenuSection::make('UGC', [
                    MenuItem::resource(UgcPoi::class),
                    MenuItem::resource(UgcTrack::class),
                ])->icon('document')
                    ->canSee(function (Request $request) {
                        $user = $request->user();

                        if ($user->hasRole('Administrator') || $user->hasRole('Validator')) {
                            return true;
                        }

                        if ($user->hasRole('Editor')) {
                            return $user->hasUgcEnabled();
                        }

                        return false;
                    }),
```

(`Request` è già importato in cima al file, `use Illuminate\Http\Request;` — nessun nuovo import necessario).

- [ ] **Step 5: Esegui il test e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest tests/Feature/Nova/UgcMenuVisibilityTest.php"
```

Atteso: PASS (6 test).

- [ ] **Step 6: Esegui l'intera suite dei test scritti in questo piano, come verifica finale**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest tests/Feature/Nova/UgcMenuVisibilityTest.php wm-package/tests/Unit/Models/UserHasUgcEnabledTest.php wm-package/tests/Unit/Policies/LayerPolicyDeleteTest.php wm-package/tests/Unit/Policies/MediaPolicyTest.php wm-package/tests/Unit/Policies/TaxonomyPoiTypePolicyTest.php wm-package/tests/Unit/Policies/TaxonomyActivityPolicyTest.php wm-package/tests/Unit/Policies/TaxonomyThemePolicyTest.php wm-package/tests/Unit/Policies/TaxonomyWherePolicyTest.php"
```

Atteso: PASS su tutti (24 test totali).

- [ ] **Step 7: Commit (bump submodule + canSee nello stesso commit)**

```bash
git add wm-package app/Providers/NovaServiceProvider.php tests/Feature/Nova/UgcMenuVisibilityTest.php
git commit -m "feat(oc:8162): hide UGC menu section from Editor when app has no UGC enabled"
```

---

> **⚠️ Nota sui Task 11-14**: aggiunti dopo l'approvazione iniziale del piano (richiesta di estendere lo scoping per-app a EC e UGC, non solo al menu UGC). A differenza dei Task 1-10, **non sono stati verificati end-to-end con Docker attivo** (daemon non raggiungibile al momento della stesura) — il design è basato su analisi statica di modelli/migrazioni/factory, comunque approfondita (nomi di relazione, colonne `app_id`, gotcha noti delle factory verificati via grep sui file reali). Segui comunque rigorosamente il ciclo scrivi-test→verifica-fallimento→implementa→verifica-successo di ogni step: non dare per scontato che il codice sia corretto solo perché è nel piano.

## Task 11: `User::ownedAppIds()` + scoping EC per `app_id` (wm-package)

**Files:**
- Modify: `wm-package/src/Models/User.php` (nuovo metodo, subito dopo `hasUgcEnabled()`)
- Modify: `wm-package/src/Nova/AbstractEcResource.php:29-39` (`indexQuery()`)
- Modify: `wm-package/src/Policies/EcPoiPolicy.php` (`view`/`update`/`delete`)
- Modify: `wm-package/src/Policies/EcTrackPolicy.php` (`view`/`update`/`delete`)
- Test: `wm-package/tests/Unit/Models/UserOwnedAppIdsTest.php` (nuovo)
- Test: `wm-package/tests/Unit/Nova/AbstractEcResourceIndexQueryTest.php` (nuovo)
- Test: `wm-package/tests/Unit/Policies/EcPoiPolicyAppScopeTest.php` (nuovo)
- Test: `wm-package/tests/Unit/Policies/EcTrackPolicyAppScopeTest.php` (nuovo)

**Interfaces:**
- Produces: `Wm\WmPackage\Models\User::ownedAppIds(): \Illuminate\Support\Collection` — `$this->apps->pluck('id')`.
- Consumes: nessuna dipendenza da altri task di questo piano.

- [ ] **Step 1: Scrivi il test che fallisce per `ownedAppIds()`**

Crea `wm-package/tests/Unit/Models/UserOwnedAppIdsTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use Wm\WmPackage\Models\App;

uses(Tests\TestCase::class, DatabaseTransactions::class);

it('returns the ids of the apps owned by the user', function () {
    $user = User::factory()->create();
    $app1 = App::factory()->createQuietly(['user_id' => $user->id]);
    $app2 = App::factory()->createQuietly(['user_id' => $user->id]);

    expect($user->ownedAppIds()->all())->toEqualCanonicalizing([$app1->id, $app2->id]);
});

it('returns an empty collection when the user owns no app', function () {
    $user = User::factory()->create();

    expect($user->ownedAppIds())->toBeEmpty();
});
```

- [ ] **Step 2: Esegui e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Models/UserOwnedAppIdsTest.php"
```

Atteso: FAIL — `Call to undefined method Wm\WmPackage\Models\User::ownedAppIds()`.

- [ ] **Step 3: Implementa `ownedAppIds()`**

In `wm-package/src/Models/User.php`, subito dopo il metodo `hasUgcEnabled()` aggiunto nel Task 1, aggiungi:

```php
    /**
     * IDs of the apps owned by the user (via the `apps.user_id` "author" relation).
     */
    public function ownedAppIds(): \Illuminate\Support\Collection
    {
        return $this->apps->pluck('id');
    }
```

- [ ] **Step 4: Esegui e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Models/UserOwnedAppIdsTest.php"
```

Atteso: PASS (2 test).

- [ ] **Step 5: Scrivi il test che fallisce per `AbstractEcResource::indexQuery()`**

Crea `wm-package/tests/Unit/Nova/AbstractEcResourceIndexQueryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Tests\TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('scopes the EcPoi index by app_id for a non-Administrator', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id]);

    $otherOwner = User::factory()->create();
    $otherApp = App::factory()->createQuietly(['user_id' => $otherOwner->id]);

    $ownPoi = EcPoi::factory()->createQuietly(['app_id' => $ownApp->id]);
    $otherPoi = EcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $editor);

    $ids = \Wm\WmPackage\Nova\EcPoi::indexQuery($request, EcPoi::query())->pluck('id');

    expect($ids)->toContain($ownPoi->id)
        ->and($ids)->not->toContain($otherPoi->id);
});

it('does not scope the EcPoi index for an Administrator', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $app = App::factory()->createQuietly(['user_id' => $admin->id]);
    $otherApp = App::factory()->createQuietly();

    $ownPoi = EcPoi::factory()->createQuietly(['app_id' => $app->id]);
    $otherPoi = EcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $admin);

    $ids = \Wm\WmPackage\Nova\EcPoi::indexQuery($request, EcPoi::query())->pluck('id');

    expect($ids)->toContain($ownPoi->id)
        ->and($ids)->toContain($otherPoi->id);
});
```

- [ ] **Step 6: Esegui e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Nova/AbstractEcResourceIndexQueryTest.php"
```

Atteso: FAIL sul primo test — oggi `indexQuery()` filtra per `user_id`, non per `app_id`: `$ownPoi`/`$otherPoi` sono entrambi creati da `EcPoi::factory()` (nessun `user_id` esplicito, quindi entrambi `null` o uguali) e nessuno dei due viene escluso correttamente in base all'app.

- [ ] **Step 7: Correggi `AbstractEcResource::indexQuery()`**

In `wm-package/src/Nova/AbstractEcResource.php`, sostituisci:

```php
    public static function indexQuery(NovaRequest $request, $query)
    {
        $user = Auth::user();

        if ($user && ! $user->hasRole('Administrator')) {
            $table = $query->getModel()->getTable();
            if (Schema::hasColumn($table, 'user_id')) {
                return $query->where('user_id', $user->id);
            }
        }

        return $query;
    }
```

con:

```php
    public static function indexQuery(NovaRequest $request, $query)
    {
        $user = Auth::user();

        if ($user && ! $user->hasRole('Administrator')) {
            $table = $query->getModel()->getTable();
            if (Schema::hasColumn($table, 'app_id')) {
                return $query->whereIn('app_id', $user->ownedAppIds());
            }
        }

        return $query;
    }
```

- [ ] **Step 8: Esegui e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Nova/AbstractEcResourceIndexQueryTest.php"
```

Atteso: PASS (2 test).

- [ ] **Step 9: Scrivi il test che fallisce per `EcPoiPolicy`/`EcTrackPolicy`**

Crea `wm-package/tests/Unit/Policies/EcPoiPolicyAppScopeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Tests\TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('allows Editor to view/update/delete an EcPoi of their own app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id]);
    $poi = EcPoi::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('view', $poi))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('update', $poi))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('delete', $poi))->toBeTrue();
});

it('denies Editor from viewing/updating/deleting an EcPoi of another app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    App::factory()->createQuietly(['user_id' => $editor->id]);
    $otherApp = App::factory()->createQuietly();
    $poi = EcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($editor)->allows('view', $poi))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $poi))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $poi))->toBeFalse();
});

it('allows Administrator to view/update/delete any EcPoi regardless of app', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $otherApp = App::factory()->createQuietly();
    $poi = EcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($admin)->allows('view', $poi))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $poi))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $poi))->toBeTrue();
});
```

- [ ] **Step 10: Esegui e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/EcPoiPolicyAppScopeTest.php"
```

Atteso: FAIL sul secondo test — oggi `view/update/delete` controllano `$user->id === $ecPoi->user_id` (entrambi `null` per POI creati senza `user_id` esplicito → `null === null` è vero, quindi l'Editor vedrebbe anche il POI di un'altra app).

- [ ] **Step 11: Correggi `EcPoiPolicy`**

In `wm-package/src/Policies/EcPoiPolicy.php`, sostituisci `view()`, `update()`, `delete()`:

```php
    public function view(User $user, EcPoi $ecPoi)
    {
        // Admins are handled by before(). Users can view their own POIs.
        return $user->id === $ecPoi->user_id;
    }
```

```php
    public function update(User $user, EcPoi $ecPoi)
    {
        // Admins are handled by before(). Users can update their own POIs.
        return $user->id === $ecPoi->user_id;
    }
```

```php
    public function delete(User $user, EcPoi $ecPoi)
    {
        // Admins are handled by before(). Users can delete their own POIs.
        return $user->id === $ecPoi->user_id;
    }
```

con (stesso corpo per tutte e tre, sostituendo `user_id` con `app_id`):

```php
    public function view(User $user, EcPoi $ecPoi)
    {
        // Admins are handled by before(). Editor/Validator can view POIs of their own app(s).
        return $user->ownedAppIds()->contains($ecPoi->app_id);
    }
```

```php
    public function update(User $user, EcPoi $ecPoi)
    {
        // Admins are handled by before(). Editor/Validator can update POIs of their own app(s).
        return $user->ownedAppIds()->contains($ecPoi->app_id);
    }
```

```php
    public function delete(User $user, EcPoi $ecPoi)
    {
        // Admins are handled by before(). Editor/Validator can delete POIs of their own app(s).
        return $user->ownedAppIds()->contains($ecPoi->app_id);
    }
```

- [ ] **Step 12: Esegui e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/EcPoiPolicyAppScopeTest.php"
```

Atteso: PASS (3 test).

- [ ] **Step 13: Ripeti Step 9-12 per `EcTrackPolicy`**

Crea `wm-package/tests/Unit/Policies/EcTrackPolicyAppScopeTest.php` (stesso contenuto di `EcPoiPolicyAppScopeTest.php`, sostituendo `EcPoi` con `EcTrack` ovunque — stesso modello di fabbrica, stessa colonna `app_id`). Applica la stessa correzione a `view()`/`update()`/`delete()` in `wm-package/src/Policies/EcTrackPolicy.php` (stesso identico cambio `user_id` → `app_id`). Esegui e verifica PASS (3 test).

- [ ] **Step 14: Commit**

```bash
cd wm-package
git add src/Models/User.php src/Nova/AbstractEcResource.php src/Policies/EcPoiPolicy.php src/Policies/EcTrackPolicy.php tests/Unit/Models/UserOwnedAppIdsTest.php tests/Unit/Nova/AbstractEcResourceIndexQueryTest.php tests/Unit/Policies/EcPoiPolicyAppScopeTest.php tests/Unit/Policies/EcTrackPolicyAppScopeTest.php
git commit -m "feat(oc:8162): scope EcPoi/EcTrack visibility by owned app instead of record ownership"
```

---

## Task 12: `LayerPolicy` — scoping per `app_id` (wm-package)

**Files:**
- Modify: `wm-package/src/Policies/LayerPolicy.php` (intero file)
- Test: `wm-package/tests/Unit/Policies/LayerPolicyAppScopeTest.php` (nuovo)

**Interfaces:**
- Consumes: `User::ownedAppIds()` (Task 11).

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Unit/Policies/LayerPolicyAppScopeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Tests\TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('allows Editor to view/update a Layer of their own app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id]);
    $layer = Layer::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('view', $layer))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('update', $layer))->toBeTrue();
});

it('denies Editor from viewing/updating a Layer of another app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    App::factory()->createQuietly(['user_id' => $editor->id]);
    $otherApp = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($editor)->allows('view', $layer))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $layer))->toBeFalse();
});

it('allows Administrator to view/update any Layer regardless of app', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $otherApp = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($admin)->allows('view', $layer))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $layer))->toBeTrue();
});

it('still denies Editor from deleting any Layer, regardless of app (unchanged from Task 2)', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id]);
    $layer = Layer::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('delete', $layer))->toBeFalse();
});
```

- [ ] **Step 2: Esegui e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/LayerPolicyAppScopeTest.php"
```

Atteso: FAIL sul secondo test — oggi `view()`/`update()` ritornano `true` per chiunque, indipendentemente dall'app.

- [ ] **Step 3: Riscrivi `LayerPolicy`**

Sostituisci l'intero contenuto di `wm-package/src/Policies/LayerPolicy.php` con:

```php
<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\Layer;

class LayerPolicy
{
    use HandlesAuthorization;

    /**
     * Perform pre-authorization checks.
     *
     * @return void|bool
     */
    public function before(User $user, string $ability)
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }
    }

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return Response|bool
     */
    public function view(User $user, Layer $layer)
    {
        return $user->ownedAppIds()->contains($layer->app_id);
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        if ($user->hasRole('Editor')) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, Layer $layer)
    {
        return $user->ownedAppIds()->contains($layer->app_id);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, Layer $layer)
    {
        if ($user->hasRole('Editor')) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore(User $user, Layer $layer)
    {
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete(User $user, Layer $layer)
    {
        return true;
    }
}
```

Nota: `before()` è nuovo (non esisteva). `create()`/`delete()` restano identici al Task 2 (il quarto test verifica esplicitamente che il comportamento del Task 2 non sia stato alterato). `restore()`/`forceDelete()` restano `true` per chiunque, invariati — non richiesti né dal ticket originale né da questa estensione.

- [ ] **Step 4: Esegui e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/LayerPolicyAppScopeTest.php"
```

Atteso: PASS (4 test).

- [ ] **Step 5: Commit**

```bash
cd wm-package
git add src/Policies/LayerPolicy.php tests/Unit/Policies/LayerPolicyAppScopeTest.php
git commit -m "feat(oc:8162): scope Layer view/update by owned app for non-Administrator"
```

---

## Task 13: Scoping reale UGC per `app_id` (wm-package) — sostituisce il Task 9

**Files:**
- Modify: `wm-package/src/Nova/AbstractUgcResource.php` (nuovo `indexQuery()`)
- Modify: `wm-package/src/Policies/UgcPoiPolicy.php` (intero file)
- Modify: `wm-package/src/Policies/UgcTrackPolicy.php` (intero file)
- Test: `wm-package/tests/Unit/Nova/AbstractUgcResourceIndexQueryTest.php` (nuovo)
- Test: `wm-package/tests/Unit/Policies/UgcPoiPolicyTest.php` (nuovo)
- Test: `wm-package/tests/Unit/Policies/UgcTrackPolicyTest.php` (nuovo)

**Interfaces:**
- Consumes: `User::ownedAppIds()` (Task 11), `User::hasDashboardShow()` (già esistente).

- [ ] **Step 1: Scrivi il test che fallisce per `AbstractUgcResource::indexQuery()`**

Crea `wm-package/tests/Unit/Nova/AbstractUgcResourceIndexQueryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Tests\TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('scopes the UgcPoi index by app_id for a non-Administrator', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id]);
    $otherApp = App::factory()->createQuietly();

    $ownUgcPoi = UgcPoi::factory()->createQuietly(['app_id' => $ownApp->id]);
    $otherUgcPoi = UgcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $editor);

    $ids = \Wm\WmPackage\Nova\UgcPoi::indexQuery($request, UgcPoi::query())->pluck('id');

    expect($ids)->toContain($ownUgcPoi->id)
        ->and($ids)->not->toContain($otherUgcPoi->id);
});

it('does not scope the UgcPoi index for an Administrator', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $otherApp = App::factory()->createQuietly();
    $otherUgcPoi = UgcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $admin);

    $ids = \Wm\WmPackage\Nova\UgcPoi::indexQuery($request, UgcPoi::query())->pluck('id');

    expect($ids)->toContain($otherUgcPoi->id);
});
```

- [ ] **Step 2: Esegui e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Nova/AbstractUgcResourceIndexQueryTest.php"
```

Atteso: FAIL sul primo test — oggi `AbstractUgcResource` non ha alcun `indexQuery()` (eredita il no-op di `Resource`), quindi la lista non è mai filtrata.

- [ ] **Step 3: Aggiungi `indexQuery()` a `AbstractUgcResource`**

In `wm-package/src/Nova/AbstractUgcResource.php`, aggiungi (dopo la dichiarazione della classe, prima di `fields()`); verifica gli `use` già presenti in cima al file e aggiungi quelli mancanti tra `Illuminate\Support\Facades\Auth` e `Illuminate\Support\Facades\Schema`:

```php
    /**
     * Build an "index" query for the given resource.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        $user = \Illuminate\Support\Facades\Auth::user();

        if ($user && ! $user->hasRole('Administrator')) {
            $table = $query->getModel()->getTable();
            if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'app_id')) {
                return $query->whereIn('app_id', $user->ownedAppIds());
            }
        }

        return $query;
    }

```

(Usato il FQCN inline per `Auth`/`Schema` per non introdurre conflitti con eventuali alias già presenti nel file — se preferisci, verifica prima gli `use` esistenti e importa normalmente.)

- [ ] **Step 4: Esegui e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Nova/AbstractUgcResourceIndexQueryTest.php"
```

Atteso: PASS (2 test).

- [ ] **Step 5: Scrivi il test che fallisce per `UgcPoiPolicy`**

Crea `wm-package/tests/Unit/Policies/UgcPoiPolicyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Tests\TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('always allows Administrator on any UGC ability, regardless of app', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $otherApp = App::factory()->createQuietly();
    $ugcPoi = UgcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($admin)->allows('viewAny', UgcPoi::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $ugcPoi))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $ugcPoi))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $ugcPoi))->toBeTrue();
});

it('always allows Validator on any UGC ability, regardless of app (mirrors menu visibility decision)', function () {
    $validator = User::factory()->create();
    $validator->assignRole('Validator');
    $otherApp = App::factory()->createQuietly();
    $ugcPoi = UgcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($validator)->allows('viewAny', UgcPoi::class))->toBeTrue()
        ->and(Gate::forUser($validator)->allows('view', $ugcPoi))->toBeTrue()
        ->and(Gate::forUser($validator)->allows('update', $ugcPoi))->toBeTrue()
        ->and(Gate::forUser($validator)->allows('delete', $ugcPoi))->toBeTrue();
});

it('allows Editor with hasDashboardShow to view a UGC of their own app, but not create/update/delete', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id, 'dashboard_show' => true]);
    $ugcPoi = UgcPoi::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('viewAny', UgcPoi::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $ugcPoi))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', UgcPoi::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $ugcPoi))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $ugcPoi))->toBeFalse();
});

it('denies Editor with hasDashboardShow from viewing a UGC of another app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    App::factory()->createQuietly(['user_id' => $editor->id, 'dashboard_show' => true]);
    $otherApp = App::factory()->createQuietly();
    $ugcPoi = UgcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($editor)->allows('view', $ugcPoi))->toBeFalse();
});

it('denies Editor without hasDashboardShow from viewing any UGC (existing gate, unchanged)', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id, 'dashboard_show' => false]);
    $ugcPoi = UgcPoi::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('viewAny', UgcPoi::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('view', $ugcPoi))->toBeFalse();
});
```

- [ ] **Step 6: Esegui e verifica che fallisca**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/UgcPoiPolicyTest.php"
```

Atteso: FAIL su gran parte dei test — oggi `before()` ritorna sempre `true` per chiunque (Editor incluso può create/update/delete; nessuno scoping per app).

- [ ] **Step 7: Riscrivi `UgcPoiPolicy`**

Sostituisci l'intero contenuto di `wm-package/src/Policies/UgcPoiPolicy.php` con:

```php
<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\UgcPoi;

class UgcPoiPolicy
{
    use HandlesAuthorization;

    /**
     * Perform pre-authorization checks.
     *
     * Administrator and Validator see/manage any UGC regardless of app (same
     * treatment as the menu-visibility decision: a Validator's job is to
     * validate UGC across apps, not just their own).
     *
     * @return void|bool
     */
    public function before(User $user, string $ability)
    {
        if ($user->hasRole('Administrator') || $user->hasRole('Validator')) {
            return true;
        }
    }

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        if ($user->hasRole('Editor') && $user->hasDashboardShow()) {
            return true;
        }
    }

    /**
     * Determine whether the user can view the model.
     *
     * Editor: read-only, limited to UGC of their own app(s).
     *
     * @return Response|bool
     */
    public function view(User $user, UgcPoi $ugcPoi)
    {
        if ($user->hasRole('Editor') && $user->hasDashboardShow()) {
            return $user->ownedAppIds()->contains($ugcPoi->app_id);
        }
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, UgcPoi $ugcPoi)
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, UgcPoi $ugcPoi)
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore(User $user, UgcPoi $ugcPoi)
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete(User $user, UgcPoi $ugcPoi)
    {
        return false;
    }
}
```

- [ ] **Step 8: Esegui e verifica che passi**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest wm-package/tests/Unit/Policies/UgcPoiPolicyTest.php"
```

Atteso: PASS (5 test).

- [ ] **Step 9: Ripeti Step 5-8 per `UgcTrackPolicy`**

Crea `wm-package/tests/Unit/Policies/UgcTrackPolicyTest.php` (stesso contenuto di `UgcPoiPolicyTest.php`, sostituendo `UgcPoi` con `UgcTrack` ovunque). Sostituisci l'intero contenuto di `wm-package/src/Policies/UgcTrackPolicy.php` con lo stesso codice di `UgcPoiPolicy.php` sopra, sostituendo `UgcPoi`/`$ugcPoi` con `UgcTrack`/`$ugcTrack`. Nota: questo **armonizza** `UgcTrackPolicy::viewAny()` (oggi `return true;` senza gate `hasDashboardShow()`) alla stessa struttura di `UgcPoiPolicy` — asimmetria pre-esistente risolta come effetto necessario di questo fix (vedi overview, Rischi). Esegui e verifica PASS (5 test).

- [ ] **Step 10: Commit**

```bash
cd wm-package
git add src/Nova/AbstractUgcResource.php src/Policies/UgcPoiPolicy.php src/Policies/UgcTrackPolicy.php tests/Unit/Nova/AbstractUgcResourceIndexQueryTest.php tests/Unit/Policies/UgcPoiPolicyTest.php tests/Unit/Policies/UgcTrackPolicyTest.php
git commit -m "feat(oc:8162): scope UGC visibility by owned app for Editor; Administrator/Validator unrestricted"
```

---

## Task 14: Verifica finale completa (wm-package + Maphub)

- [ ] **Step 1: Esegui l'intera suite dei test scritti in questo piano (Task 1-13)**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/pest \
  tests/Feature/Nova/UgcMenuVisibilityTest.php \
  wm-package/tests/Unit/Models/UserHasUgcEnabledTest.php \
  wm-package/tests/Unit/Models/UserOwnedAppIdsTest.php \
  wm-package/tests/Unit/Nova/AbstractEcResourceIndexQueryTest.php \
  wm-package/tests/Unit/Nova/AbstractUgcResourceIndexQueryTest.php \
  wm-package/tests/Unit/Policies/LayerPolicyDeleteTest.php \
  wm-package/tests/Unit/Policies/LayerPolicyAppScopeTest.php \
  wm-package/tests/Unit/Policies/MediaPolicyTest.php \
  wm-package/tests/Unit/Policies/TaxonomyPoiTypePolicyTest.php \
  wm-package/tests/Unit/Policies/TaxonomyActivityPolicyTest.php \
  wm-package/tests/Unit/Policies/TaxonomyThemePolicyTest.php \
  wm-package/tests/Unit/Policies/TaxonomyWherePolicyTest.php \
  wm-package/tests/Unit/Policies/EcPoiPolicyAppScopeTest.php \
  wm-package/tests/Unit/Policies/EcTrackPolicyAppScopeTest.php \
  wm-package/tests/Unit/Policies/UgcPoiPolicyTest.php \
  wm-package/tests/Unit/Policies/UgcTrackPolicyTest.php"
```

Atteso: PASS su tutti.

- [ ] **Step 2: PHPStan (has_phpstan_ci: true per questo progetto)**

```bash
docker exec -it php-maphub sh -c "cd /var/www/html/maphub && vendor/bin/phpstan analyse"
```

Se emergono errori sui file toccati da questo piano, correggili prima di procedere (vedi `wm-plan` → `review-gate: phpstan-check`).

- [ ] **Step 3: Verifica manuale rapida in Nova (se possibile prima del commit finale)**

Login come Editor con un'app UGC-abilitata e un'App di cui non è owner (se esistono nel DB locale — visti in Fase: environment-setup: "Via Dinarica Kosovo" abilitata, le altre 3 no): naviga EC/Layer/UGC e conferma che la lista mostri solo i record della propria app.

- [ ] **Step 4: Commit finale (se non già coperto dai commit dei singoli task)**

Nessun commit aggiuntivo previsto in questo task — è solo verifica. Se emergono fix minori durante la verifica, applicali con un commit dedicato `fix(oc:8162): ...` seguendo la stessa disciplina TDD.

---

## Self-Review

**Copertura overview → task:**
- Taxonomy Theme/Where (nuove policy) → Task 7, 8
- Taxonomy PoiType/Activity (fix) → Task 5, 6
- Layer::delete() → Task 2
- MediaPolicy::before() → Task 3
- UserPolicy/TaxonomyTarget/TaxonomyWhen dead code → Task 4
- User::hasUgcEnabled() → Task 1
- Commento TODO UgcPoi/UgcTrack → Task 9 (superseded in parte da Task 13)
- canSee() menu UGC + tutti gli scenari di test (Administrator/Validator/Editor abilitato/non abilitato/senza app/multi-app) → Task 10
- Test path positivo+negativo su ogni policy → verificato in ogni task (Step 1)
- Test auto-discovery `Gate::getPolicyFor()` → Task 7, 8 Step 1
- Ordine di merge vincolante → nota in testa al piano + precondizione esplicita Task 10
- `User::ownedAppIds()` → Task 11
- Scoping EC (`AbstractEcResource::indexQuery()`, `EcPoiPolicy`, `EcTrackPolicy`) per `app_id` → Task 11
- Scoping Layer (`before()` nuovo, `view()`/`update()` per `app_id`, `create()`/`delete()` invariati) → Task 12
- Scoping UGC reale (`AbstractUgcResource::indexQuery()`, `UgcPoiPolicy`/`UgcTrackPolicy::before()` Administrator+Validator, `viewAny()`/`view()` per Editor scopati e sola lettura) → Task 13
- Test "record di un'altra app negato" per ogni resource scopata → Task 11 (Step 9-13), Task 12 (Step 1), Task 13 (Step 5, 9)
- Verifica finale + PHPStan → Task 14

**Nessun placeholder**: ogni step contiene codice completo, nessun "TBD"/"gestisci l'errore"/riferimento a "Task N" senza il codice ripetuto.

**Coerenza tipi/firme**: `hasUgcEnabled(?int $app_id = null): bool` (Task 1) usato identicamente in Task 10 (`$user->hasUgcEnabled()`, senza argomenti — usa il default `null`). `ownedAppIds(): \Illuminate\Support\Collection` (Task 11) riusato identicamente in Task 11 (`EcPoiPolicy`/`EcTrackPolicy`/`AbstractEcResource`), Task 12 (`LayerPolicy`) e Task 13 (`UgcPoiPolicy`/`UgcTrackPolicy`/`AbstractUgcResource`) — stessa firma, stesso metodo, nessuna divergenza.

**Verifica empirica**: Task 1-10 verificati end-to-end con Docker attivo durante la stesura del piano (scritti, eseguiti, corretti, ripristinati). Task 11-14 progettati con Docker non raggiungibile — solo analisi statica di modelli/migrazioni/factory (nomi relazione, colonne `app_id`, gotcha factory verificati via grep). Da verificare con lo stesso rigore in esecuzione.

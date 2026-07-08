> Ticket: oc:8231

# Plan — Aggiungere impersonate

## Repo coinvolto
`wm-package` — tutte le modifiche sono nel package condiviso, nessuna modifica al repo principale `maphub` (`app/Models/User.php` eredita tutto da `Wm\WmPackage\Models\User`, `config/nova.php` non richiede modifiche).

I test (Step 6 in `tests/Unit/`, Step 7 in `tests/Feature/`) usano `Tests\TestCase` (bootstrap dell'app host `maphub`, non `Wm\WmPackage\Tests\TestCase`/Testbench) — necessario perché solo l'app host ha Laravel Nova realmente registrato con le route `nova-api/*`. Si eseguono con percorso esplicito: `vendor/bin/pest wm-package/tests/...` (la testsuite `Unit` di `phpunit.xml` non li include).

---

## Step 1 — Config `impersonation.allowed_roles`

**File:** `wm-package/config/wm-package.php`

Aggiungere la nuova chiave subito dopo il blocco `super_admin_emails` esistente, stesso pattern di sanitizzazione env (trim + filter su stringa comma-separated).

```php
/*
|--------------------------------------------------------------------------
| Nova Impersonation
|--------------------------------------------------------------------------
|
| Ruoli abilitati a impersonare altri utenti da Nova {@see \Wm\WmPackage\Models\User::canImpersonate()}.
| Fallback env: WM_IMPERSONATION_ALLOWED_ROLES (comma-separated) → default Administrator.
*/
'impersonation' => [
    'allowed_roles' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WM_IMPERSONATION_ALLOWED_ROLES', 'Administrator'))
    ))),
],
```

**Commit:** `feat(oc:8231): add impersonation.allowed_roles config to wm-package`

---

## Step 2 — Trait `Impersonatable` + regole di autorizzazione su `User`

**File:** `wm-package/src/Models/User.php`

Aggiungere l'import e il trait nativo Nova nella dichiarazione `use` esistente (riga 29), poi i due metodi di autorizzazione. Posizionarli vicino agli altri helper di ruolo (dopo `hasClassificationShow()`, prima di `getGeoPassAttribute()`).

Import da aggiungere in cima al file:
```php
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Auth\Impersonatable;
```

Modifica alla riga `use Favoriteability, HasApiTokens, HasPackageFactory, HasRoles, Notifiable;`:
```php
use Favoriteability, HasApiTokens, HasPackageFactory, HasRoles, Impersonatable, Notifiable;
```

Nuovi metodi:
```php
/**
 * Determine if the user can impersonate another user.
 *
 * Compone (non sostituisce) il check nativo Nova `viewNova` — difesa in profondità:
 * anche se `impersonation.allowed_roles` fosse misconfigurato, resta necessario
 * il permesso Nova di base.
 */
public function canImpersonate(): bool
{
    $allowedRoles = config('wm-package.impersonation.allowed_roles', ['Administrator']);

    return $this->hasAnyRole($allowedRoles) && Gate::forUser($this)->check('viewNova');
}

/**
 * Determine if the user can be impersonated.
 *
 * Un Administrator non può essere impersonato (nemmeno da un altro Administrator),
 * per limitare la superficie di abuso tra pari livello massimo.
 */
public function canBeImpersonated(): bool
{
    return ! $this->hasRole('Administrator');
}
```

**Commit:** `feat(oc:8231): enable Nova Impersonatable on User with role-based authorization`

---

## Step 3 — Listener log inizio impersonation

**File:** `wm-package/src/Listeners/LogImpersonationStarted.php` (nuovo)

Stesso stile di `wm-package/src/Listeners/UpdateLastLoginAt.php` (classe minimale, un solo metodo `handle()`).

```php
<?php

namespace Wm\WmPackage\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Nova\Events\StartedImpersonating;

class LogImpersonationStarted
{
    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(StartedImpersonating $event)
    {
        Log::info('Nova impersonation started', [
            'impersonator_id' => $event->impersonator->getAuthIdentifier(),
            'impersonator_email' => $event->impersonator->email ?? null,
            'impersonated_id' => $event->impersonated->getAuthIdentifier(),
            'impersonated_email' => $event->impersonated->email ?? null,
        ]);
    }
}
```

**Commit:** `feat(oc:8231): add LogImpersonationStarted listener`

---

## Step 4 — Listener log fine impersonation

**File:** `wm-package/src/Listeners/LogImpersonationStopped.php` (nuovo)

Speculare allo Step 3.

```php
<?php

namespace Wm\WmPackage\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Nova\Events\StoppedImpersonating;

class LogImpersonationStopped
{
    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(StoppedImpersonating $event)
    {
        Log::info('Nova impersonation stopped', [
            'impersonator_id' => $event->impersonator->getAuthIdentifier(),
            'impersonator_email' => $event->impersonator->email ?? null,
            'impersonated_id' => $event->impersonated->getAuthIdentifier(),
            'impersonated_email' => $event->impersonated->email ?? null,
        ]);
    }
}
```

**Commit:** `feat(oc:8231): add LogImpersonationStopped listener`

---

## Step 5 — Registrare i listener in `EventServiceProvider`

**File:** `wm-package/src/Providers/EventServiceProvider.php`

Aggiungere i due import e le due nuove voci nell'array `$listen` esistente (dopo `OrderListReorderedEvent::class`).

Import da aggiungere:
```php
use Laravel\Nova\Events\StartedImpersonating;
use Laravel\Nova\Events\StoppedImpersonating;
use Wm\WmPackage\Listeners\LogImpersonationStarted;
use Wm\WmPackage\Listeners\LogImpersonationStopped;
```

Voci da aggiungere in `$listen`:
```php
StartedImpersonating::class => [
    LogImpersonationStarted::class,
],
StoppedImpersonating::class => [
    LogImpersonationStopped::class,
],
```

**Commit:** `feat(oc:8231): register impersonation event listeners`

---

## Step 6 — Test Pest unit: autorizzazione e listener

**File:** `wm-package/tests/Unit/ImpersonationAuthorizationTest.php` (nuovo)

Segue il pattern di `wm-package/tests/Feature/Nova/AbstractUserResourceRoleGuardTest.php`: `RolesAndPermissionsService::seedDatabase()` in `beforeEach`, `User::factory()`, asserzioni dirette sui metodi.

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Events\StartedImpersonating;
use Laravel\Nova\Events\StoppedImpersonating;
use Wm\WmPackage\Listeners\LogImpersonationStarted;
use Wm\WmPackage\Listeners\LogImpersonationStopped;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('Administrator can impersonate', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    expect($admin->canImpersonate())->toBeTrue();
});

it('Editor cannot impersonate', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    expect($editor->canImpersonate())->toBeFalse();
});

it('Validator cannot impersonate', function () {
    $validator = User::factory()->create();
    $validator->assignRole('Validator');

    expect($validator->canImpersonate())->toBeFalse();
});

it('Administrator cannot be impersonated', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    expect($admin->canBeImpersonated())->toBeFalse();
});

it('Editor can be impersonated by an Administrator', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    expect($editor->canBeImpersonated())->toBeTrue();
});

it('respects a custom allowed_roles config', function () {
    config(['wm-package.impersonation.allowed_roles' => ['Editor']]);

    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    expect($editor->canImpersonate())->toBeTrue()
        ->and($admin->canImpersonate())->toBeFalse();
});

it('logs impersonation start', function () {
    Log::shouldReceive('info')
        ->once()
        ->with('Nova impersonation started', Mockery::type('array'));

    $admin = User::factory()->create(['email' => 'admin@test.com']);
    $editor = User::factory()->create(['email' => 'editor@test.com']);

    (new LogImpersonationStarted)->handle(new StartedImpersonating($admin, $editor, null));
});

it('logs impersonation stop', function () {
    Log::shouldReceive('info')
        ->once()
        ->with('Nova impersonation stopped', Mockery::type('array'));

    $admin = User::factory()->create(['email' => 'admin@test.com']);
    $editor = User::factory()->create(['email' => 'editor@test.com']);

    (new LogImpersonationStopped)->handle(new StoppedImpersonating($admin, $editor, null));
});
```

**Commit:** `test(oc:8231): add unit tests for impersonation authorization and logging`

---

## Step 7 — Test Pest e2e: route Nova reali

**File:** `wm-package/tests/Feature/ImpersonationHttpTest.php` (nuovo)

Usa `Tests\TestCase` (bootstrap host, vedi nota in "Repo coinvolto"), colpisce le route reali `POST`/`DELETE /nova-api/impersonate` registrate da `NovaCoreServiceProvider` (prefisso `nova-api`, vedi `vendor/laravel/nova/src/NovaCoreServiceProvider.php:187`).

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('Administrator can start and stop impersonating an Editor via the real Nova route', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $this->actingAs($admin)
        ->postJson('/nova-api/impersonate', [
            'resource' => 'users',
            'resourceId' => $editor->id,
        ])
        ->assertOk();

    expect(auth()->id())->toBe($editor->id);

    $this->deleteJson('/nova-api/impersonate')
        ->assertOk();

    expect(auth()->id())->toBe($admin->id);
});

it('Editor cannot impersonate via the real Nova route', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $target = User::factory()->create();
    $target->assignRole('Validator');

    $this->actingAs($editor)
        ->postJson('/nova-api/impersonate', [
            'resource' => 'users',
            'resourceId' => $target->id,
        ])
        ->assertForbidden();
});

it('Administrator cannot impersonate another Administrator via the real Nova route', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('Administrator');

    $this->actingAs($admin)
        ->postJson('/nova-api/impersonate', [
            'resource' => 'users',
            'resourceId' => $otherAdmin->id,
        ])
        ->assertForbidden();
});
```

> **Nota implementativa:** se le route Nova risultano protette da `VerifyCsrfToken` nell'ambiente di test (risposta 419 invece di 200/403), aggiungere `$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);` in `beforeEach()`. Verificare durante l'esecuzione, non assunto a priori.

**Comando per eseguire questo test (non incluso nella testsuite di default):**
```bash
vendor/bin/pest wm-package/tests/Feature/ImpersonationHttpTest.php
```

**Commit:** `test(oc:8231): add e2e test hitting real Nova impersonate routes`

---

## Checklist pre-PR

- [ ] Step 1: `impersonation.allowed_roles` in `config/wm-package.php`
- [ ] Step 2: trait `Impersonatable` + `canImpersonate()`/`canBeImpersonated()` in `wm-package/src/Models/User.php`
- [ ] Step 3: `LogImpersonationStarted` listener
- [ ] Step 4: `LogImpersonationStopped` listener
- [ ] Step 5: listener registrati in `EventServiceProvider`
- [ ] Step 6: test unit passano (`vendor/bin/pest wm-package/tests/Unit/ImpersonationAuthorizationTest.php`)
- [ ] Step 7: test e2e passano (`vendor/bin/pest wm-package/tests/Feature/ImpersonationHttpTest.php`)
- [ ] Verifica manuale: login come Administrator su Nova (`docker exec -it php-maphub php artisan tinker` per assegnare ruolo se serve), impersonare un Editor dal menu utente/tabella risorse, verificare redirect a `/nova`, controllare la riga di log (`storage/logs/laravel.log`), eseguire "Stop impersonating", verificare redirect e log di stop
- [ ] Verifica manuale: bottone/azione impersonate non disponibile o bloccata (403) su un altro Administrator
- [ ] `php artisan wm-package:publish-missing-migrations --dry-run` — nessuna migration necessaria per questa feature, verificare comunque che il gate resti verde
- [ ] `vendor/bin/phpstan analyse` pulito sui file toccati

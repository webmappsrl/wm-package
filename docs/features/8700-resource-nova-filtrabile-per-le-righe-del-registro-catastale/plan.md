> Ticket: oc:8700

# Catasto visibile solo ad Administrator ed Editor — piano di implementazione (wm-package)

> **Per chi esegue con agenti:** SUB-SKILL RICHIESTA: superpowers:subagent-driven-development (consigliata) o superpowers:executing-plans, task per task. Gli step usano le checkbox (`- [ ]`).

**Obiettivo:** le Resource Nova del Catasto (Istanze, Registro dei codici, Anomalie) sono visibili e utilizzabili solo da Administrator ed Editor, su tutti gli endpoint, e la sezione di menu «Catasto» non compare vuota.

**Architettura:** una Policy del package (`TrailRegistryPolicy`) registrata per i tre modelli del Catasto rende `authorizable()` vero, così Nova applica la regola anche a detail, modifica e Action. La condizione sui ruoli sta in un solo metodo statico, `TrailRegistryPolicy::allows()`, riusato dalle Action e dagli shard. Il menu ricava la visibilità della sezione dalle sue voci.

**Stack:** Laravel 12, Nova 5.7.6, spatie/laravel-permission, Pest.

**Spec:** `docs/features/8700-resource-nova-filtrabile-per-le-righe-del-registro-catastale/overview.md` (questo repo). Parte forestas: `../../../../docs/features/8700-resource-nova-filtrabile-per-le-righe-del-registro-catastale/plan.md`.

## Vincoli globali

- **Nessun commit, `git add`, branch o push eseguito da Claude**: gli step «Commit» sono istruzioni per il dev, che committa dopo aver letto il diff.
- Commenti, docblock e documentazione in italiano; termini tecnici in inglese.
- Ruoli ammessi, esattamente: `Administrator`, `Editor`.
- Test del package: database `wm_package` (`phpunit.xml.dist`), si lanciano da `wm-package/` con `vendor/bin/pest`. Prima di lanciarli, verifica che `phpunit.xml.dist` punti a `DB_DATABASE=wm_package` e che non esista un `phpunit.xml` locale che punti altrove.
- Le API non devono cambiare: la Policy non va usata fuori da Nova.

## Review Focus

1. **Action eseguite per POST senza passare dalla Resource**: Nova, se un'Action ha `canRun()`, controlla solo quello (`vendor/laravel/nova/src/Actions/ActionModelCollection.php:29-31`) e la Policy non viene consultata. Un Validator che manda il POST di `approve-trail-application` deve ricevere un rifiuto → test nel Task 2.
2. **Modifica di un'istanza non in istruttoria via PUT**: oggi il PUT passa da `authorizeToUpdate()`, che senza policy non controlla nulla, quindi anche il vincolo «solo `UnderReview`» è aggirabile. Con la Policy il vincolo deve valere anche per l'Editor → test nel Task 1.
3. **Editor che crea un'istanza dal form**: oggi `TrailApplication` è creabile da Nova (`TrailApplicationCreateFormTest`). La Policy non deve togliere questa possibilità all'Editor → test nel Task 1.
4. **Shard con una sottoclasse di modello configurata** (`wm-package.features.trail_registry.models.*`): la Policy deve valere anche lì → test nel Task 1.
5. **Sezione «Catasto» con il `canSee` dello shard**: chi ha dichiarato la sezione con un `canSee` non deve perderlo nella ricostruzione → test nel Task 3.

---

### Task 1: Policy del Catasto

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-policy-del-catasto)

**File:**
- Crea: `src/TrailRegistry/Policies/TrailRegistryPolicy.php`
- Modifica: `src/WmPackageServiceProvider.php` (dopo `Gate::policy(AppModel::class, AppPolicy::class);`, riga 163)
- Test: `tests/Feature/TrailRegistry/TrailRegistryPolicyTest.php`

**Interfacce:**
- Produce: `Wm\WmPackage\TrailRegistry\Policies\TrailRegistryPolicy::allows(?\Illuminate\Contracts\Auth\Authenticatable $user): bool` — vero se l'utente ha `Administrator` o `Editor`. Usato dal Task 2 e da forestas.
- Produce: costante `TrailRegistryPolicy::ROLES = ['Administrator', 'Editor']`.

**Note di progettazione:**
- Una sola classe Policy per i tre modelli: `Gate::policy()` accetta la stessa classe per più modelli, e i metodi distinguono il modello dove serve (solo `create` e `update`).
- **La Policy si registra sempre, non solo a dominio acceso.** Lo scostamento dall'overview è voluto: il flag `trail_registry` si legge a runtime (i test lo cambiano dopo il boot), e a dominio spento le Resource sono già nascoste dal trait `HidesWhenTrailRegistryDisabled`. Registrata sempre, la Policy non apre nulla a dominio spento: il trait mette in AND il dominio con `parent::`, che ora passa dalla Policy.
- Si registra sia sulla classe base sia su quella configurata (`TrailRegistryClasses::code()` ecc.), se diversa: `Gate::getPolicyFor()` trova la policy anche per le sottoclassi, ma la registrazione esplicita della classe configurata rende la cosa indipendente da quel dettaglio.
- Regole per modello:

| Metodo | TrailApplication | TrailRegistryCode | TrailRegistryAnomaly |
|---|---|---|---|
| `viewAny`, `view` | `allows` | `allows` | `allows` |
| `create` | `allows` | `false` | `false` |
| `update` | `allows` e stato `UnderReview` | `false` | `false` |
| `delete`, `restore`, `forceDelete`, `replicate` | `false` | `false` | `false` |
| `runAction`, `runDestructiveAction` | `allows` | `allows` | `allows` |

- [ ] **Step 1: scrivi i test che falliscono**

```php
<?php

use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Policies\TrailRegistryPolicy;

/**
 * Il Catasto lo vede e lo usa solo chi lo gestisce (oc:8700): Administrator
 * ed Editor. Senza una policy registrata Nova non controlla detail, modifica e
 * Action aperti per URL: per questo la regola sta qui e non solo nelle Resource.
 */
beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

function utenteConRuolo(?string $role): User
{
    $user = User::factory()->create();
    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
}

it('allows vale solo per Administrator ed Editor', function (?string $role, bool $expected) {
    expect(TrailRegistryPolicy::allows(utenteConRuolo($role)))->toBe($expected);
})->with([
    'Administrator' => ['Administrator', true],
    'Editor' => ['Editor', true],
    'Validator' => ['Validator', false],
    'Contributor' => ['Contributor', false],
    'senza ruolo' => [null, false],
]);

it('allows nega senza utente', function () {
    expect(TrailRegistryPolicy::allows(null))->toBeFalse();
});

it('la policy e registrata per i tre modelli del catasto', function () {
    foreach ([TrailApplication::class, TrailRegistryCode::class, TrailRegistryAnomaly::class] as $model) {
        expect(Gate::getPolicyFor($model))->toBeInstanceOf(TrailRegistryPolicy::class);
    }
});

it('codici e anomalie restano in sola lettura anche per Administrator', function () {
    $admin = utenteConRuolo('Administrator');

    foreach ([new TrailRegistryCode, new TrailRegistryAnomaly] as $model) {
        expect($admin->can('view', $model))->toBeTrue()
            ->and($admin->can('update', $model))->toBeFalse()
            ->and($admin->can('delete', $model))->toBeFalse()
            ->and($admin->can('create', $model::class))->toBeFalse();
    }
});

it('un istanza si modifica solo in istruttoria, e solo da Administrator ed Editor', function () {
    $editor = utenteConRuolo('Editor');
    $validator = utenteConRuolo('Validator');
    $inIstruttoria = (new TrailApplication)->forceFill(['status' => TrailApplicationStatus::UnderReview]);
    $approvata = (new TrailApplication)->forceFill(['status' => TrailApplicationStatus::Approved]);

    expect($editor->can('update', $inIstruttoria))->toBeTrue()
        ->and($editor->can('update', $approvata))->toBeFalse()
        ->and($validator->can('update', $inIstruttoria))->toBeFalse()
        ->and($editor->can('create', TrailApplication::class))->toBeTrue()
        ->and($validator->can('create', TrailApplication::class))->toBeFalse()
        ->and($editor->can('delete', $inIstruttoria))->toBeFalse();
});
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

Run (da `wm-package/`): `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryPolicyTest.php`
Expected: FAIL, `Class "Wm\WmPackage\TrailRegistry\Policies\TrailRegistryPolicy" not found`.

- [ ] **Step 3: scrivi la Policy**

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * Chi vede e usa il Catasto in Nova (oc:8700): solo Administrator ed Editor,
 * in ogni shard che accende il dominio.
 *
 * Serve una policy, non bastano gli authorizedTo*() delle Resource: senza
 * policy `authorizable()` e' falso e Nova non controlla detail, modifica e
 * download aperti per URL (`Authorizable::authorizeTo()`). Le Action con
 * `canRun()` saltano comunque l'autorizzazione della Resource
 * (`ActionModelCollection::filterForExecution()`): per questo `allows()` e'
 * pubblico, e le Action lo richiamano nel proprio `canRun()`.
 *
 * Non va usata fuori da Nova: le API del Catasto non chiamano `can()` sui
 * suoi modelli, e non devono iniziare a farlo per effetto di questa regola.
 */
class TrailRegistryPolicy
{
    public const ROLES = ['Administrator', 'Editor'];

    /**
     * L'unico punto in cui stanno i ruoli: Policy, Action e Resource degli
     * shard (forestas: le righe del registro) chiamano questo metodo.
     */
    public static function allows(?Authenticatable $user): bool
    {
        return $user !== null
            && method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(self::ROLES);
    }

    public function viewAny(Authenticatable $user): bool
    {
        return static::allows($user);
    }

    public function view(Authenticatable $user, Model $model): bool
    {
        return static::allows($user);
    }

    /**
     * Solo le istanze si creano da Nova: codici e anomalie li scrivono il
     * service e l'import.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function create(Authenticatable $user, string $modelClass = ''): bool
    {
        return static::allows($user) && is_a($modelClass, TrailApplication::class, true);
    }

    /**
     * Un'istanza si modifica solo in istruttoria (oc:8571): approvata o
     * respinta resta uno storico. Codici e anomalie sono in sola lettura.
     */
    public function update(Authenticatable $user, Model $model): bool
    {
        return static::allows($user)
            && $model instanceof TrailApplication
            && $model->status === TrailApplicationStatus::UnderReview;
    }

    public function delete(Authenticatable $user, Model $model): bool
    {
        return false;
    }

    public function restore(Authenticatable $user, Model $model): bool
    {
        return false;
    }

    public function forceDelete(Authenticatable $user, Model $model): bool
    {
        return false;
    }

    public function replicate(Authenticatable $user, Model $model): bool
    {
        return false;
    }

    public function runAction(Authenticatable $user, Model $model): bool
    {
        return static::allows($user);
    }

    public function runDestructiveAction(Authenticatable $user, Model $model): bool
    {
        return static::allows($user);
    }
}
```

> Nota per chi implementa: verifica sul `can('create', TrailApplication::class)` che il Gate passi davvero la classe come secondo argomento al metodo `create`. Se Laravel non la passa (il Gate chiama `create($user)` quando l'argomento è il solo nome di classe), sposta la distinzione per modello in tre policy sottili che estendono una base comune, una per modello, e aggiorna il test «la policy e registrata» di conseguenza.

- [ ] **Step 4: registra la Policy nel service provider**

In `src/WmPackageServiceProvider.php`, subito dopo `Gate::policy(AppModel::class, AppPolicy::class);`:

```php
        // Catasto Sentieri (oc:8700): la policy si registra sempre, anche a
        // dominio spento. Il flag si legge a runtime, e a dominio spento le
        // Resource restano nascoste dal trait HidesWhenTrailRegistryDisabled,
        // che mette il dominio in AND con la policy.
        foreach ([
            [TrailApplicationModel::class, TrailRegistryClasses::application()],
            [TrailRegistryCodeModel::class, TrailRegistryClasses::code()],
            [TrailRegistryAnomalyModel::class, TrailRegistryClasses::anomaly()],
        ] as [$base, $configured]) {
            Gate::policy($base, TrailRegistryPolicy::class);
            if ($configured !== $base) {
                Gate::policy($configured, TrailRegistryPolicy::class);
            }
        }
```

con gli import `use Wm\WmPackage\TrailRegistry\Policies\TrailRegistryPolicy;` e, se mancano, `TrailApplication as TrailApplicationModel`, `TrailRegistryCode as TrailRegistryCodeModel`, `TrailRegistryAnomaly as TrailRegistryAnomalyModel` da `Wm\WmPackage\TrailRegistry\Models`, `Wm\WmPackage\TrailRegistry\TrailRegistryClasses`.

- [ ] **Step 5: aggiungi il test sulla sottoclasse configurata** (Review Focus 4) in `TrailRegistryPolicyTest.php`:

```php
class PolicyShardCodeModel extends TrailRegistryCode {}

it('la policy vale anche per il modello configurato dallo shard', function () {
    expect(Gate::getPolicyFor(PolicyShardCodeModel::class))->toBeInstanceOf(TrailRegistryPolicy::class);
    expect(utenteConRuolo('Validator')->can('view', new PolicyShardCodeModel))->toBeFalse();
    expect(utenteConRuolo('Editor')->can('view', new PolicyShardCodeModel))->toBeTrue();
});
```

- [ ] **Step 6: lancia i test e verifica che passino**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryPolicyTest.php`
Expected: PASS.

- [ ] **Step 7: commit (istruzione per il dev)**

```bash
git add src/TrailRegistry/Policies/TrailRegistryPolicy.php src/WmPackageServiceProvider.php tests/Feature/TrailRegistry/TrailRegistryPolicyTest.php
git commit -m "feat(oc:8700): policy del Catasto per Administrator ed Editor"
```

---

### Task 2: Action, Resource e test HTTP

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-action-resource-e-test-http)

**File:**
- Modifica: `src/TrailRegistry/Nova/TrailApplication.php:84-88` (`authorizedToUpdate()`) e `:308-321` (`actions()`)
- Modifica: `tests/Feature/TrailRegistry/TrailRegistryShardResourcesTest.php:93-118`
- Test: `tests/Feature/TrailRegistry/TrailRegistryAccessHttpTest.php`

**Interfacce:**
- Consuma: `TrailRegistryPolicy::allows(?Authenticatable $user): bool` (Task 1).

**Note:** con la Policy registrata, i `parent::` del trait `HidesWhenTrailRegistryDisabled` passano dalla Policy: index, detail e create sono coperti senza toccare il trait. Restano due punti che la Policy da sola non copre:
- `TrailApplication::authorizedToUpdate()` ridefinisce il metodo e non chiama `parent::`, quindi decide il bottone «Modifica» senza la regola dei ruoli;
- le tre Action hanno `canRun()`, e Nova in quel caso non consulta la Resource né la Policy.

- [ ] **Step 1: scrivi i test HTTP che falliscono**

`tests/Feature/TrailRegistry/TrailRegistryAccessHttpTest.php`. Prendi come modello `tests/Feature/Nova/ConfigDetailAuthorizationInheritanceTest.php` (stesso `actingAs()` e stesso uso di `RolesAndPermissionsService::seedDatabase()`), e `tests/Feature/TrailRegistry/TrailApplicationCreateFormTest.php` per creare un'istanza in istruttoria valida e per l'URL dell'Action. I test devono passare da HTTP, non chiamare i metodi della Resource: chiamati direttamente, darebbero verde anche con il buco aperto.

```php
<?php

use Laravel\Nova\Nova;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode;

/**
 * La regola dei ruoli del Catasto (oc:8700) sugli endpoint veri di Nova:
 * index, detail, modifica e Action. Chiamare authorizedTo*() direttamente non
 * basta: il detail e il PUT passano da authorizeTo*(), le Action con canRun()
 * saltano la Resource.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    RolesAndPermissionsService::seedDatabase();
    config(['wm-package.features.trail_registry.enabled' => true]);
    Nova::resources([TrailApplication::class, TrailRegistryCode::class, TrailRegistryAnomaly::class]);
});

function utenteHttp(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('Administrator ed Editor aprono index e detail del registro dei codici', function (string $role) {
    $id = makeCode();

    $this->actingAs(utenteHttp($role))->getJson('/nova-api/trail-registry-codes')->assertOk();
    $this->actingAs(utenteHttp($role))->getJson("/nova-api/trail-registry-codes/{$id}")->assertOk();
})->with(['Administrator', 'Editor']);

it('Validator e Contributor ricevono 403 sull index delle tre Resource', function (string $role, string $uriKey) {
    $this->actingAs(utenteHttp($role))->getJson("/nova-api/{$uriKey}")->assertForbidden();
})->with(['Validator', 'Contributor'])
  ->with(['trail-applications', 'trail-registry-codes', 'trail-registry-anomalies']);

it('Validator e Contributor ricevono 403 sul detail di un codice aperto per URL', function (string $role) {
    $id = makeCode();

    $this->actingAs(utenteHttp($role))->getJson("/nova-api/trail-registry-codes/{$id}")->assertForbidden();
})->with(['Validator', 'Contributor']);
```

Aggiungi nello stesso file, con lo stesso impianto:
- **PUT di un'istanza** `/nova-api/trail-applications/{id}`: Validator → 403; Editor su istanza approvata → 403 (Review Focus 2); Editor su istanza in istruttoria → non 403 (usa lo stesso payload completo per tutti i casi, come in `ConfigDetailAuthorizationInheritanceTest`, così l'unica differenza è il ruolo);
- **POST dell'Action Approva** `/nova-api/trail-applications/action?action=approve-trail-application` con `resources={id}` su un'istanza in istruttoria: Validator → risposta di rifiuto (Nova risponde 403 o «Sorry! You are not authorized to perform this action.» con 403: verifica il codice esatto e asseriscilo) e l'istanza resta `UnderReview` (Review Focus 1);
- **form di creazione** `/nova-api/trail-applications/creation-fields`: Editor → 200, Validator → 403 (Review Focus 3);
- **dominio spento** (`config([...enabled => false])`): Administrator → 403 sull'index dei codici.

- [ ] **Step 2: lancia i test e verifica che falliscano dove serve**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryAccessHttpTest.php`
Expected: index e detail già corretti grazie al Task 1; FAIL sul POST dell'Action Approva da Validator (l'Action viene eseguita).

- [ ] **Step 3: applica la regola nelle Action e nell'override di `authorizedToUpdate()`**

In `src/TrailRegistry/Nova/TrailApplication.php`:

```php
    public function authorizedToUpdate(Request $request): bool
    {
        return static::trailRegistryEnabled()
            && TrailRegistryPolicy::allows($request->user())
            && $this->resource->status === TrailApplicationStatus::UnderReview;
    }
```

e in `actions()`:

```php
        // canRun() fa saltare a Nova l'autorizzazione della Resource
        // (ActionModelCollection::filterForExecution()): la regola dei ruoli
        // del Catasto (oc:8700) va ripetuta qui, o un POST all'Action passa.
        $canManage = fn ($request) => TrailRegistryPolicy::allows($request->user());
        $onlyUnderReview = fn ($request, $application) => $canManage($request)
            && $application->status === TrailApplicationStatus::UnderReview;

        return [
            (new ApproveTrailApplication)->canSee($canManage)->canRun($onlyUnderReview),
            (new RejectTrailApplication)->canSee($canManage)->canRun($onlyUnderReview),
            (new ReplaceTrailCodeNumber)->canSee($canManage)->canRun(
                fn ($request, $application) => $canManage($request)
                    && $application->activeCode?->status === TrailCodeStatus::Reserved,
            ),
        ];
```

Aggiorna il docblock di `authorizedToUpdate()`: oltre al dominio va rifatta la regola dei ruoli. Import: `use Wm\WmPackage\TrailRegistry\Policies\TrailRegistryPolicy;`.

- [ ] **Step 4: adegua `TrailRegistryShardResourcesTest.php`**

I test «a dominio acceso le autorizzazioni restano quelle della Resource» (righe 108-118) usano `Request::create('/')` senza utente, e con la Policy otterrebbero `false`. Dai alla richiesta un Editor:

```php
    RolesAndPermissionsService::seedDatabase();
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $request = Request::create('/');
    $request->setUserResolver(fn () => $editor);
    $novaRequest = NovaRequest::create('/');
    $novaRequest->setUserResolver(fn () => $editor);
    $this->actingAs($editor);
```

e usa `$novaRequest` per `authorizedToRunAction()`. Aggiungi un caso gemello con un Validator che si aspetta `false` sugli stessi metodi. I test a dominio spento restano invariati: devono continuare a dare `false`.

- [ ] **Step 5: lancia i test del Catasto**

Run: `vendor/bin/pest tests/Feature/TrailRegistry`
Expected: PASS. Se fallisce un test esistente che chiama Nova senza utente (per esempio `TrailApplicationCreateFormTest`, `TrailRegistryAnomalyTypesTest`), adeguane l'utente a un Editor come nello Step 4, senza cambiare cosa il test verifica.

- [ ] **Step 6: commit (istruzione per il dev)**

```bash
git add src/TrailRegistry/Nova/TrailApplication.php tests/Feature/TrailRegistry/
git commit -m "feat(oc:8700): regola dei ruoli su Action e modifica delle istanze del Catasto"
```

---

### Task 3: sezione di menu senza voci visibili

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-sezione-di-menu-senza-voci-visibili)

**File:**
- Modifica: `src/WmPackageServiceProvider.php:307-349` (`injectMenuSectionItems()`)
- Test: `tests/Feature/TrailRegistry/MenuSectionInjectionTest.php`

**Note:** Nova non toglie dal menu principale una sezione senza voci (`Menu::jsonSerialize()` scarta solo gli elementi con `authorizedToSee()` falso; `MenuCollection::withoutEmptyItems()` toglie solo gruppi e liste interni). Una voce `MenuItem::resource()` è visibile se `availableForNavigation() && authorizedToViewAny()` (`Menu/MenuItem.php:119`), che ora passa dalla Policy. Il comportamento vale per ogni sezione ricostruita, compresa «Tools»: una sezione senza nessuna voce visibile non serve a nessuno.

- [ ] **Step 1: scrivi i test che falliscono** in `MenuSectionInjectionTest.php`:

```php
it('conserva il canSee che lo shard ha messo sulla sezione', function () {
    $menu = [
        MenuSection::make('Catasto', [])->canSee(fn () => false),
    ];

    $result = inject($menu, 'Catasto', [MenuItem::link('Codici', '/codici')]);

    expect($result[0]->authorizedToSee(request()))->toBeFalse();
});

it('nasconde la sezione se nessuna delle sue voci e visibile', function () {
    $menu = [MenuSection::make('Catasto', [MenuItem::link('Doc', '/docs')->canSee(fn () => false)])];

    $result = inject($menu, 'Catasto', [MenuItem::link('Codici', '/codici')->canSee(fn () => false)]);

    expect($result[0]->authorizedToSee(request()))->toBeFalse();
});

it('mostra la sezione se almeno una voce e visibile', function () {
    $menu = [MenuSection::make('Catasto', [MenuItem::link('Doc', '/docs')->canSee(fn () => false)])];

    $result = inject($menu, 'Catasto', [MenuItem::link('Codici', '/codici')]);

    expect($result[0]->authorizedToSee(request()))->toBeTrue();
});

it('la sezione creata in fondo segue la stessa regola', function () {
    $result = inject([], 'Catasto', [MenuItem::link('Codici', '/codici')->canSee(fn () => false)]);

    expect($result[0]->authorizedToSee(request()))->toBeFalse();
});
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/MenuSectionInjectionTest.php`
Expected: FAIL sui quattro test nuovi (la sezione ricostruita non ha `canSee`).

- [ ] **Step 3: implementa** in `injectMenuSectionItems()`. Dopo aver calcolato `$merged = array_merge($items, $this->menuSectionItems($sectionOrGroup))`:

```php
            $rebuilt = MenuSection::make($sectionOrGroup->name, $merged)
                ->icon($sectionOrGroup->icon ?? $icon)
                ->canSee($this->visibleWhenAnyItemIs($merged, $sectionOrGroup->seeCallback));
```

e nel ramo che crea la sezione in fondo:

```php
        $menuItems[] = MenuSection::make($sectionName, $items)
            ->icon($icon)
            ->collapsedByDefault()
            ->canSee($this->visibleWhenAnyItemIs($items, null));
```

con il metodo nuovo:

```php
    /**
     * Una sezione si vede solo se almeno una delle sue voci e' visibile
     * (oc:8700): Nova non nasconde da se' una sezione di primo livello vuota,
     * e un utente a cui la policy del Catasto nega tutte le voci vedrebbe
     * «Catasto» senza niente dentro. Il `canSee` che il consumer aveva messo
     * sulla sezione resta in AND: la ricostruzione non lo perde piu'.
     *
     * @param  array<int, mixed>  $items
     */
    protected function visibleWhenAnyItemIs(array $items, ?callable $sectionCallback): \Closure
    {
        return function (Request $request) use ($items, $sectionCallback): bool {
            if ($sectionCallback !== null && ! $sectionCallback($request)) {
                return false;
            }

            foreach ($items as $item) {
                if (! method_exists($item, 'authorizedToSee') || $item->authorizedToSee($request)) {
                    return true;
                }
            }

            return false;
        };
    }
```

Aggiorna il docblock di `injectMenuSectionItems()`: ora riporta anche `canSee`.

- [ ] **Step 4: lancia i test del menu**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/MenuSectionInjectionTest.php tests/Feature/TrailRegistry/MainMenuInjectionTest.php`
Expected: PASS.

- [ ] **Step 5: commit (istruzione per il dev)**

```bash
git add src/WmPackageServiceProvider.php tests/Feature/TrailRegistry/MenuSectionInjectionTest.php
git commit -m "feat(oc:8700): la sezione di menu ricostruita conserva canSee e si nasconde se vuota"
```

---

### Task 4: documentazione e verifica finale

**File:**
- Modifica: `docs/resources/TrailRegistry.md`
- Modifica: `src/TrailRegistry/Nova/HidesWhenTrailRegistryDisabled.php` (solo docblock)

- [ ] **Step 1: documenta la regola** in `docs/resources/TrailRegistry.md`, nella parte sull'interfaccia Nova: chi vede il Catasto (Administrator, Editor), dove sta la regola (`TrailRegistryPolicy::allows()`), che uno shard la richiama per le proprie Resource del Catasto.

- [ ] **Step 2: aggiungi le trappole** nella sezione «Trappole», una riga ciascuna con `(oc:8700)`:
  - una restrizione messa solo negli `authorizedTo*()` di una Resource senza policy non protegge detail, modifica e download aperti per URL: serve una policy registrata;
  - un'Action con `canRun()` salta l'autorizzazione della Resource e della policy: la regola dei ruoli va ripetuta nel `canRun()`;
  - chi ridefinisce un `authorizedTo*()` in una Resource del Catasto deve rifare sia il controllo del dominio sia `TrailRegistryPolicy::allows()`.

- [ ] **Step 3: aggiorna il docblock del trait**: i `parent::` passano ora dalla Policy del Catasto; il trait aggiunge solo il controllo del dominio.

- [ ] **Step 4: suite del package e PHPStan**

Run: `vendor/bin/pest` (da `wm-package/`, dopo la verifica dell'isolamento nei vincoli globali) e `vendor/bin/phpstan analyse`.
Expected: PASS, nessun errore nuovo.

- [ ] **Step 5: commit (istruzione per il dev)**

```bash
git add docs/resources/TrailRegistry.md src/TrailRegistry/Nova/HidesWhenTrailRegistryDisabled.php
git commit -m "docs(oc:8700): regola dei ruoli e trappole di autorizzazione del Catasto"
```

> Ticket: oc:8006

# Plan — Refactor SuperAdminService into RolesAndPermissionsService

## Branch

```bash
# wm-package
cd wm-package && git checkout -b feature/oc-8006-refactor-superadminservice-into-accesscontrolservice

# camminiditalia (consumer esterno)
cd /path/to/camminiditalia && git checkout -b feature/oc-8006-refactor-superadminservice-into-accesscontrolservice
```

---

## Step 1 — Aggiungi i metodi a `Services/RolesAndPermissionsService.php`

Aggiungere i tre metodi statici prima di `seedDatabase()`, con i relativi import:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
```

```php
public static function allows(?Request $request): bool
{
    $user = $request !== null ? $request->user() : null;

    return self::allowsUser($user);
}

public static function allowsUser(?Authenticatable $user): bool
{
    if ($user === null) {
        return false;
    }

    $email = $user->email ?? null;

    return self::allowsEmail(is_string($email) ? $email : null);
}

public static function allowsEmail(?string $email): bool
{
    if ($email === null || $email === '') {
        return false;
    }

    /** @var array<int, string> $allowed */
    $allowed = config('wm-package.super_admin_emails', ['team@webmapp.it']);

    return in_array($email, $allowed, true);
}
```

**Commit:** `refactor(oc:8006): add super-admin access check methods to RolesAndPermissionsService`

---

## Step 2 — Aggiorna i consumer in wm-package

Per ciascuno dei 4 file: sostituisci il `use` statement e il riferimento alla classe.

### 2a — `src/Nova/App.php`

```diff
-use Wm\WmPackage\Support\SuperAdminService;
+use Wm\WmPackage\Services\RolesAndPermissionsService;
```
```diff
-$superAdminOnly = fn (NovaRequest $req) => SuperAdminService::allows($req);
+$superAdminOnly = fn (NovaRequest $req) => RolesAndPermissionsService::allows($req);
```

### 2b — `src/Nova/Actions/GenerateAppIconsAction.php`

```diff
-use Wm\WmPackage\Support\SuperAdminService;
+use Wm\WmPackage\Services\RolesAndPermissionsService;
```
```diff
-return SuperAdminService::allows($request);
+return RolesAndPermissionsService::allows($request);
```

### 2c — `src/Nova/Actions/BuildAppPoisGeojsonAction.php`

```diff
-use Wm\WmPackage\Support\SuperAdminService;
+use Wm\WmPackage\Services\RolesAndPermissionsService;
```
```diff
-return SuperAdminService::allows($request);
+return RolesAndPermissionsService::allows($request);
```

### 2d — `src/Policies/AppPolicy.php`

```diff
-use Wm\WmPackage\Support\SuperAdminService;
+use Wm\WmPackage\Services\RolesAndPermissionsService;
```
```diff
-return SuperAdminService::allowsUser($user);
+return RolesAndPermissionsService::allowsUser($user);
```

**Commit:** `refactor(oc:8006): update consumers to use RolesAndPermissionsService`

---

## Step 3 — Aggiorna il commento `@see` in config

**File:** `wm-package/config/wm-package.php`

```diff
-    | Email allowlist super-admin {@see \Wm\WmPackage\Support\SuperAdminService} (comma-separated).
+    | Email allowlist super-admin {@see \Wm\WmPackage\Services\RolesAndPermissionsService} (comma-separated).
```

**Commit:** incluso nel commit Step 2.

---

## Step 4 — Elimina `Support/SuperAdminService.php`

```bash
rm src/Support/SuperAdminService.php
```

Verificare che `Support/` non sia vuota dopo la rimozione; se lo è, rimuovere anche la cartella.

**Commit:** `refactor(oc:8006): remove SuperAdminService`

---

## Step 5 — Aggiungi test Pest

**File:** `tests/Unit/Services/RolesAndPermissionsServiceTest.php`

11 casi che coprono:
- `allowsEmail`: null, stringa vuota, email non in lista, email in lista, config key assente (fallback default)
- `allowsUser`: null, email non stringa (edge case `Authenticatable`), email ammessa, email non ammessa
- `allows`: request null, request senza utente autenticato

**Commit:** `test(oc:8006): add RolesAndPermissionsServiceTest`

---

## Step 6 — Aggiorna `camminiditalia`

**File:** `camminiditalia/app/Providers/NovaServiceProvider.php`

```diff
-use Wm\WmPackage\Support\SuperAdminService;
+use Wm\WmPackage\Services\RolesAndPermissionsService;
```
```diff
-->canSee(fn (Request $request) => SuperAdminService::allows($request)),
+->canSee(fn (Request $request) => RolesAndPermissionsService::allows($request)),
```

Aggiornare il puntatore del submodule wm-package in `camminiditalia` dopo il merge della PR wm-package.

**Commit in camminiditalia:** `refactor(oc:8006): update to RolesAndPermissionsService`

---

## Step 7 — Verifica finale

```bash
# In wm-package: nessun riferimento residuo a SuperAdminService
grep -rn "SuperAdminService" src/ tests/ config/ --include="*.php"
# atteso: nessun output

# Esegui i test
vendor/bin/pest tests/Unit/Services/RolesAndPermissionsServiceTest.php
```

---

## Step 8 — PR

- **wm-package:** PR da `feature/oc-8006-refactor-superadminservice-into-accesscontrolservice` → `develop`
- **camminiditalia:** PR da `feature/oc-8006-refactor-superadminservice-into-accesscontrolservice` → `develop`

Mergare prima wm-package, poi aggiornare il submodule pointer in camminiditalia e mergiare.

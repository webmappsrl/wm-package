> Ticket: oc:8042

# Plan — Utenti importati: ruolo assegnato in modo errato

## Repo: wm-package

### Step 1 — Aggiungere il ruolo Editor a `RolesAndPermissionsService::seedDatabase()`

File: `src/Services/RolesAndPermissionsService.php`

Aggiungere `Role::firstOrCreate(['name' => 'Editor']);` dopo la riga di `Administrator`:

```php
Role::firstOrCreate(['name' => 'Administrator']);
Role::firstOrCreate(['name' => 'Editor']);       // aggiunto
Role::firstOrCreate(['name' => 'Validator']);
Role::firstOrCreate(['name' => 'Guest']);
```

---

### Step 2 — Aggiungere `assignEditorRole()` e aggiornare `checkUserExistence()` in `GeohubImportService`

File: `src/Services/Import/GeohubImportService.php`

**2a.** Aggiungere il metodo `assignEditorRole()`:

```php
protected function assignEditorRole(User $user): void
{
    if ($user->roles->isNotEmpty()) {
        return;
    }

    $role = Role::where('name', 'Editor')->first();
    if (! $role) {
        RolesAndPermissionsService::seedDatabase();
        $role = Role::where('name', 'Editor')->first();
    }

    $user->assignRole($role);
}
```

**2b.** In `checkUserExistence()`, sostituire la chiamata a `assignAdministratorRole()` con `assignEditorRole()`.

> `assignAdministratorRole()` rimane nel codice per retrocompatibilità, non viene rimosso.

---

### Step 3 — Creare la migration stub

File: `database/migrations/zz_2026_06_26_000001_add_editor_role.php.stub`

Usa `DB::table()->insertOrIgnore()` invece di `Role::firstOrCreate()` per evitare side-effect cache Spatie in transazione PostgreSQL.

---

### Step 4 — Commit su wm-package

```
feat(oc:8042): add Editor role and assign it during GeoHub import
```

---

## Repo: maphub

### Step 5 — Pubblicare la migration e migrare

```bash
php artisan vendor:publish --tag=wm-package-migrations
php artisan migrate
php artisan permission:cache-reset
```

---

### Step 6 — Commit su maphub

```
feat(oc:8042): publish Editor role migration from wm-package
```

---

### Step 7 — Verifica

```bash
php artisan tinker --execute="echo \Spatie\Permission\Models\Role::where('name','Editor')->exists() ? 'OK' : 'MISSING';"
```

---

## Note deploy

- Dopo la migration, eseguire `php artisan permission:cache-reset` se la Spatie permission cache è abilitata nell'ambiente target.

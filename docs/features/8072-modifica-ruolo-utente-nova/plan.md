> Ticket: oc:8072

# Plan — Modifica ruolo utente Nova (wm-package)

## Task 1 — Aggiornare `AbstractUserResource` ✅

**File:** `src/Nova/AbstractUserResource.php`

- Sostituito guard `readonly` su `RoleBooleanGroup` con `RolesAndPermissionsService::allowsUser(auth()->user())`
- Aggiunta `fillUsing()` server-side su `RoleBooleanGroup`: blocca la persistenza se `allowsUser()` restituisce `false`; logica anti-self-demotion che impedisce di rimuovere il ruolo Administrator dall'utente corrente
- Sostituito guard `readonly` su `PermissionBooleanGroup` con `RolesAndPermissionsService::allowsUser(auth()->user())`
- Aggiunta `fillUsing()` server-side su `PermissionBooleanGroup`: blocca la persistenza se `allowsUser()` restituisce `false`
- `PermissionBooleanGroup` usa `dependsOn('roles', ...)` per mostrare solo i permessi rilevanti ai ruoli selezionati

**Commit:** `feat(oc:8072): use RolesAndPermissionsService as guard for role/permission fields in AbstractUserResource`

---

## Task 2 — Aggiungere test ✅

**File:** `tests/Feature/Nova/AbstractUserResourceRoleGuardTest.php` (nuovo)

Test Pest che copre:
- Non-super-admin non può modificare ruoli (`fillUsing` è no-op, ruoli originali invariati)
- Super-admin può assegnare un nuovo ruolo tramite `fillUsing`
- Super-admin non può rimuovere il proprio ruolo Administrator (anti-self-demotion)
- Super-admin può modificare il ruolo Administrator di un altro utente

**Commit:** `test(oc:8072): add feature tests for AbstractUserResource role guard`

---

## Task 3 — Fix bug bloccanti da code review

**File:** `src/Nova/AbstractUserResource.php`

### 3a — Return early su JSON non-array in `fillUsing` (Bug #1)
Aggiunto check `is_array($decoded)` dopo `json_decode` in entrambi i `fillUsing`. Se il payload è JSON malformato o non-array, la funzione esce senza toccare ruoli/permessi.

### 3b — Anti-self-demotion condizionata a `$model->hasRole('Administrator')` (Bug #2)
La protezione ora scatta solo se il modello aveva già il ruolo Administrator nel DB. Prima assegnava Administrator a chiunque si modificasse da solo, anche senza averlo mai avuto.

### 3c — `PermissionBooleanGroup.fillUsing` protegge i permessi in self-edit (Bug #3)
Aggiunta protezione simmetrica a quella dei ruoli: se `$request->user()->id === $model->id`, i permessi diretti esistenti vengono preservati tramite merge.

### 3d — Sorgente auth allineata a `$request->user()` in `readonly()` e `canSee()` (Cleanup)
`readonly()` su entrambi i campi e `canSee()` sulle `HasMany` usano ora `$request->user()` invece di `auth()->user()` — coerente con `fillUsing` e corretto in contesti headless/test.

**Commit:** `fix(oc:8072): fix json guard, anti-self-demotion, permission protection and align auth sources`

---

## Task 4 — Aggiornare test

**File:** `tests/Feature/Nova/AbstractUserResourceRoleGuardTest.php`

- Helper `makeUserResource()` e `makeRoleField()` convertiti da funzioni globali a closure file-scoped (previene `Cannot redeclare` in test paralleli)
- Aggiunto `makePermissionField` closure
- Aggiunti test per i bug fix: JSON invalido (roles e permissions), anti-self-demotion senza ruolo preesistente, guard `PermissionBooleanGroup` per non-super-admin e super-admin, protezione self-edit permessi
- Spostati da maphub i test `super-admin can assign a new role` e `super-admin can modify another users administrator role` (testano logica package, non maphub)
- Corretto commento errato: la protezione è nel `return` dentro `fillUsing()`, non in `readonly()`

**Commit:** `test(oc:8072): fix global helpers, add permission guard and bug fix tests`

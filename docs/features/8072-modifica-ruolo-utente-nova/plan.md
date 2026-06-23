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

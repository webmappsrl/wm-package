> Ticket: oc:8072

# Aggiungere in Nova la possibilità di modificare il ruolo di un utente — wm-package

## Cosa cambia

`AbstractUserResource` aggiunge guard e protezione server-side sui campi `RoleBooleanGroup` e `PermissionBooleanGroup`. Il guard usa `RolesAndPermissionsService::allowsUser()` invece di controllare direttamente i ruoli Spatie. La modifica è trasversale a tutti i progetti che usano il package.

## Perché

La logica precedente accoppiava il guard ai ruoli Spatie. `RolesAndPermissionsService` è già il punto centralizzato per la decisione di accesso super-admin — il guard deve solo consumarlo.

## Requisiti

- [x] `RoleBooleanGroup` e `PermissionBooleanGroup` usano `RolesAndPermissionsService::allowsUser(auth()->user())` come guard `readonly`
- [x] Protezione server-side via `fillUsing()`: se `allowsUser()` restituisce `false`, il payload viene ignorato e non persiste nel DB
- [x] Anti-self-demotion: `fillUsing` di `RoleBooleanGroup` impedisce di rimuovere il ruolo Administrator dall'utente corrente
- [x] Nessuna email hardcodata in `AbstractUserResource`
- [x] `PermissionBooleanGroup` contestuale ai ruoli selezionati tramite `dependsOn`

## Rischi

- **Breaking change per shard che dipendevano dal guard Administrator**: altri progetti che si aspettavano che gli Administrator potessero modificare i ruoli vedranno i campi diventare readonly. Comportamento intenzionale — la modifica è limitata ai super-admin configurati via `WM_SUPER_ADMIN_EMAILS`.

## Out of scope

- Modifica di `RolesAndPermissionsService` (solo consumo)
- Visibilità dei campi nell'index (gestita nei singoli progetti tramite override)

## Moduli toccati

| File | Tipo modifica |
|------|---------------|
| `src/Nova/AbstractUserResource.php` | Guard `readonly` + `fillUsing()` + anti-self-demotion |
| `tests/Feature/Nova/AbstractUserResourceRoleGuardTest.php` | Nuovo test |

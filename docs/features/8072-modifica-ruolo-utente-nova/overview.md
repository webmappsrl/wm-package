> Ticket: oc:8072

# Aggiungere in Nova la possibilità di modificare il ruolo di un utente — wm-package

## Cosa cambia

`AbstractUserResource` aggiunge guard e protezione server-side sui campi `RoleBooleanGroup` e `PermissionBooleanGroup`. Il guard usa `RolesAndPermissionsService::allowsUser()` invece di controllare direttamente i ruoli Spatie. La modifica è trasversale a tutti i progetti che usano il package.

## Perché

La logica precedente accoppiava il guard ai ruoli Spatie. `RolesAndPermissionsService` è già il punto centralizzato per la decisione di accesso super-admin — il guard deve solo consumarlo.

## Requisiti

- [x] `RoleBooleanGroup` e `PermissionBooleanGroup` usano `RolesAndPermissionsService::allowsUser($request->user())` come guard `readonly` (sorgente auth allineata a `fillUsing`)
- [x] Protezione server-side via `fillUsing()`: se `allowsUser()` restituisce `false`, il payload viene ignorato e non persiste nel DB
- [x] `fillUsing` di entrambi i campi esegue return early se `json_decode` restituisce un non-array (JSON malformato non azzera ruoli/permessi)
- [x] Anti-self-demotion `RoleBooleanGroup`: impedisce di rimuovere il ruolo Administrator dall'utente corrente **solo se lo aveva già nel DB**
- [x] Anti-self-demotion `PermissionBooleanGroup`: un super-admin che modifica se stesso non può rimuovere i propri permessi diretti esistenti
- [x] `canSee()` sulle `HasMany` usa `$request->user()` per coerenza con il resto della logica auth
- [x] Nessuna email hardcodata in `AbstractUserResource`
- [x] `PermissionBooleanGroup` contestuale ai ruoli selezionati tramite `dependsOn`

## Rischi

- **Breaking change per shard che dipendevano dal guard Administrator**: altri progetti che si aspettavano che gli Administrator potessero modificare i ruoli vedranno i campi diventare readonly. Comportamento intenzionale — la modifica è limitata ai super-admin configurati via `WM_SUPER_ADMIN_EMAILS`.
- **Anti-self-demotion permessi conservativa**: un super-admin non può rimuovere i propri permessi diretti. Se serve una revoca esplicita dei propri permessi, va fatta via CLI o tinker.

## Out of scope

- Modifica di `RolesAndPermissionsService` (solo consumo)
- Visibilità dei campi nell'index (gestita nei singoli progetti tramite override)

## Moduli toccati

| File | Tipo modifica |
|------|---------------|
| `src/Nova/AbstractUserResource.php` | Guard `readonly` + `fillUsing()` + anti-self-demotion |
| `tests/Feature/Nova/AbstractUserResourceRoleGuardTest.php` | Nuovo test |

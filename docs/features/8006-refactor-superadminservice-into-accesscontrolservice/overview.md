> Ticket: oc:8006

# Refactor SuperAdminService into RolesAndPermissionsService

## Cosa cambia

`Support/SuperAdminService.php` viene eliminato. I tre metodi statici (`allows`, `allowsUser`, `allowsEmail`) vengono spostati verbatim in `Services/RolesAndPermissionsService.php`, che già gestisce ruoli e permessi. I consumer interni e il consumer esterno (`camminiditalia`) aggiornano i propri `use` statement. Vengono aggiunti test Pest.

## Perché

`Support/` non è la collocazione semantica corretta per logica di accesso runtime. `RolesAndPermissionsService` in `Services/` è il punto centrale per tutto ciò che riguarda autorizzazione e accesso nel package, ed è coerente con l'architettura esistente.

## Requisiti

- [ ] Aggiungere `allows()`, `allowsUser()`, `allowsEmail()` a `Services/RolesAndPermissionsService.php` — logica invariata rispetto a `SuperAdminService`
- [ ] Eliminare `Support/SuperAdminService.php` (nessun alias deprecato)
- [ ] Aggiornare il `use` statement in `Nova/App.php`
- [ ] Aggiornare il `use` statement in `Nova/Actions/GenerateAppIconsAction.php`
- [ ] Aggiornare il `use` statement in `Nova/Actions/BuildAppPoisGeojsonAction.php`
- [ ] Aggiornare il `use` statement in `Policies/AppPolicy.php`
- [ ] Aggiornare il commento `@see` in `config/wm-package.php`
- [ ] Aggiungere test Pest in `tests/Unit/Services/RolesAndPermissionsServiceTest.php` coprendo: `allowsEmail` con null, stringa vuota, email non in lista, email in lista, config assente; `allowsUser` con null, email non stringa, email valida/non valida; `allows` con request senza utente

## Rischi

- **Consumer esterni:** altri progetti che usano wm-package potrebbero referenziare `Wm\WmPackage\Support\SuperAdminService` direttamente. Decisione presa: eliminazione diretta senza alias deprecato. `camminiditalia` aggiornato contestualmente come parte di questo ticket.

## Out of scope

- Rinomina della chiave di config `super_admin_emails` (resta invariata)
- Conversione a metodi di istanza / dependency injection
- Aggiunta di nuova logica di accesso oltre a quella già presente in `SuperAdminService`

## Moduli toccati

**`wm-package/`:**

| File | Operazione |
|------|------------|
| `src/Services/RolesAndPermissionsService.php` | Modificato — aggiunti 3 metodi statici |
| `src/Support/SuperAdminService.php` | Eliminato |
| `src/Nova/App.php` | Aggiornato `use` |
| `src/Nova/Actions/GenerateAppIconsAction.php` | Aggiornato `use` |
| `src/Nova/Actions/BuildAppPoisGeojsonAction.php` | Aggiornato `use` |
| `src/Policies/AppPolicy.php` | Aggiornato `use` |
| `config/wm-package.php` | Aggiornato `@see` |
| `tests/Unit/Services/RolesAndPermissionsServiceTest.php` | Nuovo |

**`camminiditalia/` (consumer esterno trovato durante la Challenge):**

| File | Operazione |
|------|------------|
| `app/Providers/NovaServiceProvider.php` | Aggiornato `use` |

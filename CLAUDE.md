# wm-package — CLAUDE.md

## Feature disponibili

| Feature | Ticket | Moduli toccati | Note |
|---|---|---|---|
| Refactor SuperAdminService | oc:8006 | `src/Services/RolesAndPermissionsService.php`, `src/Support/SuperAdminService.php` (rimosso), `src/Nova/App.php`, `src/Nova/Actions/GenerateAppIconsAction.php`, `src/Nova/Actions/BuildAppPoisGeojsonAction.php`, `src/Policies/AppPolicy.php` | Sposta i check super-admin email-based in RolesAndPermissionsService; rimuove SuperAdminService |

## Decisioni architetturali

### Refactor SuperAdminService (oc:8006)

- I metodi `allows()`, `allowsUser()`, `allowsEmail()` vivono in `RolesAndPermissionsService` — punto unico per logica di autorizzazione nel package
- `SuperAdminService` è stata rimossa senza alias deprecato: breaking change da comunicare nel changelog prima di ogni rilascio
- I metodi sono statici (non DI) per coerenza con il pattern preesistente; la logica legge solo `config('wm-package.super_admin_emails')` senza stato interno

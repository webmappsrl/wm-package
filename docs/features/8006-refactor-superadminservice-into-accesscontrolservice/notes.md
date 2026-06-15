> Ticket: oc:8006

# Notes — Refactor SuperAdminService into RolesAndPermissionsService

## Deviazioni dal piano

- **Destinazione finale cambiata a metà implementazione:** il piano originale prevedeva la creazione di un nuovo `Services/AccessControlService.php`. Durante l'implementazione l'utente ha deciso di spostare i metodi direttamente in `RolesAndPermissionsService.php`. Tutti i file già modificati sono stati aggiornati di conseguenza senza ulteriori problemi.

## Bug trovati

Nessuno.

## Decisioni

- **`RolesAndPermissionsService` scelto come destinazione finale** anziché un nuovo `AccessControlService`: l'utente ha preferito consolidare la logica nel service esistente piuttosto che introdurre una nuova classe, nonostante la distinzione concettuale tra seeding (one-time) e check runtime (per-request). Motivazione: semplicità e meno file da gestire.
- **Eliminazione diretta di `SuperAdminService`** senza alias deprecato: il consumer esterno `camminiditalia` è stato aggiornato contestualmente nello stesso ticket.
- **`SuperAdminService` era dichiarata `final`** nella versione originale: il vincolo non è stato replicato in `RolesAndPermissionsService` (classe non final) per coerenza con il pattern del package.

## Follow-up

- Valutare in futuro se separare i check di accesso runtime in un service dedicato qualora `RolesAndPermissionsService` cresca ulteriormente.
- Comunicare il breaking change (`SuperAdminService` rimossa) nel changelog di wm-package prima del prossimo rilascio.

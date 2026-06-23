> Ticket: oc:8072

# Notes — Modifica ruolo utente Nova (wm-package)

## Deviazioni dal piano

- **Nome file test**: il piano prevedeva `AbstractUserResourceTest.php`; il file effettivo è `AbstractUserResourceRoleGuardTest.php` per rendere esplicito lo scopo.

## Decisioni

- **`Editor` → `Validator` nei test**: il ruolo `Editor` non è creato da `seedDatabase()`. Usare `Validator` rende i test autocontenuti senza seed aggiuntivi.
- **`$editor` → `$nonSuperAdmin`**: la variabile rappresentava un Administrator generico, non un Editor. Rinominata per chiarezza.
- **`PermissionBooleanGroup` incluso**: ruolo e permessi sono logicamente inseparabili in Spatie. Il `dependsOn` mostra solo i permessi rilevanti per i ruoli selezionati (es. `validate %` per Validator, `manage roles and permissions` per Administrator).

## Follow-up

- **Override `hideFromIndex()` in ogni shard**: i campi `RoleBooleanGroup` e `PermissionBooleanGroup` ereditati da `AbstractUserResource` appaiono come colonne nell'index Nova. Ogni progetto che estende `AbstractUserResource` deve fare l'override di `fields()` in `User.php` per aggiungere `hideFromIndex()` su entrambi i campi (vedi implementazione in maphub `app/Nova/User.php`). Se non fatto, le colonne Ruoli e Permessi saranno visibili nella lista utenti.

> Ticket: oc:8072

# Notes — Modifica ruolo utente Nova (wm-package)

## Deviazioni dal piano

- **Nome file test**: il piano prevedeva `AbstractUserResourceTest.php`; il file effettivo è `AbstractUserResourceRoleGuardTest.php` per rendere esplicito lo scopo.

## Decisioni

- **`Editor` → `Validator` nei test**: il ruolo `Editor` non è creato da `seedDatabase()`. Usare `Validator` rende i test autocontenuti senza seed aggiuntivi.
- **`$editor` → `$nonSuperAdmin`**: la variabile rappresentava un Administrator generico, non un Editor. Rinominata per chiarezza.
- **`PermissionBooleanGroup` incluso**: ruolo e permessi sono logicamente inseparabili in Spatie. Il `dependsOn` mostra solo i permessi rilevanti per i ruoli selezionati (es. `validate %` per Validator, `manage roles and permissions` per Administrator).

## Bug trovati in code review

- **`json_decode` null → `syncRoles([])`**: payload JSON malformato causava azzeramento silenzioso di tutti i ruoli/permessi. Fix: return early se `is_array($decoded)` è `false`.
- **Anti-self-demotion assegnava Administrator a chi non lo aveva**: la condizione `$request->user()->id === $model->id` senza check su `$model->hasRole('Administrator')` assegnava il ruolo Administrator a qualsiasi super-admin che si modificava, anche senza averlo nel DB. Fix: aggiunta condizione `&& $model->hasRole('Administrator')`.
- **`PermissionBooleanGroup.fillUsing` senza protezione self-edit**: un super-admin poteva rimuovere i propri permessi diretti (es. `manage roles and permissions`) senza alcun blocco. Fix: se `$request->user()->id === $model->id`, i permessi esistenti vengono preservati tramite merge.
- **Sorgente auth inconsistente**: `readonly()` e `canSee()` usavano `auth()->user()`, mentre `fillUsing()` usava `$request->user()`. Fix: allineati tutti a `$request->user()`.
- **Helper test nel namespace globale**: `makeUserResource()` e `makeRoleField()` dichiarate come funzioni PHP globali — rischio `Cannot redeclare` in ambienti paralleli. Fix: convertite in closure file-scoped catturate con `use`.

## Follow-up

- **Override `hideFromIndex()` in ogni shard**: i campi `RoleBooleanGroup` e `PermissionBooleanGroup` ereditati da `AbstractUserResource` appaiono come colonne nell'index Nova. Ogni progetto che estende `AbstractUserResource` deve fare l'override di `fields()` in `User.php` per aggiungere `hideFromIndex()` su entrambi i campi (vedi implementazione in maphub `app/Nova/User.php`). Se non fatto, le colonne Ruoli e Permessi saranno visibili nella lista utenti.
- **`dependsOn` hardcoda Administrator e Validator**: ruoli custom non vedono i loro permessi nel `PermissionBooleanGroup`. Tech debt noto, fuori scope — se il progetto introduce ruoli custom con permessi, il `dependsOn` va reso dinamico.

> Ticket: oc:8042

# Notes — Utenti importati: ruolo assegnato in modo errato (wm-package)

## Deviazioni dal piano

- **`assignEditorRole`**: implementato con pattern `Role::where()` + `seedDatabase()` fallback (come `assignAdministratorRole`), non con `Role::firstOrCreate()` diretto come nel plan originale — coerente con l'overview wm-package.

## Decisioni

- **`assignEditorRole` condizionale**: `$user->roles->isNotEmpty()` → return early; non sovrascrive ruoli assegnati manualmente.
- **Migration con `insertOrIgnore`**: evita eventi Eloquent Spatie e problemi cache in transazione PostgreSQL.
- **`assignAdministratorRole()` conservato**: non rimosso per retrocompatibilità.

## Follow-up

- I test di oc:8072 che usavano `Validator` al posto di `Editor` possono essere aggiornati ora che `Editor` è nel seed (out of scope per questo ticket).

> Ticket: oc:8042

# Notes — Utenti importati: ruolo assegnato in modo errato (wm-package)

## Deviazioni dal piano

- **Migration: `Role::firstOrCreate` → `DB::table()->insertOrIgnore()`**: `Role::firstOrCreate()` nella migration causava `SQLSTATE[25P02]` — Spatie tenta di cancellare la cache dentro la transazione PostgreSQL. Sostituito con insert raw per bypassare i model event.
- **`assignEditorRole`**: implementato con `Role::where()` + fallback `seedDatabase()` (coerente con `assignAdministratorRole`), non `Role::firstOrCreate()` diretto come nel plan.
- **Posizione della call in `checkUserExistence()`**: `assignEditorRole()` è chiamata fuori dal blocco `if (!$shardUser)` (per tutti gli utenti), con guardia interna `$user->roles->isNotEmpty()` — stessa struttura di `assignAdministratorRole()`.

## Bug trovati

**Policy wm-package erano dead code per il ruolo Editor** (→ oc:8162)
Le policy referenziavano `hasRole('Editor')` ma il ruolo non esisteva mai nel db — nessun utente poteva averlo. Con il ruolo attivo si sono manifestati comportamenti incoerenti:
- `LayerPolicy::delete()`: Editor può eliminare layer (deve poter solo modificarli)
- `MediaPolicy::before()`: bypass totale di tutte le verifiche per qualsiasi utente autenticato
- `UserPolicy::before()`: controlla `hasRole('Admin')`, `hasRole('Author')`, `hasRole('Contributor')` — ruoli inesistenti

Tracciati in **oc:8162** insieme ai fix Nova (taxonomy read-only per Editor, UGC condizionale).

## Decisioni

- **`assignAdministratorRole()` conservato**: non rimosso per retrocompatibilità, anche se non più chiamato da `checkUserExistence()`.
- **`Editor` in `seedDatabase()`**: aggiunto dopo Administrator e prima di Validator per coerenza gerarchica.

## Follow-up

- **oc:8162** — Fix permessi e visibilità Nova per ruolo Editor (policy + menu Nova)
- I test di oc:8072 che usavano `Validator` al posto di `Editor` possono essere aggiornati ora che `Editor` è nel seed (out of scope per questo ticket).

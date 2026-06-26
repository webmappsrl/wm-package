> Ticket: oc:8042

# Utenti importati: ruolo assegnato in modo errato

## Cosa cambia

`GeohubImportService::checkUserExistence()` assegna il ruolo `Editor` (invece di `Administrator`) agli utenti creati durante l'import, **solo se non hanno già ruoli assegnati**. Il ruolo `Editor` viene aggiunto a `RolesAndPermissionsService::seedDatabase()` e una migration stub ne garantisce la creazione idempotente su tutti gli ambienti che usano wm-package.

## Perché

Il metodo `assignAdministratorRole()` assegnava sempre `Administrator` agli utenti importati, dando loro accesso admin completo a Nova. Il ruolo `Editor` era già referenziato nelle policy del package (MediaPolicy, LayerPolicy, UgcPoiPolicy, TaxonomyPoiTypePolicy, ecc.) ma non veniva mai creato nel database. Il ticket richiede il minimo privilegio per gli utenti importati.

## Requisiti

- [x] Aggiungere `Editor` al seed in `RolesAndPermissionsService::seedDatabase()`
- [x] Nuovo metodo `assignEditorRole()` in `GeohubImportService` che:
  - cerca il ruolo `Editor`; se non esiste chiama `seedDatabase()` come fallback
  - assegna `Editor` **solo se `$user->roles->isEmpty()`** (non sovrascrive ruoli esistenti)
- [x] `checkUserExistence()` chiama `assignEditorRole()` al posto di `assignAdministratorRole()`
- [x] Migration stub `zz_2026_06_26_000001_add_editor_role.php.stub` crea il ruolo con `insertOrIgnore` (idempotente)

## Rischi

Nessun rischio aperto: risolti a livello di design.

- **`assignRole()` aggiunge senza rimuovere** → assegnazione condizionale su `$user->roles->isEmpty()`: se l'utente ha già ruoli, il metodo non interviene e preserva la configurazione manuale
- **Ambienti già migrati** → migration usa `insertOrIgnore`, non fallisce se il ruolo esiste già
- **Fallback cerca 'Administrator'** → il nuovo `assignEditorRole()` cerca 'Editor'; `seedDatabase()` aggiornato include 'Editor'

## Out of scope

- Policy di visibilità Nova per Editor (altro ticket)
- Assegnazione ruoli basata su ruolo GeoHub (ruolo sempre fisso: `Editor`)
- Metodo `assignAdministratorRole()` rimane nel codice per retrocompatibilità (non viene rimosso)

## Moduli toccati

- `src/Services/Import/GeohubImportService.php`
- `src/Services/RolesAndPermissionsService.php`
- `database/migrations/zz_2026_06_26_000001_add_editor_role.php.stub`

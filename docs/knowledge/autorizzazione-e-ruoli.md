# Autorizzazione, ruoli e policy

Chi può fare cosa nel package, e dove il consumer deve completare il quadro.

## Stato attuale

### Il package registra una sola policy

`WmPackageServiceProvider` registra **solo** `App::class → AppPolicy`. Non registra policy di
default per `Layer`, `EcTrack`, `EcPoi`, `UgcPoi`, `UgcTrack`, e l'auto-registrazione
(`Gate::guessPolicyNamesUsing()`) è stata scartata deliberatamente: sovrascriverebbe tutte le
policy applicative del consumer.

**Conseguenza**: un consumer che monta la Nova Resource `EcTrack` senza fare
`Gate::policy(EcTrack::class, EcTrackPolicy::class)` nel proprio `AppServiceProvider` lascia Nova
autorizzare chiunque. `EcTrackPolicy` esiste nel package ma va collegata a mano. In camminiditalia
è registrata dal commit consumer `4fe834e`; gli altri consumer restano da verificare (oc:8181).

### Asimmetria voluta fra le policy

`LayerPolicy` (consumer) blocca **tutti** i Validator su `update()`/`delete()`, anche sui propri
Layer: un Layer è configurazione di alto livello (home, whitelabel, API), riservata
all'Administrator. `EcTrackPolicy` (package) ed `EcPoiPolicy` (consumer) sono invece
ownership-based, perché EcTrack ed EcPoi sono il contenuto quotidiano.

Non è un'incoerenza da correggere: è scritto qui perché un ticket futuro sull'autorizzazione di
questi tre modelli non lo "aggiusti" per errore (oc:8181).

### Super-admin per email

`RolesAndPermissionsService` ospita `allows()`, `allowsUser()`, `allowsEmail()`, che leggono solo
`config('wm-package.super_admin_emails')`. Sono statici, senza stato interno, per coerenza col
pattern preesistente. Sono il punto unico di autorizzazione del package (oc:8006).

Convivono due soglie di privilegio per capacità comparabili — il ruolo Spatie per
`canImpersonate()`, l'allowlist email per `allowsUser()` — segnalato in review e non riconciliato
(oc:8231).

### Ruoli in Nova

- `RoleBooleanGroup` e `PermissionBooleanGroup` in `AbstractUserResource` usano `allowsUser()`
  come guard, con `fillUsing()` server-side: il guard non è solo visivo.
- Anti-self-demotion: `fillUsing` di `RoleBooleanGroup` reimpone il ruolo Administrator
  all'utente che sta modificando se stesso.
- `PermissionBooleanGroup` usa `dependsOn('roles')` per mostrare solo i permessi rilevanti.
- Il package **non** gestisce `hideFromIndex()`: ogni progetto lo fa con un override di
  `fields()` in `User.php` (oc:8072).

### Impersonation

- `canImpersonate()` compone `hasRole('Administrator') && Gate::check('viewNova')`, hardcoded:
  nessuna config per consumer.
- `canBeImpersonated()` è `$this->can('access-nova')`. Un Administrator può impersonare un altro
  Administrator, e il requisito di accesso a Nova è necessario: senza, il target non potrebbe mai
  chiamare "Stop impersonating", visto che ogni route `nova-api/*` richiede il gate `viewNova`.
- Nessun log né audit trail: rifiutato esplicitamente, non rimandato — se servirà va introdotto
  trasversalmente nel package.
- **Trappola Nova**: dalla pagina Detail il bottone "Impersonate" può produrre un falso redirect
  al login. Il backend risponde sempre 200; la causa è nel vendor
  (`layout.blade.php` stampa `<meta name="csrf-token">` una volta e
  `bootstrap/axios.js` lo legge una volta sola all'avvio della SPA, senza mai rinfrescarlo).
  Segnalato upstream e chiuso come "not planned"
  ([nova-issues#5773](https://github.com/laravel/nova-issues/issues/5773),
  [#6082](https://github.com/laravel/nova-issues/issues/6082)). Aggirato nascondendo il bottone
  in Detail: `AbstractUserResource::authorizedToImpersonate()` torna `false` se
  `$request->isResourceDetailRequest()`. Dall'Index il bug non si presenta. Il workaround non ha
  meccanismo di scadenza se Nova risolvesse il bug (oc:8231).
- `last_login_at` viene sporcato da ogni start/stop impersonation, via `UpdateLastLoginAt`
  (listener preesistente sullo stesso evento `Login`) — noto, non risolto (oc:8231).

### Login API

`POST /auth/login` e `/auth/signup` sono sotto `throttle:100,1` per IP. In Laravel 11+ il gruppo
di middleware `api` non porta più `throttle:api` per default e i consumer non compensano: senza
questo throttle il login resterebbe illimitato ovunque. Le altre route `auth:api` (`refresh`,
`me`, `user`, `delete`) non hanno throttle — richiedono già un token valido, non sono un vettore
di brute-force.

**Se un consumer inizia a ricevere 429 sul login dopo un bump del submodule, la causa è questa.**
Non sono gli utenti veri a mordere, ma gli script che rifanno login a ogni iterazione (oc:8333).

### Ruoli nel DB: da dove arrivano

Se un ruolo o permesso Spatie risulta nel DB e non è chiaro da dove venga, si guarda **prima** la
tabella `migrations` (`select * from migrations where migration like '%<nome>%'`): è quasi sempre
una migration stub del package (`database/migrations/zz_*` nel consumer).
`RolesAndPermissionsService::seedDatabase()` è chiamato da `GeohubImportService` **solo come
fallback dentro un `if (! $role)`**, quindi in un DB maturo non scatta quasi mai: non presumere
che sia un import a introdurre un ruolo nuovo (oc:8218, oc:8231).

`assignEditorRole()` segue lo stesso pattern `Role::where()` + fallback `seedDatabase()` di
`assignAdministratorRole`, ed è condizionale su `$user->roles->isEmpty()` per preservare i ruoli
configurati a mano. Il suo stub usa `insertOrIgnore` per non far scattare eventi Eloquent Spatie
dentro una transazione PostgreSQL (oc:8042).

## Come ci siamo arrivati

- `SuperAdminService` è stata rimossa senza alias deprecato: breaking change da comunicare nel
  changelog prima di ogni rilascio (oc:8006).
- `UserPolicy::emulate()` è dead code (`hasRole('Admin')` invece di `'Administrator'`), verificato
  non referenziato da nessun consumer, lasciato com'è (oc:8231).
- Impersonare un Guest distruggeva la sessione dell'admin con un falso 200: Nova chiama
  `$guard->login($user)`, che spara un evento `Login` reale intercettato da
  `EnforceNovaAccessOnLogin` (oc:8161), e l'eccezione veniva inghiottita dal `rescue()` di Nova.
  Un primo fix aggiungeva un bypass `nova_impersonated_by` nel listener; è stato rimosso in review
  perché ridondante col requisito `access-nova` su `canBeImpersonated()`, che risolve alla radice.
  Il listener è tornato invariato allo stato di oc:8161 (oc:8231).
- Una config `impersonation.allowed_roles` (env `WM_IMPERSONATION_ALLOWED_ROLES`) è stata rimossa
  in review: solo Administrator, hardcoded (oc:8231).
- `EnforceNovaAccessOnLogin` contiene un bypass `app()->runningUnitTests()`, sempre `true` sotto
  Pest/PHPUnit dato `APP_ENV=testing`: un test di regressione sul login bloccato falliva per
  questo, ed è stato rimosso. Il bypass non è stato corretto (oc:8231).
- `visibleAppsFor()` in `ImportEcPoiFromOsm` conserva un ramo `hasRole('Administrator')` ormai
  irraggiungibile: scelta deliberata per minimizzare il diff, non dead code da pulire (oc:8239).

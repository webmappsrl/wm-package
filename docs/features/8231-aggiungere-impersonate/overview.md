> Ticket: oc:8231

# Aggiungere impersonate

## Cosa cambia

Viene abilitata la funzione "Impersonate" nativa di Laravel Nova 5 su `wm-package/src/Models/User.php` (base condivisa tra tutti i progetti Webmapp), con regole di autorizzazione custom:

- Aggiunto il trait `Laravel\Nova\Auth\Impersonatable` al modello `User` del package.
- `canImpersonate()` **compone** (non sostituisce) il check nativo Nova: `hasRole('Administrator') && Gate::forUser($this)->check('viewNova')`. Solo Administrator può impersonare — ruolo hardcoded, nessuna configurabilità per consumer (vedi "Decisioni CTO" più sotto: la configurabilità inizialmente introdotta è stata rimossa).
- `canBeImpersonated()` restituisce `true` solo per chi ha il permesso `access-nova` (`$this->can('access-nova')`): ogni route `nova-api/*`, incluso lo stop, richiede il gate `viewNova` — impersonare un utente senza `access-nova` (es. Guest) lascerebbe l'amministratore bloccato con 403 su qualunque azione Nova, "Stop impersonating" compreso (bug trovato in review, vedi "Rischi"). **Un Administrator può impersonare un altro Administrator** (ammesso esplicitamente dal CTO in review, vedi sotto) — nessuna esclusione di ruolo oltre al requisito `access-nova`.
- `EnforceNovaAccessOnLogin` (listener preesistente di oc:8161) **non modificato**: un bypass per l'impersonation era stato introdotto e poi rimosso su decisione del CTO (ridondante, vedi "Decisioni CTO").
- Nessun listener di logging: un tentativo di introdurne uno per gli eventi `StartedImpersonating`/`StoppedImpersonating` è stato rimosso su richiesta del CTO (vedi "Decisioni CTO").
- Nessuna modifica lato maphub: `app/Models/User.php` eredita il comportamento da `Wm\WmPackage\Models\User` senza override. `config/nova.php` non richiede modifiche (i redirect di default `/` già puntano a `/nova` tramite `routes/web.php`).

## Perché

Il ticket richiede la possibilità di impersonare utenti su Nova per supporto/debug. L'investigazione tecnica ha confermato che Laravel Nova 5.7 (versione già installata) include il supporto nativo completo (route, controller, binding, UI) — non serve alcun pacchetto terzo (es. `lab404/laravel-impersonate`). Il lavoro reale è: applicare il trait nel punto giusto (modello base condiviso nel package, non nell'app) e definire le regole di autorizzazione Webmapp, che di default Nova lascerebbe troppo permissive (chiunque abbia `access-nova`, quindi anche Editor/Validator, potrebbe impersonare chiunque).

## Requisiti

- [x] Solo Administrator può impersonare altri utenti (ruolo hardcoded in `canImpersonate()`)
- [x] Editor e Validator non possono impersonare nessuno
- [x] Un Administrator PUÒ impersonare un altro Administrator (ammesso esplicitamente dal CTO in review)
- [x] Un Administrator NON può impersonare Guest (privo di `access-nova` — lo lascerebbe bloccato senza poter fare "Stop impersonating")
- [x] Test Pest (unit) coprono: Administrator può impersonare, Editor non può, Validator non può, Administrator può essere impersonato da un altro Administrator, Editor può essere impersonato, Guest non può essere impersonato
- [x] Test Pest (feature, e2e) colpiscono realmente le route Nova `POST /nova-api/impersonate` e `DELETE /nova-api/impersonate` (`ImpersonateController::startImpersonating`/`stopImpersonating`): verificano risposta e cambio identità reale di `Auth::user()` per un Administrator su un Editor e su un altro Administrator, risposta 403 per un Editor che tenta di impersonare, risposta 403 impersonando un Guest
- [x] Verifica manuale in locale: login come Administrator su Nova, impersonare un utente Editor dal menu utente/tabella risorse, verificare redirect a `/nova`, eseguire "Stop impersonating"; verificare che il bottone impersonate non sia disponibile dalla pagina Detail (workaround CSRF)

## Rischi

Emersi dalla Fase: challenge (revisione adversariale) e relativa mitigazione:

- **Nomi ruolo fragili / falso negativo silenzioso.** Trovato in `wm-package/src/Policies/UserPolicy.php` un metodo `emulate()` (dead code, mai registrato via `Gate::policy`) che usa `hasRole('Admin')` invece di `'Administrator'` — prova che i nomi ruolo sono già driftati una volta nel package. Verificato che `emulate()` non è referenziato da nessun consumer (controllati osm2cai2 e forestas via clone diretto dei repo). *Mitigazione*: `canImpersonate()` compone `hasRole('Administrator') && Gate::check('viewNova')` (difesa in profondità); i test Pest usano `RolesAndPermissionsService::seedDatabase()` (ruoli reali) così un typo futuro farebbe fallire i test, non solo la produzione. `UserPolicy::emulate()` non viene toccato in questo ciclo (fuori scope) ma va segnalato in `notes.md` come debito tecnico noto.
- **Copertura test solo unitaria sul wiring reale.** *Mitigazione*: test e2e sulle route Nova reali (vedi Requisiti) oltre alla verifica manuale.
- **Scoping multi-tenant (Administrator che impersona utenti di un'altra App/cliente).** Verificato sullo schema reale: la tabella `users` di maphub **non ha colonna `app_id`** — gli utenti possiedono le App (`User::apps(): HasMany`), non viceversa, e l'unico Administrator ha comunque visibilità globale su tutte le App (`AppFilter::options()`). Non è un rischio applicabile a maphub: nessuna mitigazione necessaria.
- **Accettato esplicitamente, non mitigato**: azioni compiute durante una sessione impersonata vengono attribuite (`Auth::id()`) all'utente impersonato, non all'admin reale — non esiste alcuna traccia dell'impersonazione (nessun log, nessun audit trail: rimosso su decisione esplicita del CTO, vedi "Decisioni CTO"). Se un audit trail dovesse servire in futuro, va introdotto trasversalmente nel package (non ad hoc per questa feature).
- **`last_login_at` sporcato da ogni start/stop di impersonation.** Il listener preesistente `UpdateLastLoginAt` (sullo stesso evento `Login` intercettato anche da `EnforceNovaAccessOnLogin`) aggiorna `last_login_at` sia per l'utente impersonato (al login interno di start) sia per l'admin (al login interno di stop) — nessuno dei due è un vero login. *Accettato, non mitigato in questo ciclo*: chi usa quella colonna per audit di inattività può trovare valori "oggi" per utenti che in realtà non hanno mai fatto un login reale di recente. Trovato in review (`wm-skills:wm-review-ticket`).
- **Blast radius multi-progetto.** Il trait vive sul modello condiviso: un bug introdotto qui si propaga a ogni consumer di wm-package al prossimo aggiornamento submodule, non solo a maphub. Verificati tutti e tre i consumer noti via clone diretto dei repo:
  - **osm2cai2**: `App\Models\User` dichiara già `use Impersonatable;` con override custom (Administrator-only, no admin-su-admin, enum `UserRole` proprio) — nessun impatto, l'override della classe figlia vince sempre sul default del package.
  - **camminiditalia**: `App\Models\User` dichiara già `use Impersonatable;` **senza** override — usa il default nativo Nova completamente permissivo (chiunque abbia `viewNova` può impersonare chiunque, anche altri Administrator). Nessun impatto da questo ticket: dichiarando il trait direttamente nella classe figlia, i suoi metodi diventano "propri" di `App\Models\User` e prevalgono comunque su un eventuale override nella classe padre. Il comportamento permissivo pre-esistente di camminiditalia resta invariato — segnalato come possibile follow-up (fuori scope) per allinearlo alla policy più restrittiva.
  - **forestas**: nessuna traccia di impersonation configurata — riceverà il default del package (Administrator-only) alla prossima sincronizzazione del submodule, un miglioramento non richiesto ma coerente con la policy Webmapp.

Emerso dalla verifica manuale post-implementazione:

- **Falso "redirect al login" cliccando Impersonate dalla pagina Detail di un utente (non dall'Index).**
  **Non è un bug del codice di questo progetto — è un problema noto e non risolto di Laravel Nova stesso.** Escluso in due modi indipendenti: (1) riprodotto via HTTP reale (login → detail page → impersonate → redirect) che il backend risponde sempre 200 e la sessione passa correttamente all'utente impersonato, sia dall'Index sia dalla Detail; (2) il meccanismo difettoso vive interamente in `vendor/laravel/nova` (codice di terze parti, non modificato da noi): `resources/views/layout.blade.php` stampa il tag `<meta name="csrf-token">` una volta al caricamento pagina, e `resources/js/bootstrap/axios.js` lo legge **una sola volta** all'avvio della SPA senza mai rinfrescarlo durante la navigazione client-side. Se quel token è stantio, la prima chiamata AJAX che riceve 401 attiva il redirect automatico al login di Nova.
  **Conferma esterna:** lo stesso comportamento — redirect forzato al login causato da impersonate — è segnalato da altri utenti sul bug tracker ufficiale `laravel/nova-issues`, e **chiuso dal team Laravel/Nova come "not planned"/stale** (nessuna correzione prevista upstream):
  - [laravel/nova-issues#5773 — "Users can't be impersonated more than once per session/back-to-back"](https://github.com/laravel/nova-issues/issues/5773)
  - [laravel/nova-issues#6082 — "Invalid sessions when impersonate a user for the second time..."](https://github.com/laravel/nova-issues/issues/6082)
  - Spiegazione tecnica generale del pattern "meta tag CSRF stantio dopo un cambio di sessione lato SPA": [Why Your Laravel + Inertia.js Fetch Requests Fail with 419 After Save (dev.to)](https://dev.to/vsimke/why-your-laravel-inertiajs-fetch-requests-fail-with-419-after-save-3lg4)
  *Mitigazione (risolta):* patchare `vendor/laravel/nova` è sconsigliato (si perderebbe ad ogni aggiornamento del pacchetto); invece di correggere il bug — impossibile lato applicazione — **si evita di esporre l'unico punto d'ingresso che lo attiva**: `AbstractUserResource::authorizedToImpersonate()` (`wm-package/src/Nova/AbstractUserResource.php`) è stata sovrascritta per restituire `false` quando `$request->isResourceDetailRequest()` è vero, nascondendo il bottone "Impersonate" dalla pagina Detail. Dall'Index l'azione resta disponibile e funzionante. Test in `wm-package/tests/Feature/Nova/AbstractUserResourceImpersonateTest.php`. **Confermato valido dal CTO in review** (issue Nova upstream verificate chiuse "not planned") — nessuna modifica richiesta.

Emerso da `wm-skills:wm-review-ticket` (finder paralleli sul diff, prima del commit iniziale):

- **BLOCKER, risolto — impersonare un Guest distruggeva la sessione dell'admin con falso esito positivo.** `SessionImpersonator::impersonate()` di Nova chiama `$guard->login($user)`, che spara un evento `Login` reale sul guard `web`. Il listener preesistente `EnforceNovaAccessOnLogin` (oc:8161) intercetta *qualunque* login su quel guard — anche questo interno all'impersonation — e poiché Guest non ha mai `access-nova`, faceva logout, invalidava la sessione e lanciava un'eccezione; Nova avvolge la chiamata in un `rescue()` che la inghiotte silenziosamente, e il controller rispondeva comunque `200 {"redirect":"/"}` senza controllare l'esito. **Riprodotto e verificato con test reale**: `POST /nova-api/impersonate` verso un Guest → `200`, ma `auth()->check()` subito dopo era `false`.
  *Fix iniziale (poi rimosso, vedi "Decisioni CTO"):* `EnforceNovaAccessOnLogin` era stato reso consapevole dell'impersonation (bypass su `session()->has('nova_impersonated_by')`).
  *Fix definitivo (mantenuto):* `canBeImpersonated()` richiede `$this->can('access-nova')` — un Guest non può proprio essere scelto come target, quindi il login interno di Nova durante l'impersonation coinvolge sempre utenti che hanno già `access-nova` e passerebbero comunque il check nativo di `EnforceNovaAccessOnLogin`. Il bypass esplicito nel listener era quindi ridondante una volta introdotto questo fix a monte — il CTO lo ha fatto rimuovere in review per tenere il listener invariato.
- **Cleanup segnalati dalla review iniziale**, poi in parte superati dalla review del CTO (vedi "Decisioni CTO" per l'esito finale): duplicazione tra i due listener di log (rimossi del tutto), nessuna asserzione sul log nel test e2e (non più rilevante, log rimossi), `last_login_at` sporcato da start/stop impersonation (resta, vedi sopra), incoerenza tra `canImpersonate()` e `RolesAndPermissionsService::allowsUser()` (resta come nota, non affrontata), test del package non eseguiti dalla pipeline CI di maphub (gap pre-esistente, già tracciato in `notes.md`).

## Decisioni CTO (review su wm-package#242, 2026-07-13)

Review formale con il CTO (Giuseppe Bonfanti) sulla PR. Decisioni vincolanti applicate prima del merge:

1. **Rimossi i log di impersonation** (`LogImpersonationStarted`, `LogImpersonationStopped`, `ImpersonationLogListener`, registrazione in `EventServiceProvider`) — non necessari; un audit trail, se richiesto in futuro, va introdotto trasversalmente nel package, non ad hoc per questa feature.
2. **Rimosso il bypass `nova_impersonated_by`** in `EnforceNovaAccessOnLogin` (il "Fix iniziale" del blocker Guest) — ridondante: `canBeImpersonated()` esclude già a monte ogni target senza `access-nova`, quindi il login interno di Nova durante l'impersonation coinvolge sempre utenti che passerebbero comunque il check `access-nova` nativo del listener. `EnforceNovaAccessOnLogin` torna quindi al suo stato di oc:8161, non toccato da questo ticket.
3. **Rimossa la config `impersonation.allowed_roles` / env `WM_IMPERSONATION_ALLOWED_ROLES`** — solo Administrator può impersonare, hardcoded in `canImpersonate()`, nessuna configurabilità per consumer.
4. **Ammesso admin-su-admin** — rimossa l'esclusione `! $this->hasRole('Administrator')` da `canBeImpersonated()`, che ora è solo `$this->can('access-nova')`.
5. **Workaround CSRF su Detail page confermato valido** — issue Nova upstream (`#5773`, `#6082`) verificate chiuse `NOT_PLANNED`, nessuna modifica richiesta.

Il CTO ha inoltre verificato un problema tecnico nel test e2e di regressione su `/nova/login` (rimosso, vedi `notes.md`): falliva realmente (200 invece di 422 atteso) a causa dell'early-return `app()->runningUnitTests()` in `EnforceNovaAccessOnLogin` — bypass pre-esistente (non di oc:8231, introdotto in un commit successivo a oc:8161) che rende quel branch del listener non verificabile tramite Pest/PHPUnit (`APP_ENV=testing` in `phpunit.xml` fa sì che `runningUnitTests()` sia sempre `true` durante i test). Il test è stato rimosso da questo ciclo — dettagli e follow-up in `notes.md`.

## Out of scope

- UI/frontend custom per l'azione di impersonation: si usa esclusivamente il bottone nativo Nova (menu utente e riga tabella risorse), nessuna personalizzazione grafica
- **Audit trail (log applicativo o persistente su database)**: rifiutato esplicitamente dal CTO in review, non solo rimandato — se servirà, va introdotto trasversalmente nel package, non per questa feature
- Modifiche a `config/nova.php` (redirect `impersonation.started`/`stopped`): i default `/` sono già corretti per maphub
- Pulizia di `UserPolicy::emulate()` (dead code preesistente nel package, non referenziato da nessun consumer): tracciato come debito tecnico in `notes.md`, non risolto in questo ciclo
- Allineamento di camminiditalia (che oggi ha `Impersonatable` senza alcuna restrizione — chiunque può impersonare chiunque) alla policy Administrator-only: fuori scope, da valutare come ticket dedicato per quel progetto
- Validazione automatica del comportamento su altri consumer di wm-package (forestas): riceverà il default del package alla prossima sincronizzazione submodule, ma non viene testato in questo ciclo. osm2cai2 e camminiditalia non sono impattati: hanno già un proprio `use Impersonatable;` a livello di classe figlia che continua a prevalere
- Fix del bypass `runningUnitTests()` in `EnforceNovaAccessOnLogin` (rende quel ramo del listener non testabile via Pest): pre-esistente, non introdotto da oc:8231, fuori scope

## Moduli toccati

- `wm-package/src/Models/User.php` — trait `Impersonatable` + `canImpersonate()`/`canBeImpersonated()` (Administrator hardcoded, admin-su-admin ammesso, target richiede `access-nova`)
- `wm-package/src/Nova/AbstractUserResource.php` — override `authorizedToImpersonate()`: nasconde l'azione dalla pagina Detail (workaround al bug Nova CSRF-meta-tag, confermato valido dal CTO)
- `wm-package/tests/Feature/Nova/AbstractUserResourceImpersonateTest.php` (nuovo) — verifica `authorizedToImpersonate` true su Index, false su Detail
- `wm-package/tests/Unit/ImpersonationAuthorizationTest.php` (nuovo) — test su `canImpersonate()`/`canBeImpersonated()`
- `wm-package/tests/Feature/ImpersonationHttpTest.php` (nuovo) — test e2e sulle route Nova reali `POST`/`DELETE /nova-api/impersonate`

Non più toccati dopo la review del CTO (rimossi): `config/wm-package.php` (chiave `impersonation` rimossa), `src/Listeners/LogImpersonationStarted.php`, `src/Listeners/LogImpersonationStopped.php`, `src/Listeners/Abstracts/ImpersonationLogListener.php`, `src/Providers/EventServiceProvider.php` (registrazione rimossa), `src/Listeners/EnforceNovaAccessOnLogin.php` (fix rimosso, tornato allo stato di oc:8161).

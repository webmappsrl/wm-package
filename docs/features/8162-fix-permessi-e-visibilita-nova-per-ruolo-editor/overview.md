> Ticket: oc:8162

# Fix permessi e visibilità Nova per ruolo Editor (wm-package)

## Cosa cambia

- **Taxonomy** (`TaxonomyTheme`, `TaxonomyWhere`, `TaxonomyPoiType`, `TaxonomyActivity`): solo `Administrator` può creare/modificare/eliminare. `Editor` e `Validator` restano in sola visualizzazione.
  - `TaxonomyThemePolicy` e `TaxonomyWherePolicy` **non esistono oggi** — vengono create ex novo (nessuna policy → nessuna restrizione oggi, Nova default-allow).
  - `TaxonomyPoiTypePolicy::before()` ritorna oggi `true` incondizionato per qualsiasi utente autenticato, bypassando ogni check — rimosso, sostituito con bypass ristretto ad `Administrator`.
  - `TaxonomyActivityPolicy::create()`/`update()`/`delete()`/`restore()`/`forceDelete()` ritornano oggi `true` incondizionato — ristretti ad `Administrator`.
- **`LayerPolicy::delete()`**: ritorna oggi `true` per chiunque — ristretto a `false` per `Editor` (mirror di `create()`, che è già corretto), `true` per tutti gli altri ruoli.
- **`MediaPolicy::before()`**: ritorna oggi `true` incondizionato per qualsiasi utente autenticato, azzerando l'effetto di ogni altro metodo della policy — un Editor può fare qualsiasi operazione sui Media. Sostituito con bypass ristretto ad `Administrator`. `viewAny()`/`view()` restano come oggi (Editor con `hasDashboardShow()`); `create()`/`update()`/`delete()`/`restore()`/`forceDelete()` restano negati per chiunque non sia Administrator (nessun'altra modifica).
- **`UserPolicy::before()`**: controlla `hasRole('Admin')`/`hasRole('Author')` — ruoli davvero inesistenti (typo per `Administrator`) — e `hasRole('Contributor')`, che invece è un ruolo reale (`RolesAndPermissionsService::seedDatabase()`, usato per gli autori UGC importati). Verificato però che `Contributor` non riceve mai il permesso `access-nova` in `seedDatabase()`, quindi è bloccato a monte dal gate `viewNova` di Nova prima di poter mai raggiungere questa policy (usata solo da risorse Nova) — dead code confermato per via indiretta (gate a monte), non perché il ruolo non esista. Rimosso.
- **Nuovo metodo `User::hasUgcEnabled(?int $app_id = null): bool`** — stesso pattern di `hasDashboardShow()`/`hasClassificationShow()` già esistenti: itera le app possedute dall'utente (`$this->apps`) e ritorna `true` se almeno una ha `auth_show_at_startup && geolocation_record_enable` entrambi `true`. Usato da `NovaServiceProvider` (repo Maphub) per la visibilità della sezione UGC nel menu.

### Estensione (post-approvazione plan): scoping per-app di EC e UGC per Editor/Validator

Richiesta emersa dopo l'approvazione del piano iniziale: un Editor (e, per gli EC, anche un Validator) deve vedere **solo** i dati (EcPoi, EcTrack, Layer, UgcPoi, UgcTrack) legati alla/e propria/e App — non tutti i record dell'installazione. Verificato che oggi:
- `AbstractEcResource::indexQuery()` **esiste già** e filtra EcPoi/EcTrack per `user_id` (autore del record) per qualsiasi non-Administrator — non per `app_id`. Un record importato da GeoHub o creato da un collega della stessa app non è visibile all'Editor anche se appartiene alla sua app.
- `EcPoiPolicy`/`EcTrackPolicy::view()/update()/delete()` autorizzano solo se `$user->id === $model->user_id` (stesso criterio per-autore, non per-app) — vale per la pagina Detail, indipendente dallo scoping della lista.
- `LayerPolicy` non ha scoping (`viewAny`/`view` sempre `true`) e non ha `before()`.
- `AbstractUgcResource` non ha alcun `indexQuery()` — nessuno scoping di lista per UgcPoi/UgcTrack.
- `UgcPoiPolicy`/`UgcTrackPolicy::before()` ritornano `true` incondizionato (vedi sopra) — bypassano qualunque scoping aggiunto a `view()`, quindi **vanno corretti per davvero** in questo ciclo (non più solo commento TODO, vedi sotto) perché lo scoping richiesto abbia un effetto reale.

**Decisioni prese (in ordine di dipendenza):**
1. Il criterio per EC passa da `user_id` (autore) ad `app_id` (appartenenza all'app), **sostituendo** il criterio esistente — non aggiungendosi in OR. Nuovo metodo `User::ownedAppIds(): \Illuminate\Support\Collection` (mirror di `hasUgcEnabled()`/`hasDashboardShow()`: `$this->apps->pluck('id')`), riusato ovunque serva questo elenco.
2. Lo scoping EC per `app_id` si applica a **qualsiasi non-Administrator**, quindi anche Validator — stesso meccanismo condiviso, nessuna diversificazione per ruolo in `indexQuery()`/Policy EC. **Assunzione operativa**: perché questo abbia effetto pratico per un Validator, il Validator deve possedere almeno un'App tramite `apps.user_id` — se in produzione i Validator non posseggono mai App, questo scoping li priverebbe della visibilità EC (vedi Rischi).
3. Lo scoping si estende anche a Detail (`view()`/`update()` di `EcPoiPolicy`/`EcTrackPolicy`/`LayerPolicy`), non solo alla lista Index — altrimenti un Editor con l'URL diretto di un record di un'altra app potrebbe ancora aprirlo/modificarlo nonostante la lista sia filtrata.
4. Layer è incluso nello stesso scoping (stessa sezione menu "EC", stesso bisogno): nuovo `before()` su `LayerPolicy` (Administrator bypass, mancava), `view()`/`update()` scopati per `app_id`. `create()` (Editor→false) e `delete()` (Editor→false) **non cambiano** — restano come da Requisiti originali, indipendenti dall'app.
5. Per le UGC, `before()` diventa bypass per **Administrator O Validator** (non solo Administrator) — coerente con la decisione già presa per la visibilità del menu ("Validator vede sempre la sezione UGC, a prescindere, come Administrator"): un Validator il cui compito è validare UGC deve poterle vedere/gestire a prescindere dall'app, esattamente come un Administrator. Editor resta scopato per `app_id` **in sola lettura**: `viewAny()`/`view()` di `UgcPoiPolicy`/`UgcTrackPolicy` verificano `hasRole('Editor') && hasDashboardShow()` (gate esistente, invariato) **e** `ownedAppIds()->contains($model->app_id)`; `create()`/`update()`/`delete()`/`restore()`/`forceDelete()` restano negati per Editor (nessuna scrittura, come deciso).
6. `AbstractUgcResource::indexQuery()` nuovo (non esisteva): stesso schema-check pattern di `AbstractEcResource`, per `app_id`.

## Perché

Test manuale su utente Editor + analisi policy ha rilevato che diverse policy del package concedono permessi non coerenti con il ruolo assegnato (vedi ticket oc:8162): Editor può creare/modificare taxonomy che dovrebbe solo visualizzare, e alcune policy (`MediaPolicy`) bypassano completamente l'autorizzazione per qualsiasi utente autenticato.

## Requisiti

- [ ] `TaxonomyThemePolicy` nuova: `viewAny`/`view` aperti a tutti gli utenti Nova, `create`/`update`/`delete`/`restore`/`forceDelete` solo `Administrator`
- [ ] `TaxonomyWherePolicy` nuova: stessa struttura di `TaxonomyThemePolicy`
- [ ] `TaxonomyPoiTypePolicy`: rimuovere bypass incondizionato in `before()`, sostituire con bypass ristretto ad `Administrator`; `create`/`update`/`delete`/`restore`/`forceDelete` esplicitamente `Administrator`-only
- [ ] `TaxonomyActivityPolicy`: `create`/`update`/`delete`/`restore`/`forceDelete` ristretti ad `Administrator` (oggi `true` per chiunque)
- [ ] `LayerPolicy::delete()`: `false` per `Editor`, `true` per gli altri ruoli
- [ ] `MediaPolicy::before()`: bypass ristretto ad `Administrator` (rimosso `return true` incondizionato)
- [ ] `UserPolicy::before()`: rimosso (dead code, ruoli inesistenti)
- [ ] `TaxonomyTargetPolicy::before()` e `TaxonomyWhenPolicy::before()`: stesso identico pattern di `UserPolicy::before()` (`Admin`/`Author` inesistenti, `Contributor` reale ma bloccato a monte dal gate `viewNova`, mai raggiunge la policy) — rimosso (aggiunto in Challenge, non nel ticket originale ma stesso identico fix a costo/rischio nullo)
- [ ] Nuovo metodo `User::hasUgcEnabled(?int $app_id = null): bool`, mirror di `hasDashboardShow()`/`hasClassificationShow()` — deve ritornare `false` (mai eccezione) per un Editor senza alcuna app associata
- [ ] Test Pest per ogni policy modificata/creata: **ogni test verifica sia il path positivo (Administrator `can`) sia il path negativo (Editor `cannot`)** — un test che verifica solo il positivo non rileva una regressione futura del bypass
- [ ] Test esplicito che `Gate::getPolicyFor(TaxonomyTheme::class)`/`Gate::getPolicyFor(TaxonomyWhere::class)` risolvano rispettivamente `TaxonomyThemePolicy`/`TaxonomyWherePolicy` — verifica diretta che l'auto-discovery Laravel (convenzione `Models\X` → `Policies\XPolicy`, mai testata esplicitamente nel codebase) funzioni davvero per queste due nuove policy, non solo che l'ability sia negata
- [ ] Test per `User::hasUgcEnabled()`: caso app abilitata, app non abilitata, utente senza alcuna app associata (deve ritornare `false` senza eccezioni)
- [ ] Commento esplicito (`// TODO oc:8162` o annotazione equivalente) su `UgcPoiPolicy::before()` e `UgcTrackPolicy::before()` che segnala il bug noto di bypass totale — per evitare che un futuro dev copi questo pattern pensando sia lo standard del progetto (vedi Rischi)

### Requisiti aggiuntivi — scoping per-app EC/UGC

- [ ] Nuovo metodo `User::ownedAppIds(): \Illuminate\Support\Collection` — `$this->apps->pluck('id')`
- [ ] `AbstractEcResource::indexQuery()`: criterio da `user_id` ad `app_id` (`whereIn('app_id', $user->ownedAppIds())`) per qualsiasi non-Administrator
- [ ] `AbstractUgcResource::indexQuery()` nuovo: stesso criterio `app_id`, stesso schema-check pattern
- [ ] `EcPoiPolicy::view()/update()/delete()`: da `$user->id === $model->user_id` a `$user->ownedAppIds()->contains($model->app_id)`
- [ ] `EcTrackPolicy::view()/update()/delete()`: stessa modifica
- [ ] `LayerPolicy`: nuovo `before()` (bypass Administrator, mancava); `view()`/`update()` scopati per `app_id` come sopra; `create()`/`delete()` invariati
- [ ] `UgcPoiPolicy::before()`: bypass per `Administrator` **o** `Validator` (non solo Administrator)
- [ ] `UgcPoiPolicy::view()`: aggiunge `ownedAppIds()->contains($model->app_id)` al gate `hasRole('Editor') && hasDashboardShow()` esistente
- [ ] `UgcTrackPolicy::before()`: stessa modifica di `UgcPoiPolicy`
- [ ] `UgcTrackPolicy::viewAny()/view()`: armonizzati alla stessa struttura di `UgcPoiPolicy` (oggi `UgcTrackPolicy::viewAny()` ritorna `true` per chiunque, senza gate `hasDashboardShow()` — asimmetria pre-esistente, risolta come effetto collaterale necessario di questo fix, non un refactoring gratuito)
- [ ] Test Pest: per ogni Policy/indexQuery toccato, verificare esplicitamente il caso "record di un'altra app" (Editor NON lo vede/non lo modifica) oltre ai casi già pianificati Administrator/Editor generici

## Rischi

- **Bug identico trovato ma NON incluso in questo ticket** (stesso pattern di `MediaPolicy`): `UgcPoiPolicy::before()` e `UgcTrackPolicy::before()` ritornano `true` incondizionato per qualsiasi utente autenticato — un Editor (o chiunque acceda a Nova) può oggi vedere/creare/modificare/eliminare qualsiasi UGC di qualsiasi app, indipendentemente da `hasDashboardShow()`. Il ticket oc:8162 non lo menziona esplicitamente e lo scoping per-app della query è stato esplicitamente escluso da questo ciclo (vedi Out of scope) — segnalato qui come rischio noto, da valutare per un ticket dedicato. Mitigato in parte da un commento esplicito nel codice (vedi Requisiti) per evitare che sembri il pattern standard del progetto.
- Cambiare `MediaPolicy::before()` da bypass totale a bypass ristretto ad Administrator presume che nessun altro ruolo (Validator, Guest) debba oggi operare su Media in Nova — non verificato con un test end-to-end reale su Nova, solo per lettura di codice. Se in produzione un Validator dipende oggi da questo bypass per operazioni su Media, il fix introdurrebbe una regressione.
- **Auto-discovery Laravel delle Policy non verificato esplicitamente altrove nel codebase** (nessun `Gate::policy()` esplicito trovato per `Layer`/`Media`/`User`/`TaxonomyPoiType`/`TaxonomyActivity` — si affidano tutti alla convenzione di namespace `Models\X` → `Policies\XPolicy`). Le due nuove policy (`TaxonomyThemePolicy`, `TaxonomyWherePolicy`) assumono che questo meccanismo implicito continui a funzionare: se fallisse (cache di configurazione, conflitto con altro service provider), il fix sarebbe silenziosamente no-op — nessun errore, la policy semplicemente non verrebbe mai consultata. Mitigato da un test esplicito che verifica `Gate::getPolicyFor()` (vedi Requisiti).
- **`hasUgcEnabled()` assume `apps()` (relazione diretta `apps.user_id`) come unica fonte di "app possedute" dall'utente.** Se in futuro esistesse un pattern multi-operatore sulla stessa app non modellato da questa FK, il metodo ritornerebbe sempre `false` per quell'Editor, nascondendo la sezione UGC anche quando dovrebbe essere visibile — una regressione più grave del bug originale (voce fuorviante → voce mancante, contenuti UGC irraggiungibili da Nova). Rischio accettato, stesso limite già presente in `hasDashboardShow()`/`hasClassificationShow()`.
- **Recuperabilità dei dati, non solo del codice**: se prima del fix un Editor ha già sfruttato il bypass per alterare Taxonomy o Layer indebitamente, un rollback del codice non recupera i dati alterati — nessun audit trail esiste oggi per queste tabelle, introdurne uno è esplicitamente fuori scope in questo ciclo.
- **Rischio di rottura totale del menu Nova per tutti gli Editor**: `canSee()` viene valutato ad ogni caricamento pagina Nova. Se `hasUgcEnabled()` lanciasse un'eccezione non gestita (es. ordine di merge invertito tra i due repo, o un bug interno), l'impatto non si limiterebbe a nascondere la sezione UGC — romperebbe il rendering dell'intero menu per ogni Editor. Mitigato da: (1) ordine di merge vincolante esplicito (wm-package prima, poi bump submodule + `canSee()` in Maphub nello stesso commit — stesso pattern già documentato in oc:8348), (2) test esplicito "Editor senza app associate" che verifica ritorno `false` senza eccezioni.

### Rischi aggiuntivi — scoping per-app EC/UGC

- **Validator senza App possedute perderebbe la visibilità EC**: lo scoping EC (a differenza di quello UGC) non ha un bypass per Validator — si affida a `ownedAppIds()`, quindi presuppone che ogni Validator possieda almeno un'App tramite `apps.user_id`. Se questo non è vero in produzione oggi, il fix introduce una regressione per quel ruolo su EcPoi/EcTrack/Layer. Non verificabile da codice statico — da confermare operativamente prima del merge (query rapida `SELECT * FROM apps WHERE user_id IN (SELECT id FROM users WHERE ... hasRole Validator)` o equivalente, da eseguire quando Docker/DB sono di nuovo raggiungibili).
- **Cambiare il criterio di `AbstractEcResource::indexQuery()` da `user_id` ad `app_id` è una modifica condivisa** che si applica a **qualsiasi** risorsa figlia di `AbstractEcResource` (oggi EcPoi ed EcTrack) — non solo al caso discusso in questo ticket. Se in futuro un'altra risorsa estendesse `AbstractEcResource` assumendo lo scoping per `user_id`, erediterebbe silenziosamente il nuovo criterio per `app_id`.
- **Armonizzazione di `UgcTrackPolicy::viewAny()`** (aggiunta del gate `hasDashboardShow()` oggi assente) è un cambio di comportamento non esplicitamente richiesto dal ticket originale, ma necessario perché lo scoping funzioni in modo simmetrico tra UgcPoi e UgcTrack — segnalato qui per trasparenza, non è un refactoring opportunistico scollegato dal fix.
- **Verifica empirica non eseguita in fase di plan**: a differenza dei Task 1-10 (verificati end-to-end con Docker attivo, poi ripristinati), questa estensione è stata progettata da analisi statica di schema/migrazioni con Docker non raggiungibile — va verificata con lo stesso rigore (scrivi test → verifica fallimento → implementa → verifica successo) in fase di esecuzione, non assunta corretta a priori.

## Out of scope

- Fix di `UgcPoiPolicy::before()`/`UgcTrackPolicy::before()` limitato al bypass Administrator/Validator — non tocca `create()`/`update()`/`delete()` per Editor (restano negati, "solo visualizzare" deciso esplicitamente).
- Traduzioni: nessun nuovo testo utente introdotto da questa feature (solo logica di autorizzazione e visibilità menu).
- Un audit trail o notifica per i record che un Editor smette di vedere dopo questo fix (nessuna comunicazione automatica, puramente un cambio di visibilità silenzioso lato Nova).

## Moduli toccati

- `src/Policies/TaxonomyThemePolicy.php` (nuovo)
- `src/Policies/TaxonomyWherePolicy.php` (nuovo)
- `src/Policies/TaxonomyPoiTypePolicy.php`
- `src/Policies/TaxonomyActivityPolicy.php`
- `src/Policies/LayerPolicy.php`
- `src/Policies/MediaPolicy.php`
- `src/Policies/UserPolicy.php`
- `src/Policies/TaxonomyTargetPolicy.php` (rimozione dead code)
- `src/Policies/TaxonomyWhenPolicy.php` (rimozione dead code)
- `src/Models/User.php` (nuovo metodo `hasUgcEnabled()`, nuovo metodo `ownedAppIds()`)
- `src/Nova/AbstractEcResource.php` (`indexQuery()`: `user_id` → `app_id`)
- `src/Nova/AbstractUgcResource.php` (`indexQuery()` nuovo)
- `src/Policies/EcPoiPolicy.php` (`view`/`update`/`delete`: `user_id` → `app_id`)
- `src/Policies/EcTrackPolicy.php` (stessa modifica)
- `src/Policies/UgcPoiPolicy.php` (`before()` Administrator+Validator, `view()` scopato per app)
- `src/Policies/UgcTrackPolicy.php` (stessa modifica + armonizzazione `viewAny()`)
- Test Pest corrispondenti in `tests/Feature/Policies/` o `tests/Unit/Policies/` (da definire in plan)

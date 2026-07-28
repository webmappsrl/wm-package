# wm-package — Note per Claude

## HasPackageFactory — trappola nelle classi figlio

Il trait `HasPackageFactory` (usato da tutti i modelli del package) risolve la factory tramite `get_called_class()`:

```php
$package = Str::before(get_called_class(), 'Models\\');  // es. "App\"
$path = $package.'Database\\Factories\\UgcPoiFactory';   // cerca "App\Database\Factories\UgcPoiFactory"
```

Se una classe figlia in un altro namespace (es. `App\Models\UgcPoi`) eredita questo trait, **`::factory()` non funziona** perché cerca una factory nel namespace della figlia, non del package.

**Soluzione:** sovrascrivere `newFactory()` nel modello figlio:

```php
protected static function newFactory(): Factory
{
    return \Wm\WmPackage\Database\Factories\UgcPoiFactory::new();
}
```

## Decisioni architetturali

### Campo descrizione sempre visibile in creazione EcPoi/EcTrack (oc:8303)
- `PropertiesPanel::makeWithModel()` nasconde l'intero pannello se `hasDataForPath()` non trova dati per il path richiesto — condizione sempre vera su un record nuovo, quindi il pannello "Proprietà" (con `description`, `excerpt`, `ref`, ecc.) non compariva mai in creazione. Fix scelto: spostare `description` fuori dal pannello dinamico, come campo statico dichiarato direttamente in `getInfoTabFields()` di `EcPoi`/`EcTrack` (stesso pattern di `Contact email`, `Not Accessible Message` — sempre visibili perché non passano da `PropertiesPanel`)
- **Bug scoperto ma non corretto** (fuori scope): `PropertiesPanel::jsonForm()` seleziona lo schema statico per `EcPoi` solo in base a `columnName` (sempre `'properties'`), ignorando `$attribute` — il ramo che dovrebbe caricare lo schema UGC-specifico per il pannello `properties->ugc` è codice morto, intercettato sempre prima dal ramo generico. Oggi innocuo perché lo schema POI è vuoto (`fields: []`), ma riemergerebbe se in futuro si aggiungono altri campi a `wm-ec-poi-schema.php`
- `AbstractEcResource::tiptapButtons()` duplica intenzionalmente la stessa configurazione toolbar di `PropertiesPanel::tiptapButtons()`/`Nova\App::tiptapButtons()` — nessuna base class condivisa tra Nova Fields e Nova Resources; un trait condiviso è stato valutato ma escluso (richiederebbe toccare `PropertiesPanel`, out of scope)
- `Layer`/`wm-layer-schema.php` intenzionalmente non toccati — stesso bug di visibilità in creazione resta presente su Layer, da affrontare in un ticket separato se necessario
- Test in `Panel::$data`, non `Panel::$fields` — `Laravel\Nova\Panel` estende `Illuminate\Http\Resources\MergeValue`, che espone `$data` come proprietà pubblica

### Builder traduzioni App: field custom senza nova-devtool + componenti condivisi (oc:7546)
- **`Nova::mix()` + `laravel-nova-devtool` non disponibile**: pacchetto privato, credenziali `nova.laravel.com` scadute in questo ambiente. Alternativa funzionante: `laravel-mix` + `vue` + `laravel-nova` (pubblici su npm) con `webpack.mix.js` → `externals: { vue: 'Vue' }` (altrimenti bundla una seconda copia di Vue, causando rendering silenzioso del campo — nessun errore, il tab appare vuoto). `webpack` va pinnato **esatto** a `5.75.0` (non `^5.75.0`): versioni più recenti rompono `webpack-cli@4` (bundlato da `laravel-mix@6`) per un cambio nello schema di `ProgressPlugin`.
- **Classi Tailwind "invisibili" nei campi custom**: nessun campo Nova custom del package compila una build Tailwind propria — si riusano solo le classi già presenti nel CSS compilato di Nova. Una classe Tailwind che Nova non usa mai nella propria UI viene eliminata dal suo purge e non ha alcun effetto (scoperto con `bg-opacity-50`, `px-5`, `gap-3`, `mb-1`/`mb-4`/`mb-5`). Regola pratica: usare solo classi verificate già presenti in Nova (grep su `vendor/laravel/nova/resources/{js,ui}`), altrimenti CSS in `<style scoped>` (compilato nel nostro bundle da vue-loader) o `style` inline per spaziatura/posizionamento.
- **`<Teleport to="body">` obbligatorio per i modali**: senza, un modale `position: fixed` annidato nei pannelli tab di Nova risulta disallineato orizzontalmente (containing block alterato, probabile `transform` in catena). Pattern già presente in `FeatureCollectionMap.vue` — confermato identico al `Modal.vue` nativo di Nova.
- **Componenti condivisi introdotti**: `src/Nova/Fields/_shared/resources/js/components/{Button,SelectInput,TextInput}.vue` + `src/Nova/Fields/_shared/resources/js/utils/collectAllKeys.js` — prima non esisteva alcun componente Button/Select/TextInput riutilizzabile tra i campi custom del package (verificato con ricerca dedicata). Le classi di `Button.vue` replicano esattamente il `<Button>` nativo di Nova (varianti `solid`/`ghost`/`danger`, size large) — garantite presenti nel CSS di Nova (la variante `danger` replica `bg-red-500`/`border-red-500` da `vendor/laravel/nova/resources/ui/components/composables/useButtonStyles.ts`, aggiunta in review per l'azione elimina). Path relativo per importarli da un nuovo field: `../../../../_shared/resources/js/components/...` (o `.../js/utils/...` per funzioni pure non-Vue).
- **Estensione a lingue oltre it/en (follow-up, non incluso)**: richiede migration DB per nuove colonne `translations_<lang>`, cast nel modello `App`, e generalizzazione di `AppConfigService::config_section_translations()` (oggi hardcoded solo su it/en) — probabile fonte lingue: `config('wm-tab-translatable.locales')` (già contiene it/en/fr/es/de). Il campo Vue e la classe PHP sono già generici su `langs`, nessuna modifica necessaria lì.
- **Eliminazione chiave (aggiunta in `wm-skills:wm-review-ticket`, non nel piano originale)**: `deleteTranslation(key)` in `FormField.vue`, simmetrica a `upsertTranslation()` — rimuove la chiave da tutte le lingue gestite in un'unica azione, richiamata da un bottone "Elimina" (variante `danger`) nel modale di modifica, con `window.confirm()` prima di procedere. La prima stesura di `overview.md` escludeva esplicitamente questa azione ("Out of scope"); rimossa dall'esclusione dopo verifica che, senza di essa, ogni chiave inserita per errore resta cruft permanente rimovibile solo dal DB — non praticabile per l'utente finale non-developer target della feature.

### Validazione dimensioni minime icon/splash upload (oc:8247)
- **Trappola critica in `Ebess\AdvancedNovaMediaLibrary\Fields\Media`**: `->rules([...])` popola `collectionMediaRules`, validato contro l'**intero array della collection** (mix di ID media esistenti + nuovi file) in `fillAttributeFromRequest`. La rule `dimensions` (e qualsiasi rule basata su file, es. `image`, `mimes`) fallisce sempre in quel contesto perché un array non è mai un'istanza `File` — questo blocca **ogni** salvataggio del form con quel campo già valorizzato, anche senza toccarlo. Usare sempre `->singleMediaRules([...])`, che valida solo i nuovi file effettivamente caricati (istanze `UploadedFile`), lasciando intatti i media esistenti. Le rule `dimensions:width=1024,height=1024` erano commentate in `App.php` dal commit `43d01c49` esattamente per questo motivo (mai documentato, scoperto solo ricostruendo la storia git in fase di challenge)
- I messaggi di validazione custom per rule imbustate in un field Nova (`dimensions`, ecc.) non hanno un canale diretto nel field stesso — l'unico modo è la convenzione Laravel `validation.custom.<attribute>.<rule>` in `resources/lang/{locale}/validation.php`, che va **registrato esplicitamente** con `$this->loadTranslationsFrom(__DIR__.'/../resources/lang')` (senza namespace) in `WmPackageServiceProvider::boot()` — diverso da `loadJsonTranslationsFrom()` (già presente, usato solo per le stringhe `__('...')` dei field/help Nova, gruppo JSON separato dal gruppo `validation`)
- Laravel non sostituisce placeholder come `:min_width`/`:min_height` nel messaggio della rule `dimensions` (nessun `replaceDimensions` nel replacer) — le soglie vanno scritte hardcoded nel messaggio custom, non come placeholder dinamico
- Validazione client-side (prima del submit) esplicitamente fuori scope: richiederebbe di estendere il componente Vue del field Ebess (vendor) o un field Nova custom — non fattibile nella stima approvata per questo ciclo
- Il messaggio custom non è mostrabile nel toast Nova: per errori 422 il core (`vendor/laravel/nova/resources/js/mixins/HandlesFormRequest.js`) mostra sempre la stringa fissa "There was a problem submitting the form.", non personalizzabile senza patchare il vendor

### Aggiungere impersonate su Nova (oc:8231)
- `canImpersonate()` compone `hasRole('Administrator') && Gate::check('viewNova')` invece di sostituire il check nativo — solo Administrator può impersonare, hardcoded (nessuna config per consumer, vedi review CTO sotto)
- `canBeImpersonated()` è `$this->can('access-nova')` — un Administrator PUÒ impersonare un altro Administrator (ammesso esplicitamente dal CTO in review); l'unico requisito è avere accesso a Nova, altrimenti il target non potrebbe mai chiamare "Stop impersonating" (ogni route `nova-api/*` richiede il gate `viewNova`)
- Nessun log/audit trail per start/stop impersonation — rifiutato esplicitamente dal CTO in review (non solo rimandato): se servirà, va introdotto trasversalmente nel package, non ad hoc per questa feature
- `UserPolicy::emulate()` (dead code, `hasRole('Admin')` invece di `'Administrator'`) non toccato: verificato non referenziato da nessun consumer
- **Bug scoperto**: `Wm\WmPackage\Tests\TestCase` non è in `autoload-dev` di maphub — i test `wm-package/tests/**` che non dichiarano esplicitamente `uses(Tests\TestCase::class)` falliscono silenziosamente se lanciati da maphub (`BindingResolutionException: Target class [config] does not exist`), incluso `AbstractUserResourceRoleGuardTest.php` preesistente (oc:8072). Non risolto in questo ciclo, vedi notes.md
- **Gotcha Nova SPA — falso "redirect al login" (bug di Nova, NON del codice di questo progetto)**: cliccando "Impersonate" dalla pagina **Detail** di una risorsa (non dall'Index) può apparire un redirect alla pagina di login pur essendo tutto autorizzato lato server. Doppia esclusione del nostro codice: (1) riprodotto via HTTP reale che il backend risponde sempre 200 sia da Index sia da Detail — non è un bug di autorizzazione/sessione lato applicazione; (2) la causa vive interamente in `vendor/laravel/nova` (pacchetto di terze parti): `resources/views/layout.blade.php` stampa il tag `<meta name="csrf-token">` una volta al caricamento pagina, e `resources/js/bootstrap/axios.js` lo legge **una sola volta** all'avvio della SPA senza mai rinfrescarlo durante la navigazione client-side; se il token in memoria è stantio, la prima chiamata AJAX che riceve 401 attiva `Nova.redirectToLogin()`.
  Confermato come comportamento noto e **mai risolto upstream** — il team Laravel/Nova ha chiuso segnalazioni identiche come "not planned"/stale:
  - [laravel/nova-issues#5773](https://github.com/laravel/nova-issues/issues/5773) — stesso sintomo (impersonate → redirect a `/login`)
  - [laravel/nova-issues#6082](https://github.com/laravel/nova-issues/issues/6082) — sessione invalidata da impersonate ripetuto
  - [Spiegazione tecnica del pattern "meta tag CSRF stantio" (dev.to)](https://dev.to/vsimke/why-your-laravel-inertiajs-fetch-requests-fail-with-419-after-save-3lg4)
  **Risolto** evitando l'esposizione del punto d'ingresso, non il bug in sé (impossibile senza patchare Nova): `AbstractUserResource::authorizedToImpersonate()` restituisce `false` se `$request->isResourceDetailRequest()`, nascondendo il bottone "Impersonate" dalla pagina Detail. Dall'Index resta disponibile (unico punto dove il bug non si presenta). Test: `wm-package/tests/Feature/Nova/AbstractUserResourceImpersonateTest.php`. **Confermato valido dal CTO in review** — nessuna modifica richiesta.
- **BLOCKER trovato da `wm-skills:wm-review-ticket`, risolto — impersonare un Guest distruggeva la sessione dell'admin con falso esito positivo (200).** Nova chiama `$guard->login($user)` durante l'impersonation, che spara un evento `Login` reale intercettato dal listener preesistente `EnforceNovaAccessOnLogin` (oc:8161): un target senza `access-nova` (Guest) faceva scattare logout + invalidazione sessione, ma l'eccezione veniva inghiottita dal `rescue()` di Nova e il controller rispondeva comunque 200. Riprodotto e verificato con test reale. **Fix definitivo**: `canBeImpersonated()` richiede `access-nova` — un Guest non può proprio essere scelto come target, quindi il login interno di Nova coinvolge sempre utenti che avrebbero comunque passato il check nativo del listener. Un primo fix (bypass `nova_impersonated_by` in `EnforceNovaAccessOnLogin`) era stato aggiunto e poi **rimosso dal CTO in review** perché ridondante rispetto al fix su `canBeImpersonated()` — `EnforceNovaAccessOnLogin` è tornato invariato allo stato di oc:8161.
- **Review formale con il CTO su wm-package#242 (2026-07-13)** — decisioni vincolanti applicate prima del merge:
  1. Rimossi i log di impersonation (listener + registrazione in `EventServiceProvider`) — non necessari, un audit trail va introdotto trasversalmente se servirà
  2. Rimosso il bypass `nova_impersonated_by` in `EnforceNovaAccessOnLogin` — ridondante (vedi sopra)
  3. Rimossa la config `impersonation.allowed_roles`/env `WM_IMPERSONATION_ALLOWED_ROLES` — solo Administrator, hardcoded
  4. Ammesso admin-su-admin — `canBeImpersonated()` semplificata a `$this->can('access-nova')`
  5. Workaround CSRF Detail-page confermato valido, nessuna modifica
  Il CTO ha anche verificato che un test di regressione (login reale bloccato per utenti senza `access-nova`) falliva realmente per un bypass pre-esistente e non correlato (`app()->runningUnitTests()` in `EnforceNovaAccessOnLogin`, sempre `true` sotto Pest/PHPUnit dato `APP_ENV=testing`) — quel test è stato rimosso, fix del bypass fuori scope, vedi notes.md.
- **Cleanup segnalati in review, non risolti** (dettaglio in `notes.md`): `last_login_at` sporcato da ogni start/stop impersonation (via `UpdateLastLoginAt`, listener preesistente sullo stesso evento `Login`); soglia di privilegio incoerente tra `canImpersonate()` (ruolo Spatie) e `RolesAndPermissionsService::allowsUser()` (allowlist email) per capacità comparabili; workaround `authorizedToImpersonate()` senza meccanismo di "scadenza" se Nova risolvesse il bug CSRF upstream.

### Horizontal Scroll: campo Poi/Traccia custom + follow-up post-testing (oc:8241)
- `PoiTrackReferenceField` (Model Poi/Track + search filtrata client-side) sostituisce due `Select` sempre visibili in `HorizontalScrollPoiTrackItemRepeatable` — costruito da zero perché `dependsOn()` di Nova non raggiunge campi annidati in un Repeater dentro un Flexible content (verificato in `vendor/laravel/nova/src/Http/Controllers/UpdateFieldController.php`)
- Il titolo (`title`) è **sempre readonly**, mai editabile dal builder: `Text::readonly()->resolveUsing()` legge `poi_id`/`track_id` dalla riga del Repeater (non il model App — confermato che `resolveUsing` riceve `$this->data` del `Repeatable`, non la risorsa padre) e mostra il nome cascata `it→en→prima lingua disponibile`; vuoto per un item non ancora salvato. `ConfigHomeResolver::mergeItemTitle()` eredita comunque il titolo dal modello quando il campo è assente dal payload — nessuna modifica al resolver necessaria per questo comportamento
- **Bug corretto**: cambiare il Model (Poi↔Traccia) su un item esistente senza riselezionare un valore cancellava silenziosamente l'item (il vecchio `selectedId` veniva azzerato, il salvataggio scriveva `{type:null,id:null}`, il resolver scartava l'item senza errori). Fix: `FormField.vue::fill()` non invia più il campo se `selectedId` è vuoto + `->rules('required')` sul `PoiTrackReferenceField` — Nova propaga correttamente le rules di un field dentro un Repeater tramite `Repeater::formatRules()` (`vendor/laravel/nova/src/Fields/Repeater.php`)
- `$model->name` (accesso magico Eloquent su un modello con Spatie `HasTranslations`) risolve **sempre** una stringa per la locale corrente, mai l'array delle traduzioni — per leggere tutte le traduzioni serve `$model->getTranslations('name')` esplicito. Un vecchio controllo `is_array($model->name)` in questo file era quindi codice morto, mai eseguito
- `PoiTrackReferenceField` era l'unico campo custom del package senza un proprio `postcss.config.js` locale (tutti gli altri — `IconSelect`, `TrackColor`, `OrderList`, `LayerFeatures`, `FeatureCollectionMap` — ce l'hanno) — senza quel file, `npm run prod` risale l'albero delle cartelle e trova il `postcss.config.js` (ESM) della root del progetto consumer, causando un errore `ERR_REQUIRE_ESM`. Aggiungere sempre `postcss.config.js` (`module.exports = {}`) ad ogni nuovo campo custom con build CSS, mirror degli altri campi
- **Bug bloccante trovato in review (2026-07-15), corretto (2026-07-20)**: `ConfigHomeResolver::buildPoiTrackElement()`/`finalizePoiTrackElement()` (allora chiamati `buildGeoElement()`/`finalizeGeoElement()`) scrivevano `box_type: "horizontal_scroll_geo"` nel `config.json` pubblico — valore **inesistente** nel front-end (webmapp-app, submodule `wm-core`): `IBOX.box_type` (in `wm-core/projects/wm-core/src/types/config.ts`) non lo elenca, e `home-landing.component.html` monta `wm-features-box` solo su `box_type === 'base'`. Il box configurato in Nova risultava invisibile in app, senza errori a schermo. Fix: entrambi i metodi ora scrivono `box_type: 'base'`. Il valore persistito legacy `'horizontal_scroll_geo'` resta riconosciuto in lettura per compatibilità con i dati già in produzione (vedi punto sotto per il nome della chiave di registro Nova, cambiato in revisione 7). **Lato lettura aggiornato di conseguenza** (`resolveLayoutName()`, `getAttributesForItem()`, `previousPoiTrackItemsForGroup()`): usa il `box_type` persistito per risolvere il nome del layout Nova da cercare, quindi cambiare solo la scrittura avrebbe fatto sparire silenziosamente ogni item già salvato dal form Nova in edit (nessun layout si chiama `'base'`) — questi metodi riconoscono sia `'base'` che il vecchio `'horizontal_scroll_geo'` per compatibilità con i dati già in produzione. **Lezione generale**: verificare sempre il consumer front-end reale (non solo la correttezza sintattica lato back-end) prima di considerare completa una feature che scrive `config.json` o strutture simili consumate da un altro repo.
- **Rename "Geo" → "PoiTrack" nel naming interno (revisione 7, 2026-07-22)**: il naming interno rimasto "Geo" dopo il fix del `box_type` (classi `HorizontalScrollGeoItemRepeatable`/`HorizontalScrollGeoRepeaterJsonPreset`/`GeoReferenceField`, metodi del resolver, chiave di layout Nova `'horizontal_scroll_geo'`, attributo virtuale `geo_ref`) leakava nella UI Nova — il pulsante "Add" del Repeater mostrava di default il nome classe umanizzato ("Add Horizontal Scroll Geo Item Repeatable"), perché non c'era un `label()`/`singularLabel()` override. Rinominato tutto in `PoiTrack` (`HorizontalScrollPoiTrackItemRepeatable`, `HorizontalScrollPoiTrackRepeaterJsonPreset`, `PoiTrackReferenceField`, `model_ref`, chiave layout `'horizontal_scroll_poi_track'`) — **il valore persistito `box_type` non è stato toccato**: è un dato già salvato in produzione, distinto dagli identificatori di codice puramente interni (nessuno di questi è mai scritto nel `config.json` pubblico). Dist del campo Nova ricompilato di conseguenza (`npm run prod` dentro `PoiTrackReferenceField/`, il vecchio blocco licenza Nova non si è ripetuto perché `auth.json` con credenziali valide era già presente nella root del progetto consumer).
- **Due bug preesistenti in Detail, mascherati a vicenda (revisione 8, 2026-07-23)**: (1) `Repeater::make()->preset(new ...)` senza `->showOnDetail()` — Nova nasconde il field da index/detail per default (`onlyOnForms()` nel costruttore), un preset custom non lo riattiva come farebbe `->asJson()`; il repeater "Elementi" non è mai stato visibile in Detail per NESSUN box `horizontal_scroll_*`, da quando la feature esiste (round 1). (2) `extractRawItems()` (nel preset custom) faceva `if (! is_object($model)) return null;`, ma `Layout::resolveForDisplay()` (Detail) passa un array semplice — solo `Layout::resolve()` (form edit) passa l'oggetto Layout; senza il fix (1) questo bug era invisibile perché il codice non veniva mai eseguito. Corretti entrambi solo per il box Poi/Track (`horizontalScrollPoiTrackItemsRepeater()` in `App.php` + `HorizontalScrollPoiTrackRepeaterJsonPreset::extractRawItems()`) — **lo stesso identico bug esiste nel box a tassonomie originale** (`horizontalScrollItemsRepeater()`/`HorizontalScrollRepeaterJsonPreset`, mai toccato da oc:8241), non corretto lì, fuori scope.
- **Bug salvataggio Title del box: chiavi lingua editabili (revisione 8, 2026-07-23)**: `HasFlexibleTranslatableFields::translatableFields()` (condiviso da **tutti** i box `horizontal_scroll_*`, non specifico a Poi/Track) aveva la colonna delle chiavi (it/en/fr/es/de) del campo `KeyValue` editabile e senza `keyLabel` visibile — l'admin poteva sovrascrivere il codice lingua stesso digitando nella riga, producendo `{"testo digitato": "testo digitato"}` invece di `{"it": "testo digitato"}` (riprodotto e confermato simulando la request di salvataggio reale). Fix: aggiunto `->disableEditingKeys()` (colonna chiavi bloccata, `readonlyKeys()` → `true`). Effetto collaterale aggravante corretto in parallelo: `resolveUsing()` (`array_merge($default, $value)`) manteneva per sempre qualsiasi chiave estranea già salvata, anche con `disableAddingRows()` attivo — ora filtra (`array_intersect_key($value, $default)`) prima del merge, autopulendo anche i dati già corrotti al prossimo salvataggio.
- **Bug separato trovato nel frontend, non risolto (repo/pipeline diversi)**: `wm-core`/`FeaturesBoxComponent` (box `base` in `webmapp-app`) fa `{{title}}` senza la pipe `wmtrans`, a differenza del box gemello `box_type: 'title'` in `home-landing.component.html` che fa correttamente `{{box.title|wmtrans}}` — anche con il titolo del box Poi/Track salvato correttamente in tutte le lingue, l'app mostrerebbe oggi l'oggetto non tradotto invece del testo. File: `wm-core/projects/wm-core/src/box/features-box/features-box.component.html`. Segnalato, da risolvere in un ticket su quel repo.
- **Title del box rimosso, allineato a GeoHub (revisione 9, 2026-07-23)**: un `config_home` reale prodotto da GeoHub (funzionante in produzione) non ha mai la chiave `title` sul box `box_type: "base"` — le intestazioni sono box `title` separati, non un campo proprio del box. `config_home_title_layout()` (condiviso da tutti i box `horizontal_scroll_*`) imponeva invece `required: true` sul Title, impedendo il salvataggio del box Poi/Track senza titolo. Rimosso il campo dal box Poi/Track (`horizontal_scroll_poi_track_layout()` non chiama più `config_home_title_layout()`) e la chiave `title` viene ora omessa interamente dal JSON quando vuota (`ConfigHomeResolver::finalizePoiTrackElement()`), non più scritta come `"title": {}`. Retrocompatibile: un record con un titolo legacy già salvato lo mantiene finché non viene risalvato. **Non toccato per gli altri box** (`title` box_type stesso, Activities, POI Types) — stesso possibile disallineamento con GeoHub non verificato, fuori scope.

### Import EcPoi da OSM (oc:8239)
- `EcPoi::actions()` registra `ImportEcPoiFromOsm` con `->canSee($superAdminOnly)->canRun($superAdminOnly)`, dove `$superAdminOnly = fn (NovaRequest $req) => RolesAndPermissionsService::allows($req)` — stesso pattern già usato in `Wm\WmPackage\Nova\App::actions()` per `RegenerateAppPbfAction`/`ReindexAppScoutAction`. L'azione è visibile ed eseguibile **solo** dagli utenti con email in `WM_SUPER_ADMIN_EMAILS`, non più da qualsiasi Administrator/Editor/Validator. Servono **entrambi** `canSee` e `canRun` (enforced lato server, non solo UI) — `canSee` da solo non basta a bloccare l'esecuzione diretta
- `visibleAppsFor()` in `ImportEcPoiFromOsm` resta **invariato** (`$user->hasRole('Administrator') || RolesAndPermissionsService::allowsUser($user)`) nonostante il ramo `hasRole('Administrator')` sia ormai irraggiungibile in pratica (il gate `canSee`/`canRun` blocca chiunque non sia super-admin prima di arrivare a `fields()`) — scelta deliberata per minimizzare il diff, non rimuovere questo ramo scambiandolo per codice morto da pulire senza motivo
- `OsmImportReportController`/`OsmImportReportStore` **non richiedono modifiche**: il payload del report è già scoped per `user_id` esatto (non per ruolo), quindi non c'è fuga di dati cross-utente indipendentemente da chi può lanciare l'import
- `OsmPoiImporter::findExistingEcPoiByOsmid()` richiede `$appId` e filtra sempre per `app_id` — non rimuovere questo filtro: senza di esso, due App diverse sullo stesso DB con lo stesso `osmid` importato si sovrascriverebbero a vicenda
- Nessun `User-Agent` custom su `Wm\WmPackage\Http\Clients\OsmClient` — rischio noto di rate-limit condiviso tra tutti i consumer del package se più progetti eseguono import OSM in parallelo; non risolto in questo ciclo (fuori scope, vedi `docs/features/8239-.../overview.md`)
- Traduzioni OSM presenti solo in `resources/lang/{en,it}.json` — nessuna traduzione fr/es/de per questa feature (decisione esplicita, non un'omissione)

### Layer Nova: Map panel e EcPoi sulla mappa (oc:8160)
- `addFeaturesForMap()` in `FeatureCollectionMapTrait` inietta `$this->id` (= ID del layer) in ogni feature — non causa problemi perché il click usa la property `link` (URL completo), non `id`. L'`id` è rilevante solo in modalità popup, non usata per EcTrack/EcPoi sulla mappa del layer
- Tabella `ec_pois` hardcoded nella raw SQL di `getFeatureCollectionMap()` — stessa scelta di `taxonomy_wheres` nello stesso metodo; nessun `ec_poi_table` in config per simmetria con `ec_track_table`
- I test per `getFeatureCollectionMap()` sono in `wm-package/tests/Feature/` con `Wm\WmPackage\Tests\TestCase` — girano dalla suite phpunit del package (DB `wm_package`), non da `php artisan test` di camminiditalia

### Fix import POI taxonomy type (oc:8041)
- `processDependencies()` usa `$model->properties['geohub_id']` come ID autoritativo per `getTaxonomyMorphableRecords()` — `$this->entityId` può divergere in scenari di re-import
- `taxonomy_poi_types` aggiunto a `default_dependencies.app` in `wm-geohub-import.php` — era assente a differenza di `taxonomy_activity` e `taxonomy_theme`, rendendo il fix ID ininfluente nel flusso standard
- Timing del dispatch (taxonomy prima degli EcPoi) è un rischio noto ma lasciato fuori scope — da riaprire se il bug persiste dopo questo fix

### Inserire foto — my_paths e my_downloads (oc:7480)
- `getOrDownloadIcon()` usava `isset($app->$type)` che restituisce sempre `false` per le media collection Spatie (non sono attributi Eloquent) — sostituito con `$app->getMedia($type)->first()` + null-check esplicito
- `$mediaItem->mime_type` (attributo nativo Spatie) al posto di `getCustomProperty('mime-type')` che può restituire `null`
- `($disk->getConfig()['driver'] ?? 'local')`: il fake disk nei test non ha la chiave `driver` nel config — il default `local` è corretto semanticamente
- URL in `config.json` via `getFirstMediaUrl()` invece di `route()`: evita il conflitto di naming tra i due gruppi `webmapp` che condividono `->name('webmapp.')` in `routes/api.php`
- **Naming chiave config.json in camelCase**: nel `config.json` le chiavi sono `APP.myPaths`/`APP.myDownloads` (contratto col frontend), mentre media collection Spatie (`getMedia('my_paths')`), route API (`/resources/my_paths.png`, `->name('my_paths')`) e attributi campi Nova restano in snake_case
- `icon_notify` e `logo_homepage` hanno route e metodi controller ma **non** hanno `registerMediaCollections()` né campi Nova — non usarli come pattern di riferimento

### Fix mappa layer bounding box (oc:8093)
- Il dist `field.js` di `FeatureCollectionMap` va sempre ricompilato dalla sorgente verificata — il commit `fb3c0555` conteneva `"inline-geojson":A.field.geojson||null` nel template del `DetailFeatureCollectionMap` (non presente in `DetailField.vue`) perché compilato da una working copy locale non committata
- Prima di ogni `npm run prod` verificare che la sorgente `DetailField.vue` non abbia prop spurie; dopo la compilazione, grep `inline-geojson.*field.geojson` nel dist per controllo

## Decisioni architetturali

### Analytics Layer: selezione range temporale (oc:7648)
- WHERE per mesi usa `timestamp >= 'YYYY-MM-01' AND timestamp < 'YYYY-MM+1-01'` — `toYYYYMM`/`toUInt32` non supportati da PostHog HogQL
- `getTranslation('name', locale)` può restituire stringa vuota (non null) su EcTrack — usare cascade `['it', 'en', locale]` con `empty()` check
- `Collection->get($key)` invece di `$collection[$key]` per evitare `ErrorException` su chiavi assenti
- `webpack` pinnato a `^5.75.0` in `LayerAnalytics/package.json` per compatibilità con `laravel-mix@6`
- `LayerAnalyticsCard.php`: `created_at` arriva come stringa, non Carbon — usare `Carbon::parse()` invece di `?->format()`

## Feature disponibili

| Feature | Ticket | Moduli toccati | Note |
|---|---|---|---|
| Campo descrizione sempre visibile in creazione EcPoi/EcTrack | oc:8303 | `src/Nova/AbstractEcResource.php`, `src/Nova/EcPoi.php`, `src/Nova/EcTrack.php`, `config/wm-ec-poi-schema.php`, `config/wm-ec-track-schema.php`, `tests/Unit/EcDescriptionTiptapTest.php` | Campo `description` spostato dal pannello dinamico "Proprietà" (nascosto quando vuoto, mai visibile in creazione) a campo statico traducibile in `getInfoTabFields()`; entry rimossa dagli schema dinamici per evitare doppio editor; `Layer` non toccato |
| Horizontal Scroll: campo Poi/Traccia custom (Model + Search) | oc:8241 | `src/Nova/Fields/PoiTrackReferenceField/**`, `src/Nova/Flexible/ConfigHome/HorizontalScrollPoiTrackItemRepeatable.php`, `src/Nova/Flexible/ConfigHome/HorizontalScrollPoiTrackRepeaterJsonPreset.php`, `src/Nova/App.php`, `src/Nova/Traits/HasFlexibleTranslatableFields.php`, `resources/lang/{it,en}.json` | Campo Nova custom Model(Poi/Track)+Search al posto di due Select; titolo sempre readonly ereditato dal modello; fix bug perdita silenziosa item al cambio Model; cascade traduzioni `it→en→prima disponibile`; naming interno "Geo" rinominato in "PoiTrack" in revisione 7 (il `box_type` persistito resta `base`/legacy `horizontal_scroll_geo`, invariato); revisione 8: fix `showOnDetail()`/`extractRawItems()` mancanti (stesso bug preesistente nel box tassonomie, non corretto) + fix salvataggio Title (`disableEditingKeys`, chiavi lingua condivise da tutti i box) |
| Builder traduzioni App | oc:7546 | `src/Nova/Fields/TranslationsBuilder/**`, `src/Nova/Fields/_shared/**`, `src/Nova/App.php`, `src/Services/Models/App/AppConfigService.php`, `src/WmPackageServiceProvider.php` | Fix bug bloccante `json_decode` su colonna già `array`; nuovo campo Nova custom (select lingua invece di box fissi, tabella con colonna chiave sticky + scroll orizzontale/verticale, niente paginazione, upsert manuale+bulk JSON, eliminazione chiave da tutte le lingue aggiunta in review); introdotti i primi componenti condivisi (`Button` con variante `danger`, `SelectInput`, `TextInput`, `collectAllKeys`) tra i campi custom del package |
| Validazione dimensioni minime icon/splash upload | oc:8247 | `src/Nova/App.php`, `src/Models/App.php`, `src/WmPackageServiceProvider.php`, `resources/lang/{it,en}/validation.php`, `tests/Feature/Nova/AppIconSplashDimensionsValidationTest.php` | `singleMediaRules` (min_width/min_height/ratio, non `->rules()`) su icon/splash; `singleFile()` sulle 3 collection; messaggi custom via nuovo `loadTranslationsFrom` |
| Aggiungere impersonate su Nova | oc:8231 | `src/Models/User.php`, `src/Nova/AbstractUserResource.php`, `tests/Unit/ImpersonationAuthorizationTest.php`, `tests/Feature/ImpersonationHttpTest.php`, `tests/Feature/Nova/AbstractUserResourceImpersonateTest.php` | Trait nativo Nova `Impersonatable`; solo Administrator può impersonare (hardcoded, dopo review CTO); `canBeImpersonated()` richiede `access-nova` (blocca Guest, ammette admin-su-admin). Nessun log/audit trail (rifiutato dal CTO) |
| Horizontal Scroll: campo Poi/Traccia custom (Model + Search) | oc:8241 | `src/Nova/Fields/GeoReferenceField/**`, `src/Nova/Flexible/ConfigHome/HorizontalScrollGeoItemRepeatable.php`, `resources/lang/{it,en}.json` | Campo Nova custom Model(Poi/Track)+Search al posto di due Select; titolo sempre readonly ereditato dal modello; fix bug perdita silenziosa item al cambio Model; cascade traduzioni `it→en→prima disponibile` |
| Horizontal Scroll: campo Poi/Traccia custom (Model + Search) | oc:8241 | `src/Nova/Fields/PoiTrackReferenceField/**`, `src/Nova/Flexible/ConfigHome/HorizontalScrollPoiTrackItemRepeatable.php`, `src/Nova/Flexible/ConfigHome/HorizontalScrollPoiTrackRepeaterJsonPreset.php`, `resources/lang/{it,en}.json` | Campo Nova custom Model(Poi/Track)+Search al posto di due Select; titolo sempre readonly ereditato dal modello; fix bug perdita silenziosa item al cambio Model; cascade traduzioni `it→en→prima disponibile`; naming interno "Geo" rinominato in "PoiTrack" in revisione 7 (il `box_type` persistito resta `base`/legacy `horizontal_scroll_geo`, invariato) |
| Import EcPoi da OSM (Nova Action, CLI, report) | oc:8239 | `src/Nova/Actions/ImportEcPoiFromOsm.php`, `src/Services/Osm/*`, `src/Dto/Osm*.php`, `src/Commands/WmImportEcPoiFromOsmCommand.php`, `src/Http/Controllers/OsmImportReportController.php`, `config/wm-osm-import.php` | Lift-and-shift da Maphub; action registrata di default in `EcPoi::actions()`; fix isolamento multi-app su `findExistingEcPoiByOsmid()`; `visibleAppsFor()` preserva `hasRole('Administrator')` oltre a `RolesAndPermissionsService::allowsUser()` |
| Import Layer: associazione EcPoi via taxonomy + poi_mode | oc:8043 | `src/Services/Import/GeohubImportService.php`, `src/Jobs/Import/ImportLayerJob.php`, `config/wm-geohub-import.php`, `src/Models/Layer.php`, `src/Services/Models/LayerService.php`, `src/Nova/Fields/LayerFeatures/**`, `src/Observers/{TaxonomyActivityablesObserver,TaxonomyWhereablesObserver,LayerObserver,EcPoiObserver}.php`, `src/Jobs/Layer/SyncAutoLayerAfterPoiTaxonomyChangeJob.php`, `tests/Feature/GeohubImportServiceAssociateLayerPoiTest.php`, `tests/Feature/LayerAssignPoisByTaxonomyTest.php` | Round 1: `associateLayersWithEcPoi()` traversa tutti e tre i meccanismi taxonomy (theme, where, poi_types) durante l'import GeoHub. Round 2 (fix review): cleanup morphable_type/idempotenza/duplicazione + nuovo `poi_mode` (auto/manuale) a piena parità con `track_mode` per l'associazione manuale POI↔Layer da Nova |
| Utenti importati: ruolo Editor in import GeoHub | oc:8042 | `src/Services/Import/GeohubImportService.php`, `src/Services/RolesAndPermissionsService.php`, `database/migrations/zz_2026_06_26_000001_add_editor_role.php.stub` | `assignEditorRole()` condizionale; Editor aggiunto a `seedDatabase()` |
| Layer Nova: Map panel dedicato + EcPoi sulla mappa | oc:8160 | `src/Nova/Layer.php`, `src/Models/Layer.php`, `resources/lang/it.json`, `resources/lang/en.json` | `FeatureCollectionMap` spostato in panel `__('Map')` separato; `getFeatureCollectionMap()` include EcPoi come Point features (tooltip nome, link `ec-pois/{id}`) |
| BulkEditAction: bulk edit dinamico da Nova Resource | oc:8133 | `src/Nova/Actions/BulkEditAction.php`, `tests/Unit/Nova/Actions/BulkEditActionTest.php`, `tests/Feature/Nova/Actions/BulkEditActionFeatureTest.php` | Action parametrica: `new BulkEditAction(Resource::class, ['field'])` — filtra campi da Resource, appiattisce Panel/Tab, `saveQuietly()` in `DB::transaction()` |
| Analytics Layer: selezione range temporale | oc:7648 | `src/Services/PostHog/AnalyticsService.php`, `src/Http/Controllers/Nova/AnalyticsController.php`, `src/Nova/Cards/LayerAnalytics/` | Dropdown 30/90/365gg + mesi da created_at; tabella download per traccia |
| Inserire foto (my_paths, my_downloads) | oc:7480 | `src/Models/App.php`, `src/Nova/App.php`, `src/Http/Controllers/Api/AppController.php`, `routes/api.php`, `src/Services/Models/App/AppConfigService.php` | Media collection + Nova fields + route nei 3 gruppi + URL in APP section del config.json; fix getOrDownloadIcon() (isset→getMedia, mime_type, driver null-safe) |
| EC POI map icon display | oc:7645 | `src/Models/EcPoi.php`, `src/Nova/EcPoi.php`, `src/Http/Resources/RelatedEcPoiResource.php` | `show_image_on_map` in `feature_image` dei related_pois dell'EcTrack; checkbox readonly se il POI non ha immagini |
| Fix mappa layer bounding box | oc:8093 | `src/Nova/Fields/FeatureCollectionMap/dist/js/field.js` | Dist ricompilato: rimossa prop `inline-geojson:field.geojson` introdotta per errore in `fb3c0555` (oc:7756) |
| Fix import Excel POI: nomi mancanti in pois.geojson | oc:8063 | `src/Imports/Processors/EcPoiRowProcessor.php`, `tests/Unit/Imports/Processors/EcPoiRowProcessorTest.php` | Sync `properties['name']` da `getTranslations('name')` in `apply()` — replica logica observer bypassata da `saveQuietly()` |
| ImportTaxonomyThemeJob | oc:8014 | `src/Jobs/Import/ImportTaxonomyThemeJob.php`, `src/Services/Import/GeohubImportService.php`, `config/wm-geohub-import.php` | Aggiunge il job mancante per importare TaxonomyTheme da GeoHub; registra taxonomy_theme in MODEL_IMPORT_ORDER e default_dependencies |
| Dipendenza visiva auth → geolocalizzazione in Nova | oc:7852 | `src/Nova/App.php`, `resources/lang/it.json` | HTML field `onlyOnDetail()` con valore calcolato; `mobileAuthDependent()` mantiene grayed-out in edit; Boolean `onlyOnForms()` evita duplicazione in detail |
| Fix import POI taxonomy type | oc:8041 | `src/Jobs/Import/ImportTaxonomyJob.php` | `processDependencies()` usa `$model->properties['geohub_id']` invece di `$this->entityId` per chiamare `getTaxonomyMorphableRecords()` |
| Fix getTaxonomyMorphableRecords | oc:8013 | `src/Jobs/Import/ImportTaxonomyJob.php` | Corregge il parametro passato a getTaxonomyMorphableRecords: entityId (GeoHub) invece di model->id (Maphub) |
| Refactor SuperAdminService | oc:8006 | `src/Services/RolesAndPermissionsService.php`, `src/Support/SuperAdminService.php` (rimosso), `src/Nova/App.php`, `src/Nova/Actions/GenerateAppIconsAction.php`, `src/Nova/Actions/BuildAppPoisGeojsonAction.php`, `src/Policies/AppPolicy.php` | Sposta i check super-admin email-based in RolesAndPermissionsService; rimuove SuperAdminService |
| Fix esposizione assets API | oc:7913 | `src/Http/Controllers/Api/AppController.php` | `getOrDownloadIcon` usa `getMedia()->first()` invece di `isset($app->$type)` — fix 404 su app con media in Spatie e colonne null |
| Modifica ruolo utente in Nova | oc:8072 | `src/Nova/AbstractUserResource.php`, `tests/Feature/Nova/AbstractUserResourceRoleGuardTest.php` | Guard `RolesAndPermissionsService::allowsUser()` su ruoli/permessi; fillUsing server-side; anti-self-demotion |
| Comandi migration stub wm-package | oc:8218 | `src/Commands/WmPackage{PublishMigration,PublishMissingMigrations}Command.php`, `src/Commands/Concerns/InteractsWithWmPackageMigrationStubs.php` | Stub obbligatori. CI: `publish-missing-migrations --dry-run`. Dev: `publish-missing-migrations` / `publish-migration`. Suffisso file non basta |

## Decisioni architetturali

### Comandi migration stub wm-package (oc:8218)

**Fonte di verità:** `docs/features/8218-cicd-migration-wm-package-permission-cache/overview.md`. Pipeline CI end-to-end: overview maphub (`maphub/docs/features/8218-.../overview.md`).

- Stub in `database/migrations/*.php.stub` **obbligatori** per ogni consumer
- **`publish-missing-migrations --dry-run`** — gate CI consumer (dopo `migrate`, stesso DB dei test); exit 1 se non allineato
- **`publish-missing-migrations`** — workflow dev: pubblica stub con gap schema e senza file identico committato
- **`publish-migration <stub>`** — singolo stub; non si ferma al suffisso se contenuto diverso (es. `create_users_table`)
- Mai `vendor:publish` in deploy; mai `vendor:publish --force` in locale

**Logica per stub** (`InteractsWithWmPackageMigrationStubs`):
- `schemaGapsForStub` → colonne/tabelle/ruoli mancanti sul DB
- `isAppliedToDatabase` → nessun gap (o migration eseguita per suffisso se stub non parsabile)
- `needsPublishing` → gap + nessun file committato identico allo stub
- `stubsPendingMigration` → file identico committato ma non in tabella `migrations`

**Tabella casi d'uso (agenti):**

| Scenario | `--dry-run` | Azione |
|----------|-------------|--------|
| Schema già completo | pass | Nessuna |
| Gap schema, nessun file identico | fail | `publish-missing-migrations` / `publish-migration` |
| File identico in git, non migrato | fail | `migrate` |
| Suffisso uguale, contenuto diverso | fail | Pubblica stub wm-package |
| Schema ok via migration custom | pass | Nessuna |

### Import Layer: associazione EcPoi via taxonomy (oc:8043)
- `associateLayersWithEcPoi()` controlla tre meccanismi GeoHub in sequenza: `taxonomy_themeables`, `taxonomy_whereables`, `taxonomy_poi_typeables` — **taxonomy_theme è il meccanismo primario** (app 63: 48/11/4 poi; app 44: 101-109 poi per layer)
- GeoHub non ha un rapporto diretto Layer→EcPoi: la relazione è indiretta tramite taxonomy condivise
- `attach()` con `alreadyExists` check per idempotenza (re-import safe); i geohub_poi_id vengono deduplicati prima dell'attach per gestire POI trovati da più meccanismi
- `$config['morphable_type']['value'] = 'App\Models\Layer'` non dipende dal tipo Maphub — è il type string di GeoHub

#### Round 2 — fix review + poi_mode (oc:8043)
- **Morph map locale vs stringhe GeoHub**: `Relation::morphMap()` in `WmPackageServiceProvider` mappa `'App\Models\EcPoi'`/`'App\Models\EcTrack'`/`'App\Models\Layer'` alle classi del package. Il check idempotenza sulla pivot locale `layerables` deve confrontare con l'**alias** (`'App\Models\EcPoi'`), non con l'FQCN (`EcPoi::class`) — altrimenti l'idempotenza fallisce silenziosamente (duplica le righe al re-import). Il match esatto sul `morphable_type` nelle tabelle GeoHub (`taxonomy_themeables` ecc.) usa la stessa stringa `'App\Models\EcPoi'`, ma è un sistema diverso (DB GeoHub, non morph map locale) — coincidenza di stringa, non stesso meccanismo.
- **`config('wm-package.ec_poi_model', ...)`**: il default corretto è `EcPoi::class` (`Wm\WmPackage\Models\EcPoi`), **non** la stringa `'App\Models\EcPoi'` come invece fa `ec_track_model` — in molti progetti (incluso questo) non esiste una classe applicativa `App\Models\EcPoi` che estenda il package, a differenza di `App\Models\EcTrack` che tipicamente esiste. La chiave `ec_poi_model` non è registrata in `config/wm-package.php` (a differenza di `ec_track_model`): non aggiungerla con un default `'App\Models\EcPoi'`, romperebbe silenziosamente ogni progetto senza quella classe applicativa.
- **`EcPoi` non ha il trait `Laravel\Scout\Searchable`** (a differenza di `EcTrack`) — i POI non sono indicizzati su Scout/Elasticsearch. Qualsiasi job/observer che debba "reindicizzare" un EcPoi dopo un `saveQuietly()` deve dispatchare `BuildAppPoisGeojsonJob`, non chiamare `->searchable()`.
- **`poi_mode` (auto/manuale su Layer) replica esattamente `track_mode`**: nuovo flag `configuration['poi_mode']` su `Layer` (`isAutoPoiMode()`/`setPoiMode()`), `LayerService::assignPoisByTaxonomy()` mirror di `assignTracksByTaxonomy()` (stesso JOIN `taxonomy_activityables` + `ST_Intersects` su `taxonomy_wheres`, **non** copre `taxonomy_poi_types`), stessi 4 observer reattivi (`TaxonomyActivityablesObserver`, `TaxonomyWhereablesObserver`, `LayerObserver`, `EcPoiObserver` mirror di `EcTrackObserver`) e nuovo job `SyncAutoLayerAfterPoiTaxonomyChangeJob`.
- **Bug fix**: `LayerFeatureController::sync()`/`getFeatures()` avevano la logica auto/manuale hardcoded su `$relationName === 'ecTracks'` — nel pannello Ec Pois di Nova, cliccare "Selezione Automatica" eseguiva `$layer->ecPois()->sync([])`, cancellando tutte le associazioni POI. Resi relation-aware. Il meta key del field `LayerFeatures` è stato rinominato da `trackMode` a `mode` (generico) in `LayerFeatures.php`, `LayerFeature.vue` e `interfaces.ts` — dist ricompilato con `npm run prod` (verificare sempre che il diff del dist non contenga proprietà spurie, vedi decisione oc:8093 sotto).
- **Limite noto, non risolto**: `setTrackMode()`/`setPoiMode()` non sono mai invocati da nessun endpoint/action in produzione — `isAutoTrackMode()`/`isAutoPoiMode()` sono quindi sempre `true` di default, e qualunque evento di tassonomia successivo sovrascrive una selezione manuale fatta da Nova con un `sync()` ricalcolato. Il toggle "manuale" funziona solo fino al prossimo evento reattivo. Comportamento ereditato da `track_mode` (mai risolto), replicato identico per `poi_mode` per parità esplicita — non un'omissione di questo ciclo. Da risolvere in un ticket dedicato se serve persistenza reale (es. campo Select in Nova con `fillUsing` che chiama `setTrackMode()`/`setPoiMode()`).
- **Doppio meccanismo non coordinato sulla pivot `layerables`**: `associateLayersWithEcPoi()` (import GeoHub, `attach()` additivo) e `assignPoisByTaxonomy()` (auto mode, `sync()` a rimpiazzo totale) possono scrivere sulla stessa pivot per lo stesso layer con semantiche diverse. Un layer in `poi_mode: auto` può perdere POI aggiunti dall'import (specialmente quelli associati solo via `taxonomy_poi_types`, non coperto da `assignPoisByTaxonomy()`) al primo ricalcolo taxonomy-based. Stesso trade-off già esistente per `ecTracks`, non introdotto da questo ciclo — nessuna coordinazione (lock, flag "import in corso") implementata.
- **`EcPoiFactory` (test)**: il default di `properties` è una stringa JSON-encoded assegnata a un campo con cast Eloquent `'array'` — produce doppia serializzazione se non sovrascritto esplicitamente con un array nei test (`'properties' => []`). Bug pre-esistente del factory, non corretto in questo ciclo.
- **`regeneratePbfsForLayer()` non va chiamato per `ecPois`**: i PBF (`PBFGeneratorService`, `GenerateOptimizedPBFChainJob`, `GeneratePBFByZoomJob`) referenziano solo `ec_track_table` — non contengono mai contenuto POI. `SyncAutoLayerAfterPoiTaxonomyChangeJob` (bug trovato in review, corretto) non chiama più `regeneratePbfsForLayer()`; `LayerFeatureController::sync()` la chiama solo se `$relationName === 'ecTracks'`, stessa guardia già usata in `LayerableObserver::handleRelatedFeaturesUpdate()`. Senza questa guardia, un layer POI-only in un'app senza `map_bbox` fa fallire il job con eccezione non catturata, impedendo anche il reindex POI a valle.

### Utenti importati: ruolo Editor (oc:8042)
- `assignEditorRole()` usa pattern `Role::where()` + `seedDatabase()` fallback (come `assignAdministratorRole`)
- Assegnazione condizionale su `$user->roles->isEmpty()` — preserva ruoli già configurati manualmente
- Migration stub usa `insertOrIgnore` per evitare eventi Eloquent Spatie in transazione PostgreSQL

### BulkEditAction (oc:8133)
- Contratto: `new BulkEditAction(Resource::class, $fields = [], $exclude = ['name', 'geometry', 'description'])` — `$exclude` default protegge da bulk edit accidentale di nome, geometria e descrizione; override con `[]` per disabilitare
- `BelongsToMany`/`MorphToMany` implementano `ListableField`, non `RelatableField` — il filtro deve controllare entrambe le interfacce per escludere tutti i campi relazionali
- Readonly dinamico (closure): azzerare con `->readonly(false)` prima di restituire il campo — evalutare la closure su `::newModel()` (modello vuoto) darebbe sempre `true` per field tipo `getMedia()->isEmpty()`
- `showOnUpdate === false` come criterio di esclusione: corregge il mismatch tra `ActionRequest` e `UpdateRequest` in `fields()` 
- Attributi piatti: `forceFill([$attribute => $value])`. Path arrow notation (`properties->*`): merge esplicito — legge l'array corrente della colonna, `Arr::set()` sul solo path, riassegna l'intero array così i sibling non modificati restano invariati
- Nova serializza i campi `properties->*` come oggetto annidato sotto `properties` in `ActionFields` (il `Fluent::forceFill()` di Nova converte `->` in path dot e fa `Arr::set()`), NON come chiavi letterali `properties->*`. `handle()` non deve iterare le chiavi top-level di `ActionFields` (tratterebbe `properties` come unico valore e sovrascriverebbe il JSON). Usare `resolveChanges()`: cicla sui campi di `fields()` e legge ognuno con `array_key_exists` (flat) o `data_get()` con notazione dot (annidato)
- Test wm-package che girano con `php artisan test` di camminiditalia devono usare `Tests\TestCase` (non `Wm\WmPackage\Tests\TestCase`) — quest'ultima non è in `autoload-dev` di camminiditalia

### Fix esposizione assets API (oc:7913)
- `getOrDownloadIcon` usa `getMedia($type)->first()` come unica fonte di verità — `isset($app->$type)` controllava la colonna DB che su Maphub è sempre null (upload via Spatie Media Library)
- Nessun fallback sulla colonna: app che hanno solo la colonna valorizzata (senza media in Spatie) ricevono 404 — comportamento atteso e diagnosticabile
- `getCustomProperty('mime-type')` può restituire null (custom property non salvata al caricamento); il campo nativo corretto è `$mediaItem->mime_type` — fix separato tracciato in oc:8122

### Modifica ruolo utente in Nova (oc:8072)
- `RoleBooleanGroup` e `PermissionBooleanGroup` in `AbstractUserResource` usano `RolesAndPermissionsService::allowsUser()` come guard — nessuna email hardcodata
- `fillUsing()` server-side su entrambi i campi: blocca la persistenza se `allowsUser()` restituisce `false` — il guard non è solo visivo
- Anti-self-demotion: `fillUsing` di `RoleBooleanGroup` forza il ruolo Administrator nell'utente corrente se sta modificando se stesso
- Il package NON gestisce `hideFromIndex()` — ogni progetto deve farlo tramite override `fields()` in `User.php`
- `PermissionBooleanGroup` usa `dependsOn('roles')` per mostrare solo i permessi rilevanti (es. `validate %` per Validator, `manage roles and permissions` per Administrator)

### EC POI map icon display (oc:7645)
- `show_image_on_map` salvato in `properties` JSON di `EcPoi` — nessuna migration, il modello ha già il campo `properties` (jsonb)
- Nessun fallback sulla categoria POI (TaxonomyPoiType) — non esiste un caso d'uso reale per il default a livello categoria
- Il campo viene esposto solo in `RelatedEcPoiResource` (non in `EcPoiResource`) — API standalone EcPoi invariata
- `show_image_on_map` aggiunto dentro `feature_image` solo se `getMedia()->isNotEmpty()` — se l'EC non ha immagini, `feature_image` rimane null
- Nova field `->readonly()` quando il POI non ha media — evita che l'admin attivi un campo senza effetto
- Non chiamare `->toArray($request)` esplicitamente su `RelatedEcPoiResource` in `getRelatedPois()` — causa crash su `MediaResource(null)`. Mantenere il pattern `->toArray()` sulla collection

### Fix import Excel POI nomi mancanti (oc:8063)
- `EcPoiRowProcessor::apply()` deve sincronizzare `properties['name']` da `getTranslations('name')` dopo il loop — `saveQuietly()` bypassa `AbstractObserver::saving()` che normalmente fa questa sync
- La logica è duplicata tra observer e processor: tech debt noto, accettato per mantenere il fix minimale
- In update mode il merge delle traduzioni è implicito: il modello caricato dal DB porta già tutte le lingue, `setTranslation` aggiorna solo la locale fornita
- `EcTrackRowProcessor` non è affetto: non usa `setTranslation` per il nome

### ImportTaxonomyThemeJob (oc:8014)
- `Log::error()` aggiunto nel catch di `handle()` rispetto al pattern originale di `ImportTaxonomyActivityJob` — senza di esso i fallimenti appaiono come "completed" in Horizon e sono impossibili da diagnosticare
- Campo `icon` **attivo** nel config `wm-geohub-import.php`: GeoHub ha la colonna `icon` in `taxonomy_themes` con formato SVG, identico a `taxonomy_activity`. Il transformer `svgIconToNameIcon` è corretto.
- `ImportTaxonomyJob::processDependencies()` usa `syncWithoutDetaching()` (non `sync()`): `sync()` azzerava tutte le associazioni del record lasciando solo l'ultimo tema importato — bug critico confermato su app 63.
- Dopo merge: i progetti con config già pubblicato devono aggiornare manualmente `default_dependencies['app']` in `wm-geohub-import.php` oppure ri-pubblicare con `--force`
- `taxonomy_when` e `taxonomy_target` hanno lo stesso problema (`'job' => ''`) — esclusi da questo ticket, servono ticket separati

### Dipendenza visiva auth → geolocalizzazione (oc:7852)
- In detail view: `Text::make(..., fn() => ...)->asHtml()->onlyOnDetail()` mostra icona verde se `auth_show_at_startup && geolocation_record_enable`, rossa altrimenti — heroicons 24/solid `w-6 h-6`, stesso rendering dei Boolean nativi Nova
- In edit/create: `mobileAuthDependent()` mantiene il grayed-out (`->readonly(true)`) quando `auth_show_at_startup=false`; Boolean con `->onlyOnForms()` (non `hideFromIndex()`) per evitare duplicazione in detail
- `webappAuthDependent()` non creato: rimandato a quando esisterà un campo dipendente reale nel tab Webapp
- `AppConfigService` non modificato: il config generato riflette sempre il valore reale nel DB

### Fix getTaxonomyMorphableRecords (oc:8013)
- `processDependencies()` deve passare `$this->entityId` (ID GeoHub) a `getTaxonomyMorphableRecords()`, non `$model->id` (ID Maphub locale) — i due ID sono diversi e il metodo interroga il DB GeoHub

### Refactor SuperAdminService (oc:8006)
- I metodi `allows()`, `allowsUser()`, `allowsEmail()` vivono in `RolesAndPermissionsService` — punto unico per logica di autorizzazione nel package
- `SuperAdminService` è stata rimossa senza alias deprecato: breaking change da comunicare nel changelog prima di ogni rilascio
- I metodi sono statici (non DI) per coerenza con il pattern preesistente; la logica legge solo `config('wm-package.super_admin_emails')` senza stato interno

## Migration wm-package (stub obbligatori)

Valido per ogni consumer (maphub, camminiditalia, osm2cai2, ...). Overview completa: `docs/features/8218-cicd-migration-wm-package-permission-cache/overview.md`.

### Workflow

```bash
php artisan wm-package:publish-missing-migrations --dry-run
php artisan wm-package:publish-missing-migrations   # se exit 1
php artisan migrate
git add database/migrations/ && git commit
```

### Se `--dry-run` fallisce

1. Leggi stub in `database/migrations/<nome>.php.stub` (questo package)
2. Cerca migration equivalente nel consumer (per **contenuto/schema**, non nome)
3. Schema già allineato → nessuna azione
4. Gap sul DB → `publish-migration <stub>` o `publish-missing-migrations` + `migrate` + commit
5. File identico committato ma non migrato → `php artisan migrate`

### Debug: "perché esiste questo ruolo/permesso nel DB?"

Se un ruolo o permesso Spatie (es. `access-nova`, aggiunto per oc:8231/oc:8161) risulta presente nel DB e non è chiaro da dove arrivi, **verificare prima la tabella `migrations`** (`select * from migrations where migration like '%<nome>%'`, con batch e timestamp) — è quasi sempre una migration stub di questo package (`database/migrations/zz_*` nel consumer), non un side-effect di job applicativi.

`RolesAndPermissionsService::seedDatabase()` è chiamato da `GeohubImportService` **solo come fallback dentro un `if (! $role)`** — in un DB maturo con ruoli già presenti quel branch non scatta praticamente mai. Non presumere che un import GeoHub (anche periodico) sia la fonte di un ruolo/permesso nuovo senza aver letto il guard che lo circonda.

## Documentazione

La documentazione delle feature va in `docs/resources/`.

Esempi:
- `docs/resources/Analytics.md` — sistema PostHog analytics in Nova
- `docs/resources/TaxonomyWhere.md` — resource TaxonomyWhere

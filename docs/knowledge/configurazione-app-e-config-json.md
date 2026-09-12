# App, `config.json` e funzioni di prodotto

Cosa finisce nel `config.json` che il frontend consuma, e le scelte che lo governano: theme,
deep link, condivisione social, preferiti, asset.

## Stato attuale

### Il contratto di scrittura

`AppObserver::saved()` chiama `writeAppConfigOnAws()` — quindi `config()`, che concatena 12+
sezioni — **sincronamente dentro il salvataggio Nova**. Un'eccezione in una qualsiasi sezione
blocca con 500 il salvataggio di *qualsiasi* campo dell'App, non solo quello toccato: ogni lettura
di `properties->*` va difensiva (`??`, `is_array()`, `empty()`) (oc:8367).

Lo stesso observer scrive su AWS anche per un'App appena creata: nei test serve
`App::factory()->createQuietly()` (oc:7749, oc:8242).

### Theme

Lo storage resta `properties->theme->*` in **snake_case** (`primary_color`, `secondary_color`,
`tertiary_color`, `default_feature_color`, `font_family_header`, `font_family_content`); nessuna
migrazione dati. `AppConfigService::THEME_KEY_MAP` è l'unico punto che traduce verso le chiavi
camelCase attese da `ITHEME` in wm-core (`primary`, `secondary`, `tertiary`,
`defaultFeatureColor`, `fontFamilyHeader`, `fontFamilyContent`). Stesso pattern già in produzione
su geohub (`ConfTrait`).

- Nessuna chiave vuota o `null` entra nell'array `THEME`: il frontend applica i propri default.
- `config_section_theme()` valida anche il **formato** colore, non solo presenza e tipo: un
  `preg_match` sulle sole chiavi che finiscono in `_color`. I due campi font restano testo libero.
  Il gap era raggiungibile solo scrivendo direttamente su `properties->theme->*`, bypassando la
  regex di Nova.
- La regex del color picker tollera 3 o 6 cifre; il campo Nova `Color` è un `<input type="color">`
  nativo che produce sempre 6 — il ramo a 3 serve ai valori scritti via API o tinker. La notazione
  con alpha è esclusa: `theme.ts::getCSSVariables()` assume sempre un array RGB a 3 componenti.
- `hexToRgba()` va **sempre** preceduto da `sanitizeHexColor($value, $fallback)`
  (`src/helpers.php`), su qualunque valore, anche quelli "già corretti altrove" — `fill_color` e
  `stroke_color` delle `FeatureCollection` sono campi Nova di testo libero, senza validazione, e
  passano dallo stesso percorso.
- Il tab "Theme" espone oggi 4 color picker: `primary`, `secondary`, `tertiary`,
  `default_feature_color`. `success`/`warning`/`danger` sono fuori in via definitiva (oc:8367).

**Cambio visivo retroattivo, voluto**: ogni App con quei colori già impostati cambierà aspetto al
primo save dopo il deploy. Nessun comando di backfill, un re-save rigenera il `config.json`.

Restano orfane ma inerti le colonne DB `apps.primary_color`, `font_family_header`,
`font_family_content`, `default_feature_color`: non rimosse, nessun altro consumer trovato
(oc:8367).

### Deep link, QR e well-known registry

- QR e deep link su Track/Poi sono un **Nova Field** (`Text::make(...)->asHtml()`), non un'Action:
  un'Action richiederebbe di gestire due contesti di richiesta diversi e un endpoint da eseguire,
  mentre il field si autopopola al caricamento del dettaglio. Accanto al QR c'è il bottone copia
  (`App::renderDeepLinkCopyButton()`, stesso schema clipboard di
  `Layer::renderLayerWebComponentCopyButton()`).
- Bundle identifier delle entry well-known: riusa `sku` su `App`, già usato da
  `AppVersionService`.
- **Team ID Apple e fingerprint Android sono campi Nova per-app**, non env:
  `properties->apple_team_id` (default da `config('wm-package.deep_link.apple_team_id')`) e
  `properties->android_cert_sha256`, che supporta **più fingerprint separate da virgola** —
  `WellKnownRegistryService::parseFingerprints()` le splitta. Nessun override via env per questa
  feature.
- `App::getDeepLinkDomain()`: priorità `website_url` (per-app), poi
  `{app_id}.{config('app.name')}.webmapp.it`. Usa `config('app.name')`, mai un letterale, per non
  far collidere due installazioni con App `id=1` sullo stesso registry; e mai `env()` diretto nel
  modello, che si romperebbe con config caching in produzione.
- Il path scope di `apple-app-site-association` è `*`, l'intero dominio, non `/map*`.
- Il registry è aggiornato via disco SFTP dedicato (`well_known_registry`,
  `league/flysystem-sftp-v3`) con download → merge → upload, auth via password **o** chiave
  privata, mai entrambe. `env('WELLKNOWN_SFTP_PORT', 22)` restituisce una **stringa** una volta
  valorizzata in `.env` (il default int vale solo a chiave assente), e `SftpConnectionProvider`
  richiede `int`: serve il cast.
- La sync è asincrona (`SyncWellKnownRegistryJob`, 3 tentativi, backoff 30/90/300s) e il job
  prende **primitivi**, non un'istanza `App`: per l'azione `remove` da `deleting()` la riga non
  esiste più quando il job gira. `AppObserver::saved()`/`deleting()` avvolgono il dispatch in
  try/catch — è un side-effect best-effort che non deve mai far fare rollback alla transazione di
  Nova.
- Nessun locking distribuito: azione a bassa frequenza, mitigata da backup timestampato e
  validazione JSON prima dell'upload (oc:8251).

### Condivisione su Instagram/Facebook Stories

`POST /api/share-story-image` è **stateless**: cerca la `UgcTrack` per `properties->uuid`,
verifica l'ownership (403 se non è dell'utente, 404 se l'uuid non esiste) e deriva l'app da
`$ugcTrack->properties['app_id'] ?? $ugcTrack->app_id`. **Mai** da un `app_id` inviato dal client:
è ciò che elimina alla radice il rischio che un utente ottenga lo `story_frame` di un'altra app.

Il compositing sta in `StoryShareImageService` (non in `MediaService`, che ha responsabilità
ristretta a `exif()`): screenshot mappa di qualunque aspect ratio + statistiche + `story_frame` in
un'immagine 1080×1920, con le coordinate in `StoryImageLayout`. I font sono vendorizzati
(`resources/fonts/DejaVuSans{,-Bold}.ttf`) per avere lo stesso rendering a prescindere
dall'ambiente. Nessun rate-limit dedicato, solo la validazione dei 10MB sullo screenshot
(oc:8183).

### Preferiti sui layer

Trait `Favoriteable` su `Layer`, mirror di `EcTrack`; endpoint
`layer/favorite/{add,remove,toggle,list}`. Nessuno scoping `app_id`: il legame è Layer↔App, non
Layer↔Utente. Il flag `show_favorites` vive in `properties` (nessuna migration) ed è esposto come
`OPTIONS.showFavorites` in camelCase; il campo Nova sta nel tab "Frontend". `list()` usa
`MediaService::getThumbnailUrl()` (400×200), non `getFirstMediaUrl()` — pattern consolidato per le
risposte leggere (oc:8176).

### Asset e icone dell'API

- `getOrDownloadIcon()` usa `getMedia($type)->first()` come unica fonte: `isset($app->$type)`
  controlla la colonna DB, che con l'upload via Spatie è sempre `null`, e restituisce sempre
  `false` per le media collection. Nessun fallback sulla colonna — un'app con solo la colonna
  valorizzata riceve 404, comportamento atteso e diagnosticabile.
- `$mediaItem->mime_type` è il campo nativo corretto; `getCustomProperty('mime-type')` può essere
  `null` (oc:7913).
- `($disk->getConfig()['driver'] ?? 'local')`: il fake disk dei test non ha la chiave `driver`, e
  `local` è il default semanticamente giusto.
- **Naming misto, deliberato**: nel `config.json` le chiavi sono camelCase (`APP.myPaths`,
  `APP.myDownloads`), mentre media collection Spatie, route API e attributi Nova restano
  snake_case.
- `icon_notify` e `logo_homepage` hanno route e metodi controller ma **non** hanno
  `registerMediaCollections()` né campi Nova: non usarli come pattern di riferimento (oc:7480).
- Gli URL in `config.json` passano da `getFirstMediaUrl()`, non da `route()`: evita il conflitto
  fra i due gruppi `webmapp` che condividono `->name('webmapp.')` in `routes/api.php` (oc:7480).

### Altri campi App

- `AppController::layer()` ha un `if ($layer->feature_image)` sempre falso: codice vestigiale
  copiato dal pattern `EcTrack`, `Layer` non ha quell'attributo (oc:8272).
- `App::author()` è un `belongsTo(User::class)` che inferirebbe `author_id`, ma la colonna è
  `user_id`: la FK esplicita è obbligatoria (oc:7749, oc:8242).
- La dipendenza visiva auth → geolocalizzazione in Nova: in detail un `Text` `onlyOnDetail()`
  `asHtml()` mostra un'icona verde o rossa; in edit `mobileAuthDependent()` tiene il grayed-out, e
  il Boolean usa `->onlyOnForms()` (non `hideFromIndex()`) per non duplicarsi in detail.
  `AppConfigService` non è toccato: il config riflette sempre il valore reale nel DB (oc:7852).

## Come ci siamo arrivati

- `config_section_map()` leggeva `$this->app->primary_color`, una colonna DB reale mai scritta da
  Nova: restava sempre al default `#de1b0d` della migration, indipendentemente dal colore scelto
  dall'admin. Corretto leggendo `properties['theme']['primary_color']` con `sanitizeHexColor()`,
  stesso pattern già in `StoryShareImageService::resolveAccentColor()` (oc:8367).
- Il primo fix validava solo `$primaryColor` prima di `hexToRgba()`, lasciando scoperti
  `fill_color`/`stroke_color` sullo stesso percorso — che è raggiungibile anche da un endpoint
  pubblico non autenticato, `AppController::baseConfig()`: un valore malformato in una
  `FeatureCollection` produce un 500 continuo, non un rischio puntuale al save. Da lì
  l'estrazione di `sanitizeHexColor()` come helper globale (oc:8367).
- Il conteggio dei consumatori CSS reali su `webmapp-app` è stato misurato, non dedotto:
  `primary` ~64 usi, `secondary` 2, `tertiary` 0, `success`/`warning` 0, `danger` 4 (solo
  semantici), `defaultFeatureColor` ignorato da `map-core`. Su quel dato sono stati rimossi tutti
  e sei; poi `secondary`, `tertiary` e `default_feature_color` sono stati **ripristinati** per
  decisione esplicita del dev. Il consumo CSS reale non è stato il criterio decisivo finale su
  cosa esporre in Nova. Se in futuro servisse più spazio per il brand, la scelta corretta sarebbe
  `light`/`dark` (50/63 consumatori), non riciclare ruoli semantici (oc:8367).
- `FRONTEND_URL` (env, per l'intera installazione) è stato rimosso: ridondante con `website_url`,
  già per-app (oc:8251).
- Una prima versione di `ShareStoryImageController` derivava l'app da una relazione
  `User belongsTo App` via `users.app_id` — nel farlo si è scoperto che quella colonna non viene
  mai popolata dal flusso di signup live. Bug preesistente e reale, segnalato come indipendente,
  non risolto (oc:8183).
- `EcPoi` non aveva la relazione `app()`, a differenza di `EcTrack`. È stata aggiunta con il nome
  completo `\Wm\WmPackage\Models\App::class` — non è stile: un "optimize imports" automatico l'ha
  già semplificata una volta in `App::class`, che risolve silenziosamente alla Facade e rompe la
  relazione a runtime. Guardato da un test dedicato (oc:8251).

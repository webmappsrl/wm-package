# Link e QR code verso l'app

Come un link o un QR code porta l'utente nell'app nativa, e perché così: i deep link su Track e
Poi (oc:8251) e il link unico dell'App che manda allo store, all'app o alla webapp a seconda del
dispositivo (oc:8803).

## Come funziona oggi

### Link unico dell'App: `/app/{id}/open`

Ogni App con almeno un link a uno store (`ios_store_link`, `android_store_link`) ha un link
pubblico `GET /app/{id}/open` (route `wm.app-open`, `AppOpenController`), mostrato con il suo QR
nel tab **Release** di Nova App (campo `app_open_qr_code`, solo nel dettaglio, nessun toggle). Il
QR si scarica in SVG e in PNG da almeno `App::APP_OPEN_QR_PNG_MIN_SIZE` px, generati al volo e mai
salvati (`renderAppOpenQrCode*()`).

Cosa riceve chi apre il link, in base allo user agent (`AppOpenService::detectPlatform()`):

- **iOS** → App Store, che mostra «Apri» se l'app è già installata;
- **Android** → l'app, se installata, altrimenti Google Play, con i pulsanti «Apri l'app» e
  «Google Play» visibili come via d'uscita;
- **qualsiasi altro dispositivo** (desktop, user agent vuoto) → la webapp dell'App
  (`App::getWebappUrl()`), tranne un iPad che si presenta come Mac, che va all'App Store;
- **iOS o Android senza il link del proprio store** → pagina con gli store presenti e «Non ancora
  disponibile per …»;
- **App senza nessun link** → pagina di indisponibilità; **id inesistente** → 404.

Il dettaglio di ogni ramo è in `AppOpenController::__invoke()`; qui sotto i vincoli che il codice
non dice da sé.

Vincoli:

- **La route sta in `routes/web.php` con `->withoutMiddleware('web')`**: niente sessione, cookie
  né CSRF. Laravel espande i nomi di gruppo anche fra i middleware esclusi
  (`Router::resolveMiddleware()`); un test verifica che non esca il cookie di sessione.
- Ogni risposta, 302 compreso, porta `Cache-Control: private, no-store` e `Vary: User-Agent`.
- **Il package Android** si prende dal parametro `id=` di `android_store_link`, con `sku` come
  ripiego, ed è valido solo se `^[A-Za-z0-9_.]+$`: finisce dentro l'`intent://`, dove un `;`
  aggiungerebbe parametri. Se non è valido, la pagina Android mostra solo il pulsante Google Play.
- **Lingua della pagina**: `getPreferredLanguage(['en','it','fr','es','de'])`. Symfony ripiega
  sulla **prima** lingua dell'elenco sia senza header sia con lingue non supportate, mai su
  `null`: l'inglese sta in testa per questo.
- L'URL nel QR esce da `route()`, quindi dal dominio da cui si apre Nova: sotto il QR c'è l'URL in
  chiaro, uguale al contenuto del QR.

### Deep link su Track e Poi, well-known registry

- QR e deep link su Track/Poi sono un **Nova Field** (`Text::make(...)->asHtml()`), non un'Action:
  un'Action richiederebbe di gestire due contesti di richiesta diversi e un endpoint da eseguire,
  mentre il field si autopopola al caricamento del dettaglio. Accanto al QR c'è il bottone copia
  (`App::renderCopyLinkButton()`, stesso schema clipboard di
  `Layer::renderLayerWebComponentCopyButton()`), e il riquadro è `App::renderQrCodeCard()`, in
  comune con il QR dell'App (oc:8803).
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

## Perché così

- **Redirect nel backend, non nella webapp né in Apache** (oc:8803): un URL stampato è un
  contratto permanente, quindi sta in codice testato; i link agli store si leggono dall'App e si
  cambiano da Nova; vale per ogni shard con il solo bump del package. Le regole `mod_rewrite`
  avrebbero duplicato i link nel server, senza test e senza le pagine che servono ad Android e
  all'iPad.
- **Su Android una pagina, non un 302 all'`intent://`** (oc:8803): Chrome ignora un `intent://`
  arrivato da un redirect senza un tocco dell'utente, e i browser interni (Instagram, Telegram,
  lettori di QR in WebView) non lo capiscono affatto: il timeout verso Google Play e i pulsanti
  sono la via d'uscita.
- **L'`intent://` punta al dominio dei deep link** (oc:8803): Chrome aggiunge sempre
  `category.BROWSABLE`, e l'activity di avvio di un'app Capacitor ha solo `MAIN/LAUNCHER`; il
  filtro `BROWSABLE` dei deep link di oc:8251 invece c'è. **Verificato su Cammini d'Italia**
  (prova del dev del 21/10/2026, app installata dal Play Store): con l'app installata, Android la
  apre direttamente.
- **Desktop verso la webapp attraverso una pagina, non con un 302** (oc:8803): Safari su iPadOS si
  presenta come Mac e solo lo script lo distingue dal touch.
- **Su iOS l'app non si apre direttamente** (oc:8803): servirebbe un universal link su un dominio
  dichiarato negli associated domains della build, quindi una nuova release. L'App Store con
  «Apri» è il massimo senza toccare l'app. I QR già stampati diventerebbero «apertura diretta»
  anche su iOS senza ristamparli, a patto che una release futura dichiari il dominio del backend
  negli associated domains e gestisca il percorso `/app/{id}/open`.

## Come ci siamo arrivati

- **`intent://#Intent;package=…;end` con il solo package** (oc:8803, scartato in challenge):
  senza scheme e host non colpisce nessun filtro `BROWSABLE`, e Chrome andrebbe sempre al
  ripiego anche con l'app installata.
- **Desktop verso una pagina con i due pulsanti degli store** (oc:8803, superato): cambiato
  dopo la prova sui dispositivi, perché dal desktop serve la webapp.
- **Percorso `/app/{id}/download` e un file di route a sé** registrato fuori dal gruppo `web`
  (oc:8803, superati prima del rilascio): dal desktop non si scarica nulla, e la route è
  passata in `web.php` con `withoutMiddleware('web')`.
- **Ripiego della lingua con `?? 'en'`** (oc:8803, corretto in review): non scattava mai, e un
  browser in giapponese vedeva la pagina in italiano.
- `FRONTEND_URL` (env, per l'intera installazione) è stato rimosso: ridondante con `website_url`,
  già per-app (oc:8251).
- `EcPoi` non aveva la relazione `app()`, a differenza di `EcTrack`: aggiunta con `App::class`,
  che nel namespace `Wm\WmPackage\Models` risolve al modello. Un
  `use Illuminate\Support\Facades\App;` aggiunto da un «optimize imports» la farebbe risolvere
  alla Facade e romperebbe la relazione a runtime: lo intercetta un test dedicato (oc:8251).

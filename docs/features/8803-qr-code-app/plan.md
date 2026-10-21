> Ticket: oc:8803

# QR Code App — Piano di implementazione

> **Per chi esegue:** usare superpowers:subagent-driven-development oppure
> superpowers:executing-plans, un task alla volta. I passi usano le checkbox (`- [ ]`).
> **Nessun `git commit`/`git add`/`git push` durante l'esecuzione**: i passi «Commit» sono
> istruzioni per il dev, da eseguire solo dopo la review-gate.

**Obiettivo:** un link unico per App (`/app/{id}/download`) con il suo QR code, che porta all'App
Store su iOS, tenta di aprire l'app su Android con Google Play come ripiego, e mostra una pagina con
i pulsanti degli store in tutti gli altri casi; QR scaricabile in SVG e PNG dal tab Release di Nova.

**Architettura:** un service senza stato (`AppDownloadService`) decide piattaforma, package
Android e URL `intent://`; un controller invocabile, su una route registrata **senza** il gruppo
`web`, risponde con un 302 o con una vista Blade; il modello `App` costruisce l'URL di download e
il markup Nova del QR riusando lo stile di `renderDeepLinkQrCodeHtml()`.

**Tech stack:** Laravel, Nova 5, `chillerlan/php-qrcode` 5.0.5 (`QRMarkupSVG`, `QRGdImagePNG`; GD
presente nel container), PHPUnit-style test eseguiti con Pest.

**Spec:** [overview.md](overview.md)

## Vincoli globali

- Tutto il codice in `wm-package`; in camminiditalia solo il gitlink e `CLAUDE.md`.
- Route `GET /app/{app}/download`, nome `wm.app-download`, `{app}` = id numerico, **senza**
  middleware `web` (niente sessione, cookie, CSRF).
- Ogni risposta della route: `Cache-Control: private, no-store` e `Vary: User-Agent`.
- Timeout Android: **1500 ms**.
- Package Android: parametro `id=` di `android_store_link`, ripiego `sku`, valido solo se
  `^[A-Za-z0-9_.]+$`.
- PNG almeno **1024×1024**; SVG e PNG generati al volo, mai salvati su storage né su `qr_code`.
- QR visibile in Nova appena c'è almeno un link agli store, **senza toggle**.
- Traduzioni in `wm-package/resources/lang/{it,en,fr,es,de}.json`, chiave in inglese.
- Commenti e docblock in italiano (regola del package).
- **Nei test nessuna App con `native_app_deep_link_enabled = true`**: scriverebbe sul registro
  SFTP condiviso (regola in cima a `wm-package/CLAUDE.md`). `getDeepLinkDomain()` non dipende dal
  toggle, quindi non serve accenderlo.

Comando test (dalla root di camminiditalia):

```bash
docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia \
  -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest <percorso-test>
```

## Punti da tenere d'occhio in review

1. **User agent vuoto o assente** (crawler, `curl`): deve finire sulla pagina con i pulsanti, mai
   in errore → test in Task 1 e Task 2.
2. **`android_store_link` senza `id=`, o con `id` non valido, e `sku` con caratteri strani**
   (`it.app;S.x=y`): niente intent, solo il pulsante Google Play → test in Task 1.
3. **Link agli store con caratteri da escapare** (`&`, `"`): nella vista e nell'attributo
   `href` vanno escapati, nell'intent `S.browser_fallback_url` va codificato con `rawurlencode`
   → test in Task 1 e Task 2.
4. **iPad che si presenta come Mac**: la pagina con i pulsanti contiene lo script
   `navigator.maxTouchPoints` solo se c'è `ios_store_link` → test in Task 2.
5. **App con uno solo dei due link** inquadrata dal dispositivo «sbagliato»: pagina con il solo
   pulsante disponibile e il messaggio «non ancora disponibile» → test in Task 2.

---

### Task 1: `AppDownloadService` — piattaforma, package Android, intent

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#decisioni)

**File:**
- Create: `wm-package/src/Services/AppDownloadService.php`
- Test: `wm-package/tests/Unit/Services/AppDownloadServiceTest.php`

**Interfacce:**
- Produce:
  - `AppDownloadService::detectPlatform(?string $userAgent): string` → `'ios'` (contiene
    `iPhone`, `iPad` o `iPod`), `'android'` (contiene `Android`), altrimenti `'other'`
    (anche per `null`/stringa vuota)
  - `AppDownloadService::androidPackage(App $app): ?string` → `id` dalla query di
    `android_store_link`, altrimenti `sku`; `null` se il valore scelto non rispetta
    `^[A-Za-z0-9_.]+$`
  - `AppDownloadService::androidIntentUrl(App $app): ?string` →
    `intent://{getDeepLinkDomain()}/#Intent;scheme=https;package={package};S.browser_fallback_url={rawurlencode(android_store_link)};end`;
    `null` se manca `android_store_link` o il package

- [ ] **Passo 1: scrivere i test che falliscono** (`App::factory()->make([...])`, nessun salvataggio
  necessario; `config(['app.name' => 'test_app'])` nel `setUp`)
  - `detects_ios_for_iphone_ipad_and_ipod` — tre UA reali → `'ios'`
  - `detects_android` — UA di Chrome Android → `'android'`
  - `falls_back_to_other_for_desktop_empty_and_null` — UA di Chrome su Windows, `''`, `null` → `'other'`
  - `android_package_comes_from_store_link_id` — `android_store_link =
    'https://play.google.com/store/apps/details?id=it.webmapp.cammini&hl=it'`, `sku = 'altro.sku'`
    → `'it.webmapp.cammini'`
  - `android_package_falls_back_to_sku` — link senza `id=`, `sku = 'it.webmapp.x'` → `'it.webmapp.x'`
  - `android_package_is_null_when_invalid` — `sku = 'it.app;S.x=y'`, link senza `id` → `null`
  - `intent_url_targets_deep_link_domain_with_encoded_fallback` — `id=1`, `website_url = null`,
    link `https://play.google.com/store/apps/details?id=it.webmapp.cammini` →
    `'intent://1.test_app.webmapp.it/#Intent;scheme=https;package=it.webmapp.cammini;S.browser_fallback_url=https%3A%2F%2Fplay.google.com%2Fstore%2Fapps%2Fdetails%3Fid%3Dit.webmapp.cammini;end'`
  - `intent_url_is_null_without_android_link` — `android_store_link = null` → `null`

- [ ] **Passo 2: eseguire e verificare che falliscano** — `tests/Unit/Services/AppDownloadServiceTest.php`,
  atteso: FAIL, classe non trovata.

- [ ] **Passo 3: implementare `AppDownloadService`** — classe semplice (`new` o container), metodi
  sopra; `parse_url` + `parse_str` per l'`id`.

- [ ] **Passo 4: eseguire i test** — atteso: PASS.

- [ ] **Passo 5: commit (istruzione per il dev)** —
  `feat(oc:8803): servizio per piattaforma e intent del download app`

---

### Task 2: route senza sessione, controller e vista

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-route-senza-sessione-controller-e-vista)

**File:**
- Create: `wm-package/routes/app-download.php`
- Modify: `wm-package/src/WmPackageServiceProvider.php:150-151` (registrare il nuovo file accanto
  a `routes/web.php`, **senza** `middleware('web')`)
- Create: `wm-package/src/Http/Controllers/AppDownloadController.php`
- Create: `wm-package/resources/views/app-download.blade.php`
- Test: `wm-package/tests/Feature/AppDownloadControllerTest.php`

**Interfacce:**
- Consuma: `AppDownloadService` (Task 1)
- Produce: route `wm.app-download` (`GET /app/{app}/download`),
  `AppDownloadController::__invoke(Request $request, int $app): Response`

Comportamento (`App::find($app)`; inesistente → `abort(404)`):

| Caso | Risposta |
|---|---|
| nessuno dei due link | 200, vista `mode = 'unavailable'` |
| `ios` + `ios_store_link` | 302 a `ios_store_link` |
| `android` + `android_store_link` | 200, vista `mode = 'android'` con `intentUrl` (può essere `null`) |
| ogni altro caso | 200, vista `mode = 'choice'` |

Tutte le risposte (302 e 200) con `Cache-Control: private, no-store` e `Vary: User-Agent`.

Vista unica `app-download.blade.php`, HTML autonomo (nessun layout Nova), `lang` dalla locale
dell'header `Accept-Language` se è fra le 5 lingue, altrimenti `en`; titolo = nome dell'App
(`getStringName()` o `name`, quello che restituisce una stringa); CSS inline minimo, leggibile a
360 px. Contenuti per `mode`:
- `android`: se `intentUrl` non è `null`, script che al caricamento fa
  `window.location.href = intentUrl` e dopo 1500 ms, se `document.visibilityState === 'visible'`,
  `window.location.replace(android_store_link)`; pulsante «Open the app» (`href = intentUrl`) e
  pulsante «Google Play»; se `intentUrl` è `null`, solo il pulsante Google Play, nessuno script.
- `choice`: un pulsante per ogni link presente («App Store», «Google Play»); se la piattaforma
  rilevata è `ios`/`android` e manca il suo link, il testo «Not yet available for :platform»; se
  `ios_store_link` c'è, script che, se lo UA contiene `Macintosh` e `navigator.maxTouchPoints > 1`,
  fa `window.location.replace(ios_store_link)`.
- `unavailable`: «The app is not currently available on the stores, please try again later.»

URL nei `href` escapati da Blade (`{{ }}`); negli script passati con `@json`.

- [ ] **Passo 1: scrivere i test che falliscono** (App create con `App::factory()->create([...])`,
  toggle deep link **spento**, `Storage::fake` come in `AppDeepLinkUrlTest`)
  - `ios_is_redirected_to_app_store` — UA iPhone → 302, `Location` = `ios_store_link`
  - `android_gets_page_with_intent_and_fallback` — UA Android → 200, il contenuto contiene l'intent
    del Task 1 (escapato JSON), `1500` e il link Google Play
  - `android_with_invalid_package_shows_only_play_button` — `sku = 'it.app;S.x=y'`, link senza `id`
    → 200, nessun `intent://` nel contenuto, link Google Play presente
  - `desktop_gets_choice_page_with_both_buttons` — UA Windows → 200, entrambi i link, script
    `maxTouchPoints` presente
  - `empty_user_agent_gets_choice_page` — header `User-Agent: ''` → 200, pagina `choice`
  - `iphone_without_ios_link_sees_not_available_message` — solo `android_store_link`, UA iPhone →
    200, testo «Not yet available for iOS», nessuno script `maxTouchPoints`
  - `app_without_store_links_shows_unavailable_page` — entrambi `null` → 200, testo di indisponibilità
  - `unknown_app_returns_404` — id inesistente → 404
  - `responses_have_no_cache_headers_and_no_session_cookie` — sia sul 302 sia sul 200:
    `Cache-Control` contiene `no-store` e `private`, `Vary` contiene `User-Agent`,
    `assertCookieMissing(config('session.cookie'))`
  - `store_link_with_ampersand_is_escaped_in_href` — `ios_store_link` con `&` → nella pagina
    `choice` compare `&amp;`

- [ ] **Passo 2: eseguire e verificare che falliscano** — atteso: FAIL, route inesistente (404).

- [ ] **Passo 3: implementare** route, registrazione nel service provider, controller e vista.

- [ ] **Passo 4: eseguire i test** — atteso: PASS. Verificare a mano che `php artisan route:list
  --name=wm.app-download` nel container `laravel-camminiditalia` mostri la route **senza**
  middleware `web`.

- [ ] **Passo 5: commit (istruzione per il dev)** —
  `feat(oc:8803): route pubblica di download app con redirect per dispositivo`

---

### Task 3: URL di download e QR sul modello `App`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#decisioni)

**File:**
- Modify: `wm-package/src/Models/App.php` (dopo `renderDeepLinkQrCodeHtml()`, ~riga 650)
- Test: `wm-package/tests/Unit/Models/AppDownloadQrCodeTest.php`

**Interfacce:**
- Consuma: route `wm.app-download` (Task 2)
- Produce:
  - `App::hasStoreLinks(): bool` — almeno uno fra `android_store_link` e `ios_store_link` non vuoto
  - `App::getAppDownloadUrl(): string` — `route('wm.app-download', ['app' => $this->id])` assoluto
  - `App::renderAppDownloadQrCodeSvg(): string` — SVG grezzo (`outputBase64 = false`)
  - `App::renderAppDownloadQrCodePng(): string` — PNG binario, lato ≥ 1024 px
    (`QRGdImagePNG`, `scale` calcolato dal numero di moduli)
  - `App::renderAppDownloadQrCodeHtml(): ?string` — `null` se `! hasStoreLinks()`; altrimenti
    stesso box di `renderDeepLinkQrCodeHtml()` con anteprima SVG, input readonly con l'URL,
    pulsante copia (`renderDeepLinkCopyButton()`), due link `download` a data URI:
    «Download SVG» (`{sku}-download-qrcode.svg`) e «Download PNG» (`{sku}-download-qrcode.png`)

- [ ] **Passo 1: scrivere i test che falliscono**
  - `has_store_links_is_true_with_only_one_link` / `has_store_links_is_false_without_links`
  - `download_url_points_to_named_route` — termina con `/app/{id}/download`
  - `svg_encodes_download_url` — inizia con `<?xml` o contiene `<svg`
  - `png_is_at_least_1024_pixels` — `getimagesizefromstring()` → larghezza e altezza ≥ 1024, mime
    `image/png`
  - `html_is_null_without_store_links`
  - `html_contains_url_and_both_downloads` — contiene l'URL escapato, `data:image/svg+xml;base64,`,
    `data:image/png;base64,` e i due nomi file

- [ ] **Passo 2: eseguire e verificare che falliscano.**

- [ ] **Passo 3: implementare i metodi.** L'HTML condivide il markup con
  `renderDeepLinkQrCodeHtml()` solo se l'estrazione è banale; altrimenti si copia il box, senza
  toccare il metodo esistente.

- [ ] **Passo 4: eseguire i test del task e `tests/Unit/Models/AppDeepLinkUrlTest.php`** — atteso:
  PASS su entrambi (nessuna regressione sul QR dei deep link).

- [ ] **Passo 5: commit (istruzione per il dev)** —
  `feat(oc:8803): QR code di download app in SVG e PNG`

---

### Task 4: campo nel tab Release e traduzioni

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#decisioni)

**File:**
- Modify: `wm-package/src/Nova/App.php:864-869` (subito dopo `ios_store_link`)
- Modify: `wm-package/resources/lang/{it,en,fr,es,de}.json`
- Test: `wm-package/tests/Unit/Nova/AppDownloadQrFieldTest.php`

**Interfacce:**
- Consuma: `App::hasStoreLinks()`, `App::renderAppDownloadQrCodeHtml()` (Task 3)

- [ ] **Passo 1: scrivere il test che fallisce** — istanzia la risorsa `Wm\WmPackage\Nova\App` su
  un'App con un link, chiama `app_release_data_tab()` tramite reflection e verifica che esista un
  campo con attributo `app_download_qr_code`, `asHtml`, solo nel dettaglio.

- [ ] **Passo 2: eseguire e verificare che fallisca.**

- [ ] **Passo 3: aggiungere il campo**

  `Text::make(__('App download QR code'), 'app_download_qr_code', fn () => $this->resource->renderAppDownloadQrCodeHtml())`
  con `->asHtml()->onlyOnDetail()->canSee(fn () => $this->resource->hasStoreLinks())` e help
  `__('Single link and QR code to download the app: App Store on iOS, the app or Google Play on Android, both stores elsewhere.')`.

- [ ] **Passo 4: aggiungere le chiavi nei 5 json** — testo base inglese = chiave; italiano:

  | Chiave | it |
  |---|---|
  | `App download QR code` | QR code download app |
  | `Single link and QR code to download the app: App Store on iOS, the app or Google Play on Android, both stores elsewhere.` | Link e QR code unici per scaricare l'app: App Store su iOS, l'app o Google Play su Android, entrambi gli store altrove. |
  | `Download SVG` | Scarica SVG |
  | `Download PNG` | Scarica PNG |
  | `Open the app` | Apri l'app |
  | `Download the app` | Scarica l'app |
  | `Not yet available for :platform` | Non ancora disponibile per :platform |
  | `The app is not currently available on the stores, please try again later.` | L'app non è al momento disponibile sugli store, riprova più tardi. |

  fr, es, de tradotti di conseguenza; `App Store` e `Google Play` restano nomi propri, non chiavi.
  Verificare che nessuna chiave esista già con un testo diverso (`grep` sui json).

- [ ] **Passo 5: eseguire il test del task e quelli dei Task 1-3** — atteso: PASS. Poi
  `composer analyse` (PHPStan) nel container: nessun errore nuovo sui file toccati.

- [ ] **Passo 6: verifica manuale in Nova** — aprire il dettaglio dell'App 1 su camminiditalia:
  tab Release con anteprima, URL, copia, due download; aprire il PNG e controllare che sia nitido.

- [ ] **Passo 7: commit (istruzione per il dev)** —
  `feat(oc:8803): QR code di download nel tab Release di Nova App`

---

### Task 5: prova su dispositivi veri e chiusura

- [ ] **Passo 1: prova obbligatoria** (dal rischio dell'overview), con il link di un ambiente
  raggiungibile dal telefono:
  - iPhone, fotocamera: App Store, con «Apri» se l'app è installata;
  - Android con l'app installata, da Chrome: l'app si apre, oppure Google Play con «Apri» dopo
    1,5 s (esito accettabile, da annotare in `notes.md` perché dice se la build dichiara il filtro
    `BROWSABLE`);
  - Android senza app, da Chrome e da un lettore QR di terze parti: Google Play;
  - desktop: pagina con i due pulsanti.
  Annotare ogni esito in `notes.md`.

- [ ] **Passo 2: camminiditalia** — bump del gitlink `wm-package` (dopo il merge nel package,
  regola «una modifica che attraversa package e consumer si merge prima qui») e riga in
  `CLAUDE.md` di camminiditalia (`## Feature disponibili`).

- [ ] **Passo 3: commit (istruzione per il dev)** — in camminiditalia
  `chore(oc:8803): gitlink wm-package al QR code di download app`.

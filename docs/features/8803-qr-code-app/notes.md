> Ticket: oc:8803

# Notes — QR Code App

## Divergenze dal piano, task per task

### Task 2 route senza sessione controller e vista

Percorso, file della route e nomi delle classi sono cambiati dopo l'approvazione: vedi in
«Decisioni» le voci «Percorso `/app/{app}/open`» e «La route sta in `routes/web.php`».

Dopo la prova sui dispositivi il dev ha cambiato il comportamento sui dispositivi che non sono
né iOS né Android (desktop, user agent vuoto o sconosciuto): **si apre la webapp**, non più la
pagina con i due pulsanti. L'indirizzo è generico per ogni shard, `App::getWebappUrl()` =
`https://` + `getDeepLinkDomain()` (`website_url`, altrimenti `{id}.{APP_NAME}.webmapp.it`).

Non è un 302 ma una pagina (`mode = 'webapp'`) che reindirizza subito con `location.replace`,
perché Safari su iPadOS si presenta come Mac e solo lo script lo distingue
(`navigator.maxTouchPoints > 1`): se c'è `ios_store_link`, l'iPad va all'App Store. Senza
JavaScript resta visibile il pulsante «Apri la webapp».

Di conseguenza la pagina con i pulsanti (`mode = 'choice'`) resta solo per iOS o Android senza
il link del proprio store, e lo script dell'iPad si è spostato da `choice` a `webapp`. Nuova
chiave di traduzione `Open the web app`; il testo di aiuto del campo Nova dice ora «la webapp
altrove» invece di «entrambi gli store altrove».

## Bug trovati

- **`QROptions::$outputInterface` in php-qrcode 5.0.5 vale solo con `outputType = CUSTOM`**
  (`QRCode.php:296-299`): impostato da solo, l'output resta SVG. `CUSTOM` (come le altre
  costanti di `outputType`) è deprecato «senza sostituto» (chillerlan/php-qrcode#223): la riga
  ha un `@phpstan-ignore classConstant.deprecated` con il motivo.
- Assegnare le proprietà di `QROptions` una per una fa segnalare a PHPStan l'accesso a proprietà
  protette (sono magiche): le opzioni nuove si passano come array al costruttore.

## Decisioni

- **Desktop → webapp invece della pagina con i pulsanti**: modifica chiesta dal dev dopo
  l'approvazione del piano, a prova sui dispositivi riuscita. Dettaglio nella divergenza del
  Task 2.
- Lingua della pagina pubblica: `Request::getPreferredLanguage(['en','it','fr','es','de'])`.
  Symfony ripiega sulla **prima** lingua dell'elenco sia senza header sia quando nessuna lingua
  del browser è supportata, mai su `null`: per questo l'inglese è in testa. La prima stesura
  aveva `it` in testa e un `?? 'en'` che non scattava mai (trovato in review, vedi sotto).
- **Percorso `/app/{app}/open` (route `wm.app-open`, metodo `App::getAppOpenUrl()`)** invece di
  `/app/{app}/download`, su richiesta del dev dopo l'approvazione del piano: dal desktop non si
  scarica nulla, si apre la webapp. Scelto `open` invece di `/app/{app}` per non occupare la
  radice dell'App in un package condiviso. Sempre su richiesta del dev, anche il codice segue il
  percorso: `AppOpenController`, `AppOpenService`, vista `app-open`, `renderAppOpenQrCode*`,
  attributo Nova `app_open_qr_code`, file scaricati `{sku}-qrcode.svg|png`, test `AppOpen*Test`
  (nel piano compaiono ancora i nomi `AppDownload*` della prima stesura). I testi per il
  cliente («QR code download app») restano: dicono a cosa serve il QR.
- **La route sta in `routes/web.php` con `->withoutMiddleware('web')`**, su richiesta del dev,
  invece del file separato `routes/app-download.php` registrato fuori dal gruppo nel service
  provider (prima stesura). Laravel espande i nomi di gruppo anche fra i middleware esclusi
  (`Router::resolveMiddleware()`), quindi la route resta senza sessione: verificato con
  `route:list` e con il test che controlla l'assenza del cookie di sessione.
- Nella risorsa Nova il modello si legge con un helper privato `appModel()` (tipizzato
  `ModelsApp`) perché `$this->resource` è tipizzato `Model` e PHPStan non vede i metodi nuovi.
- PHPStan del repo principale (camminiditalia) ha 13 errori preesistenti in 5 file di test,
  nessuno toccato da questa feature; la prima esecuzione si era fermata sulla race condition
  della cache dei worker paralleli (nota di oc:8312), rieseguita con `--debug`.

- **PHPStan del package** sui file toccati (`AppOpenService`, `AppOpenController`, `Models/App.php`,
  `Nova/App.php`): nessun errore sulle righe nuove; restano quelli preesistenti dei due file
  modificati.

## Review formale (wm-review-ticket, 21/10/2026)

Un bloccante e quattro cleanup, corretti prima del commit:

- **Lingua di ripiego** (bloccante): un browser in una lingua non supportata (es. `ja`) vedeva la
  pagina in italiano. Corretto mettendo `en` in testa; test
  `unsupported_browser_language_falls_back_to_english` e `supported_browser_language_is_used`.
- **Id oltre `PHP_INT_MAX`** (`/app/99999999999999999999/open`) dava 500: prima per la firma
  `int`, poi per Postgres (`out of range for type bigint`). Ora il parametro è `string` e l'id
  passa da `filter_var(FILTER_VALIDATE_INT)` prima della query; test
  `app_id_beyond_integer_range_returns_404`.
- **Box QR duplicato**: il QR dei deep link e quello dell'App condividono ora `renderQrSvg()`,
  `renderQrCodeCard()` e la costante `QR_BUTTON_STYLE`; `renderDeepLinkCopyButton()` è diventato
  `renderCopyLinkButton()`. L'HTML del QR dei deep link è rimasto identico byte per byte (md5
  confrontato prima e dopo, su un'App non salvata per non toccare il registro SFTP).
- **Stringhe e numeri senza nome**: i modi della pagina sono `AppOpenService::MODE_*`, il ritardo
  Android è `AppOpenService::ANDROID_FALLBACK_DELAY_MS`, il lato del PNG è
  `App::APP_OPEN_QR_PNG_MIN_SIZE`; la vista usa le costanti e il controller `hasStoreLinks()`.
- **Nomi e commenti** senza più «download» nel codice; il test sull'SVG verifica ora l'URL
  codificato; senza `sku` i file scaricati si chiamano `app-{id}-qrcode.*`.
- Aggiunto il test, mancante, sul messaggio «Not yet available for Android».

Lasciato com'è: il PNG da 1024 px generato a ogni apertura del dettaglio App in Nova (decine di
KB in base64). Generarlo su richiesta vorrebbe un endpoint autenticato in più, sproporzionato per
una pagina aperta di rado.

## Prova sui dispositivi

Eseguita dal dev in locale (Nova aperto dall'IP del Mac sulla stessa Wi-Fi), con le app vere
installate dagli store.

- **Android con l'app installata: l'app si apre direttamente.** La build attuale dichiara quindi
  un intent-filter `BROWSABLE` per il dominio dei deep link (`1.camminiditalia.webmapp.it`), e
  l'`intent://` costruito su quel dominio lo colpisce: il ripiego su Google Play resta per chi
  non ha l'app.
- Gli altri casi (iPhone con e senza app, Android senza app, desktop): «funziona», senza dettagli
  caso per caso. Non è stato provato esplicitamente un lettore di QR di terze parti in WebView.

## Follow-up

- La nota sul ticket scritta all'inizio dice che il QR «apre l'app se installata» senza
  distinguere: va precisato che vale solo per Android (su iOS si arriva all'App Store, che mostra
  «Apri»).
- Apertura diretta su iOS: richiede una release dell'app con il dominio del backend negli
  associated domains (fuori scope, vedi overview).

> Ticket: oc:8803

> ⚠️ Dopo l'approvazione sono cambiati il percorso (`/app/{id}/open`), il comportamento sul
> desktop (webapp) e il file della route: lo stato finale è in [notes.md](notes.md#decisioni).

# QR Code App

## Cosa cambia

Ogni App ha un **link unico per scaricare l'app**, `https://<dominio-backend>/app/{id}/download`,
con il suo QR code. Il backend guarda il dispositivo di chi apre il link e reindirizza:

- **iPhone / iPad (iOS)** → pagina dell'App Store (`ios_store_link`). Se l'app è già installata,
  è l'App Store stesso a mostrare «Apri»;
- **Android** → una piccola pagina che al caricamento tenta di aprire l'app con `intent://` sul
  dominio dei deep link; se dopo circa 1,5 secondi l'app non si è aperta, va da sola su Google
  Play (`android_store_link`). Mostra comunque i pulsanti «Apri l'app» e «Google Play»;
- **qualunque altro dispositivo** (desktop, sistemi diversi) → una pagina minima del backend con
  il nome dell'app e i pulsanti «App Store» e «Google Play», tradotta nella lingua del browser.

Nella scheda **Release** della risorsa Nova App, accanto ai link agli store, compaiono il link
copiabile, l'anteprima del QR e due pulsanti per scaricarlo in **SVG** e in **PNG** ad alta
risoluzione.

Tutto il lavoro sta in `wm-package` e vale per ogni App Webmapp. In camminiditalia cambia solo il
puntatore del submodule.

## Perché

Il cliente di Cammini d'Italia chiede un QR code unico da distribuire, che porti all'App Store o a
Google Play a seconda del dispositivo che lo inquadra. Oggi l'App ha solo i due link agli store,
separati, e il QR esistente (oc:8251) apre un singolo Track o Poi tramite deep link.

Vincolo deciso nel dialogo: **solo backend, nessuna nuova release dell'app**. Per questo su iOS
l'app non si apre direttamente: servirebbe un universal link su un dominio dichiarato nella build
dell'app. Su Android lo fa `intent://`, che non chiede nulla all'app.

## Requisiti

- [ ] Route pubblica `GET /app/{app}/download`, senza autenticazione, identificata dall'id
  dell'App (stesso schema di `/top-ten/{app}`), registrata **fuori dal gruppo `web`**: niente
  sessione, cookie né CSRF
- [ ] Ogni risposta della route porta `Cache-Control: private, no-store` e `Vary: User-Agent`
- [ ] User agent iOS (iPhone, iPad, iPod) con `ios_store_link` valorizzato → redirect 302
  all'App Store
- [ ] User agent Android con `android_store_link` valorizzato → pagina Android che al caricamento
  naviga a `intent://<dominio-deep-link>/#Intent;scheme=https;package=<package>;S.browser_fallback_url=<android_store_link codificato>;end`
  (`<dominio-deep-link>` da `App::getDeepLinkDomain()`), dopo ~1,5 s con la pagina ancora visibile
  va a `android_store_link`, e mostra i pulsanti «Apri l'app» (stesso intent) e «Google Play»
- [ ] Il package Android si ricava dal parametro `id=` di `android_store_link`, con `sku` come
  ripiego, validato con `^[A-Za-z0-9_.]+$`; se non è valido la pagina Android mostra solo il
  pulsante Google Play
- [ ] Tutti gli altri casi, compreso il dispositivo riconosciuto ma senza il link del suo store
  → pagina minima con i pulsanti degli store disponibili; se manca quello del dispositivo, un
  messaggio «non ancora disponibile per <sistema>»
- [ ] App esistente senza nessuno dei due link → pagina minima con il nome dell'app e il
  messaggio «L'app non è al momento disponibile sugli store, riprova più tardi»; 404 solo per un
  id di App inesistente
- [ ] La pagina minima, su un iPad che si presenta come Mac (Safari di iPadOS), porta comunque
  all'App Store: il controllo lato client (`navigator.maxTouchPoints`) lo distingue da un Mac
- [ ] Nova App, tab Release: anteprima QR + URL completo in chiaro e copiabile (lo stesso
  codificato nel QR) + pulsanti «Scarica SVG» e «Scarica PNG», visibili appena c'è almeno un link
  agli store, **senza toggle**
- [ ] SVG e PNG generati al volo con `chillerlan/php-qrcode`, senza salvarli su storage né sul
  campo `qr_code` dell'App; PNG almeno 1024×1024
- [ ] Testi nuovi (etichette Nova, pagina minima) nei json di `wm-package/resources/lang/` in tutte
  e 5 le lingue (it, en, fr, es, de); testo base in inglese, come le chiavi esistenti
- [ ] Test PHPUnit nel package (stile di `tests/Unit/Models/AppDeepLinkUrlTest.php`) su ogni ramo
  del redirect, sul 404 e sulla generazione SVG/PNG

## Rischi

- **L'apertura diretta su Android dipende dalla build attuale dell'app.** Chrome apre solo
  activity con un intent-filter `BROWSABLE`, quindi un `intent://` con il solo package andrebbe
  sempre al ripiego; per questo l'intent punta al dominio dei deep link (oc:8251), che la build
  potrebbe già dichiarare. Il manifest non è in questo repo: se il filtro non c'è, l'utente
  finisce su Google Play con «Apri», che resta un esito accettabile. I browser interni (Instagram,
  Telegram, lettori QR in WebView) non capiscono `intent://`: lì salvano il timeout verso Google
  Play e i pulsanti. **Prova obbligatoria prima della consegna** su un Android vero, con e senza
  app, da Chrome e da un lettore di QR di terze parti.
- **Traffico automatico sui QR** (anteprime dei link, crawler): mitigato registrando la route
  senza sessione, così non scrive righe in `sessions` (`SESSION_DRIVER=database` su
  camminiditalia).
- **Il QR stampato dipende dal dominio del backend** da cui viene generato: se il dominio
  cambia, i QR già stampati smettono di funzionare. Mitigato mostrando in Nova l'URL in chiaro
  sotto il QR; nessun avviso per gli ambienti non di produzione, perché il cliente lavora solo in
  produzione.
- **Link agli store svuotati per qualche tempo** (app ritirata e ripubblicata, link sostituito):
  il QR stampato mostra un messaggio di indisponibilità invece di un 404.
- **Rilevamento del dispositivo dallo user agent**: uno user agent insolito finisce sulla pagina
  minima, che offre comunque entrambi gli store.

## Out of scope

- Apertura diretta dell'app su iOS (universal link sul dominio del backend): richiede una nuova
  release dell'app con il dominio negli associated domains, e i file well-known pubblicati dal
  backend. Il percorso `/app/{id}/download` è scelto in modo da poterlo dichiarare in futuro:
  i QR stampati oggi diventerebbero «apertura diretta» senza essere ristampati.
- Statistiche sulle scansioni del QR.

## Moduli toccati

Repo `wm-package`:
- `routes/` — nuovo file di route senza middleware `web`, registrato in
  `src/WmPackageServiceProvider.php`
- `src/Http/Controllers/` — nuovo controller del redirect
- `resources/views/` — pagina minima con i pulsanti e pagina Android con il tentativo di apertura
- `src/Models/App.php` — URL di download e generazione del QR in SVG/PNG
- `src/Nova/App.php` — campo nel tab Release (`app_release_data_tab()`)
- `resources/lang/{it,en,fr,es,de}.json` — nuove chiavi
- `tests/` — test del redirect e della generazione del QR

Repo `camminiditalia`:
- gitlink `wm-package`
- `CLAUDE.md` — riga nella tabella delle feature

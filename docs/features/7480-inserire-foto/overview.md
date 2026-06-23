> Ticket: oc:7480

# [wm-package][app] inserire foto

## Cosa cambia

Il modello `App` acquisisce due nuovi campi immagine (`my_paths` e `my_downloads`) gestibili da Nova nella tab Release. Le immagini sono servite tramite route dedicate e, se caricate, le loro URL assolute compaiono nella sezione `APP` del `config.json`.

## Perché

L'app mobile usa immagini nei box "I miei percorsi" e "I miei download". Attualmente queste immagini sono hardcoded nel frontend (default built-in). Aggiungendo i campi nel backend, ogni app può personalizzarle senza toccare il codice del frontend — coerentemente con quanto già fatto per `icon`, `icon_small` e `splash`.

## Requisiti

- [ ] `App::registerMediaCollections()` registra le collezioni `my_paths` e `my_downloads`
- [ ] Nova `app_release_data_tab()` espone due campi `Images` per `my_paths` e `my_downloads` con dimensione consigliata 2214×1013px e `hideFromIndex()`
- [ ] Route `GET /{app}/resources/my_paths.png` e `GET /{app}/resources/my_downloads.png` aggiunte in tutti e tre i gruppi di route (`elbrus`, `webmapp`, `v2/webmapp`)
- [ ] `AppController` aggiunge i metodi `myPaths()` e `myDownloads()` che delegano a `getOrDownloadIcon()`
- [ ] `getOrDownloadIcon()` usa `$app->getMedia($type)->isNotEmpty()` (non `isset($app->$type)`) e null-check su `$mediaItem` prima di `getPath()`; usa `$mediaItem->mime_type` (attributo nativo Spatie, non custom property) per il `Content-Type`
- [ ] `AppConfigService::config_section_app()` include `APP.myPaths` e `APP.myDownloads` come URL assolute **solo se `$app->getMedia('my_paths')->isNotEmpty()`** — mai `->first()->getUrl()` senza guard
- [ ] Test Feature in wm-package: route 200/404 e presenza/assenza chiave in config.json

## Rischi

- **`isset($app->$type)` è rotto per le media collection Spatie** — non sono attributi Eloquent, `isset` restituisce sempre `false`. Sostituito con `$app->getMedia($type)->isNotEmpty()`.
- **Route su tre gruppi** — `elbrus`, `webmapp`, `v2/webmapp`: tutte e tre devono avere le nuove route, altrimenti alcune app ricevono 404.
- **Config.json su S3 con chiavi residue dopo rollback** — comportamento noto per qualsiasi campo del config.json; documentato in notes.md.
- `icon_notify` e `logo_homepage` esistono nelle route ma **non** hanno media collection né campi Nova — non usarli come pattern di riferimento.

## Out of scope

- Gulp task per scaricare le immagini nell'app (wm-webmapp, da fare in un secondo momento)
- Modifiche a camminiditalia (eredita automaticamente da wm-package — nessuna logica custom richiesta)
- Aggiornamento PR esistenti (si riparte da zero)

## Moduli toccati

**wm-package:**
- `src/Models/App.php` — `registerMediaCollections()`
- `src/Nova/App.php` — `app_release_data_tab()`
- `routes/api.php` — due nuove route
- `src/Http/Controllers/Api/AppController.php` — `myPaths()`, `myDownloads()`
- `src/Services/Models/App/AppConfigService.php` — `config_section_app()`
- `tests/Feature/` — nuovi test per route e config.json

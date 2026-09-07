> Ticket: oc:8176

# Salva cammino nei preferiti — wm-package

## Cosa cambia

Il modello `Layer` diventa "favoritabile" tramite il trait `Favoriteable` di `ChristianKuri\LaravelFavorite` — lo stesso pacchetto già usato da `EcTrack`, sulla stessa tabella `favorites` polimorfica già esistente su `camminiditalia` (nessuna nuova migration).

Nuovo controller `LayerController` (o metodi aggiunti a `LayerAPIController`) che replica esattamente il pattern già in produzione per `EcTrackController`: `addFavorite`/`removeFavorite`/`toggleFavorite` (auth:api) su un singolo layer, più un endpoint `list` — ma a differenza del pattern `EcTrack` (che ritorna solo ID), questo ritorna una lista **leggera di oggetti Layer completi** (`id`, `title`/`name`, `feature_image`, `logo_image`, `style.color`), non l'intero `AppController::layer()` (che include tracce annidate, non necessario qui). Questa singola chiamata serve sia a popolare la lista "I miei preferiti" sia — cacheata client-side — a determinare se un singolo layer è tra i preferiti (stesso pattern già usato da `GeohubService.favourites()` per le tracce: nessun flag `is_favorited` embedded in nessuna risposta layer).

Il flag `show_favorites`, già letto (ma orfano) in `AppConfigService.php:745` (`$this->app->show_favorites`), viene spostato dentro `properties` JSON dell'`App` (pattern già usato per altri flag recenti, es. `apple_team_id`, `android_cert_sha256`) — **nessuna migration/colonna nuova**. Default `false`; il developer lo abiliterà manualmente per Cammini d'Italia da Nova dopo il merge. Nuovo campo Boolean in `src/Nova/App.php` che legge/scrive `properties->show_favorites`.

## Perché

Estendere ai `Layer` (cammini) un'infrastruttura preferiti già completa e in produzione per `EcTrack` (tracce) — vedi `webmapp-app/docs/features/8176-salva-cammino-nei-preferiti/overview.md` per il contesto frontend completo (tab "Favourites" già esistente, oggi solo tracce).

## Requisiti

- [ ] Trait `Favoriteable` (`ChristianKuri\LaravelFavorite`) aggiunto a `Wm\WmPackage\Models\Layer`
- [ ] Route `POST /api/layer/favorite/add/{layer}`, `POST /api/layer/favorite/remove/{layer}`, `POST /api/layer/favorite/toggle/{layer}` — `middleware('auth:api')`, mirror esatto di `EcTrackController` (stesso response shape `{'favorite': bool}`)
- [ ] Route `GET /api/layer/favorite/list` — ritorna array di oggetti Layer leggeri (`id`, `title`/`name`, `feature_image`, `logo_image`, `style.color`), non solo ID
- [ ] `show_favorites` spostato in `properties` JSON dell'`App` — nessuna migration; default `false`
- [x] Campo Nova Boolean (`src/Nova/App.php`) per `properties->show_favorites` — vive nel tab **"Frontend"** (`app_tab()`), subito sotto `Ugc Track Share Enabled`, non nel tab "Home" (spostato su richiesta esplicita del developer dopo il primo giro di implementazione)
- [x] `AppConfigService::config_section_options()` aggiornato per leggere `$this->app->properties['show_favorites'] ?? false` invece della colonna inesistente — **la chiave esposta in `OPTIONS` di config.json è `showFavorites` (camelCase)**, non `show_favorites`: solo la chiave di storage in `properties` (Nova, DB) resta snake_case, su richiesta esplicita del developer per coerenza con lo stile prevalentemente camelCase di `OPTIONS`

## Rischi

- **Nessun rischio di migration condivisa**: verificato che la tabella `favorites` esiste già su `camminiditalia` (usata da `EcTrack`) — il rischio originale del ticket è di fatto già risolto
- **`show_favorites` era già scritto (dead code) in `AppConfigService.php` da gennaio 2025** senza mai avere una sorgente dati — nessuna app ha mai ricevuto questo flag finora; il cambio non causa regressioni, ma va verificato che nessun frontend/consumer facesse già affidamento (silenzioso) su un `show_favorites: null`
- **Nessuno scoping per `app_id` sugli endpoint `layer/favorite/*`** (emerso in Fase: challenge, analogo al pattern già esistente — non introdotto — per `EcTrackController`): confermato esplicitamente dal developer che il legame è Layer↔App, non Layer↔Utente — un utente vede solo i layer della propria app, quindi non serve alcuna verifica aggiuntiva di ownership. Nessuna azione richiesta su questo punto; annotato solo per tracciabilità della decisione

## Out of scope

- Preferiti su `EcPoi`
- Qualsiasi nuovo campo/migration sulla tabella `apps` (si usa `properties` JSON)
- Endpoint pubblico "get layer by id" generico (fuori scope di questo ticket, anche se emerso come gap architetturale durante l'analisi — vedi notes.md)

## Moduli toccati

- `src/Models/Layer.php` — trait `Favoriteable`
- `src/Http/Controllers/Api/LayerController.php` (nuovo) o estensione `LayerAPIController.php`
- `routes/api.php` — nuovo blocco `layer/favorite/*`
- `src/Services/Models/App/AppConfigService.php` — lettura `show_favorites` da `properties`
- `src/Nova/App.php` — campo Boolean `properties->show_favorites`

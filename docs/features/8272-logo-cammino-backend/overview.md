> Ticket: oc:8272

# Logo cammino: backend (Layer model, Nova, API)

## Cosa cambia

`Layer` acquisisce una nuova collection Spatie Media Library `logo`, separata dalla collection `default` (l'attuale immagine di copertina/`feature_image`). `Layer` eredita già `HasMedia`/`InteractsWithMedia` da `GeometryModel` (via `Polygon`): non serve implementare l'interfaccia, basta estendere `registerMediaCollections()` chiamando `parent::registerMediaCollections()` e aggiungendo la nuova collection.

Il gestore del cammino (Nova, panel esistente dell'immagine di copertina) potrà caricare il logo tramite un campo `Images::make('Logo', 'logo')` con validazione mimetype (`png`, `webp`) e proporzione quadrata (`ratio=1/1`), single file. Il `Layer` espone un accessor `logo_image` (via `$appends`), disponibile in ogni `toArray()`/`toJson()` del modello — inclusa l'API consumata dall'app (`AppController::layer()`).

## Perché

Il cliente vuole un'identità visiva ufficiale per ogni cammino, distinta dalla foto di copertina, riutilizzabile in futuro come immagine badge nel sistema passaporto (ticket separato, non ancora esistente). Questo sotto-ticket copre esclusivamente il lato backend di oc:8164; il frontend (box lista cammini, dettaglio) è tracciato nel ticket padre.

## Requisiti

- [ ] `Layer::registerMediaCollections()` (wm-package, `src/Models/Layer.php`): override che chiama `parent::registerMediaCollections()` e aggiunge `addMediaCollection('logo')->singleFile()`
- [ ] Nova `Layer` (wm-package, `src/Nova/Layer.php`): campo `Images::make(__('Logo'), 'logo')->singleMediaRules(['mimes:png,webp', 'dimensions:ratio=1/1'])` nello stesso panel del campo `Image` esistente (`Images::make(__('Image'), 'default')`)
- [ ] Test Feature: upload con immagine non quadrata deve fallire la validazione (`dimensions:ratio=1/1`)
- [ ] `Layer` (wm-package, `src/Models/Layer.php`): accessor `getLogoImageAttribute()` (`getFirstMediaUrl('logo') ?: null`) aggiunto a `$appends`
- [ ] Test Feature: crea un `Layer`, allega un media alla collection `logo`, verifica `logo_image` corretto in `toArray()`/JSON (incluso caso assente → `null`)

## Rischi

- **Dipendenza con ticket badge/passaporto** — il logo va definito prima o in parallelo al ticket badge (non ancora creato), che lo riutilizzerà come immagine del badge. Confermato dal dev: logo e badge devono avere la stessa dimensione, quindi riusare la stessa collection `logo` non crea conflitto. Nessuna azione richiesta ora.
- **Formato file: solo PNG + WebP — SVG rimosso in review formale** — inizialmente accettato anche SVG (rischio XSS/SSRF valutato e accettato in fase di challenge), ma la review formale (`wm-review-ticket`) ha trovato un blocker: il campo Nova `Images::make()` (libreria Ebess) applica sempre una regola base hardcoded `image` (non sovrascrivibile via `singleMediaRules()`), e la versione di Laravel installata ha rimosso SVG dalla regola implicita `image` per motivi di sicurezza (richiede `allow_svg` esplicito, non impostabile su quella regola base). Risultato: ogni upload SVG veniva rifiutato sempre, indipendentemente dalla forma. Rimosso SVG dai mimetype accettati; se servirà in futuro, richiede di cambiare il tipo di campo Nova (es. `Files::make()`, che non applica quella regola base) — valutazione UX da fare in un ciclo separato. WebP aggiunto a posteriori (dopo l'approvazione iniziale del piano) per supportare loghi già ospitati in quel formato sullo storage esistente, e verificato funzionante.
- **Trappola `singleMediaRules` vs `rules`** (nota da CLAUDE.md, oc:8247): usare sempre `singleMediaRules()` sul campo Nova, mai `->rules()`, altrimenti la validazione fallisce sempre confrontando l'intero array della collection invece del solo file nuovo.
- **Modifica wm-package condivisa** — la collection `logo` su `Layer` è disponibile a tutti gli shard consumer; non breaking (opzionale, nessun default).

## Out of scope

- Frontend (box lista cammini, dettaglio app) — tracciato nel ticket padre oc:8164
- Dimensione minima/massima in pixel — validata solo la proporzione quadrata (`ratio=1/1`), non una soglia assoluta; rimandato a quando il cliente fornisce specifiche
- Logica di assegnazione badge/passaporto — ticket separato, non ancora esistente
- Verifica manuale del caricamento da Nova in ambiente locale — il criterio di successo di questo ticket è il test Feature sull'esposizione di `logo_image`

## Moduli toccati

**wm-package:**
- `src/Models/Layer.php`
- `src/Nova/Layer.php`
- `tests/Feature/LayerLogoMediaTest.php` (nuovo)
- `tests/Feature/Nova/LayerLogoFieldValidationTest.php` (nuovo)

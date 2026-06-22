> Ticket: oc:7480

# Notes — [wm-package][app] inserire foto

## Deviazioni dal piano

- **Naming**: i campi sono stati rinominati da `tracks_image`/`downloads_image` a `my_paths`/`my_downloads` durante la fase di planning, su indicazione del developer.
- **Fix `getOrDownloadIcon()`**: scoperto durante i test che `isset($app->$type)` non funziona per le media collection Spatie (non sono attributi Eloquent). Sostituito con `$app->getMedia($type)->first()` + null-check esplicito. Applicato al metodo esistente, non solo ai nuovi.
- **`mime_type` vs `getCustomProperty('mime-type')`**: il secondo poteva restituire `null` per media caricati senza custom property esplicita. Sostituito con l'attributo nativo Spatie `$mediaItem->mime_type`.
- **`?? 'local'` per driver check**: `$disk->getConfig()['driver']` crashava su fake disk nei test (chiave `driver` assente nel config del fake). Fix con null coalescing che defaulta a `local` — corretto semanticamente.

## Bug trovati

- `getOrDownloadIcon()` aveva un bug latente: `isset($app->$type)` restituisce sempre `false` per le media collection, rendendo di fatto non funzionanti anche `icon_notify` e `logo_homepage` (che non hanno nemmeno media collection registrata nel modello).

## Decisioni

- **URL in config.json via `getFirstMediaUrl()`** invece di `route()`: evita il conflitto di naming tra i due gruppi `webmapp` che condividono `->name('webmapp.')` in `routes/api.php`.
- **Helper text con ratio e dimensioni**: derivato dal CSS del componente `wm-box` (`height: 176px`, `object-fit: cover`, container max `~388px`) e dalle immagini di esempio cammini (2214×1013px). Minimo consigliato 800×360px per retina.
- **Dimensioni immagine**: il frontend usa `object-fit: cover` — l'immagine viene ritagliata se il ratio non corrisponde. Ratio corretto: 2.2:1.

## Follow-up

- **Gulp task** (wm-webmapp): scaricare `APP.my_paths` e `APP.my_downloads` dal config.json e sovrascrivere `assets/images/profile/my-path.webp` e `assets/images/profile/downloads.webp` nel bundle — stesso pattern della splash screen. Ticket separato da creare.
- `icon_notify` e `logo_homepage` hanno route e controller ma **non** hanno `registerMediaCollections()` né campi Nova — pattern incompleto da completare in un ticket dedicato.
- Config.json su S3 cached: dopo rollback del codice le chiavi `APP.my_paths`/`APP.my_downloads` rimangono nel file cached finché non si forza rigenerazione con `base-config.json`. Comportamento noto per tutti i campi del config.

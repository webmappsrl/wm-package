# Media, upload e avatar

Come il package gestisce i media Spatie e i campi media di Nova.

## Stato attuale

### Validazione dei campi media Nova

**Su un campo `Ebess\AdvancedNovaMediaLibrary\Fields\Media` si usa `singleMediaRules()`, mai
`->rules()`.** `->rules()` popola `collectionMediaRules`, validato in `fillAttributeFromRequest`
contro l'**intero array della collection** — un mix di ID di media esistenti e file nuovi. Ogni
rule basata su file (`dimensions`, `image`, `mimes`) fallisce sempre lì, perché un array non è mai
un'istanza `File`, e questo blocca **ogni** salvataggio del form con quel campo già valorizzato,
anche senza toccarlo. `singleMediaRules()` valida solo i file effettivamente caricati (oc:8247).

`Images::make()` (Ebess) applica sempre `$defaultValidatorRules = ['image']`, unito con
`array_merge()` alle tue `singleMediaRules` e **non sovrascrivibile**. Con Laravel 12 la regola
`image` esclude l'SVG, quindi ogni SVG viene rifiutato prima ancora di arrivare alle tue regole.
`Files::make()` ha invece `$defaultValidatorRules = []` (oc:8272).

I messaggi di validazione di una rule imbustata in un field Nova non hanno un canale diretto: si
passa dalla convenzione Laravel `validation.custom.<attribute>.<rule>` in
`resources/lang/{locale}/validation.php`, che va registrata con
`$this->loadTranslationsFrom(__DIR__.'/../resources/lang')` (**senza namespace**) in
`WmPackageServiceProvider::boot()` — diverso da `loadJsonTranslationsFrom()`, già presente per le
stringhe `__('...')`, che è un gruppo separato. Laravel non sostituisce `:min_width`/`:min_height`
nel messaggio di `dimensions` (nessun `replaceDimensions` nel replacer): le soglie vanno scritte
a mano nel messaggio (oc:8247).

Il messaggio custom **non è mostrabile nel toast di Nova**: per gli errori 422 il core mostra
sempre la stringa fissa "There was a problem submitting the form.", non personalizzabile senza
patchare il vendor (oc:8247).

### Upload via campo `File`

- Nel callback `->store()`, `return null` significa per Nova «scrivi `null` sull'attributo» e
  **distrugge il valore esistente** a ogni update senza file. `return true` = non toccare;
  `return <stringa>` = persisti quel valore (oc:8175).
- Il callback `->store()` gira **prima** del `save()`: in creazione `$model->id` è ancora `null`,
  quindi l'upload in create va fatto in un `afterCreate()` statico sulla Resource — e lì serve
  `updateQuietly()`, altrimenti l'observer `saved` rilancia `UpdateAppConfigJob` due volte
  (oc:8175).
- Senza `->disk('wmfe')` Nova cerca il file sul disco locale invece che su MinIO, e il Download
  dal detail non funziona (oc:8175).
- `dependsOn` sui campi `File` non si attiva al primo render del form in Nova 5 (la `FormData` è
  vuota sul GET `update-fields`): il campo risulta sempre nascosto. Esito empirico, dipende dal
  vendor (oc:8175).
- `Laravel\Nova\Fields\File::$storageCallback` è `public`: nei test si accede direttamente, niente
  reflection (oc:8175).
- `php.ini` con `upload_max_filesize` al default (2M) fa fallire con `validation.uploaded` upload
  ben sotto il limite dichiarato nelle regole Nova: i due limiti vanno allineati (oc:8175).

### Avatar

`avatar_url` punta a una conversion `Fit::Crop` 150×150 (`avatar_150_150`) su `User`, non
all'originale.

- La conversion è **self-contained**: non riusa
  `MediaService::getMediaConversionNameByWidthAndHeight()` né
  `config('wm-package.services.image.thumbnail_sizes')`, che generano *tutte* le dimensioni per le
  gallerie EcTrack/EcPoi — dominio diverso. Il naming resta coerente per convenzione
  (`{prefix}_{width}_{height}`).
- È generata in modo **sincrono** (`->nonQueued()`), non in coda come da default Spatie: una
  conversion in coda dipende dal worker Horizon, e un client mobile offline-first potrebbe non
  richiedere mai più `avatar_url` dopo che la coda l'ha generata, rendendo il fix silenziosamente
  inefficace.
- Nessun try/catch attorno a `toMediaCollection('avatar')`: Spatie salva comunque la riga `Media`
  prima di generare le conversion, quindi un fallimento lascia il media salvato ma non ritagliato,
  e `getAvatarUrlAttribute()` ripiega su `getUrl()` tramite `hasGeneratedConversion()` — mai un
  URL rotto.
- `FetchGravatarAvatarJob` deriva l'estensione dal `Content-Type` reale con
  `Symfony\Component\Mime\MimeTypes` (fallback `jpg`), non più hardcoded `.jpg`: Gravatar può
  restituire PNG. E richiede `s=300` invece del default 80×80, altrimenti un crop 150×150
  upscalerebbe una sorgente più piccola (oc:8343).

Nessun backfill per gli avatar già esistenti: la feature padre (oc:8163) è su un server dev, non
in produzione (oc:8343).

### Altro

- Spatie `getMedia()` senza argomento legge la collection `default`: aggiungere una collection
  `logo` non interferisce con `feature_image` (oc:8272).
- `show_image_on_map` su `EcPoi` vive in `properties` JSON, senza migration. Non c'è fallback a
  livello di categoria POI (nessun caso d'uso reale). È esposto solo in `RelatedEcPoiResource`,
  dentro `feature_image` e solo se `getMedia()->isNotEmpty()`; il campo Nova è `->readonly()`
  quando il POI non ha media. **Non** chiamare `->toArray($request)` esplicitamente su
  `RelatedEcPoiResource` in `getRelatedPois()`: causa un crash su `MediaResource(null)` — va
  mantenuto il `->toArray()` sulla collection (oc:7645).

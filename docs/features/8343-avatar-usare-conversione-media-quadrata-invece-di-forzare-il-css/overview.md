> Ticket: oc:8343

# Avatar: usare conversione media quadrata invece di forzare il CSS

## Cosa cambia

`User` (wm-package) guadagna una conversion Spatie Media Library dedicata sulla collection `avatar`: un crop quadrato 150×150 (`Fit::Crop`), generato **in modo sincrono** (`->nonQueued()`) nella stessa request/job che salva il media — nessuna dipendenza dal worker Horizon per avere l'immagine corretta da subito. `getAvatarUrlAttribute()` restituisce l'URL di questa conversion (con un check defensivo `hasGeneratedConversion()` e fallback all'originale solo come rete di sicurezza, non come comportamento atteso), invece dell'immagine originale non ritagliata. `FetchGravatarAvatarJob` richiede a Gravatar un'immagine da almeno 300px (`?s=300`) invece del default 80×80, per avere una fonte sufficientemente grande da produrre un crop a 150×150 nitido, e corregge un bug preesistente (estensione file hardcoded `.jpg` indipendentemente dal `Content-Type` reale della risposta, che può essere PNG) — rilevante ora perché la generazione sincrona della conversion dipende dalla leggibilità corretta di quel file.

La dimensione 150 e il parametro `?s=300` sono legati tramite una costante PHP condivisa su `User` (es. `AVATAR_CONVERSION_SIZE`), referenziata anche nel job Gravatar, per rendere visibile nel codice il legame tra i due valori.

Nessuna modifica al campo Nova `UserAvatar` (usa già `avatar_url`, beneficia del fix senza toccare codice) né a `wm-core`/frontend mobile (consumano lo stesso `avatar_url` esposto da `AppAuthController::me()`/`update()` — l'effetto si propaga automaticamente via API, nessuna modifica necessaria in quel repo).

## Perché

L'avatar caricato manualmente da un utente (foto non quadrata) o scaricato da Gravatar (default 80×80) viene oggi mostrato "storto"/non perfettamente rotondo nell'app e in Nova, perché la resa circolare è affidata al solo CSS senza che l'immagine sottostante sia effettivamente ritagliata in un quadrato. In più, l'immagine servita è quella a piena risoluzione: più pesante da scaricare del necessario per un avatar. Emerso durante lo scrum del 2026-07-30 (Alessandro Peci / Giuseppe Bonfanti), come follow-up tecnico di oc:8163 (profilo utente: nome, cognome e avatar), da tracciare come ticket figlio.

## Requisiti

- [ ] `User::registerMediaConversions()` (wm-package): nuova conversion quadrata 150×150 (`Fit::Crop`) sulla collection `avatar`, generata in modo sincrono (`->nonQueued()`), self-contained — nessuna dipendenza da `MediaService`/`thumbnail_sizes` (quella config è pensata per le gallerie EcTrack/EcPoi, dominio diverso)
- [ ] Costante condivisa (es. `User::AVATAR_CONVERSION_SIZE = 150`) usata sia dalla conversion sia referenziata nel commento/valore di `FetchGravatarAvatarJob`, per rendere esplicito il legame tra le due dimensioni
- [ ] `User::getAvatarUrlAttribute()` (wm-package): usa la conversion se generata (`hasGeneratedConversion()`), fallback all'URL del media originale solo come rete di sicurezza (formato non supportato dal motore immagini) — `null` se l'utente non ha alcun avatar
- [ ] `FetchGravatarAvatarJob::handle()` (wm-package): richiesta Gravatar con `?s=300` (o valore equivalente ≥150) invece del default 80×80; estensione del file temporaneo derivata dal `Content-Type` reale della risposta HTTP invece di `.jpg` hardcoded
- [ ] Test aggiornati/aggiunti in `wm-package/tests/` per: generazione sincrona della conversion, fallback dell'accessor se la conversion non è generabile, dimensione ed estensione corretta della richiesta Gravatar

## Rischi

- **Upscaling su foto molto piccole (<150×150) caricate manualmente**: `Fit::Crop` ingrandisce prima di ritagliare, con perdita di nitidezza — rischio accettato esplicitamente dal dev (caso raro con foto da fotocamera moderna/mobile, nessuna validazione dimensione minima introdotta)
- **Crop centrato geometricamente, senza rilevamento del volto**: su una foto non quadrata con soggetto non centrato (panoramica, inquadratura larga), il crop automatico può tagliare via il volto — limite noto e accettato, nessun face-detection o crop manuale in scope
- **Generazione sincrona aggiunge latenza alla request di upload/al job Gravatar**: costo stimato basso (crop 150×150 su un file ≤5MB), ma se il motore immagini (GD/Imagick) fallisce su un formato non gestito (es. HEIC non convertito lato client), l'eccezione va gestita senza rompere il salvataggio del media stesso — il fallback in `getAvatarUrlAttribute()` copre solo la lettura, non previene il fallimento della generazione
- **Nessun backfill per avatar già esistenti** (vedi Out of scope) — un avatar caricato prima di questo fix resta non ritagliato finché l'utente non ne carica uno nuovo

## Out of scope

- Backfill/comando artisan per rigenerare la conversion sugli avatar già esistenti — decisione basata sullo stato attuale (oc:8163 è solo su un server dev, non in produzione): è una foto del presente, non una garanzia permanente. Se oc:8163 viene promossa in produzione con utenti reali prima che questo fix sia stato applicato e gli avatar esistenti "sanati" naturalmente (nuovo upload), la scelta di non fare backfill va rivalutata con un ticket dedicato
- Validazione di dimensione minima sull'upload avatar
- Face-detection o crop manuale per centrare il ritaglio sul soggetto
- Formattazione ad alta risoluzione dedicata per un eventuale, futuro "click per ingrandire" sul dettaglio del media in Nova (esplicitamente rimandato da Giuseppe nello scrum)
- Modifiche a `wm-core` (frontend mobile) — non necessarie in questo ciclo, l'URL corretto arriva già tramite l'API esistente

## Moduli toccati

**wm-package** (unico repo coinvolto — nessuna modifica al repo principale camminiditalia né a wm-core):
- `src/Models/User.php` (`registerMediaConversions()`, `getAvatarUrlAttribute()`, costante `AVATAR_CONVERSION_SIZE`)
- `src/Jobs/FetchGravatarAvatarJob.php` (parametro dimensione richiesta a Gravatar, estensione file da `Content-Type` reale)
- Test in `wm-package/tests/` (Unit e/o Feature, da individuare in Fase: write-plan)

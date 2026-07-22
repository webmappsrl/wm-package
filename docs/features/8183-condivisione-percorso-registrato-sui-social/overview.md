> Ticket: oc:8183

# Condivisione percorso registrato sui social (stile Strava) — rendering mappa, statistiche e pagina pubblica (wm-package)

## Cosa cambia rispetto alla revisione precedente

Questa è la terza revisione architetturale della feature (vedi `notes.md` per la cronologia completa). Il developer ha deciso di spostare **tutto** il lavoro pesante sul backend, semplificando drasticamente il client:

- **Il client manda solo `uuid`** — non più screenshot, non più statistiche calcolate lato TypeScript, non più `app_id` nel payload (restava comunque non fidato, ora è del tutto assente).
- **Il backend calcola lui stesso durata/distanza/dislivello** da `properties.locations` della `UgcTrack` (array grezzo di punti GPS con `time`/`latitude`/`longitude`/`altitude`/`altitudeAccuracy`) — nessun valore precalcolato esiste oggi da nessuna parte (verificato: né nel frontend al salvataggio, né nel backend). Algoritmo di riferimento, porting dell'equivalente TypeScript già scritto per la versione precedente (`core/src/app/services/geoutils.service.ts`, ora rimosso da quell'uso):
  - **Distanza**: somma haversine tra coordinate GPS consecutive
  - **Durata**: `(ultimo time − primo time) / 1000`
  - **Dislivello**: somma delle differenze positive di altitudine tra punti consecutivi, scartando come rumore quelle sotto la soglia `max(altitudeAccuracy dei due punti) / 6`
- **Il backend genera lui stesso l'immagine della mappa** (non riceve più uno screenshot): compositing manuale in PHP — recupera i tile raster XYZ dallo stesso tile server già usato dall'app (`AppTiles.php`), li assembla per l'estensione (bounding box) della traccia, disegna la polyline del percorso sopra (`intervention/image`/GD), poi compone il risultato con `story_frame` + testo statistiche nel formato Stories (9:16, 1080×1920) — stessa logica di compositing già esistente, ora a monte anche del rendering mappa stesso, non solo dell'assemblaggio finale.
- **L'immagine finale viene ora persistita** (Spatie Media Library, collection dedicata sulla `UgcTrack`, es. `share_image`) — necessario per poter servire la nuova pagina pubblica (vedi sotto), che un crawler (WhatsApp/Facebook/Twitter) può richiedere in un momento successivo alla generazione, non nella stessa request.
- **Nuova pagina pubblica** `GET /share/ugc-track/{uuid}` (Blade), **una sola route/template parametrico** che serve contenuto diverso per traccia in base allo `uuid` nell'URL — con tag Open Graph (`og:title`, `og:description`, `og:image` → l'immagine persistita, `og:url`) e visualizzazione dell'immagine. Reintroduce l'approccio della primissima stesura del ticket (scartato durante la Fase: reverse-interaction quando si era deciso lo share nativo Stories, ora di nuovo utile perché il meccanismo di condivisione lato app è tornato ad essere `Share.share()` generico — vedi overview `webmapp-app`).
- **Ciclo di vita della pagina pubblica**: snapshot statico generato al momento della condivisione (non si aggiorna se l'utente modifica la traccia dopo) — vive finché esiste la `UgcTrack`, **404 se la traccia viene eliminata** (i dati personali dietro non devono restare accessibili pubblicamente più a lungo della traccia stessa), nessuna scadenza temporale aggiuntiva.
- **Risposta dell'endpoint**: ora deve fornire sia l'immagine (per l'allegato diretto nello share nativo del device) sia l'URL della pagina pubblica (per i canali che fanno unfurling OG) — formato esatto (JSON con entrambi i campi vs binario+header) da definire in `plan.md`.

## Perché

Il primo approccio (screenshot client-side + compositing backend) si è scontrato con un problema di WKWebView su iOS mai completamente isolato (persisteva anche dopo aver eliminato la dipendenza da `crossOrigin` con un caricamento tile via `fetch()`). Il developer ha scelto di eliminare interamente la generazione mappa lato client, spostandola sul backend — che non ha vincoli di CORS/WebView ed è più semplice da debuggare. Contestualmente, passando da un plugin nativo Stories a `Share.share()` generico (vedi overview `webmapp-app`), diventa utile avere anche un link condivisibile con anteprima (non solo l'immagine diretta) per i canali che non aprono Instagram/Facebook Stories nativamente — da qui il ritorno della pagina pubblica con OG tags.

## Requisiti

- [ ] Flag conf `ugc_track_share_enabled` esposto nel config.json generico per shard (invariato dalla revisione precedente)
- [ ] Media collection `story_frame` su `App`, upload via Nova (invariato)
- [ ] **Servizio di calcolo statistiche** da `UgcTrack.properties.locations` (distanza haversine, durata, dislivello con soglia di rumore) — nuovo, porting dell'algoritmo TS esistente
- [ ] **Servizio di rendering mappa**: dato un bounding box/estensione e la geometria della traccia, recupera i tile XYZ necessari dal tile server dell'app (stesso URL già usato altrove, `AppTiles.php`), li assembla in un'immagine, disegna la polyline del percorso proiettando le coordinate GPS in pixel — nuovo
- [ ] Endpoint semplificato (`POST /api/share-story-image` o simile): accetta **solo `{uuid}`**, cerca la `UgcTrack`, verifica ownership (`user_id` = utente autenticato, altrimenti 403/404), calcola le statistiche, genera la mappa, compone con `story_frame` (frame+testo, invariato), **persiste l'immagine finale** (Spatie, nuova collection dedicata), ritorna `{immagine, shareUrl}` al client
- [ ] Se `story_frame` non è caricato: stesso fallback già deciso (immagine non brandizzata, contain non crop, log warning) — invariato
- [ ] **Nuova route pubblica** `GET /share/ugc-track/{uuid}` (nessuna autenticazione richiesta): risolve la traccia, legge l'immagine persistita, renderizza un Blade con tag OG (`og:title` da nome traccia+statistiche, `og:description` testo fisso per shard, `og:image` → immagine persistita, `og:url` → URL canonico) — **404 se la traccia non esiste o è stata eliminata**
- [ ] Validazione dimensione massima e formato font/stile testo — invariati dalla revisione precedente
- [ ] Traduzioni/testo della pagina pubblica: coerenti con la lingua principale del backend/shard (verificare pattern esistente per contenuti pubblici, es. pagine di errore o simili)

## Rischi

- **Rendering mappa manuale in PHP è un pezzo di codice nuovo e non banale**: proiezione coordinate GPS → pixel, math dei tile XYZ (calcolo z/x/y per un bounding box), stitching di più tile in un'unica immagine, disegno polyline — nessun precedente diretto in questo codebase (il pattern più vicino è il compositing statico già esistente in `StoryShareImageService`, che compone immagini già pronte, non ne genera di nuove da tile geografici).
- **Persistenza immagine = nuovo spazio di storage per traccia condivisa**: a differenza della revisione stateless precedente, ogni condivisione ora occupa spazio permanente (Spatie media) finché la traccia esiste — da monitorare se il volume cresce, nessuna pulizia automatica prevista oltre alla cancellazione a cascata quando la traccia viene eliminata.
- **Pagina pubblica non autenticata**: espone nome traccia e immagine a chiunque conosca lo `uuid` (non enumerable/indovinabile, ma pubblico una volta condiviso) — accettato, coerente con la natura "contenuto che l'utente sceglie di condividere pubblicamente".
- **Snapshot statico**: se l'utente modifica la traccia dopo aver condiviso, la pagina pubblica mostra dati non aggiornati — comportamento scelto deliberatamente (semplicità, coerenza con "condivisione one-shot"), ma da comunicare chiaramente se il cliente chiede "perché il link non si aggiorna".
- **Nessuna scadenza temporale sulla pagina pubblica**: vive indefinitamente finché la traccia esiste — se in futuro serve un limite (es. per motivi di storage/privacy), è una modifica successiva, non prevista in questo ciclo.
- **Qualità visiva dipendente dai tile disponibili**: aree con basemap a bassa risoluzione o zone di scarsa copertura del tile server producono uno screenshot di qualità inferiore — stesso limite che avrebbe avuto qualunque approccio (client o backend).

## Out of scope

- Rate-limiting dedicato sull'endpoint (stessa decisione precedente: nessun altro endpoint media nel progetto ce l'ha, rivalutare se emergono segnali di abuso)
- Configurabilità da Nova delle coordinate di layout (mappa/testo statistiche dentro il frame) — hardcoded lato codice
- Editor visuale in Nova per il frame — upload diretto del file finale
- Aggiornamento dinamico della pagina pubblica se la traccia cambia dopo la condivisione (snapshot statico per design)
- Fix della persistenza di `users.app_id` al signup — bug preesistente indipendente, non più rilevante per questa feature (il meccanismo di risoluzione app resta basato su `UgcTrack.uuid`, invariato dalla revisione precedente)

## Moduli toccati

- `wm-package/src/Models/App.php` (media collection `story_frame`, invariato)
- `wm-package/src/Nova/App.php` (campo upload `story_frame`, invariato)
- `wm-package/src/Models/UgcTrack.php` (nuova media collection per l'immagine persistita, es. `share_image`)
- `wm-package/src/Services/Models/App/AppConfigService.php` (esposizione config, invariato)
- `wm-package/src/Services/Models/StoryShare/StoryShareImageService.php` (esistente, da estendere o affiancare con la nuova logica statistiche+rendering mappa)
- Nuovo servizio statistiche (percorso da definire in `plan.md`, es. `wm-package/src/Services/Models/StoryShare/TrackStatsService.php`)
- Nuovo servizio rendering mappa (percorso da definire in `plan.md`, es. `wm-package/src/Services/Models/StoryShare/MapRenderService.php`)
- `wm-package/src/Http/Controllers/Api/ShareStoryImageController.php` (esistente, da semplificare/estendere: solo `uuid` in input, persistenza output, risposta con `shareUrl`)
- Nuovo controller/route per la pagina pubblica (es. `wm-package/src/Http/Controllers/ShareUgcTrackController.php` + vista Blade)
- `wm-package/routes/api.php` (endpoint esistente, da aggiornare) e `wm-package/routes/web.php` o simile (nuova route pubblica non-API)

> Ticket: oc:8183

# Condivisione percorso registrato sui social (stile Strava) — flag conf, asset branding e compositing (wm-package)

## Cosa cambia
- Nuovo flag conf `ugc_track_share_enabled` esposto nel config.json generico (stesso builder usato per altri flag opzionali per shard, es. `showTrackRemainingDistance`), per permettere al frontend di mostrare/nascondere il pulsante "Condividi" nel pannello proprietà traccia UGC in base all'attivazione per istanza.
- Nuova media collection Spatie su `App` (es. `story_frame`), upload via Nova — pattern già consolidato su questo stesso modello per `icon`/`icon_small`/`splash`/`my_paths`/`my_downloads` (`wm-package/src/Models/App.php`, `wm-package/src/Nova/App.php`). Permette al team/cliente di cambiare lo sfondo brandizzato della Story senza toccare codice.
- `AppConfigService.php` espone l'URL di `story_frame` in `config.json` (stesso meccanismo di `APP.myPaths`) — ma **non** viene scaricato/bundlato a build-time dal gulp (a differenza di `my_paths`/`my_downloads`, il cui download a build-time è legato a un vincolo specifico su iOS/webp, non una regola generale): l'app lo userà solo indirettamente, passandolo al nuovo endpoint di compositing (vedi sotto), non per uso diretto client-side.
- **Endpoint di compositing (non più completamente stateless)**: riceve lo screenshot grezzo della traccia (generato on-device via OpenLayers, qualunque aspect ratio — vedi overview `map-core`), i dati statistici del percorso (tempo, km percorsi, dislivello), **più `app_id` e `uuid` della `UgcTrack` condivisa** (lo `uuid` è già generato client-side alla registrazione e presente in `properties.uuid`). Compone il tutto con il `story_frame` dell'app tramite `intervention/image` (già dipendenza esistente in `wm-package`) nel formato verticale Stories (9:16, es. 1080×1920), e ritorna l'immagine finale — nessun salvataggio dell'immagine risultante, ma l'endpoint ora legge (non scrive) la `UgcTrack` corrispondente per verifica.
- **Il rispetto del formato Stories è responsabilità esclusiva di questo endpoint**: lo screenshot grezzo ricevuto può avere qualunque aspect ratio (es. quadrato) — il compositing lo inserisce in una posizione/dimensione prestabilita all'interno del frame 9:16, insieme al testo delle statistiche.
- **App ricavata dalla `UgcTrack`, verificata via `uuid`, non dal contesto utente** (rivisto rispetto alla prima implementazione in Fase: challenge — vedi `notes.md` per la cronologia completa): il client manda sia `app_id` sia `uuid` nel payload, ma il server **non si fida del valore `app_id` inviato dal client** — cerca la `UgcTrack` tramite `uuid`, verifica che `UgcTrack.user_id` corrisponda all'utente autenticato (ownership check, altrimenti 403/404), e usa l'`app_id` letto da `properties` di quella traccia come unica fonte di verità per decidere quale `story_frame` caricare. Scelto al posto di derivare l'app da una relazione `User → App` (approccio esplorato e poi scartato: richiedeva un fix più ampio e fuori scope alla persistenza di `users.app_id` al signup, non necessario con questo meccanismo basato sul record della traccia stessa).

## Perché
Con lo share nativo Instagram/Facebook Stories (deciso in Fase: reverse-interaction) non serve più un link pubblico né uno stato persistito per traccia — ma il developer ha successivamente richiesto che il **compositing** (sfondo/frame brandizzato + screenshot mappa) avvenga lato backend anziché nell'app, per poter aggiornare il design della Story (frame, layout, eventuali testi/loghi) senza dover rilasciare una nuova build/submission sugli store. Il backend fornisce quindi sia l'asset di branding (Nova, come altri asset app) sia la logica di composizione, mentre il client resta responsabile solo della cattura della mappa.

## Requisiti
- [ ] Flag conf `ugc_track_share_enabled` esposto nel config.json generico per shard (default assente/`false` → pulsante nascosto, zero impatto sugli shard che non lo attivano)
- [ ] Media collection `story_frame` su `App`, upload via Nova (stesso pattern di `icon`/`splash`/`my_paths`)
- [ ] URL `story_frame` esposto in `config.json` (per uso lato endpoint di compositing, non per fetch diretto client-side)
- [ ] Nuovo endpoint (es. `POST /api/share-story-image`): riceve screenshot grezzo + valori statistici (tempo, km, dislivello) + `app_id`+`uuid` della `UgcTrack` condivisa. Cerca la `UgcTrack` per `uuid`, verifica ownership (`user_id` = utente autenticato, altrimenti 403/404), ricava l'`app_id` autentico da `properties` della traccia trovata (mai dal valore inviato dal client), compone il tutto (mappa + testo statistiche + frame) via `intervention/image` nel formato Stories (9:16, es. 1080×1920), ritorna immagine finale — nessuna persistenza dell'immagine risultante
- [ ] Posizione/dimensione della mappa e di ciascun campo testo statistiche all'interno del frame 9:16: coordinate fisse lato codice (non configurabili da Nova in questo ciclo) — vedi Rischi per il vincolo che questo impone su futuri redesign del frame
- [ ] Font/stile del testo statistiche: da definire in `plan.md` (font di sistema di `intervention/image`/GD o font custom da bundlare)
- [ ] Comportamento se `story_frame` non è stato caricato per l'app: fallback ragionevole (es. ritorna lo screenshot grezzo non composito, oppure un frame di default) — da decidere in `plan.md`
- [ ] Errore esplicito (HTTP 4xx/5xx) se il compositing fallisce (immagine ricevuta corrotta, formato non valido) — coerente con la decisione "errore chiaro + retry" del flusso complessivo
- [ ] Endpoint protetto da autenticazione per evitare abuso/costo di elaborazione da chiamate anonime; app ricavata dalla `UgcTrack` verificata via `uuid`+ownership, mai dal parametro `app_id` inviato dal client (elimina il rischio cross-tenant, vedi Rischi)
- [ ] Validazione dimensione massima del file in ingresso (limite esplicito in MB, coerente con la dimensione attesa di uno screenshot mappa) — nessun rate-limit dedicato per ora (decisione esplicita, vedi Rischi), solo il size limit per bloccare payload anomali

## Rischi
- **Reintroduce una dipendenza di rete sincrona al momento della condivisione**: se il device non ha connettività, la condivisione non può completarsi (accettato consapevolmente per il beneficio di poter aggiornare il branding senza release app) — l'errore deve essere chiaro e il retry esplicito (già deciso per l'intero flusso).
- **Nessuna cache/persistenza dell'immagine finale**: ogni tap "Condividi" ripete l'intera chiamata di compositing anche se traccia e frame non sono cambiati — accettabile dato il costo contenuto di un'operazione di image compositing sincrona, ma da monitorare se il volume di condivisioni cresce.
- **Fallback frame mancante**: se un'app non ha ancora caricato `story_frame` su Nova, il comportamento di default va definito esplicitamente per non produrre un'esperienza rotta al primo utilizzo della feature su una nuova istanza.
- **Coordinate di layout (mappa + testo statistiche) hardcoded lato codice, non configurabili da Nova**: l'upload di `story_frame` su Nova cambia solo l'immagine di sfondo, non dove vengono posizionati mappa/testo sopra di essa. Se un futuro redesign del frame sposta significativamente gli elementi grafici (es. la finestra dove va la mappa cambia posizione/dimensione), le coordinate hardcoded vanno aggiornate lato codice — quindi l'obiettivo dichiarato "aggiornare il branding senza release app" vale solo per lo sfondo in sé, non per un redesign strutturale del layout, che richiederebbe comunque un deploy backend (più veloce di una release store, ma non è comunque "zero codice").
- **Costo/abuso endpoint**: essendo stateless e senza legame a una risorsa specifica, richiede comunque autenticazione e un limite di dimensione file esplicito. **Rate-limiting esplicito rimandato** (decisione presa in Fase: challenge, coerente col fatto che nessun altro endpoint media nel progetto ne ha uno oggi, unico precedente è il throttle sulla signup) — se in produzione emergono segnali di abuso o carico anomalo sul pool PHP-FPM condiviso tra tutte le app, va aggiunto in un ciclo successivo. Rischio residuo consapevolmente accettato.

## Out of scope
- Persistenza dell'immagine finale o stato di condivisione per traccia (resta stateless, coerente con la natura effimera delle Stories)
- Pagina pubblica con Open Graph tags (approccio della prima stesura del ticket, superato)
- Configurazione Facebook App ID (vive lato frontend/instance in `webmapp-app`, non nel backend)
- Editor visuale in Nova per posizionare/anteprima il frame prima dell'upload (upload diretto del file finale, come già avviene per `icon`/`splash`)
- Configurabilità da Nova delle coordinate di layout (posizione mappa/testo statistiche) — hardcoded lato codice in questo ciclo (vedi Rischi)
- Localizzazione delle etichette statistiche in più lingue (se il testo composito richiede label, es. "km"/"dislivello", vs solo numeri) — da chiarire in `plan.md`
- Fix della persistenza di `users.app_id` al signup (`AppAuthController::createUser()`) e relazione `User::app()` — bug preesistente reale ma indipendente, scoperto durante l'implementazione e poi scartato da questo diff perché non più necessario col meccanismo basato su `UgcTrack.uuid`; da trattare come ticket separato se rilevante

## Moduli toccati
- `wm-package/src/Models/App.php` (nuova media collection `story_frame`)
- `wm-package/src/Nova/App.php` (nuovo campo upload immagine, sezione da definire in `plan.md`, es. accanto a `icon`/`splash`)
- `wm-package/src/Services/AppConfigService.php` (esposizione URL `story_frame` in config.json)
- `wm-package/src/Services/Models/MediaService.php` o nuovo servizio dedicato (compositing via `intervention/image`)
- `wm-package/src/Http/Controllers/Api/...` (nuovo endpoint compositing)
- `wm-package/routes/api.php`
- Config builder del `config.json` generico (flag `ugc_track_share_enabled` per shard — file esatto da confermare in `plan.md`, probabile `AppController.php` già individuato per la generazione conf)

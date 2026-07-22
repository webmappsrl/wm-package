> Ticket: oc:8183

# Piano implementativo — flag conf, asset branding e compositing (wm-package)

Riferimento: `overview.md` in questa stessa cartella. Nessuna dipendenza dal componente mini-map di map-core (riceve solo un'immagine grezza qualunque), ma è un prerequisito per l'orchestrazione in `webmapp-app` — implementare prima di quella.

## Task

1. **Media collection `story_frame` su `App`**: aggiungere la collection in `wm-package/src/Models/App.php` (pattern già esistente per `icon`/`icon_small`/`splash`/`my_paths`/`my_downloads`).
   `feat(oc:8183): aggiungi collection media story_frame al modello App`

2. **Campo Nova per upload `story_frame`** in `wm-package/src/Nova/App.php` — `Images::make(...)` con validazione dimensioni coerente con l'output finale (9:16, es. 1080×1920), stesso pattern dichiarativo di `icon`/`splash` (che hanno `singleMediaRules` con `dimensions:`/`ratio:`, a differenza di `icon_small`/`my_paths`/`my_downloads` che non le hanno — usare il pattern più stringente qui per evitare che un frame con proporzioni sbagliate rompa il compositing silenziosamente, rischio identificato in Fase: challenge).
   `feat(oc:8183): campo Nova per upload story_frame su App`

3. **Esposizione URL `story_frame` in `config.json`**: `AppConfigService.php`, stesso meccanismo di `APP.myPaths` — **non** aggiungere il download a build-time nel gulp (quello è specifico del vincolo webp/iOS di `my_paths`/`my_downloads`, non applicabile qui).
   `feat(oc:8183): esponi URL story_frame nel config.json`

4. **Flag conf `ugc_track_share_enabled`**: esporlo nel config.json generico per shard (verificare il file esatto del builder, probabile `AppController.php` — confermare durante l'implementazione).
   `feat(oc:8183): flag conf ugc_track_share_enabled esposto per shard`

5. **Servizio di compositing dedicato** (nuova classe, non aggiungere a `MediaService.php` — quella classe ha oggi una responsabilità ristretta a `exif()`/manipolazione media generica, aumentarne la coesione impropriamente è un rischio identificato in Fase: challenge). Accetta: screenshot grezzo (qualunque aspect ratio) + valori statistici (tempo, km, dislivello) già calcolati dal client. Usa `intervention/image` (dipendenza esistente) per: inserire lo screenshot in una posizione/dimensione fissa all'interno del `story_frame`, scrivere il testo delle statistiche in posizioni fisse, produrre l'immagine finale a 1080×1920. Le coordinate di layout (dove va la mappa, dove va ciascun campo testo) sono costanti hardcoded in questo servizio — documentarle chiaramente in un unico punto (es. una classe/config di costanti) per facilitare un futuro aggiustamento se il frame cambia.
   `feat(oc:8183): servizio di compositing immagine Stories (mappa+statistiche+frame)`

6. **Font/stile testo statistiche**: scegliere se usare un font di sistema disponibile a `intervention/image`/GD sul server, o bundlare un font custom (`.ttf`) nel repo — verificare cosa è già disponibile nell'ambiente Docker PHP prima di aggiungerne uno nuovo.
   `feat(oc:8183): font e stile per il testo delle statistiche nel compositing`

7. **Fallback quando `story_frame` non è ancora stato caricato per l'app**: decisione presa in questo piano — ritornare lo screenshot grezzo centrato/paddato a 9:16 **senza** frame overlay (ma comunque con le statistiche, se i dati sono disponibili), loggare un warning server-side per rendere visibile il gap di configurazione. Evita un'esperienza rotta al primo utilizzo su una nuova istanza, pur senza branding.
   `feat(oc:8183): fallback quando l'app non ha ancora caricato story_frame`

8. **Nuovo endpoint `POST /api/share-story-image`** (nome definitivo da confermare): nessun `app`/`app_id` nel path o payload — l'app si ricava dal contesto di autenticazione (Sanctum/token esistente, verificare il meccanismo auth già in uso per altri endpoint API app-scoped). Accetta screenshot (multipart) + campi statistiche, richiama il servizio di compositing, ritorna l'immagine finale (binaria o base64 — verificare cosa si aspetta il client in `webmapp-app`, coordinare con quel piano).
   `feat(oc:8183): endpoint POST /api/share-story-image`

9. **Validazione dimensione massima file in ingresso**: limite esplicito in MB (valore da definire, es. 10MB, coerente con uno screenshot mappa) — nessun rate-limit dedicato in questo ciclo (decisione presa in Fase: challenge).
   `feat(oc:8183): validazione dimensione massima screenshot in ingresso`

10. **Registrazione route** in `wm-package/routes/api.php`, middleware di autenticazione.
    `feat(oc:8183): route per l'endpoint di compositing`

11. **Errore esplicito** (HTTP 4xx per input invalido, 5xx per fallimento compositing) — mai un 200 con immagine parziale/corrotta.
    `feat(oc:8183): gestione errori espliciti su fallimento compositing`

12. **Test**: unit test sul servizio di compositing (input fissi → verifica posizionamento/formato output), feature test sull'endpoint (autenticazione richiesta, validazione size, fallback frame mancante, errore su immagine non valida, verifica che l'app sia sempre derivata dal contesto auth e mai da un parametro cross-tenant).
    `test(oc:8183): test per servizio di compositing ed endpoint share-story-image`

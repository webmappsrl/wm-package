> Ticket: oc:8251

# Toggle QR code deep link nel backend + generazione automatica file well-known

## Cosa cambia
- Nuovo flag `native_app_deep_link_enabled` (rinominato da `qr_code_deep_link_enabled` durante l'implementazione, vedi notes.md) dentro `properties` (JSON) del modello `App`, esposto come toggle "Native App Deep Link" in Nova (sezione frontend mobile).
- Quando il toggle passa da `false` a `true`, viene aggiunta l'entry dell'app nel file well-known condiviso (`apple-app-site-association` per iOS, `assetlinks.json` per Android) ospitato sul server della webapp; quando passa a `false`, l'entry viene rimossa.
- Il file well-known viene aggiornato via script SCP: download del file esistente dal server remoto → merge (add/remove) della sola entry di questa app → upload. Nessun locking distribuito per la v1 (rischio race condition accettato, vedi Rischi).
- L'aggiornamento del well-known viene ritriggerato anche in due casi oltre al cambio di stato del toggle: (1) eliminazione di un'App con toggle attivo (rimuove l'entry), (2) modifica di `android_cert_sha256` mentre il toggle è già attivo (rigenera l'entry con il nuovo fingerprint).
- Nuovo campo Text `properties->android_cert_sha256` in Nova `App`, tab "App Release Data", visibile e modificabile solo da Administrator, con validazione formato esadecimale (`XX:XX:...`). Necessario per costruire l'entry Android in `assetlinks.json` — non recuperabile via API pubblica (solo Play Console API con OAuth, fuori scope).
- Team ID Apple configurabile per-app in Nova (`properties->apple_team_id`, admin-only) — se vuoto usa il default di codice condiviso da tutte le app Webmapp. Usato per comporre `appID = TEAMID.bundle_id` in `apple-app-site-association`. Nessun override via env.
- In Nova `EcTrack` e `EcPoi`: se l'app associata ha il toggle attivo, viene mostrata un'azione/campo che genera il link deep-link (`https://{dominio}/map?track={id}` o `?poi={id}`) e il QR code scaricabile corrispondente.
- Il `{dominio}` si risolve con questa priorità: (1) campo `website_url` dell'App se valorizzato; (2) default `{app_id}.{APP_NAME}.webmapp.it` (usa `config('app.name')`, non hardcoded, per distinguere le app tra le diverse installazioni Maphub che condividono lo stesso well-known registry). Nessun override via env.
- I Validator possono generare/scaricare il QR e il link anche per Track/Poi che non sono proprie — oggi non esiste alcuno scoping "per layer" su EcTrack/EcPoi in Nova (i Validator vedono già tutte le EcPoi in sola lettura per `EcPoiPolicy`, e tutte le EcTrack senza alcuna policy registrata). L'azione va resa utilizzabile dai Validator con il pattern già noto nel progetto (oc:7640): `$this->canRun(fn($request, $model) => true)` nel costruttore, senza introdurre nuovo scoping per layer.

## Perché
La feature di apertura dell'app nativa tramite QR/link diretto (universal link / app link) è già stata sviluppata lato app in oc:7980 (repo `webmapp-app`). Manca il lato backend: un modo per attivare/disattivare la funzionalità per singola app e per permettere ai gestori di generare il materiale (QR code, link) da usare su guide cartacee, cartelli e materiale promozionale.

## Requisiti
- [ ] Flag `native_app_deep_link_enabled` in `properties` di `App`, toggle Nova nella sezione frontend mobile
- [ ] Al cambio toggle: script che aggiorna (add/remove) l'entry dell'app nel file well-known condiviso sul server della webapp via SCP
- [ ] Script con logica download → merge → upload, senza locking (v1, azione amministrativa a bassa frequenza)
- [ ] Nuova connessione SFTP (`league/flysystem-sftp-v3`), credenziali in `.env` (non versionate), accesso via chiave SSH (non password)
- [ ] Nuovo campo Text `properties->android_cert_sha256` in Nova App > Release Data, admin-only, con validazione formato
- [ ] Team ID Apple: campo Nova per-app (`properties->apple_team_id`), fallback a costante di config se vuoto
- [ ] Azione/campo in Nova `EcTrack` e `EcPoi`: genera link deep-link + QR scaricabile, visibile solo se l'app associata ha il toggle attivo
- [ ] Dominio deep-link: priorità `website_url` (per-app) → default `{app_id}.{APP_NAME}.webmapp.it`, nessun override via env
- [ ] Validator autorizzato a generare/scaricare QR e link per qualsiasi Track/Poi (nessuno scoping per layer, coerente con l'assenza di tale scoping oggi su EcTrack/EcPoi); azione resa usabile con `canRun(fn => true)` come in oc:7640
- [ ] Traduzioni it/en per tutte le nuove label Nova (toggle, campo fingerprint, azione QR)
- [ ] Se un'App con toggle attivo viene eliminata, l'entry viene rimossa dal file well-known (hook su `deleting()` dell'App)
- [ ] Se `android_cert_sha256` cambia mentre il toggle è già attivo, viene ritriggerato l'aggiornamento dell'entry nel file well-known (non solo al cambio di stato del toggle)
- [ ] Backup del file well-known esistente (copia con timestamp) prima di ogni overwrite, per rollback manuale rapido in caso di scrittura errata
- [ ] Validazione JSON di base (decode + verifica struttura minima attesa) prima dell'upload — blocca l'upload se il merge ha prodotto un file corrotto

## Rischi
- **Race condition sul file well-known condiviso**: più istanze Maphub potrebbero toccare il toggle nello stesso momento e sovrascriversi a vicenda. Accettato per v1 vista la bassa frequenza dell'azione (toggle amministrativo raro); da rivedere con locking (es. `flock` remoto) se si presentano conflitti reali in produzione.
- **Dipendenza da accesso SSH esterno**: lo script richiede credenziali verso un server terzo (quello della webapp) — single point of failure se cambia configurazione/hosting di quel server. Nessuna API lato webapp disponibile per disaccoppiare (il server è solo frontend).
- **Certificate fingerprint SHA-256 inserito manualmente**: rischio di errore umano o formato non valido che romperebbe l'App Link per l'intera piattaforma condivisa (tutte le app nel file well-known), non solo per l'app in questione. Mitigato con validazione formato lato Nova, ma non garantisce la correttezza del valore (solo la sintassi).
- **File well-known malformato**: mitigato con backup con timestamp prima di ogni overwrite + validazione JSON di base prima dell'upload (vedi Requisiti) — riduce ma non azzera il rischio, dato che la validazione verifica solo la struttura, non la correttezza semantica del contenuto.

## Out of scope
- Integrazione con Play Console API per recupero automatico del certificate fingerprint
- Locking distribuito per scritture concorrenti sul file well-known (rimandato a iterazione futura)
- Sviluppo di un endpoint API sul server della webapp (resta puramente frontend)
- Modifiche al repo `webmapp-app` (oc:7980, feature nativa già esistente e fuori scope di questo ticket)

## Modifica (21-07-2026)

Richiesta emersa in call (Davide Nanna, riportata da Giuseppe Bonfanti il 21-07-2026): oltre al QR code, mostrare accanto anche il link testuale/URL diretto, come già fatto per l'embed del widget (`Layer::renderLayerWebComponentCopyButton()`), così da avere un'alternativa quando la scansione del QR non è praticabile.

**Nota:** `notes.md` (riga 7) documentava già l'intento originale di un "QR code e link copiabile", andato perso nell'implementazione finale (solo download). Il test `tests/Feature/DeepLinkQrFieldVisibilityTest.php` (righe 113, 115) asserisce già `/map?track={id}` visibile e `navigator.clipboard.writeText` — nessuna modifica al test necessaria, l'implementazione li farà tornare veri.

### Requisiti aggiuntivi
- [ ] URL diretto mostrato come testo cliccabile accanto al QR (a destra su desktop, sotto su mobile — sfruttando il layout responsive già esistente `flex-col sm:flex-row`)
- [ ] Bottone "Copia link" con `navigator.clipboard.writeText` (stesso pattern/fallback di `Layer::renderLayerWebComponentCopyButton()`), copia l'URL invece dello snippet embed
- [ ] Nuove traduzioni it/en: "Copy link"/"Copia link", "Link copied"/"Link copiato" (riuso di "Copy error" già esistente)

## Moduli toccati
- `src/Models/App.php` — flag in `properties`, eventuale helper per risoluzione dominio deep-link
- `src/Nova/App.php` — toggle Nova (frontend mobile), campo `android_cert_sha256` (Release Data)
- `src/Nova/EcTrack.php`, `src/Nova/EcPoi.php` — azione/campo per generazione deep-link + QR, condizionata al toggle dell'app associata
- `src/Http/Clients/` — nuovo client/servizio per l'aggiornamento del file well-known via SCP (download/merge/upload)
- `config/wm-package.php` — costante Team ID Apple
- `composer.json` — nuova dipendenza `league/flysystem-sftp-v3`
- `resources/lang/it.json`, `resources/lang/en.json` — nuove traduzioni

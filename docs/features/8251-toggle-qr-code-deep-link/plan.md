> Ticket: oc:8251

# Piano di implementazione — Toggle QR code deep link + well-known registry

Riferimento: `docs/features/8251-toggle-qr-code-deep-link/overview.md` (requisiti, rischi, out of scope).

Convenzione commit: `feat(oc:8251): ...` / `fix(oc:8251): ...` / `test(oc:8251): ...` — commit testuali nel piano, non eseguiti automaticamente (vedi gate di revisione del workflow).

---

## 1. Flag toggle sull'App

- Aggiungere costante/accessor su `src/Models/App.php` per leggere/scrivere `properties['qr_code_deep_link_enabled']` (bool, default `false`)
- In `src/Nova/App.php`, sezione frontend mobile: aggiungere `Boolean::make(__('Enable QR Code Deep Link'), 'properties->qr_code_deep_link_enabled')`
- Commit: `feat(oc:8251): add qr_code_deep_link_enabled flag to App`

## 2. Campo certificate fingerprint Android

- In `src/Nova/App.php`, tab "App Release Data": `Text::make(__('Android Certificate SHA-256'), 'properties->android_cert_sha256')`
- Validazione formato (regex `^([0-9A-Fa-f]{2}:){31}[0-9A-Fa-f]{2}$`) via `->rules()`
- `->canSee()` / campo visibile e modificabile solo da Administrator (pattern coerente con altri campi admin-only già presenti nel resource)
- Commit: `feat(oc:8251): add android_cert_sha256 field to App Release Data`

## 3. Team ID Apple in config

- Aggiungere `'apple_team_id' => env('WMPACKAGE_APPLE_TEAM_ID')` a `config/wm-package.php`
- Documentare in `.env.example` del package (se esiste) o nel README del modulo
- Commit: `feat(oc:8251): add apple_team_id config constant`

## 4. Risoluzione dominio deep-link

- Nuovo metodo `App::getDeepLinkDomain(): string` — ritorna `website_url` se valorizzato (validato come URL/dominio), altrimenti fallback a `https://{$this->id}.app.webmapp.it` (stesso default di `generateQrCode()`)
- Nuovo metodo `App::getDeepLinkUrl(string $type, int $id): string` — costruisce `https://{dominio}/map?track={id}` o `?poi={id}` in base a `$type`
- Test unitari su `App::getDeepLinkDomain()` (con e senza `website_url`) e `App::getDeepLinkUrl()`
- Commit: `feat(oc:8251): add deep link domain and URL resolution to App`

## 5. Setup connessione SFTP

- Aggiungere `league/flysystem-sftp-v3` a `composer.json`
- Nuovo disk `well_known_registry` in config filesystem del package (host, utente, chiave privata, path remoto — tutti da `.env`)
- Variabili `.env.example`: `WELLKNOWN_SFTP_HOST`, `WELLKNOWN_SFTP_USERNAME`, `WELLKNOWN_SFTP_PRIVATE_KEY_PATH`, `WELLKNOWN_SFTP_ROOT`
- Commit: `feat(oc:8251): add SFTP disk for well-known registry`

## 6. Client di aggiornamento well-known

- Nuova classe `src/Services/WellKnownRegistryService.php` (o `src/Http/Clients/WellKnownRegistryClient.php`, seguendo il pattern di `JsonClient` dove applicabile per la parte HTTP-like, ma qui è I/O su file remoto via disk SFTP):
  - `addAppEntry(App $app): void` — scarica `apple-app-site-association` e `assetlinks.json` esistenti, aggiunge/aggiorna la entry di questa app (chiave = `sku`), valida JSON, fa backup con timestamp del file precedente, ricarica
  - `removeAppEntry(App $app): void` — stessa logica ma rimuove la entry
  - Metodo privato di costruzione entry iOS (`appID = "{config('wm-package.apple_team_id')}.{$app->sku}"`, path `/map*`) e Android (`package_name = $app->sku`, `sha256_cert_fingerprints = [$app->properties['android_cert_sha256']]`)
  - Validazione JSON di base (decode + verifica chiavi attese) prima dell'upload; se non valido, log errore e non procede con l'upload
- Test unitari con disk SFTP fake (`Storage::fake`) per: aggiunta entry, rimozione entry, backup creato, upload bloccato su JSON non valido
- Commit: `feat(oc:8251): add WellKnownRegistryService for assetlinks/apple-app-site-association sync`

## 7. Trigger osservazionali

- In `AppObserver` (`src/Observers/AppObserver.php`, già esistente — verificare hook `saved`/`updating` disponibili):
  - Su `saved()`: se `qr_code_deep_link_enabled` è cambiato da `false` a `true` → `WellKnownRegistryService::addAppEntry()`; da `true` a `false` → `removeAppEntry()`
  - Su `saved()`: se `android_cert_sha256` è cambiato **e** il toggle è già `true` → richiama `addAppEntry()` per rigenerare l'entry con il nuovo fingerprint
  - Su `deleting()`: se il toggle era `true` → `removeAppEntry()`
- Test feature: toggle da false→true chiama addAppEntry, true→false chiama removeAppEntry, cambio fingerprint a toggle attivo richiama addAppEntry, cancellazione App con toggle attivo richiama removeAppEntry
- Commit: `feat(oc:8251): wire well-known registry updates to App observer`

## 8. Azione/campo QR e deep-link su EcTrack e EcPoi

- Nuova Nova Action (o campo `Text`/`Heading` con markup, valutare in fase di implementazione quale si adatta meglio al pattern UI esistente) su `src/Nova/EcTrack.php` e `src/Nova/EcPoi.php`:
  - Visibile solo se `$this->model()->app->properties['qr_code_deep_link_enabled'] ?? false` è `true` (via `canSee`)
  - Genera l'URL con `App::getDeepLinkUrl('track'|'poi', $model->id)` e il QR code (riusa `chillerlan/QRCode`, stesso approccio di `App::generateQrCode()`)
  - `$this->canRun(fn($request, $model) => true)` nel costruttore, per renderla usabile anche dai Validator (pattern oc:7640)
- Verificare se esiste già un trait/base comune per azioni condivise tra EcTrack/EcPoi da estendere, altrimenti duplicare la logica minimamente (non introdurre astrazioni premature per due soli modelli)
- Commit: `feat(oc:8251): add deep link + QR generation action to EcTrack and EcPoi`

## 9. Traduzioni

- Aggiungere le chiavi it/en per: label toggle, label campo fingerprint, nome e messaggi dell'azione QR (`resources/lang/it.json`, `resources/lang/en.json`)
- Commit: `feat(oc:8251): add it/en translations for QR deep link feature`

## 10. Test end-to-end del flusso (entro i limiti testabili senza accesso reale al server SFTP)

- Feature test completo: toggle attivato su App con `android_cert_sha256` valorizzato → verifica che il disk SFTP fake riceva il file well-known aggiornato con la entry corretta (iOS + Android)
- Feature test: azione QR su EcTrack/EcPoi non visibile se il toggle dell'app è `false`, visibile se `true`
- Feature test: azione QR eseguibile da un utente con ruolo Validator
- Commit: `test(oc:8251): add feature tests for well-known sync and QR action visibility`

---

## 11. Modifica (21-07-2026) — link diretto + bottone copia accanto al QR

Riferimento: `overview.md`, sezione "Modifica (21-07-2026)".

- In `src/Models/App.php::renderDeepLinkQrCodeHtml()`: aggiungere un secondo blocco `<div>` sibling (dentro il container flex esistente `sm:flex-row`) con:
  - l'URL diretto come testo cliccabile (`<a href="{$escapedUrl}" target="_blank" rel="noopener noreferrer">`)
  - un bottone "Copia link" con `onclick` JS (`navigator.clipboard.writeText` + fallback `execCommand('copy')`), stesso schema di `Layer::renderLayerWebComponentCopyButton()` ma copia `$url` invece dello snippet embed — estratto in un metodo privato dedicato `renderDeepLinkCopyButton(string $url): string` per non appesantire `renderDeepLinkQrCodeHtml()`
- Nuove chiavi traduzione in `resources/lang/it.json` / `resources/lang/en.json`: `"Copy link"` / `"Copia link"`, `"Link copied"` / `"Link copiato"` (riuso di `"Copy error"` già esistente)
- Nessuna modifica al test `tests/Feature/DeepLinkQrFieldVisibilityTest.php` (repo principale) — le assertion su `/map?track={id}`/`/map?poi={id}` visibile e `navigator.clipboard.writeText` esistono già (righe 113, 115) e verificano esattamente questo comportamento
- Verifica: eseguire il test dopo l'implementazione (richiede che il DB di test abbia le migration applicate — problema ambientale pre-esistente e scorrelato, vedi nota separata)
- Commit: `feat(oc:8251): show copyable direct link next to deep link QR code`

---

## Note per l'esecuzione

- Le credenziali SFTP reali non sono disponibili in questo ambiente (vedi overview, Rischi) — lo sviluppo e i test usano `Storage::fake()`/mock del disk. La verifica end-to-end contro il server reale della webapp resta un task successivo, da eseguire quando l'accesso SSH a chiave sarà configurato (fuori dallo scope di questo ciclo di sviluppo).
- Nessuna migration richiesta — tutti i nuovi campi vivono in `properties` (JSON) esistente.

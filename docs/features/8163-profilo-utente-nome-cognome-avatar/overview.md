> Ticket: oc:8163

# Profilo utente: nome, cognome e avatar (wm-package)

## Cosa cambia

Il modello `User` e `AppAuthController` di wm-package vengono estesi per supportare cognome e avatar:

- `User` guadagna una colonna `surname` opzionale e diventa `HasMedia` (Spatie Media Library) con una collection `avatar` (single file).
- `AppAuthController::update` accetta `surname` (`sometimes`) e `avatar` (file, `sometimes`); salva il cognome su DB e la foto tramite `addMediaFromRequest()->toMediaCollection('avatar')`.
- `AppAuthController::me` restituisce `surname` e `avatar_url` (null se assenti).
- Al signup, un job asincrono (`Wm\WmPackage\Jobs\Abstract\BaseJob`) verifica se l'email ha un Gravatar reale (`https://www.gravatar.com/avatar/{md5(email)}?d=404` → 200 = immagine reale, 404 = nessuna) e, se sì, lo scarica e salva come avatar via `addMediaFromUrl()`.

## Perché

Il cliente vuole che gli utenti abbiano un'identità riconoscibile nell'app, come base per le feature social future (esperienze, passaporto, badge).

## Requisiti

- [ ] Migration stub pubblicabile: colonna `surname` (nullable string) su `users`
- [ ] `User` model: `surname` aggiunto a `$fillable`; implementa `HasMedia`, usa `InteractsWithMedia`; `registerMediaCollections()` con `singleFile()` per la collection `avatar`
- [ ] `AppAuthController::update`: validazione `surname` (`sometimes|string|max:255`), `avatar` (`sometimes|file|image|max:5120`); persistenza `surname` su DB; avatar via `addMediaFromRequest('avatar')->toMediaCollection('avatar')`
- [ ] `AppAuthController::me`: risposta include `surname` (null se assente) e `avatar_url` (`getFirstMediaUrl('avatar') ?: null`)
- [ ] Job asincrono Gravatar dispatchato da `createUser()` al signup: se Gravatar risponde `200` (immagine reale), salva come avatar; se risponde `404` esplicito, nessun avatar (fallback iniziali gestito lato client). Qualsiasi altra risposta (429 rate-limit, timeout, 5xx) è trattata come fallimento del job, non come "nessun avatar" — nessun retry automatico previsto in questo ciclo (coerente con "il job non blocca il signup"), ma il log deve distinguere esplicitamente i due casi per non mascherare problemi di rete/rate-limit come assenza legittima di Gravatar
- [ ] Job estende `Jobs\Abstract\BaseJob` (pattern esistente, con `getRedisLockKey()`/`getLogChannel()`)
- [ ] Strip dei metadati EXIF (inclusi eventuali dati GPS) dall'immagine avatar caricata dall'utente, prima del salvataggio in `AppAuthController::update` — non richiesto per l'avatar auto-popolato da Gravatar (già un'immagine pubblica gestita da terzi)

## Rischi

- **Breaking change wm-package condiviso** — le modifiche a `AppAuthController` e `User` impattano tutti gli shard (maphub, osm2cai2, carg, camminiditalia, forestas). Mitigato rendendo `surname` e `avatar` opzionali (`sometimes`/nullable) e la migration uno stub pubblicabile: nessuno shard è impattato finché non pubblica esplicitamente la migration.
- **Job Gravatar asincrono non blocca il signup** — se il job fallisce (rete, Gravatar down), l'utente parte semplicemente senza avatar: comportamento corretto, non un errore da gestire.
- **`User` non estende `GeometryModel`** — `HasMedia`/`InteractsWithMedia` vanno aggiunti direttamente al modello `User`, che già usa `Favoriteability`, `HasApiTokens`, `HasPackageFactory`, `HasRoles`, `Impersonatable`, `Notifiable`. Va verificata l'assenza di conflitti di metodo tra questi trait e `InteractsWithMedia` prima di considerare il task chiuso.
- **`HasMedia`/`InteractsWithMedia` su `User` non è gated per-shard come `surname`** — a differenza della colonna (dietro migration stub opt-in), l'aggiunta dei trait si spedisce a tutti e 5 gli shard (maphub, osm2cai2, carg, camminiditalia, forestas) nello stesso rilascio di wm-package. Se `AppAuthController::me()` chiamasse incondizionatamente `getFirstMediaUrl('avatar')` su uno shard privo della tabella polimorfica `media` di Spatie, ogni chiamata `/me` fallirebbe con errore SQL (outage totale login su quello shard). **Verificato preventivamente** (emerso in Fase: challenge): tutti e 5 gli shard hanno già la migration `create_media_table` pubblicata ed eseguita (usata da tempo da `GeometryModel`) — il rischio di tabella mancante è quindi escluso, non solo mitigato.
- **Collection `avatar` deve essere `singleFile()`** — altrimenti ogni update accumula media invece di sostituire l'avatar precedente.
- **[GDPR] Fetch Gravatar automatico invia l'hash MD5 dell'email a un servizio terzo (Gravatar/Automattic) al signup, senza consenso esplicito distinto dalla privacy generale** — decisione presa col developer: si mantiene il fetch automatico (non opt-in) perché in questo ciclo l'avatar è visibile solo al proprietario stesso (out of scope la visibilità ad altri utenti/Nova, vedi sopra) — il rischio di esposizione a terzi non aumenta oltre quanto già coperto dal consenso privacy generale esistente (`privacy.agree`/`privacy.date`). **Richiede verifica legale esplicita prima del deploy in produzione** (non bloccante per lo sviluppo, ma da chiudere prima del rilascio): confermare con il cliente/legale se il fetch Gravatar rientra nell'informativa privacy esistente o ne richiede una dedicata. Quando in un ciclo futuro l'avatar diventerà visibile ad altri utenti (feature social: esperienze, passaporto, badge), sarà necessario un consenso privacy esplicito e separato per quel momento — non coperto da questo ticket.

## Out of scope

- Visibilità del profilo ad altri utenti o nel backoffice Nova
- Avatar generati da servizi terzi oltre Gravatar

## Moduli toccati

- `src/Models/User.php`
- `src/Http/Controllers/Api/AppAuthController.php`
- `database/migrations/` (stub pubblicabile, es. `zz_2026_07_24_000001_add_surname_to_users_table.php.stub`)
- `src/Jobs/` (nuovo job, es. `FetchGravatarAvatarJob.php`)

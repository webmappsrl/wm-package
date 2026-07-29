# Note di implementazione — oc:8163

## Task 2: User model — surname, HasMedia, collection avatar

### Nessun conflitto di trait con `InteractsWithMedia`
Verificato che `Favoriteability`, `HasApiTokens`, `HasPackageFactory`, `HasRoles`, `Impersonatable`,
`Notifiable` coesistono senza collisioni di metodi con `InteractsWithMedia`. In particolare tutti i
test di impersonation (`ImpersonationHttpTest`, `ImpersonationAuthorizationTest`,
`Nova/AbstractUserResourceImpersonateTest`) passano invariati dopo l'aggiunta di `HasMedia`.

### Bug pre-esistente scoperto e corretto: `MediaObserver::validateModelHasAppId`
Aggiungere `avatar` come prima media collection su `User` ha fatto emergere un bug pre-esistente in
`src/Observers/MediaObserver.php`, non relativo a conflitti di trait: `validateModelHasAppId(Model $model)`
chiamava `$this->setDefaultValues($model)` e `$this->handleException($e, $model)` — entrambi i metodi
sono type-hintati per accettare `Media $media`, non il model proprietario. Questo branch si attiva
quando `$model->app_id` è null (non solo assente) e il model non è un'istanza di `App` — condizione
mai vera prima per i model esistenti (Layer, EcTrack, ecc. hanno sempre `app_id` valorizzato), ma vera
di default per `User` (colonna `app_id` nullable, non valorizzata da `UserFactory`). Il branch andava in
crash con `TypeError` per QUALSIASI model l'avesse raggiunto, quindi non era una scelta di design ma
codice morto/rotto. Fix: passato `$media` invece di `$model` in entrambe le chiamate, e aggiornata la
firma del metodo a `validateModelHasAppId(Model $model, Media $media)`.

### Test fixture: necessaria un'App con `app_id` reale, non nel test verbatim del brief
Il test verbatim dello Step 1 del brief non crea una `App` prima di allegare la media a `User`. Ma
`media.app_id` è una FK NOT NULL verso `apps`, e nel DB di test usato (`postgres-camminiditalia`) la
tabella `apps` è vuota — il fallback hardcoded `app_id = 1` in `MediaObserver::setDefaultValues()` fallisce
con violazione di FK. A differenza di `Layer` (che si auto-assegna `app_id` da `App::first()` nel suo
`boot()`), `User` non ha questo meccanismo. Soluzione: helper `makeUserForMedia()` in
`tests/Feature/UserAvatarMediaTest.php` che crea una `App::factory()->createQuietly()` e assegna il suo
id a `User::factory()->create(['app_id' => $app->id])`, stesso pattern già usato da
`LayerLogoMediaTest::makeLayerForMedia()`. Usato solo nei due test che allegano media; i test su
`surname` e su `avatar_url` nullo restano verbatim dal brief (non serve media, non serve app_id).

### Limite ambientale: suite completa (`vendor/bin/pest wm-package/tests`) non eseguibile in questo container
Il comando indicato per lo Step 5 fallisce già in fase di collection, prima di eseguire qualsiasi test,
per motivi indipendenti da questo ticket:
- ~48 file di test usano `Wm\WmPackage\Tests\TestCase` (namespace proprio del pacchetto, via
  `autoload-dev` → `Wm\WmPackage\Tests\` → `tests/`), ma il `vendor/composer/autoload_psr4.php` compilato
  in questa immagine docker (`wm-phpfpm:8.4`, montata sull'app principale `camminiditalia`) non contiene
  questa entry — probabilmente perché il vendor è stato buildato senza le dev-dependency del pacchetto.
- Diversi di questi file richiedono direttamente `Orchestra\Testbench\TestCase`, non installato in questo
  vendor.
- Questo è confermato pre-esistente e indipendente dal diff di questo task: nessuno di questi file risulta
  modificato (`git status` pulito su di essi), e il gap è a livello di autoload compilato, non di codice
  sorgente di test.

Come mitigazione, ho eseguito il sottoinsieme di test effettivamente eseguibile in questo container (i
file che usano `Tests\TestCase`, il namespace dell'app principale — incluso il nuovo
`UserAvatarMediaTest`): 93 passed, 25 failed. Ho verificato che i 25 fallimenti sono pre-esistenti e non
correlati a `User`/`HasMedia`/trait: riguardano `AppConfigServiceOverlaysTest`,
`GeohubImportServiceAssociateLayerPoiTest`, `InteractsWithWmPackageMigrationStubsTest`,
`LayerAssignPoisByTaxonomyTest`, `Nova/Actions/BulkEditActionFeatureTest`,
`WmPackagePublishMissingMigrationsCommandTest` — tutti falliscono per `App::first()` che ritorna null
(tabella `apps` vuota in questo DB di test) o per asserzioni sul contenuto reale delle migration su disco
(divergenza locale). Riprodotto lo stesso fallimento eseguendo `AppConfigServiceOverlaysTest` e
`BulkEditActionFeatureTest` in isolamento, senza alcun file di questo task nel run — confermato
indipendente dal diff.

**Nessun test esistente fallisce per conflitto di metodo con `InteractsWithMedia`** — la clausola
condizionale dello Step 5 non si applica.

## Task 3: `AppAuthController::update()` — surname/avatar/EXIF

### Bug pre-esistente scoperto e corretto: `filterUserPrivacyByAppId` con header `app-id` assente
`update()` chiamava incondizionatamente `filterUserPrivacyByAppId($user, $appIdForResponse)`, il cui
secondo parametro è tipizzato `string $appId` non nullable. Nessun test del brief invia l'header
`app-id`, quindi ogni richiesta andava in `TypeError`. Fix: stesso guard già usato da `me()` —
`$appIdForResponse ? filterUserPrivacyByAppId(...) : $user->toArray()`.

### Fixture EXIF con GPS reale, generata a mano
Nessun tool (`exiftool`, Imagick) disponibile nel container per generare rapidamente un JPEG con blocco
EXIF GPS. Costruita via script PHP con GD: JPEG 400×400 + segmento APP1/EXIF costruito byte a byte
(IFD0 → puntatore GPSInfo → sub-IFD con GPSLatitude/GPSLongitude 45°N 11°E), verificato con
`exif_read_data()` prima di usarlo come fixture (`tests/fixtures/avatar-with-gps-exif.jpg`).

## Task 5: Job Gravatar

### `app_id` esplicito per evitare il fallback hardcoded di `MediaObserver`
Sia `update()` (avatar caricato manualmente) sia `FetchGravatarAvatarJob` passano `app_id` esplicito
a `withAttributes(['app_id' => ...])` prima di `toMediaCollection('avatar')`, per evitare che
`MediaObserver::setDefaultValues()` assegni silenziosamente `app_id = 1` (dato cross-tenant errato su
shard multi-app). Per l'endpoint, la fonte è l'header `app-id` della richiesta; per il job, un secondo
parametro `?int $appId` nel costruttore.

## Final review (whole-branch) — 4 fix critici in un'unica ondata

Prima di considerare il piano concluso, una review finale sull'intero branch ha trovato 4 problemi
Important/Critical, tutti corretti in un'unica ondata di fix con re-review scoped:
1. **`app_id = 1` hardcoded per utenti senza header** — vedi sopra (fix applicato a `update()` e al job).
2. **Il job Gravatar sovrascriveva un avatar caricato manualmente dall'utente** — aggiunta guardia
   `if ($user->getFirstMedia('avatar')) return;` prima di scaricare/allegare l'immagine Gravatar.
3. **Estensione file temporaneo controllata dal client** — `stripExifFromUploadedImage()` usava
   `getClientOriginalExtension()` (falsificabile, causava 500 su upload con estensione errata); sostituito
   con `$file->extension()` (dedotto dal contenuto reale, non dal nome file).
4. **Dispatch del job Gravatar poteva far fallire il signup** — se la coda è down, `dispatch()` può
   lanciare un'eccezione; isolato in un try/catch separato da quello di `$user->save()`, così un fallimento
   del dispatch non fa fallire la creazione dell'utente.

## Task 6 (post-hoc, richiesta esplicita dell'utente): comando di backfill Gravatar

**Deviazione dal piano originale**: non nell'overview.md — l'utente ha chiesto esplicitamente, durante
l'esecuzione, un modo per popolare l'avatar Gravatar anche per gli utenti già esistenti (il job gira solo
al signup di nuovi utenti). Implementato `wm:backfill-gravatar-avatars`.

### Bug reale trovato e corretto durante la review formale: `--app-id` filtrava/attribuiva su una colonna quasi sempre NULL
La prima versione del comando usava `$user->app_id` sia per filtrare quali utenti processare
(`--app-id` opzionale) sia per attribuire l'`app_id` al media Gravatar scaricato. **Verificato sui dati
reali del DB locale (ripristinato da dump)**: su 5129 utenti, solo **3** hanno `app_id` valorizzato — la
stragrande maggioranza degli utenti registrati via app ha questa colonna `NULL` (non viene mai
valorizzata da `AppAuthController::createUser()`). Conseguenze del bug:
- Il filtro `--app-id` era di fatto inefficace (non selezionava quasi nessun utente reale).
- L'attribuzione `$user->app_id` era quasi sempre `null`, facendo ricadere il job Gravatar sul fallback
  hardcoded `app_id = 1` di `MediaObserver` — esattamente il problema cross-tenant che il resto del
  ticket previene esplicitamente altrove (vedi sopra, punto 1 della final review).

**Fix**: `--app-id` è ora **obbligatorio** e viene usato per attribuire l'`app_id` al media di **tutti**
gli utenti processati nell'esecuzione (non per filtrare quali utenti selezionare — la selezione resta
"tutti gli utenti senza avatar", indipendentemente dal loro `app_id`). Corretto per shard con una singola
App (come `camminiditalia`, verificato: `apps` count = 1); per shard multi-app la selezione utenti
resterebbe comunque "tutti", limite noto non risolto in questo ciclo (`User.app_id` non è un segnale
affidabile di affiliazione app per nessun utente, non solo per il backfill).

Test aggiornati di conseguenza: rimosso il test "filters by --app-id" (comportamento non più presente),
aggiunto un test che verifica l'`app_id` del comando viene assegnato al job anche quando
`user.app_id` è `null`. Rimosso anche il test "zero users to process" originale: con il filtro per
app_id rimosso, non esiste più una combinazione di opzioni che garantisca deterministicamente zero
risultati contro il DB di sviluppo reale e popolato usato da questi test (`DatabaseTransactions`, non
uno schema isolato) — il branch `if ($total === 0)` resta comunque banale e auto-evidente.

**Eseguito realmente in locale** (prima di scoprire il bug, con la versione poi corretta): il comando è
stato lanciato sul DB locale ripristinato dal dump (5128 job accodati). Dato che questo shard ha una sola
App (`app_id = 1` è l'unica App reale), l'attribuzione `app_id = 1` risultante dal fallback di
`MediaObserver` era **per coincidenza corretta** in questo caso specifico — non richiede pulizia dei dati
locali già scaricati. Il fix di codice resta comunque necessario per la correttezza generale (altri shard
multi-app userebbero questo comando in modo non sicuro con la versione precedente).

## Task 7 (post-hoc, richiesta esplicita dell'utente): campo Nova con fallback Gravatar

**Deviazione dal piano originale**: l'overview.md escludeva esplicitamente "Visibilità del profilo... nel
backoffice Nova" dallo scope. L'utente ha chiesto esplicitamente, durante l'esecuzione, di poter vedere in
Nova l'avatar del nuovo sistema con fallback sul Gravatar live. Implementato `Nova\Fields\UserAvatar`
(estende `Avatar`, non `Gravatar` di Nova direttamente — stesso pattern del field nativo ma con
`avatar_url` come priorità), sostituisce `Gravatar::make()` in `AbstractUserResource`. Nessuna richiesta
di rete server-side per il fallback: la formula dell'URL Gravatar viene solo calcolata, non scaricata.

## Review formale (`wm-skills:wm-review-ticket`) — bug reale trovato e corretto: il cognome non si poteva mai svuotare

**Bug**: `if ($surname) { $updateData['surname'] = $surname; }` in `update()` — `''` è falsy in PHP, quindi
svuotare il campo cognome dal frontend (che invia correttamente `surname: ''`, non `undefined`, per questo
esatto motivo) non aveva alcun effetto: il valore precedente restava in DB. Confermato indipendentemente
da 2 dei 5 finder della review formale.

**Causa più sottile della sola truthy-check**: anche cambiando a `if ($surname !== null)`, il test falliva
comunque con 400 invece di 200 — Laravel 11 converte di default le stringhe vuote in `null` prima della
validazione (`ConvertEmptyStringsToNull`, middleware di default in `bootstrap/app.php`), quindi
`$request->input('surname')` per un client che invia `''` arriva già come `null` al controller, e la regola
di validazione `sometimes|string` rifiuta `null`.

**Fix definitivo**: regola di validazione `sometimes|nullable|string|max:255`; nel controller,
`$request->has('surname')` (non il valore) per distinguere "campo non inviato" da "utente ha svuotato il
campo", persistendo `(string) $surname` (cast di `null` a `''`). Aggiunto test
`clears an existing surname when surname is sent as an empty string`.

> Ticket: oc:8997
> impronta `d7664dcee474903f440a76df8cd59aa9b076ac72` dell'overview approvata

# Note di lavorazione — wm-package

Il diario del lavoro. L'overview non si tocca durante l'esecuzione: ogni cambiamento a un
requisito si annota qui, e alla chiusura `wm-plan` riporta queste voci nell'overview.

## Modifiche ai requisiti

Nessuna finora.

## Divergenze dal piano

Nessuna finora.

## Bug trovati

- **La suite del package non si lancia intera, né per directory.** `vendor/bin/pest` si ferma su
  `tests/Feature/AddSurnameToUsersMigrationTest.php` (`Tests\TestCase` non trovata), e una
  directory qualsiasi — provata `tests/Unit/Dto`, che questo lavoro non tocca — si ferma su
  «Test case `Wm\WmPackage\Tests\TestCase` can not be used». Preesistente: i test del package si
  lanciano un file per volta. Coerente con la trappola già documentata, che dice che la CI del
  package non gira.
- **Il database di test `wm_package` era indietro.** Mancava `app_taxonomy_term.models`, perché
  `vendor:publish` non sovrascrive le migration già pubblicate in
  `vendor/orchestra/testbench-core/laravel/database/migrations`, e la regola del repo è «si
  modifica la create della tabella». Svuotata quella cartella e ricreato lo schema: i 16 test di
  `EcTrackGeojsonTaxonomiesTest` che fallivano ora passano. Va saputo da chi lavora al package
  dopo un bump che cambia una create.
- **`StorageService::getShardName()` fa fallire i test che non configurano `shard_name`.** Il
  metodo dichiara `string` e restituisce `config('wm-package.shard_name', 'webmapp')`, che su una
  chiave esistente e valorizzata `null` restituisce `null`, non il default. In testbench né
  `SHARD_NAME` né `APP_NAME` sono valorizzati: falliscono i 6 test di
  `tests/Unit/Services/StorageService/` (verificato prima di ogni modifica di questa sessione) e i
  5 di `tests/Feature/TrailRegistry/WalkabilityApiTest.php` (stessa causa, non verificato prima).
  Non è un effetto di questo lavoro e non è stato corretto qui.
- **`UpdateTracksOnAws` è codice morto.** La classe
  `src/Nova/Actions/UpdateTracksOnAws.php` non è elencata nell'`actions()` di nessuna Resource:
  in Nova non compare. La voce «Update Tracks on AWS» che si vede sulla Resource `EcTrack` è
  `ExecuteEcTrackDataChainAction` istanziata con il solo `UpdateEcTrackAwsJob`
  (`src/Nova/EcTrack.php:174-176`). Scoperto in pianificazione; su richiesta del dev si è messo
  solo un commento in testa alla classe, senza registrarla né cancellarla.

## Decisioni

- **La catena delle trasformazioni non è scritta: c'è un passo solo.** Con due versioni non c'è
  niente da incatenare, e `build()` conosce la sola trasformazione verso la versione senza numero.
  Perché il giorno della v2 non passi inosservato, `build()` lancia `LogicException` su una
  versione per cui non ha una trasformazione, invece di ripiegare sulla forma corrente: scrivere
  la forma `v2` sotto il path `v1/` sarebbe un file sbagliato che nessuno vede. La pagina di
  knowledge e il docblock dicono questo, non che la catena esiste già.
- **L'approval test congela ogni versione.** Non solo quella senza numero: ogni versione è un
  contratto verso qualcuno, e un refactor che cambiasse la forma della `v1` romperebbe chi la sta
  adottando senza che nessun test se ne accorgesse. `TrackJsonShapeTest` cicla su
  `WmfeTrackVersion::cases()` e tiene una fixture per versione; una versione nuova non richiede di
  toccare il test, solo un giro con `WM_CAPTURE_SHAPE=1`.
- **L'approval test congela anche i valori, con dati di produzione.** La forma da sola non vede un
  filtro che smette di essere applicato o un campo calcolato al contrario. I valori sono
  deterministici se i dati di input non cambiano, quindi le properties vengono da un sentiero vero
  (la traccia 622 di forestas su dev, la più ricca del database), versionate **intere** in
  `tests/fixtures/track/production-track-input.json` — l'HTML dei Blocchi Dettaglio e le
  descrizioni non sono accorciati, perché un testo tagliato a metà tag è un input che in
  produzione non esiste. Fuori resta il solo blocco `forestas`, che è il punto di estensione dello
  shard e lo prova forestas. Nel file prodotto si normalizzano solo id autoincrement, date e id
  dentro i percorsi. Scelta del dev: «il verosimile non lo dedurrei, ne
  prenderei uno attuale in produzione».
- **L'approval test ha tre casi per versione, non uno.** Le zone a chiavi tutte numeriche escono come lista
  JSON (`JsonResource::filter()` le reindicizza), quelle a chiavi miste — gli id osmfeatures tipo
  `R40158` — restano oggetto, ed è il caso del file di produzione della 769. Entrambe le forme
  hanno una fixture. La firma si calcola sul JSON decodificato a oggetti, così `{}` e `[]` non si
  confondono.

- **Direzione del versionamento: quella di Stripe.** La forma corrente è canonica e le versioni
  precedenti sono trasformazioni applicate a ritroso, una classe per versione. Scartata la
  direzione opposta (la versione nuova che parte dalla vecchia): con due versioni produce lo
  stesso codice a parti invertite, ma alla terza lascerebbe la forma più vecchia come base di
  tutte le altre, non cancellabile nemmeno quando nessuno la scrive più. Il dev ha scelto di
  prendere il metodo documentato di una grande azienda invece di inventarne uno:
  [How API versioning works](https://docs.stripe.com/api-versions),
  [APIs as infrastructure](https://stripe.com/blog/api-versioning).
- **Il presidio di [R1](overview.md#r1) è un test, non la prudenza.** Con la derivazione a
  ritroso la forma vecchia è calcolata a partire da quella nuova, quindi ogni versione futura la
  fa ricalcolare: il confronto con la forma catturata prima del lavoro è l'unica prova che non
  cambi.
- **La serializzazione resta dentro `EcTrackResource`.** `JsonResource::filter()` reindicizza gli
  array con chiavi tutte numeriche
  (`vendor/laravel/framework/.../ConditionallyLoadsAttributes.php:92`): un `json_encode()` diretto
  nel job cambierebbe la forma del file — `related_pois` con un POI scartato diventerebbe un
  oggetto invece di una lista. Emerso dalla revisione del piano.
- **Nomi concordati col dev:** `EcTrackPropertiesBuilder` per il punto unico, `WMFE_TRACK_VERSIONS`
  / `wmfe_track_versions` per la dichiarazione delle versioni attive. `WmfeTrackVersion` per l'enum
  è derivato dal secondo.
- **Il caso «traccia nuova senza file legacy» non si protegge.** Decisione del dev in
  pianificazione: i dati su AWS non li cancella nessuno, quindi un'app non aggiornata non resta
  senza dati, al più senza dati aggiornati; e fra una versione e l'altra passano anni, nei quali
  le app chiedono l'aggiornamento a ogni apertura.

## Da sapere per la review

- **L'ordine delle chiavi nel file senza versione cambia**, e l'approval test non lo vede perché
  ordina le chiavi prima di confrontarle: al primo livello esce `type, geometry, properties`
  invece di `type, properties, geometry`, e le tre forme vecchie delle tassonomie finiscono in
  coda alle `properties` invece che in mezzo. Per un parser JSON non è una differenza, e
  [R1](overview.md#r1) parla di chiavi e struttura; ma i byte del file cambiano.
- **Tre cose minori lasciate come sono:** `EcTrackObserver::deleted()` scarta l'esito di
  `deleteTrack()` (preesistente, ma ora quel valore dice di più); `WmfeTrackVersion::active()` su
  un valore non stringa ripiega in silenzio sulla versione senza numero; `geometry()` restituisce
  `[]` dove prima il file avrebbe avuto `"geometry": null`, se il line-merge non decodifica.

- **[R12](overview.md#r12) interrompe le catene di job.** Oggi una `put` fallita lasciava
  proseguire la catena accodata da `EcTrackService` (`src/Services/Models/EcTrackService.php:367-368`
  e `:419-421`); con l'eccezione, esauriti i tre tentativi di Horizon, i job successivi non
  partono. Lo stesso vale per un nome di versione sbagliato nel `.env`: ogni salvataggio di traccia
  si fermerebbe lì. È il prezzo dichiarato di non lasciare due file divergenti in silenzio.
- **Uno shard con la sola `v1` attiva farebbe accodare un job a ogni richiesta dell'endpoint.**
  `getTrackGeojson()` legge sempre la versione senza numero, quindi
  `EcTrackController::getGeojson()` non troverebbe il file e accoderebbe
  `UpdateEcTrackAwsJob` a ogni GET, senza che quel file nasca mai. Oggi non riguarda nessuno
  shard; va deciso prima che uno accorci la finestra.

## Follow-up

- `UpdateTracksOnAws`: registrarla o cancellarla. Fuori da questo ticket.
- `docs/howto/deploy-automatico.md:41` dello shard dice «orario sul dev, alle 06:00 su UAT», ma il
  reset notturno è acceso su entrambi i server: la riga è di un altro ticket e va corretta lì.

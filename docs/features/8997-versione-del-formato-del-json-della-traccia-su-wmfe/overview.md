> Ticket: oc:8997
> Stato: approvata il 2026-11-17

# Versione del formato del JSON della traccia su wmfe

## Cosa cambia

Il JSON del dettaglio di una traccia su `wmfe` prende una **versione di formato**. Il file di oggi
resta dov'è, con il formato che ha: lo chiamiamo `legacy`, e il package continua a scriverlo finché
lo shard lo dichiara attivo. Il contratto nuovo nasce accanto, in `tracks/v1/{id}.json`, ed è il
primo versionamento — non il secondo, perché il file senza versione è nato prima che un contratto
esistesse e numerarlo a posteriori sarebbe una finzione.

La v1 nasce con una differenza sola rispetto al `legacy`: le tassonomie hanno una forma unica,
`taxonomies`. Le tre forme vecchie — `taxonomy`, `taxonomy_where`, `taxonomyWheres` — non ci sono
più. Tutto il resto è identico.

## Perché

L'indirizzo del file è compilato dentro ogni app: `awsApi` sta in `wm-types/src/environment.ts`,
viene letto a build-time e finisce nel binario pubblicato sugli store. Un'app installata continua a
chiedere `wmfe/<shard>/tracks/769.json` per mesi dopo qualsiasi rilascio del backend, e non esiste
modo di farle cambiare indirizzo da remoto.

Oggi quindi nessuna modifica incompatibile alle `properties` è possibile, e le forme vecchie si
accumulano accanto a quelle nuove senza che nessuna possa sparire: `taxonomies` è il modo corrente
di esporre le tassonomie, ma `taxonomy`, `taxonomy_where` e `taxonomyWheres` continuano a viaggiare
accanto perché toglierle romperebbe le app installate. Lasciare fermo il file che quelle app
chiedono, e scrivere il contratto nuovo altrove, è ciò che sblocca la pulizia — questa e tutte
quelle che verranno dopo.

## Punto di partenza

Il file `forestasdev/tracks/769.json` pesa 64.266 byte, con tre chiavi di primo livello (`type`,
`geometry`, `properties`). Le quattro forme delle tassonomie convivono: `taxonomies` (1.044 byte,
con `activity`, `hiking-difficulty`, `route-type`, `validation-status`, `walkability-status`,
`where`), `taxonomy_where` (431, una mappa per id che porta fuori anche metadati interni come
`_source: "osm2cai"`), `taxonomy` (285, la forma `activity`/`when`/`poi_type`/`poi_types`) e
`taxonomyWheres` (85, un array piatto di nomi).

- `StorageService::getTrackPath()` ([src/Services/StorageService.php:359-362](../../../src/Services/StorageService.php))
  produce `<shard>/tracks/{id}.json` e non conosce nessuna nozione di versione. Lo usano
  `storeTrack()`, `getTrackGeojson()` e `deleteTrack()`.
- `UpdateEcTrackAwsJob::handle()` ([src/Jobs/Track/UpdateEcTrackAwsJob.php:59-61](../../../src/Jobs/Track/UpdateEcTrackAwsJob.php))
  istanzia `EcTrackResource` e scrive un file solo. È l'unico punto del package che usa quella
  Resource: nessun controller, nessuna rotta, nessuno shard la estende.
- `EcTrackResource::toArray()` ([src/Http/Resources/EcTrackResource.php:27-58](../../../src/Http/Resources/EcTrackResource.php))
  costruisce il GeoJSON Feature: prende `getDetailGeojson()`, forza la geometria a linestring,
  compone le `properties` e le assegna in una riga sola (`$geojson['properties'] = $properties;`).
- `EcTrack::getDetailGeojson()` ([src/Models/EcTrack.php:839-855](../../../src/Models/EcTrack.php))
  aggiunge `taxonomies` (da `DetailTaxonomiesService`) e `walkability` al geojson di base; le tre
  forme vecchie arrivano invece da `getGeojson()` e dal jsonb `properties`.
- `EcTrackController::getGeojson()` ([src/Http/Controllers/Api/EcTrackController.php:22-34](../../../src/Http/Controllers/Api/EcTrackController.php))
  serve il contenuto del file da S3 e, se il file manca, accoda il job e risponde ricostruendo il
  GeoJSON dal database con `getDetailGeojson()` — senza passare da `EcTrackResource`.
- `EcTrackApiLinksCard` ([src/Nova/Cards/ApiLinksCard/src/EcTrackApiLinksCard.php:19](../../../src/Nova/Cards/ApiLinksCard/src/EcTrackApiLinksCard.php))
  compone l'URL del file a mano, concatenando `/<shard>/tracks/<id>.json`.
- `config/wm-package.php` è un array piatto; le prime chiavi sono `version`, `shard_name`,
  `analytics_shard_name`. Non esiste nessuna chiave che riguardi i path su `wmfe`.
- Le tracce si rigenerano in massa con l'Action Nova «Update Tracks on AWS» della Resource
  `EcTrack`, che è `ExecuteEcTrackDataChainAction` con il solo `UpdateEcTrackAwsJob`
  (`src/Nova/EcTrack.php:174-176`); non esiste un artisan
  command equivalente, e non serve crearlo.

## Requisiti

- <a id="r1"></a>**R1** — Il file senza versione resta al suo indirizzo,
  `<shard>/tracks/{id}.json`, che è quello compilato dentro le app già installate: non si sposta e
  non viene rinominato.
  Ne conserva anche la **forma**: stesse chiavi, stessa struttura, per tutto il tempo in cui lo
  shard dichiara attiva la versione `legacy`. Nessuna modifica di forma fatta in una versione
  numerata lo raggiunge.
  I **valori** invece seguono il dato: se il DEM viene ricalcolato, se una tassonomia cambia, se un
  media viene sostituito, il file lo recepisce — è lo stesso sentiero, e congelare i valori
  significherebbe servire dati che il sistema sa essere sbagliati.
- <a id="r2"></a>**R2** — Il contratto corrente si scrive in `<shard>/tracks/v1/{id}.json`. È
  identico al `legacy` tranne che per le tassonomie: `taxonomies` è l'unica forma presente, e
  `taxonomy`, `taxonomy_where` e `taxonomyWheres` non ci sono.
- <a id="r3"></a>**R3** — Il backend tiene sempre aggiornate **due versioni: quella corrente e
  quella che la precede**. Oggi sono `legacy` e `v1`; quando nascerà la `v2`, le versioni scritte
  diventano `v1` e `v2`, e il `legacy` esce dalla scrittura. I file di una versione uscita non si
  cancellano: restano su S3, fermi all'ultimo valore scritto, perché è quello che le app installate
  continuano a chiedere.
- <a id="r11"></a>**R11** — Quali versioni sono attive su uno shard è una sua configurazione, non
  una costante del package: uno shard può restare su `legacy` e `v1` mentre un altro è già passato
  a `v1` e `v2`. La finestra di due versioni di [R3](#r3) è il default e la regola normale, ma lo
  shard può **accorciarla** quando il suo parco app è rinnovato, e **allungarla** a tre quando non
  lo è ancora — al prezzo di un file in più per traccia. La scelta si fa guardando quante
  installazioni vecchie sono ancora vive, non il calendario dei nostri rilasci: la nascita di una
  versione e il rinnovo del parco app sono due orologi diversi.
- <a id="r4"></a>**R4** — Le forme delle `properties` nascono da **un solo punto**, che prende il
  modello e le restituisce tutte. Quello che si condivide è il **calcolo dei dati** — DEM,
  geometria, tassonomie, POI, media: roba che si prende dal modello una volta sola e non si
  ricalcola per ogni versione.
  La **disposizione** delle properties ha come riferimento la **versione corrente**: è quella
  scritta per intero. Ogni versione precedente è una trasformazione che si applica a ritroso: parte
  dalla versione che la segue e rimette solo ciò che in quella è cambiato. Una modifica di forma
  decisa oggi riguarda quindi la versione corrente, e le versioni vecchie restano un modulo chiuso
  che si cancella quando muoiono.

  **Adottiamo il metodo di versionamento di Stripe**, documentato pubblicamente
  ([How API versioning works](https://docs.stripe.com/api-versions),
  [APIs as infrastructure: future-proofing Stripe with versioning](https://stripe.com/blog/api-versioning)).
  Tre cose che prendiamo da lì:

  - ogni modifica incompatibile è **un modulo a sé**, che porta la propria trasformazione e sa su
    quali tipi agisce, invece di controlli sulla versione sparsi nel codice;
  - le trasformazioni **si applicano all'indietro a partire dalla versione corrente**, e si
    incatenano una dopo l'altra fino a quella richiesta;
  - il codice di tutte le versioni **resta in casa**, con il costo dichiarato: ogni versione in più
    è altro codice da capire e mantenere.

  La direzione opposta — far partire la versione nuova da quella vecchia — produce oggi lo stesso
  codice a parti invertite, perché le versioni sono due, ma alla terza lascerebbe la forma più
  vecchia come base di tutte le altre: non cancellabile nemmeno quando nessuno la scrive più. E il
  lavoro di spostare il riferimento non si risparmia, si rimanda: nel metodo di Stripe si paga a
  ogni versione, nell'altro si paga tutto insieme il giorno che la catena va raddrizzata.

  Da Stripe non prendiamo invece il momento in cui la trasformazione gira: loro la applicano a ogni
  richiesta, noi scriviamo file fermi e le forme le produciamo tutte al salvataggio.
- <a id="r5"></a>**R5** — La differenza fra le versioni non è conservata da nessuna parte se non nei
  file stessi: nessuna tabella, nessuna colonna, nessuna cache. Cambiato il codice che le genera, si
  riscrivono i file e la differenza è quella nuova.
- <a id="r6"></a>**R6** — Fra una versione e l'altra cambia solo il contenuto di `properties`:
  l'involucro del GeoJSON Feature — `type`, `geometry`, `id` — è identico in tutte, e resta
  costruito in un punto solo.
- <a id="r7"></a>**R7** — Cancellare una traccia cancella il suo file in tutte le versioni, comprese
  quelle non più attive in scrittura: un file che resta su S3 dopo la cancellazione è un dettaglio
  pubblico di un contenuto che non esiste più.
  **Limite noto, dichiarato:** vale per le cancellazioni che passano da Eloquent, non per il reset
  notturno di forestas. `sardegnasentieri:import --reset` (schedulato alle 06:00 in
  `routes/console.php:41`) fa `TRUNCATE ... RESTART IDENTITY CASCADE`, che non emette eventi
  Eloquent: nessun observer si sveglia e i file restano su S3. Oggi è innocuo perché gli id
  ripartono da 1 e il contenuto viene sovrascritto, ma se i sentieri alla fonte calano i file in
  coda restano orfani — da questo lavoro in poi in due copie invece di una. Ripulirli non è
  compito di questo lavoro.
- <a id="r8"></a>**R8** — `GET /api/ec/track/{id}` continua a servire il file `legacy`, e il suo
  ripiego dal database continua a produrre quel formato. Chi chiama quell'endpoint non ha modo di
  dichiarare quale versione si aspetta, quindi non può riceverne una diversa da quella per cui è
  stato scritto.
- <a id="r9"></a>**R9** — Nella detail view della Resource Nova `EcTrack` l'URL di ogni versione
  attiva è visibile e distinguibile: chi lavora al formato nuovo deve poter aprire il file nuovo
  senza costruirsi l'indirizzo a mano.
- <a id="r10"></a>**R10** — Tutto il meccanismo vive nel package. Nello shard resta la sola
  dichiarazione di quali versioni scrivere.

- <a id="r12"></a>**R12** — Una scrittura fallita non passa inosservata. Il job che scrive le
  versioni guarda l'esito di ciascuna e, se una fallisce, fallisce lui: Horizon ritenta e, finiti i
  tentativi, il job risulta fallito. Oggi `storeTrack()` ritorna `string|false` e chi lo chiama
  scarta il valore, quindi una `put` fallita lascia il job verde; con due file quello stesso guasto
  li lascerebbe divergenti — uno aggiornato, uno fermo — e invisibili.

## Rischi

- **Le due scritture non sono atomiche.** Fra la scrittura di una versione e quella dell'altra
  esiste una finestra in cui i due file non concordano. Mitigato da [R12](#r12): il ritentativo
  riscrive entrambi con lo stesso contenuto, che è un'operazione idempotente. Resta un rischio
  solo per un lettore che interrogasse i due file nello stesso istante, caso che oggi non esiste.
- **La forma del file senza versione non ha una prova automatica.** [R1](#r1) promette che resti
  identica, ma il lavoro riscrive proprio il codice che la produce, e un file diverso di un byte si
  scopre solo sulle app installate. La verifica è un confronto fra il file prodotto prima e dopo il
  lavoro, catturato come dato di test e versionato: non si può ancorare a una traccia viva, perché
  il reset notturno di forestas riparte da `RESTART IDENTITY` e l'id di domani non è il sentiero di
  oggi.
  Con la derivazione all'indietro di [R4](#r4) quel test non è un di più, è **il** presidio: la
  forma vecchia non è codice fermo che nessuno tocca, è il risultato di una trasformazione
  applicata alla forma corrente, e ogni versione futura la fa ricalcolare. È la stessa ragione per
  cui Stripe può tenere aperte cento versioni — la compatibilità la garantisce una prova, non la
  prudenza di chi modifica.
- **Il punto di estensione dello shard sta nella parte condivisa.** `properties.forestas` (5.958
  byte su 761 tracce) nasce in `App\Models\EcTrack::getDetailGeojson()`, a monte della divisione
  fra le forme, e lo legge `wp-forestas`. Perderlo nel refactor romperebbe il sito oltre alle app.
  La prova sta nello shard, non nel package, che quel blocco non lo conosce.
- **La dichiarazione delle versioni attive può sparire in silenzio.** Se passa da
  `config/wm-package.php` pubblicato nello shard, lo shard congela uno snapshot dell'intero file e
  le chiavi annidate aggiunte in futuro dal package non lo raggiungono più (`mergeConfigFrom` unisce
  solo il primo livello). Va quindi letta da una variabile d'ambiente, come tutti gli altri valori
  per-shard di quel file, con due accortezze: a dichiarazione assente resta attiva la versione
  `legacy`, e un nome di versione sconosciuto fa fallire la scrittura invece di scrivere su un
  prefisso che nessuno legge. È lo schema del guasto già avvenuto con `WM_TRAIL_REGISTRY_ENABLED`.
- **Nessuno si accorge se la versione nuova smette di essere scritta**, perché finché il frontend
  non la legge non ha utenti. Il primo che la adotterà potrebbe trovare file fermi a settimane
  prima. Rischio accettato: la verifica è il confronto fra i due file su una traccia campione, e
  non vale un meccanismo dedicato prima che esista un consumatore.
- **Il ripiego dell'API non produce il formato del file**, e non è un effetto di questo lavoro:
  `EcTrackController::getGeojson()` risponde dal database con `getDetailGeojson()` senza passare da
  `EcTrackResource`, quindi senza la priorità MANUAL/OSM/DEM, senza `related_pois`, senza i media e
  senza la geometria forzata a linestring. [R8](#r8) dice che quel ripiego resta com'è: va letto
  come «non lo tocchiamo», non come «è uguale al file».

## Out of scope

- **Il resto della pulizia delle `properties`.** Questo lavoro fa solo le tassonomie; le altre
  chiavi le continua Alessandro Peci. Il censimento è già fatto ed è qui sotto, in
  «Candidati per la pulizia».
- **Il frontend: ticket a sé.** Finché `wm-core` punta ad `awsApi` senza versione continua a
  leggere il `legacy`, quindi questo lavoro non lo tocca e non lo rompe. Quello che il ticket
  frontend dovrà affrontare, già verificato qui:

  - `wm-core` legge oggi `taxonomy_where` (`track-properties.component.html:45`, `<wm-txn-where>`)
    e `taxonomy.activity` (riga 49, `<wm-tab-howto>`); `taxonomies` non è letta da nessuna parte.
  - Il codice vecchio **non si può sostituire, solo affiancare**: lo stesso `wm-core` serve anche
    GeoHub, che è una entry degli shard in `wm-types/src/environment.ts` come le altre, non monta
    `wm-package` e non prenderà mai la v1. Verificato su `wmfe/geohub/tracks/100.json`: GeoHub manda
    `taxonomy` e `taxonomyWheres`, e non manda né `taxonomies` né `taxonomy_where`. Sono quindi
    tre formati da reggere — GeoHub, `legacy`, `v1` — e `<wm-txn-where>` su GeoHub non compare
    già oggi, perché quella chiave lì non arriva.
  - La versione non va chiesta al file: il frontend costruisce lui l'URL, quindi sa già cosa ha
    chiesto. L'informazione starà accanto ad `awsApi` nella entry dello shard in `environment.ts`.
  - `taxonomies` **discrimina la piattaforma, non la versione**: c'è su tutti gli shard
    `wm-package` — nel file senza versione, nella v1 e su 976 POI di `pois.geojson` su 976 — e non
    c'è su GeoHub. Fra il file senza versione e la v1 la differenza è solo l'assenza delle tre
    chiavi vecchie. Chi userà la presenza di `taxonomies` come segnale di «questo è v1» sbaglia.
  - Le entry GeoHub in `environment.ts` sono **due su diciannove**, `geohub` e `geohubdev`, ed è
    esattamente la lista di `environment.service.ts:28`. Il criterio verificabile è `elasticApi`:
    GeoHub espone `/v2/search` su un host di ricerca, `wm-package` espone `/api/v2/elasticsearch`
    sul proprio origin. **`geohub2` è `wm-package`**, nonostante il nome.

### Un file su tre: il limite di questo versionamento

Le `properties` di una traccia raggiungono i client da tre canali, e questo lavoro ne versiona uno:

| canale | forma delle tassonomie | versionato |
|---|---|---|
| `tracks/{id}.json` | tutte e quattro oggi, solo `taxonomies` in v1 | **sì** |
| Elasticsearch (`Hit` in `wm-types/src/elastic.ts:7-23`) | `taxonomyWheres: string[]` (obbligatoria), `taxonomyActivities`, `taxonomyIcons`; nessuna `taxonomies` | no, e non lo sarà: l'indice non c'entra con i file su S3 |
| `pois.geojson` | cinque forme, con in più `taxonomyIdentifiers` | no, è fuori scope |

Non è un difetto da correggere qui — gli altri due canali hanno cicli di vita loro — ma va saputo
da chi userà la v1: il contratto versionato copre il dettaglio della traccia, non tutto ciò che il
frontend riceve.
- **Gli altri file su `wmfe`:** `config.json`, `pois.geojson`, `icons.json`, le tile PBF, gli
  elevation chart, i media e i file caricati. Nessuno di questi prende una versione, e
  `getShardBasePath()` resta senza versione proprio perché da lì passa anche
  `WmfePathGenerator::getBasePath()`, che calcola il path dei media già caricati.
- **Un command per rigenerare le tracce su S3:** la fase di bootstrap di una versione nuova si fa
  con l'Action Nova «Update Tracks on AWS» della Resource `EcTrack`, che esiste già.
- **Quando smettere di scrivere il `legacy`:** è una decisione per shard, che si prende guardando i
  dati di accesso, non una regola da scrivere in questo lavoro.
- **Versioning degli oggetti S3** (`NoncurrentVersionExpiration` e affini): serve a recuperare un
  file sovrascritto per sbaglio, è un altro problema.

### Candidati per la pulizia

Censimento fatto il 17/11/2026 su `wm-core` (il submodule condiviso da `webmapp-app` e
`wm-webapp`, commit `f5e2b7c`) e sul database di forestas, 765 tracce. Serve a chi continuerà la
pulizia: la colonna «tracce» dice quante tracce hanno quel dato valorizzato, cioè cosa si perde
davvero togliendo la chiave.

| chiave | la legge il frontend? | tracce | byte sulla 769 |
|---|---|---|---|
| `related_pois` | **sì**, i Feature POI completi (`ec.selector.ts:186`, `track-properties.component.html:75`); di `associated_ec_pois` ci sono 0 occorrenze | — | 44.833 |
| `forestas` | no in `wm-core`, **ma lo legge `wp-forestas`** (`nearby_tracks`, `downloads`, `registry_code`, `sardegnasentieri_id`, `useful_info`) | 761 | 5.958 |
| `feature_image` | no sulla traccia: solo card di ricerca, layer e POI | — | 2.688 |
| `via` | no, 0 occorrenze | **170** | 4 (nulla sulla 769) |
| `ref` | no sulla traccia: passaporto e stili della mappa | 585 | 10 |
| `layer_name` | no, solo un evento di analytics | — | 17 |
| `created_at`, `updated_at` | no, solo nel type TypeScript | — | 54 |
| `geohub_id` | no, 0 occorrenze | 3 | 4 |
| `walkability` | non ancora: è arrivata con oc:8989 | — | 82 |

Il frontend legge invece, e vanno mantenute: `name`, `description`, `excerpt`, `image_gallery`,
`not_accessible` e `not_accessible_message`, `id`, `from`, `to`, `distance`, `duration_forward`,
`duration_backward`, `roundtrip`, `ascent`, `descent`, `ele_from`, `ele_to`, `ele_min`, `ele_max`,
`layers`, `difficulty`, `cai_scale`, `config_detail`, `audio`, `related_url`, `osm_url`, `color`.
Le ultime sei non compaiono nel file della 769 perché su quella traccia sono vuote e
`GeoJsonService::removeInvalidProperties()` scarta gli array vuoti; nel database `config_detail`
non è vuoto su 315 tracce, `related_url` su 75, `excerpt` su 12.

## Moduli toccati

Previsti, tutti in `wm-package`:

- `src/Services/StorageService.php` — `getTrackPath()`, `storeTrack()`, `getTrackGeojson()`,
  `deleteTrack()`
- `src/Jobs/Track/UpdateEcTrackAwsJob.php` — scrittura di tutte le versioni attive
- `src/Http/Resources/EcTrackResource.php` — l'involucro resta qui, le `properties` escono
- `src/Http/Resources/` — la classe che dal modello produce le due forme delle `properties`
- `src/Nova/Cards/ApiLinksCard/src/EcTrackApiLinksCard.php` — un link per versione attiva
- `config/wm-package.php` — la chiave con le versioni attive
- `tests/` — `tests/Unit/Services/StorageService/`, più i test nuovi sul job e sulle due forme

Nello shard `forestas`: la dichiarazione delle versioni attive. Vedi
[forestas/docs/features/8997-…/overview.md](../../../../docs/features/8997-versione-del-formato-del-json-della-traccia-su-wmfe/overview.md).

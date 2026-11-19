# Versioni del JSON della traccia su wmfe

Il JSON del dettaglio di una traccia su `wmfe` ha una **versione di formato**. Questa pagina dice
dove finiscono i file, come uno shard sceglie quali scrivere e come si aggiunge una versione nuova.

Il perché della forma che ha questo meccanismo sta in
[docs/features/8997-…/overview.md](../features/8997-versione-del-formato-del-json-della-traccia-su-wmfe/overview.md),
requisito R4: qui c'è solo l'uso.

## I file

| versione | path | contenuto |
|---|---|---|
| `legacy` | `<shard>/tracks/{id}.json` | le quattro forme delle tassonomie: `taxonomies`, `taxonomy`, `taxonomy_where`, `taxonomyWheres` |
| `v1` | `<shard>/tracks/v1/{id}.json` | la sola `taxonomies`; tutto il resto identico |

Il file senza versione è l'indirizzo **compilato dentro le app già pubblicate sugli store**: non si
sposta e non cambia forma. I suoi valori invece seguono il dato, come per ogni altra versione.

Una versione che esce dalla scrittura non si cancella: i suoi file restano su S3, fermi all'ultimo
valore scritto, perché è quello che le app installate continuano a chiedere.

## Quali versioni scrive uno shard

```dotenv
WMFE_TRACK_VERSIONS=legacy,v1
```

Letta da `wm-package.wmfe_track_versions`. È una **variabile d'ambiente**, non una chiave del
config pubblicato: quello congelerebbe uno snapshot dell'intero file del package, e le chiavi
aggiunte in futuro non raggiungerebbero più lo shard (`mergeConfigFrom` unisce solo il primo
livello).

- **Assente o vuota:** resta attiva la sola `legacy`. Uno shard che bumpa il package senza sapere
  niente di questo meccanismo continua a scrivere un file solo, come ha sempre fatto.
- **Nome sconosciuto** (`v9`, un refuso): `WmfeTrackVersion::active()` lancia
  `InvalidArgumentException`, quindi il job di scrittura fallisce invece di scrivere file sotto un
  prefisso che nessuno legge. La card dei link in Nova è l'unica a catturare quell'eccezione e a
  ripiegare sulla sola `legacy`, perché sta nella detail view di ogni traccia.
- **Quante versioni:** la regola normale è due, la corrente e quella che la precede. Uno shard può
  accorciare a una quando il suo parco app è rinnovato, o allungare a tre quando non lo è ancora —
  al prezzo di un file in più per traccia. Si guarda quante installazioni vecchie sono vive, non il
  calendario dei rilasci.
- **Dove va scritta:** nel `.env` di ogni server, **prima** del deploy. `php artisan optimize`
  congela la configurazione, e una variabile aggiunta dopo non viene letta.

## Come si aggiunge una versione nuova

1. **Il caso nuovo va in fondo a `WmfeTrackVersion`** (`src/Enums/WmfeTrackVersion.php`): i casi
   stanno in ordine cronologico e l'ultimo è la forma canonica, quella che `current()` restituisce.
   Mai in mezzo.
2. **`EcTrackPropertiesBuilder::current()` costruisce la forma nuova**, per intero.
3. **La forma di ieri diventa una trasformazione**: una classe sotto
   `src/Http/Resources/TrackVersions/`, come `LegacyTrackPropertiesTransformation`, che parte dalle
   properties della versione che la segue e rimette solo ciò che in quella è cambiato.
   **Oggi `build()` conosce una trasformazione sola**, quella verso la versione senza numero:
   finché le versioni sono due non c'è niente da incatenare. Con la terza va scritta la catena —
   dalla corrente all'indietro, una trasformazione per passo — perché applicare la trasformazione
   verso la `legacy` direttamente sulla forma `v2` salterebbe il passo intermedio e produrrebbe un
   file sbagliato. Nel frattempo `build()` **lancia** `LogicException` se gli si chiede una
   versione per cui non ha una trasformazione, invece di ripiegare sulla forma corrente: il giorno
   della `v2` il guasto si vede subito.
4. **Gli shard dichiarano la versione nuova** nel proprio `.env`, quando sono pronti.
5. **Il bootstrap dei file** si fa con l'Action Nova «Update Tracks on AWS» sulla Resource
   `EcTrack` (è `ExecuteEcTrackDataChainAction` con il solo `UpdateEcTrackAwsJob`,
   `src/Nova/EcTrack.php:174-176`): seleziona le tracce e accoda il job che riscrive i file. Dove
   gira un reset notturno che rigenera tutto, si può anche aspettare la notte.

È il metodo di versionamento di Stripe ([How API versioning works](https://docs.stripe.com/api-versions)):
ogni modifica incompatibile è un modulo a sé, le trasformazioni si applicano all'indietro dalla
versione corrente, e il codice di tutte le versioni resta in casa. Il prezzo è dichiarato: ogni
versione in più è altro codice da mantenere. Il guadagno è che una versione che muore si cancella
con la sua classe, senza che nessun'altra ne dipenda.

## Due vincoli da non rompere

- **La serializzazione passa sempre da `EcTrackResource`**, mai da un `json_encode()` diretto.
  `JsonResource::resolve()` applica `filter()`, che reindicizza gli array annidati con chiavi tutte
  numeriche (`vendor/laravel/framework/.../ConditionallyLoadsAttributes.php:92`): `taxonomy_where`
  con id numerici esce come lista JSON solo grazie a quel passaggio, e un `json_encode()` la
  trasformerebbe in un oggetto. È una modifica di forma invisibile fino alle app installate.
- **La forma e i valori di ogni versione sono congelati**, e non affidata alla prudenza di chi modifica:
  `tests/Feature/Track/TrackJsonShapeTest.php` confronta la struttura del file prodotto con quella
  in `tests/fixtures/track/<versione>-shape.json`, per ogni caso di `WmfeTrackVersion` e in due
  varianti — zone con id tutti numerici (che escono come lista JSON) e zone con id misti, come nel
  file di produzione (che restano oggetto). Se fallisce, si risale alla chiave persa o alla lista
  diventata oggetto; **la fixture non si riscrive per far passare il test**.
  Accanto alla forma c'è il congelamento dei **valori**, in `<versione>-values.json`: i dati di
  input sono le properties di un sentiero vero, prese dal database di forestas e versionate in
  `production-track-input.json`, quindi il file prodotto è deterministico una volta tolti gli id
  autoincrement e le date. Serve a prendere ciò che la sola forma non vede — il filtro delle
  località mostrate, `roundtrip`, la priorità MANUAL > OSM > DEM, i testi che devono passare
  invariati.
  Una versione nuova non richiede di toccare quel file: si lancia una volta con
  `WM_CAPTURE_SHAPE=1`, che cattura le fixture mancanti, e da lì in poi il contratto è congelato.

## Il limite: un canale su tre

Le `properties` di una traccia raggiungono i client da tre canali, e versionato è solo il primo:

| canale | versionato |
|---|---|
| `tracks/{id}.json` | **sì** |
| Elasticsearch | no, e non lo sarà: l'indice non c'entra con i file su S3 |
| `pois.geojson` | no |

Chi usa la `v1` deve saperlo: il contratto versionato copre il dettaglio della traccia, non tutto
ciò che il frontend riceve.

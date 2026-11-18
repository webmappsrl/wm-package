> Ticket: oc:8967

# Conversione UGC POI in EC POI

## Cosa cambia

L'action Nova `ConvertUgcPoiToEcPoi` (`src/Nova/Actions/ConvertUgcPoiToEcPoi.php`) torna a convertire
gli UgcPoi che hanno foto. Le foto vengono copiate sull'EcPoi con `Media::copy()` di Spatie Media
Library, che crea un media nuovo (id e uuid nuovi) **e** copia il file originale nella cartella del
nuovo media, invece di `replicate()`, che duplicava solo la riga della tabella `media`. Le miniature
non vengono copiate: le rigenera la coda per il nuovo media, come per ogni foto caricata.

Prima di ogni copia l'action verifica che il file originale esista sullo storage, perché `copy()`
con un file mancante non dà errore e crea una foto da 0 byte. Alla copia passa anche la posizione
della foto (`geometry` del media), che `copy()` altrimenti sostituirebbe con il punto dell'EcPoi.

La catena di job che calcola località e quota dell'EcPoi (`EcPoiService::updateDataChain()`) parte
dopo il commit (`afterCommit()`), non più mentre la transazione di Nova è ancora aperta. La
rigenerazione di `pois.geojson` e della config lo fa già (`AppPoiOutputsService::dispatchRegeneration()`,
oc:8911).

Ogni UgcPoi della selezione viene convertito in una transazione annidata (savepoint) dentro la
transazione che Nova apre per l'action: se la conversione di un UgcPoi fallisce, si annulla solo
quella, le altre della stessa selezione vengono completate, e il messaggio finale in Nova dice quali
UgcPoi sono stati convertiti e quali no, e perché.

## Perché

Dal commit `4013dc65` (18/09/2025), che ha tolto la rigenerazione dell'uuid, ogni conversione di un
UgcPoi con foto finisce in errore 500. Riprodotto in locale sul vero endpoint Nova
(`POST /nova-api/ugc-pois/action?action=convert-to-ecpoi`), con gli stessi errori dello screenshot del
cliente (UgcPoi 143, «Fontana», cammino di Oropa):

1. `replicate()` copia anche l'`uuid` del media → `SQLSTATE[23505]` su `media_uuid_unique`;
2. il try/catch dell'action intercetta l'errore e lo scrive nel log, ma su Postgres la transazione
   aperta da Nova (`Laravel\Nova\Actions\Transaction::run`) è ormai invalidata;
3. il successivo `$model->saveQuietly()` fallisce con `SQLSTATE[25P02] current transaction is
   aborted`, eccezione non intercettata → Nova annulla tutto e mostra l'errore.

Rimettere solo la rigenerazione dell'uuid non basta: provato in locale su MinIO, la conversione
riesce ma l'EcPoi ha una foto che risponde **HTTP 404**, perché il percorso del file dipende dall'id
del media (`WmfePathGenerator` → `DefaultPathGenerator::getBasePath()` usa `$media->getKey()`) e
`replicate()` non copia il file nella cartella del nuovo id. Con `copy()` file e miniature esistono
nella cartella giusta (provato in locale).

La copia delle foto tiene aperta la transazione di Nova per secondi invece che per millisecondi. Con
le code a `after_commit => false` la catena di `updateDataChain()` parte prima del commit: provato in
locale (anche sul `develop` del 18/11/2026) con Horizon attivo e la transazione aperta 5 s,
`SyncModelTaxonomyWhereJob` fallisce con `ModelNotFoundException` e l'EcPoi resta senza `ele` e
senza `taxonomy_where`.

## Requisiti

- [ ] La conversione di un UgcPoi con una o più foto crea l'EcPoi con tutte le foto, e ogni foto
      dell'EcPoi ha il file originale sul disco nella cartella del proprio media (id e uuid diversi
      da quelli della foto dell'UgcPoi)
- [ ] Le miniature delle foto dell'EcPoi vengono generate per l'EcPoi (in coda, come per ogni media)
- [ ] Ogni foto copiata conserva la posizione (`geometry` del media) della foto originale
- [ ] La foto dell'UgcPoi resta invariata: stessa riga, stesso file
- [ ] Prima di copiare una foto l'action verifica che il file originale esista sullo storage: se
      manca, la conversione di quell'UgcPoi si annulla (mai una foto da 0 byte sull'EcPoi)
- [ ] Se la copia di una foto fallisce (file assente sul disco, storage che non risponde, errore sul
      DB), la conversione di **quell'UgcPoi** viene annullata del tutto: nessun EcPoi, nessun
      `ec_poi_id` sull'UgcPoi, nessuna riga `media` nuova, e nessun file copiato rimasto sullo storage
      per le foto di quell'UgcPoi copiate prima dell'errore
- [ ] In una selezione multipla, il fallimento di un UgcPoi non impedisce la conversione degli altri:
      l'errore su Postgres resta confinato al savepoint di quell'UgcPoi. Dentro la closure del
      savepoint non c'è nessun catch: l'eccezione si intercetta solo fuori, altrimenti la transazione
      resta invalidata e si torna al `25P02`
- [ ] La catena `SyncModelTaxonomyWhereJob` + `UpdateEcPoiDemJob` di `updateDataChain()` parte dopo
      il commit: un EcPoi convertito ha località e quota. Fuori da una transazione parte subito come
      oggi
- [ ] L'action non restituisce mai un errore 500 per un fallimento sulla copia delle foto: mostra un
      messaggio in Nova che elenca gli UgcPoi convertiti, quelli già convertiti in precedenza
      (comportamento attuale, invariato) e quelli non convertiti con il motivo
- [ ] Rilanciare l'action sulla stessa selezione converte solo gli UgcPoi rimasti non convertiti
      (quelli con `ec_poi_id` vengono saltati, comportamento attuale invariato)
- [ ] I messaggi dell'action (nuovi ed esistenti) sono traducibili, con chiave in inglese e
      traduzione in `resources/lang/{en,it,fr,es,de}.json`
- [ ] Test Pest in `wm-package/tests/` che coprono: UgcPoi senza foto, UgcPoi con foto (file copiato
      e uuid diverso), foto con file assente (conversione annullata, nessun residuo), selezione
      multipla con un UgcPoi che fallisce (gli altri convertiti), errore SQL durante la conversione
      di un UgcPoi (i successivi convertiti comunque), posizione della foto conservata, UgcPoi già
      convertito (saltato)

## Rischi

- **File orfani sullo storage.** Il DB si annulla con il savepoint, lo storage no: se un UgcPoi ha 3
  foto e la terza fallisce, i file delle prime due sono già stati caricati. Mitigazione: tenere
  traccia dei media creati per quell'UgcPoi e cancellarne i file in caso di errore.
- **Miniature dopo il commit.** `queue_conversions_after_database_commit` è `true`
  (`config/wm-media-library.php:20`): le miniature dell'EcPoi vengono generate da Horizon dopo il
  commit della transazione Nova, quindi compaiono qualche istante dopo la conversione.
- **Tempi su produzione.** `copy()` scarica e ricarica ogni foto dallo storage remoto: con foto da
  smartphone (4032×3024) e selezioni ampie l'action dura di più. In locale, con un'immagine
  1600×1200 su MinIO, 0,37 s per foto comprese le miniature; su produzione non misurato.
- **UgcPoi con una foto mancante sullo storage.** Per la regola «tutto o niente» non si converte
  finché la foto non viene sistemata: il messaggio in Nova dice quale foto manca.
- **`afterCommit()` in `updateDataChain()` vale per tutti i consumer** e per i due punti che la
  chiamano: `EcPoiObserver::saved()` e l'action `ExecuteEcPoiDataChainAction` (che gira anch'essa
  dentro la transazione di Nova). Fuori da una transazione il comportamento non cambia; dentro una
  transazione la catena parte al commit invece che subito, cioè quando l'EcPoi esiste davvero per
  Horizon.

## Out of scope

- Chi può vedere ed eseguire l'action: resta il default di Nova (policy `update` su UgcPoi), quindi
  in camminiditalia solo l'Administrator.
- Gli altri dati dell'UgcPoi non copiati oggi sull'EcPoi (descrizione, tipo di punto, layer): la
  conversione continua a copiare nome, geometria, `app_id` e foto, e a mettere come `user_id`
  dell'EcPoi l'utente che esegue l'action, non l'autore della segnalazione.
- `ec_poi_id` che resta sull'UgcPoi dopo la cancellazione dell'EcPoi. Provato in locale dagli
  endpoint Nova: dopo aver cancellato l'EcPoi, «Run action» risponde «already have an associated
  EcPoi» e non converte. È indipendente da questo bug (succede anche senza foto); da aprire come
  ticket a parte se serve.
- La geometria dell'EcPoi che passa dall'ORM (`EcPoi::create`), contro la regola del package:
  comportamento già presente, non legato a questo bug.
- La conversione dell'UgcPoi 143 in produzione: dopo il rilascio la rilancia il cliente da Nova.

## Moduli toccati

Repo `wm-package` (il codice):
- `src/Nova/Actions/ConvertUgcPoiToEcPoi.php` — copia delle foto con `copy()` (controllo del file,
  posizione conservata), savepoint per UgcPoi, pulizia dei file in caso di errore, messaggio di esito
- `src/Services/Models/EcPoiService.php` — catena di `updateDataChain()` con `afterCommit()`
- `src/Exceptions/MediaFileMissingException.php` — nuovo: file di un media assente sullo storage,
  lanciato prima di `copy()`
- `resources/lang/{en,it,fr,es,de}.json` — messaggi dell'action
- `tests/Feature/Nova/ConvertUgcPoiToEcPoiTest.php` — nuovo

Repo `camminiditalia`:
- gitlink del submodule `wm-package` al commit della correzione, nessun altro file

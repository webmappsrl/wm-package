> Ticket: oc:8543

# Inversione della traccia: attivazione su Forestas

> **Terzo ciclo, revisione del 24/09/2026:** dopo il merge di `develop` (oc:8571) alcune premesse
> del terzo ciclo sono cambiate. Le decisioni aggiornate sono in
> [Terzo ciclo — revisione del 24/09/2026](#terzo-ciclo--revisione-del-24092026), in fondo, e
> prevalgono sulla sezione del 23/09 quando la contraddicono.

> **Terzo ciclo (23/09/2026):** la review sul commit `bb4b75a3` ha chiesto di nuovo modifiche. Le
> decisioni del terzo ciclo sono nella sezione [Terzo ciclo](#terzo-ciclo-23092026) in fondo e
> prevalgono su quanto scritto sopra quando lo contraddicono: in particolare sull'avviso sugli
> override, sul parametro `$forceGeometryChain` e sulla catena completa di 10 job.

> **Secondo ciclo (22/09/2026):** la sezione Requisiti/Rischi/Moduli toccati sotto include anche i
> fix richiesti dalla review sulla PR [wm-package#278](https://github.com/webmappsrl/wm-package/pull/278)
> (esito changes requested, 22/09/2026). Le voci del primo ciclo restano, quelle superate sono
> segnalate come tali.

## Cosa cambia

Viene aggiunta una nuova Nova Action su `EcTrack`, in `wm-package`, che inverte il verso di
percorrenza della geometria di una traccia e rilancia automaticamente il ricalcolo dei dati che
dipendono dal verso di percorrenza (ascent, descent, ele_from, ele_to, duration_forward,
duration_backward, pendenza, TaxonomyWhere, immagine profilo altimetrico, tile PBF, dati serviti
all'app), riusando la data chain già esistente in `EcTrackService`. L'operazione geometrica vive in
`GeometryComputationService`, l'orchestrazione dei ricalcoli in `EcTrackService` — non nell'Action,
che si limita a chiamarli (**secondo ciclo**: nel primo ciclo la query SQL era scritta dentro
l'Action, unica del package a farlo; la review ha chiesto di spostarla, così l'inversione sarà
richiamabile in futuro anche da comando artisan o API, non solo da Nova).

L'inversione gestisce correttamente anche le tracce con geometria MultiLineString a più parti non
contigue (5 casi noti sul DB locale di Forestas, tra cui una traccia con 12 parti): non basta
invertire i vertici dentro ogni parte, va invertito anche l'ordine delle parti stesse.

L'azione è portata da GeoHub (`App\Nova\Actions\ReverseEcTrackGeomtryAction`), dove esiste già,
ma **non è un porting 1:1**: l'implementazione GeoHub scrive la geometria tramite `$model->save()`,
cosa vietata dalle regole di `wm-package` per i modelli con geometria PostGIS (corrompe il dato),
e non rilancia nessun ricalcolo dei dati derivati — lascia ascent/descent/ele_from/ele_to/durate
non aggiornati dopo l'inversione, riproducendo lo stesso problema che la feature dovrebbe
risolvere.

**Fuori da questo lavoro:** l'attivazione su Forestas. Per decisione emersa in call (Giuseppe
Bonfanti, scrum 16/09/2026), lo sviluppo si ferma a `wm-package` sviluppato via `maphub` in
locale; sarà Bonfanti stesso ad aggiornare il submodule su Forestas separatamente.

## Perché

Se chi ha disegnato la geometria di una traccia l'ha tracciata in senso opposto al verso di
percorrenza reale, la piattaforma calcola l'andata come fosse il ritorno: tempi e dislivelli
risultano scambiati, e il dato sembra errato quando in realtà è errato solo il verso. Poter
invertire la traccia consente al gestore di correggere alla fonte (la geometria) invece di dover
intervenire a mano sui valori calcolati — utile in particolare per le geometrie importate da
OpenStreetMap.

Priorità bassa: su Forestas non risultano casi noti di tracce invertite (osservazione di Alessio
Piccioli in call). Il valore è di prodotto/consistenza con MapHub più che un'urgenza operativa.

## Requisiti

- [ ] Nuova Nova Action su `EcTrack` (solo `EcTrack`, non `UgcTrack` — coerente con GeoHub, che
      non ha nessuna azione di reverse su `UgcTrack` e il cui modello non ha comunque campi DEM)
- [ ] L'inversione della geometria avviene via SQL puro, mai tramite assegnazione Eloquent seguita
      da `save()`. **Secondo ciclo — la logica si sposta**: un metodo `reverseGeometry()` nuovo su
      `GeometryComputationService` (il contenitore delle operazioni PostGIS del package), non più
      SQL scritto dentro l'Action. `ExecuteEcTrackDataChainAction` è il pattern di riferimento
      (delega a `app(EcTrackService::class)`, nessuna riga SQL nell'Action).
- [ ] **Secondo ciclo — gestione MultiLineString a più parti.** `ST_Reverse` da solo inverte i
      vertici dentro ogni parte ma non riordina le parti tra loro: su una traccia a parte singola
      il risultato è corretto per caso, su una a più parti no (verificato: 5 tracce sul DB locale
      di Forestas hanno più di una parte, la 334 ne ha 12). Il fix: `ST_Dump` per esplodere le
      parti, `ST_Reverse` su ciascuna, poi `ST_Collect` ricomponendo in ordine di `path`
      **discendente**, mantenendo la quota Z e il tipo MultiLineString in uscita.
- [ ] **Il ricalcolo dei dati derivati non può dipendere dal trigger automatico dell'observer.**
      `EcTrackService::updateDataChain()` parte solo se `$track->wasChanged('geometry')`, condizione
      sempre falsa con scrittura SQL pura (Eloquent non la vede). **Secondo ciclo — decisione
      ribaltata rispetto al primo ciclo:** non basta il solo ricalcolo DEM. `updateDataChain()`
      accoda dieci job quando la geometria cambia (`UpdateEcTrackDemJob`,
      `UpdateEcTrackManualDataJob`, `UpdateEcTrackCurrentDataJob`, `UpdateEcTrack3DDemJob`,
      `UpdateEcTrackSlopeValues`, `UpdateModelWithGeometryTaxonomyWhere`,
      `UpdateEcTrackGenerateElevationChartImage`, `GenerateEcTrackPBFBatch`, più
      `UpdateEcTrackAwsJob` e `UpdateEcTrackAppRelationsInfoJob` sempre) — mancava soprattutto
      `UpdateEcTrackCurrentDataJob`, che promuove i valori DEM ricalcolati dall'area di appoggio
      `properties['dem_data']` ai campi effettivamente mostrati: senza, scheda e app restano al
      verso vecchio pur con il job "completato" con successo. **Decisione presa in questo ciclo**:
      `EcTrackService::updateDataChain(EcTrack $track, bool $forceGeometryChain = false)` — nuovo
      parametro con default `false` (nessun impatto sui 3 chiamanti esistenti né sui test attuali,
      verificato), l'Action lo chiama con `true` dopo l'update SQL e il refresh del model.
      Aggiornare il messaggio di risposta Nova per segnalare che il ricalcolo (più pesante ora)
      richiederà del tempo.
- [ ] Se la traccia ha override manuali (`manual_data`) su uno dei campi sopra, l'azione **non li
      modifica automaticamente**: mostra un avviso Nova che segnala la presenza di override
      manuali potenzialmente non coerenti col nuovo verso, lasciando all'utente la decisione di
      correggerli (il dato non permette di sapere se il valore manuale era già riferito al verso
      corretto o a quello vecchio — non è un'inversione automaticamente risolvibile, vedi
      discussione in reverse-interaction). **Secondo ciclo (cleanup):** l'avviso usa
      `Action::danger()`, non `Action::message()` — quest'ultimo è reso in verde da Nova, identico
      al messaggio di successo, e un avviso che sembra una conferma non viene letto (pattern
      `Action::danger()` già usato 18 volte nel package, es. `ImportTaxonomyWhere.php:60`).
- [ ] Permessi: **Secondo ciclo — decisione ribaltata rispetto al primo ciclo.** L'azione non può
      restare priva di restrizioni: è un'operazione non annullabile dall'interfaccia, e senza
      `canSee()`/`canRun()` anche Editor e Contributor potrebbero invertire un sentiero già
      pubblicato. Ristretta a **ruolo Administrator** (confermato dal dev), stesso pattern di
      `App.php:153-154` (`canSee($adminOnly)`/`canRun($adminOnly)` con
      `optional($request->user())->hasRole('Administrator')`).
- [ ] Nome azione in inglese, wrappato in `__()`, con traduzione aggiunta in tutti i
      `resources/lang/*.json` esistenti del package (de, en, es, fr, it) — coerente con le altre
      azioni Nova di `EcTrack`
- [ ] **L'azione è vincolata a una singola traccia, non selezionabile in bulk/multi-select.**
      Nessuna azione Nova esistente su `EcTrack` ha oggi un guard contro selezioni multiple
      incoerenti (solo `DownloadUgcTrackAction`, su un altro modello, ne ha uno); vincolare
      l'azione a una sola risorsa evita il rischio di invertire N tracce per errore in un click,
      senza dover progettare un guard dedicato in questo ciclo. **Correzione emersa in write-plan
      (primo ciclo):** il metodo Nova corretto per questo comportamento è `->sole()`, non
      `->standalone()` — in Laravel Nova (`Action.php:490,898`) `standalone()` significa l'opposto
      (azione eseguibile senza nessun modello selezionato, es. `UploadTrackFile`), mentre `sole()`
      vincola l'azione a esattamente una risorsa. **Bug trovato in review (primo ciclo, già
      corretto prima del commit):** `sole()` non è comunque applicato lato server da Nova, solo
      lato UI — l'azione deve iterare comunque su tutti i `$models` ricevuti.
- [ ] Test Pest che verifica: geometria effettivamente invertita in DB dopo l'azione, e
      ascent/descent/ele_from/ele_to aggiornati ai valori restituiti da `MockDemClient` dopo il
      ricalcolo, per una traccia senza override manuali. **Nota (Fase: challenge, primo ciclo):**
      nel package non esiste un'infrastruttura di test con calcolo DEM reale — ogni test su
      `EcTrackService::updateDemData` sostituisce `DemClient` con un mock a valori fissi
      (`AbstractEcTrackServiceTest`); questo test segue lo stesso pattern, non introduce una
      copertura che il resto del package non ha
- [ ] **Test Pest dedicato per il punto sul ricalcolo esplicito**: verifica che la data chain
      parta anche quando la scrittura della geometria avviene via SQL puro (cioè che il test
      fallirebbe se qualcuno, in futuro, riscrivesse l'azione assumendo che basti il salvataggio
      Eloquent) — è il punto più a rischio di regressione silenziosa del ticket
- [ ] **Secondo ciclo — test dedicato multi-parte**: geometria MultiLineString a due parti non
      contigue, verifica che dopo l'inversione il punto di partenza/arrivo del percorso completo
      sia scambiato (asserire sugli estremi via `ST_StartPoint`/`ST_EndPoint`/`ST_GeometryN`
      invece che sulla stringa WKT intera, più robusto tra versioni PostGIS). Scritto **prima**
      del fix (deve fallire sul codice attuale), poi verificato a mano anche sulla traccia reale
      id 334 (12 parti) in locale.
- [ ] **Secondo ciclo — cleanup**: il test che verifica il messaggio di warning non deve cercare
      la stringa inglese `'Warning'` (fallirebbe silenziosamente sempre vero/falso a seconda del
      locale attivo) — asserire su un elemento non legato alla lingua (es. nome del campo in
      `:fields`, o fissare esplicitamente `App::setLocale('en')` nel test).
- [ ] **Secondo ciclo — rebase**: risolvere il conflitto reale con `develop` (unico file:
      `.claude/rules/nova.md`; i file della feature si mergiano senza conflitto, verificato con un
      merge di prova annullato).
- [ ] **Secondo ciclo — CI**: la pipeline `run-tests` fallisce su `composer install` da almeno il
      27/07/2026, su ogni PR del repo (non solo su questa), per il blocco automatico di Composer
      sulle versioni di `laravel/framework` e `ebess/advanced-nova-media-library` segnalate da
      security advisory. Fix in `composer.json` (`config.policy.advisories.ignore-id`, con motivo
      per ciascun ID dopo aver verificato che non riguarda l'uso che il package ne fa — non un
      `block: false` generico). Verificare inoltre se i test Pest di questa PR possono girare dal
      container `php-forestas` di questo repo (licenza Nova valida), invece che nel Docker
      standalone di `wm-package` bloccato dalla licenza.

## Rischi

- **Override manuali (`manual_data`) su ascent/descent/ele_from/ele_to dopo l'inversione**: il
  valore manuale ha priorità sul dato ricalcolato da DEM (`classifyField`, trait
  `HasDemClassification`) e non viene toccato dall'azione. Se il valore manuale era riferito al
  verso di percorrenza precedente, dopo l'inversione resta sbagliato finché qualcuno non
  interviene. Mitigato con un avviso a schermo (non con una correzione automatica, che rischia di
  rompere il caso in cui il valore manuale fosse già corretto per il verso giusto — vedi Perché).
- **Codice sorgente GeoHub datato**: la Nova Action originale risale, per stima di Bonfanti in
  call, a un'epoca Laravel 9 e non è pensata per le convenzioni attuali di `wm-package`
  (geometrie via SQL puro, data chain esistente). Il porting non è quindi una copia diretta ma un
  adattamento — mitigato scrivendo l'azione da zero seguendo le convenzioni del package, usando
  GeoHub solo come riferimento del comportamento atteso.
- **Job asincroni e fallimenti silenziosi**: se la data chain di ricalcolo viene dispatchata in
  coda (non sincrona), l'utente Nova vede la geometria invertita subito ma i campi derivati
  restano temporaneamente i vecchi valori finché la catena non gira. Aggravante confermata nel
  codice: `updateDemData()` logga l'errore senza rilanciarlo (`Log::error` senza `throw`) — un job
  che fallisce (es. DEM non disponibile per la zona) appare "completato" su Horizon, senza retry
  né segnale visibile: è la stessa trappola già nota nel package (vedi
  `.claude/rules/job-e-import.md`, oc:8158/oc:8014). **Rischio accettato in Fase: challenge**,
  coerente con la priorità bassa del ticket: nessun meccanismo di notifica aggiuntivo in questo
  ciclo, ma il messaggio Nova viene aggiornato per avvisare che il ricalcolo richiederà del tempo
  (vedi Requisiti, secondo ciclo — la catena completa è più pesante della sola DEM).
- **Nessun lock su doppio click**: non esiste un mutex sul modello `EcTrack` nel package, quindi
  due click ravvicinati sulla stessa traccia mettono in coda due ricalcoli quasi simultanei.
  **Accettato in Fase: challenge**: l'azione standalone (non bulk, vedi Requisiti) è considerata
  mitigazione sufficiente per la priorità bassa di questo ticket; nessun lock dedicato in questo
  ciclo.
- **Scelta "solo ricalcolo DEM" del primo ciclo, superata**: era stata decisa in Fase: challenge
  del primo ciclo per evitare di rigenerare PBF/immagine profilo/TaxonomyWhere — artefatti costosi
  giudicati sproporzionati per un'operazione a priorità bassa. La review ha mostrato che senza
  `UpdateEcTrackCurrentDataJob` i valori ricalcolati restano in un'area di appoggio interna e non
  arrivano mai ai campi mostrati: la scelta rendeva l'azione silenziosamente inefficace, non solo
  più leggera. Sostituita dalla data chain completa (vedi Requisiti, secondo ciclo).
- **Modifica della firma di `EcTrackService::updateDataChain()`**: è un metodo condiviso, chiamato
  anche da `EcTrackObserver`, `EcPoiObserver` ed `EcPoiEcTrackObserver`. Mitigato verificando (non
  solo assumendo) che tutti e 3 i chiamanti esistenti passino un solo argomento: il nuovo
  parametro `$forceGeometryChain` con default `false` non cambia il loro comportamento.
- **Rebase con `develop` durante l'implementazione**: se `develop` avanza di nuovo prima del
  merge, il conflitto da risolvere potrebbe non essere più solo su `.claude/rules/nova.md`.
  Mitigato eseguendo il rebase il più vicino possibile alla fine di questo ciclo, non a inizio
  lavoro.
- **Fix del blocco Composer sugli advisory**: un `policy.advisories.block: false` generico
  silenzierebbe anche avvisi futuri realmente rilevanti per il package. Mitigato richiedendo la
  verifica puntuale di ciascun advisory ID prima di aggiungerlo a `ignore-id` con un motivo
  esplicito (vedi Requisiti, secondo ciclo) — non una disattivazione totale del controllo.

## Out of scope

- Attivazione su Forestas: la esegue Giuseppe Bonfanti separatamente, fuori da questo ciclo di
  lavoro (decisione confermata in call del 16/09/2026)
- `UgcTrack`: nessuna azione di reverse, coerente con GeoHub
- Correzione/swap automatico dei valori in `manual_data`: solo avviso, nessuna modifica automatica
- Rework della vecchia azione in GeoHub: resta invariata, si porta solo il comportamento in
  wm-package
- Feature flag per disattivare l'azione senza un nuovo deploy: non previsto in questo ciclo —
  rischio accettato in Fase: challenge (vedi Rischi)
- **Secondo ciclo**: risoluzione strutturale del blocco Composer per l'intero ecosistema
  advisory/dipendenze (si sistemano solo gli ID che bloccano questa pipeline, non un audit
  completo delle dipendenze del package)
- **Secondo ciclo**: gate CI di parità fra `en.json`/`it.json` — segnalato dalla review come
  assente nel package, ma non richiesto da questo ticket (le chiavi di questo lavoro sono già
  allineate)

## Moduli toccati

- `wm-package/src/Nova/Actions/ReverseEcTrackGeometryAction.php` (nuovo — orchestrazione, nessuna
  riga SQL, secondo ciclo)
- `wm-package/src/Nova/EcTrack.php` (registrazione della nuova action in `actions()`,
  `canSee`/`canRun` limitati ad Administrator — secondo ciclo)
- `wm-package/src/Services/GeometryComputationService.php` (nuovo metodo `reverseGeometry()` con
  gestione MultiLineString multi-parte — secondo ciclo)
- `wm-package/src/Services/Models/EcTrackService.php` (nuovo parametro `$forceGeometryChain` su
  `updateDataChain()` — secondo ciclo)
- `wm-package/resources/lang/en.json`, `it.json` (testo aggiornato del messaggio Nova, incluso
  l'allineamento chiave/codice trovato in `wm-review-ticket` — secondo ciclo; **de/es/fr esclusi**,
  vedi `notes.md`)
- `wm-package/tests/Feature/Nova/Actions/ReverseEcTrackGeometryActionTest.php` (nuovi test:
  multi-parte, `forceGeometryChain`, permessi Administrator, fix locale-independent sul warning,
  helper `wktOf()` — secondo ciclo)
- `wm-package/tests/TestCase.php` (registrato `PermissionServiceProvider`, mancante — necessario
  per testare `canSee`/`canRun` — secondo ciclo)
- `wm-package/.claude/rules/nova.md` (risoluzione conflitto rebase con `develop` — secondo ciclo)
- **`wm-package/composer.json`: rimandato** (Task 10 del piano, `config.policy.advisories.ignore-id`
  — decisione di rischio da confermare col dev, non eseguita in questo ciclo, vedi `notes.md`)

---

## Terzo ciclo (23/09/2026)

Review del 23/09/2026 sul commit `bb4b75a3`, esito **changes requested** (dettaglio nel ticket
oc:8543). Il riferimento di questo ciclo è il ticket: dove la call di scrum del 23/09 diceva altro
(«la geometria la inverte sempre»), vale il ticket, scritto dopo la call (decisione della dev).

### Cosa cambia

L'Action non inverte più sempre e solo la geometria: diventa **«Inverti verso della traccia»**
(`Reverse Track Direction`) e apre una finestra in cui l'utente sceglie cosa fare:

- **«Inverti geometria»**, sempre presente e selezionato di default;
- un flag **«Scambia»** per ogni coppia di dati che dipende dal verso ed è valorizzata sulla
  traccia, spento di default, con i valori attuali mostrati sotto la casella.

Tutta l'operazione passa in un nuovo metodo `EcTrackService::reverse()`, richiamabile anche da
artisan o API; l'Action raccoglie le scelte e lo chiama. La catena di ricalcolo non è più quella
generica di `updateDataChain()`, ma una catena dedicata all'inversione, che dipende da cosa è
cambiato.

### Perché

1. **La catena completa cancella gli override manuali.** `updateDataChain(forceGeometryChain: true)`
   accoda `UpdateEcTrackManualDataJob`, che ricostruisce `manual_data` dai valori al primo livello
   di `properties`. Il form Nova però scrive gli override in `properties.manual_data`: il job non
   trova nulla e scrive `manual_data = null`. Verificato il 23/09 sul DB locale di Forestas, dentro
   una transazione annullata: sulla traccia 14 `updateManualData()` cancella `ascent`, `distance`,
   `duration_forward` e `duration_backward`. L'import da Sardegna Sentieri valorizza `manual_data`
   su ogni traccia (`forestas/app/Dto/Import/TrackPropertiesData.php:32-50`: distanza, dislivello,
   durate e quote dall'API), da cui il «767 su 769» del ticket. Sul DB locale di questa macchina
   invece 742 tracce hanno `manual_data = null`, tutte aggiornate nella stessa ora (09/04/2026, ore
   16), e la 730 è stata azzerata il 23/09 alle 07:09: con ogni probabilità è lo stesso bug, già
   avvenuto tramite l'observer dopo un cambio di geometria (ipotesi, non dimostrata).
2. **Non sempre tutti i dati vanno invertiti insieme alla geometria.** I casi d'uso sono tre,
   combinabili:
   - si inverte la geometria e con lei i dati manuali e partenza/arrivo;
   - si inverte la geometria, ma i dati manuali (o parte di essi) e partenza/arrivo sono già giusti,
     perché inseriti pensando al verso corretto;
   - la geometria è già stata invertita e solo dopo ci si accorge che vanno scambiati anche dei
     dati: serve scambiarli senza toccare di nuovo la geometria.
3. **`$chain[0]->afterCommit()` dentro `updateDataChain()` vale per tutti i chiamanti**, e cambia il
   comportamento di `EcPoiObserver` ed `EcPoiEcTrackObserver`, che non hanno `$afterCommit`.

### Requisiti

**Finestra dell'Action**

- [ ] Nome `Reverse Track Direction` → «Inverti verso della traccia».
- [ ] Flag `Reverse geometry` → «Inverti geometria», sempre visibile, selezionato di default.
- [ ] Per ciascuna coppia della tabella sotto, un flag `Swap :first / :second` → «Scambia :first /
      :second», **mostrato solo se almeno uno dei due valori è presente** sulla traccia
      selezionata, spento di default. I valori attuali stanno nella riga di aiuto (`->help()`), es.
      «Salita: 500 — Discesa: 320»; il valore mancante si mostra come «—». I valori non entrano
      nell'etichetta, che resta una chiave di traduzione fissa.
- [ ] Se nessuna coppia è valorizzata, resta solo «Inverti geometria».
- [ ] La traccia selezionata si legge dentro `fields()` con `$request->selectedResources()`:
      `NovaRequest` usa il trait `InteractsWithResourcesSelection` (Nova 5,
      `InteractsWithResourcesSelection.php:48`). L'Action resta vincolata a una sola traccia con
      `->sole()`. La verifica a mano controlla che la finestra mostri le coppie giuste sia dalla
      lista delle tracce sia dalla scheda della traccia.
- [ ] Tutti i flag spenti → errore `Select at least one operation.` → «Seleziona almeno
      un'operazione.», e nessuna scrittura.
- [ ] Messaggio finale `Geometry reversed: :geometry. Swapped: :pairs. Recalculation in progress.` →
      «Geometria invertita: :geometry. Scambiati: :pairs. Ricalcolo in corso.», con `:geometry`
      sì/no e `:pairs` l'elenco delle coppie scambiate oppure «nessuno». Sostituisce l'avviso sugli
      override.
- [ ] Le chiavi di traduzione vecchie (`Reverse Track Geometry` e i due messaggi) vanno tolte da
      `en.json` e `it.json`. Le etichette delle coppie riusano quelle già presenti in `it.json` se
      esistono.
- [ ] Restano i permessi del secondo ciclo: solo Administrator.

**Come cambiano i dati**

| Dato | Dove sta | Flag | Flag spento | Flag acceso |
|---|---|---|---|---|
| `geometry` | colonna | «Inverti geometria» | invariata | invertita |
| `ascent` / `descent` | `properties.manual_data` | «Scambia» | invariati | scambiati |
| `ele_from` / `ele_to` | `properties.manual_data` | «Scambia» | invariati | scambiati |
| `duration_forward` / `duration_backward` | `properties.manual_data` | «Scambia» | invariati | scambiati |
| `from` / `to` | `properties`, primo livello | «Scambia» | invariati | scambiati |
| `distance`, `ele_min`, `ele_max` | `properties.manual_data` | nessuno | sempre invariati | — |
| valori DEM | `properties.dem_data` | nessuno | ricalcolati dalla catena se la geometria è invertita | — |

- [ ] **Coppia con un solo valore, flag acceso:** il valore passa all'altro campo e quello di
      partenza resta vuoto. Per i dati di `manual_data` il campo vuoto torna a mostrare il valore
      DEM (es. `ascent=500`, `descent` assente → `descent=500`, `ascent` assente). Per `from`/`to`
      resta vuoto: su Forestas non c'è un valore di riserva, perché la tabella non ha la colonna
      `osmfeatures_data` letta come fallback in `EcTrack.php:731`. Sul DB locale di Forestas 52
      tracce hanno solo uno dei due valori, es. la 93 (`to` = `null`).
- [ ] Un valore salvato come `null` conta come assente.

**Service e catene**

- [ ] Nuovo `EcTrackService::reverse(EcTrack $track, bool $geometry, array $swaps)` che:
  - inverte la geometria se richiesto, con `GeometryComputationService::reverseGeometry()`
    (invariato, già corretto sulle MultiLineString a più parti);
  - applica solo gli scambi scelti, scrivendo `properties` senza far passare la geometria da
    Eloquent;
  - accoda la catena di ricalcolo **dopo il commit**.
- [ ] Catena con **geometria invertita**: `UpdateEcTrackDemJob`, `UpdateEcTrackCurrentDataJob`,
      `UpdateEcTrackSlopeValues`, `UpdateEcTrackGenerateElevationChartImage`,
      `UpdateEcTrackOrderRelatedPoi`, `GenerateEcTrackPBFBatch`, `UpdateEcTrackAwsJob`,
      `UpdateEcTrackAppRelationsInfoJob`.
- [ ] Catena con **solo scambi**: `UpdateEcTrackCurrentDataJob`, `GenerateEcTrackPBFBatch` (le tile
      contengono `duration_forward` letto da `manual_data`, `PBFGeneratorService.php:262-268`),
      `UpdateEcTrackAwsJob`. Prima di chiuderla, verificare cosa legge ciascun job.
- [ ] In **nessuna** delle due catene: `UpdateEcTrackManualDataJob` (cancella gli override),
      `UpdateEcTrackFromOsmJob` (riscriverebbe la geometria da OSM), `UpdateEcTrack3DDemJob` (la
      quota dei punti non cambia), `UpdateModelWithGeometryTaxonomyWhere` (dipende dalla forma, non
      dal verso).
- [ ] `UpdateEcTrackCurrentDataJob` resta in entrambe le catene, come chiede il ticket: è il passo
      che, per come è progettata la catena, allinea `manual_data` e i valori mostrati. Oggi nel
      package non lo fa per un bug del porting da GeoHub. In GeoHub la logica stava nel trait
      `HandlesData`, usato anche dal modello `EcTrack`, e `$track->getDemDataFields()` funzionava.
      Col refactor oc:4667 (commit `0d0970f4`, `f5371a4a`) la logica è passata in `EcTrackService`,
      ma la chiamata è rimasta sul modello (`EcTrackService.php:169`), che il metodo non ce l'ha più
      (è su `EcTrackService.php:48`, su nessun branch del package è sul modello). Il metodo va
      quindi sempre in errore, il `catch` scrive `HandlesData: An error occurred during a store
      operation` nel log, e si ferma prima di `saveQuietly()`. Verificato il 23/09 in una
      transazione annullata: un override salvato da Nova mentre il job gira resta intatto. Quando
      il bug sarà corretto, il job tornerà a lavorare anche nelle catene dell'inversione.

**Da togliere**

- [ ] Il parametro `$forceGeometryChain` di `EcTrackService::updateDataChain()`.
- [ ] `$chain[0]->afterCommit()` dentro `updateDataChain()`: torna al comportamento di `develop` per
      tutti i chiamanti. L'`afterCommit` va solo sulle catene dell'inversione.
- [ ] L'avviso sugli override, `getOverriddenFields()` e la costante `DIRECTION_DEPENDENT_FIELDS` in
      `ReverseEcTrackGeometryAction`.

**Test e verifica**

- [ ] Test Pest:
  - flag di scambio spenti → dati invariati;
  - flag accesi → coppie scambiate;
  - combinazione mista;
  - coppia con un solo valore;
  - traccia senza dati manuali né partenza/arrivo → solo il flag della geometria;
  - solo scambi → geometria identica e catena ridotta;
  - tutti i flag spenti → errore e nessuna scrittura;
  - elenco esatto dei job di entrambe le catene;
  - `manual_data` non azzerato dopo l'inversione, senza `Bus::fake()` sul job che lo cancellava:
    è il test che mancava. Non fa girare l'intera catena, perché AWS, PBF e immagine del profilo
    scriverebbero su storage esterni (stessa classe di incidente di oc:8251): esegue a mano solo i
    job che scrivono `properties`, cioè `UpdateEcTrackDemJob` con il `DemClient` finto e
    `UpdateEcTrackCurrentDataJob`.
- [ ] Suite completa del package lanciata in `php-forestas` con il `vendor/bin/pest` di
      `wm-package` (database `wm_package`, isolato da `forestas`), **due volte**: su `develop` e sul
      branch. Criterio: nessun test fallito in più sul branch rispetto a `develop`, e tutti i test
      di `ReverseEcTrackGeometryActionTest` verdi. I due elenchi vanno in `notes.md` e nel commento
      sulla PR. La suite serve perché la PR modifica `tests/TestCase.php`, e la CI dei test è rotta
      su tutto il repo (oc:8626).
- [ ] Verifica a mano in Nova su Forestas locale, eseguita dalla dev con una lista di passi
      preparata nel piano, su tre tracce: una con tutte le coppie valorizzate, una con un solo
      valore in una coppia (es. la 93), una senza dati manuali né partenza/arrivo. Dopo ogni prova
      la traccia si rimette com'era rilanciando l'Action con le stesse scelte.

### Rischi

Nessun rischio nuovo in questo ciclo oltre a quelli già accettati nei cicli precedenti (sopra).
Quello ipotizzato su `UpdateEcTrackCurrentDataJob`, cioè una modifica da Nova sovrascritta dal suo
`saveQuietly()`, non esiste: il metodo va in errore prima di arrivarci (vedi Requisiti, «Service e
catene»).

### Out of scope

Da aprire in un ticket separato, insieme:

- `EcTrackService::updateManualData()`, che non legge il formato di `manual_data` usato dal form Nova
  e lo cancella. Non riguarda solo l'inversione: parte in ogni catena di `updateDataChain()` dopo un
  cambio di geometria. Da verificare se è la causa delle 742 tracce con `manual_data` azzerato sul
  DB locale (vedi Perché, punto 1), e se lo stesso è successo in produzione.
- `EcTrackService::updateCurrentData()`, rotto dal porting da GeoHub alla riga 169 (vedi Requisiti,
  «Service e catene»). Correggere solo quella riga non basta: il metodo arriverebbe a un
  `saveQuietly()` che riscrive tutto `properties` anche quando non ha aggiornato nulla, e una
  modifica fatta da Nova nel frattempo andrebbe persa.
- La CI dei test (oc:8626) e gli advisory Composer (Task 10 del secondo ciclo).
- Tutto quanto già fuori scope nei cicli precedenti (attivazione su Forestas, `UgcTrack`, GeoHub).

### Moduli toccati

Tutti in `wm-package`, branch `feature/oc-8543-inversione-della-traccia-attivazione-su-forestas`.
In `forestas` nessuna modifica.

- `src/Nova/Actions/ReverseEcTrackGeometryAction.php`: `fields()` con i flag, nuovo nome, `handle()`
  che chiama `EcTrackService::reverse()`, via avviso e costante.
- `src/Services/Models/EcTrackService.php`: nuovo `reverse()` con le due catene; via
  `$forceGeometryChain` e `$chain[0]->afterCommit()` da `updateDataChain()`.
- `resources/lang/en.json`, `resources/lang/it.json`: chiavi nuove, via quelle vecchie.
- `tests/Feature/Nova/Actions/ReverseEcTrackGeometryActionTest.php` ed eventuale test del service.
- `docs/features/8543-inversione-della-traccia-attivazione-su-forestas/`: overview, plan, notes.

## Terzo ciclo — revisione del 24/09/2026

Il 24/09/2026 il branch è stato allineato a `develop` con un merge (commit `3c86ec66`, non ancora
pushato). Da `develop` è entrato oc:8571, che ha cambiato due premesse della sezione del 23/09.
L'implementazione del terzo ciclo passa a Giuseppe Bonfanti, su questo branch. Valgono tutti i
requisiti del 23/09 tranne quelli modificati qui sotto.

### Cosa cambia rispetto al 23/09

1. **`manual_data` non viene più azzerato: il bug è corretto su `develop`.** oc:8571 ha modificato
   `EcTrackService::updateManualData()` (`src/Services/Models/EcTrackService.php:229-265`): ora
   parte dal `manual_data` esistente invece di ricostruirlo dal primo livello di `properties`. Il
   «Perché», punto 1, e la prima voce dell'Out of scope del 23/09 non valgono più come scritti.
2. **`UpdateEcTrackManualDataJob` resta fuori dalle catene dell'inversione, per un motivo diverso.**
   Nell'inversione i dati manuali li decide l'utente con i flag «Scambia». Il job li ricalcolerebbe
   dal primo livello di `properties` e, su una traccia che lì ha dei valori (flusso OSM/GeoHub),
   sovrascriverebbe lo scambio appena fatto. Il primo livello è destinato a sparire (oc:8642).
3. **Anche `UpdateEcTrackCurrentDataJob` esce da entrambe le catene.** Il job non fa niente, per due
   motivi indipendenti:
   - `updateCurrentData()` chiama `$track->getDemDataFields()` sul modello
     (`EcTrackService.php:189`), mentre il metodo esiste solo sul service (riga 48): va sempre in
     errore e il `catch` scrive nel log. È rotto dal 27/01/2025 (commit `f5371a4a`, oc:4667);
   - anche corretta quella riga, il metodo lavora su `$track->getDirty()`, che in un job accodato è
     sempre vuoto perché il modello viene riletto dal DB.

   I valori mostrati non ne hanno bisogno: scheda Nova, API, JSON su AWS e tile PBF calcolano il
   valore corrente in lettura (`HasDemClassification::classifyField()`): manuale se c'è, poi OSM se
   la traccia ha `osmid`, altrimenti DEM (`HasDemClassification.php:33-51`). Le tracce con `osmid`
   sono escluse dall'Action (punto 7), quindi per l'inversione vale «manuale, altrimenti DEM». La
   rimozione del job dalle catene standard è di oc:8642.
4. **L'indice Elasticsearch si aggiorna in modo esplicito.** `EcTrack` usa Scout
   (`src/Models/EcTrack.php:28`) e l'indice contiene `from`, `to` e `duration_forward`
   (`toSearchableArray()`, `EcTrack.php:709-743`). `reverse()` non passa dall'observer, quindi Scout
   non reindicizza da solo: dopo il commit `reverse()` reindicizza la traccia, sia dopo
   un'inversione della geometria sia dopo i soli scambi. Come, e perché un errore dell'indice non
   deve far fallire l'Action, è nei Requisiti modificati.
5. **Nell'indice `ascent` diventa il valore corrente.** Oggi `toSearchableArray()` legge `ascent`
   dal primo livello di `properties`, che su Forestas è vuoto: il dislivello indicizzato è sempre 0,
   e uno scambio salita/discesa non cambierebbe niente nella ricerca. Si allinea a `distance` e
   `duration_forward`, che già usano `classifyField(...)['currentValue']`, cioè lo stesso valore
   mostrato nel detail di Nova. `from`/`to` restano letti dal primo livello, che è il loro posto
   (non sono campi DEM). Su oc:8642, che elenca questo punto, va una nota: è fatto qui.
6. **Nessuna conferma aggiuntiva prima dell'esecuzione.** La finestra con i flag è già la scelta
   esplicita. L'inversione si annulla rilanciando l'Action con le stesse scelte, e il messaggio
   finale dice cosa è stato fatto.
7. **Le tracce con `osmid` sono in sola lettura: l'Action non le modifica.** Su quelle tracce
   l'inversione non reggerebbe: senza valore manuale `classifyField()` mostra i valori OSM, riferiti
   al verso vecchio, e al primo salvataggio da Nova `updateDataChain()` mette in testa
   `UpdateEcTrackFromOsmJob` (`EcTrackService.php:361-362`), che riscrive la geometria da OSM. Il
   verso di quelle tracce si corregge su OpenStreetMap. Su Forestas oggi 0 tracce su 769 hanno
   `osmid`, ma l'Action sta nella Resource `EcTrack` del package e arriva a tutti i consumer.
8. **Il blocco dei job legati alla geometria diventa un metodo comune.** La catena dell'inversione
   è il blocco geometria di `updateDataChain()` meno quattro job, più la coda che la catena standard
   accoda sempre. Invece di una seconda lista scritta a mano, che col tempo si allontanerebbe da
   quella standard, il blocco si estrae in un metodo (es. `geometryDependentJobs(EcTrack $track,
   array $except = [])`) usato sia da `updateDataChain()` sia da `reverse()`, che gli passa le
   esclusioni. Un job aggiunto in futuro al blocco entra anche nell'inversione, salvo esclusione
   esplicita. `updateDataChain()` non cambia comportamento: le righe si spostano, non cambiano.
9. **Import da Drupal: nessun rischio in produzione.** L'import da Sardegna Sentieri riscrive
   geometria, `from`/`to` e `ascent` quando riprende una traccia. In produzione quei dati non
   verranno più importati da Drupal. Su UAT l'import `--reset` delle 06:00 ricostruisce il DB ogni
   notte: un'inversione di prova su UAT vale fino al mattino dopo.

### Requisiti modificati

- [ ] Catena con **geometria invertita**: `UpdateEcTrackDemJob`, `UpdateEcTrackSlopeValues`,
      `UpdateEcTrackGenerateElevationChartImage`, `UpdateEcTrackOrderRelatedPoi`,
      `GenerateEcTrackPBFBatch`, `UpdateEcTrackAwsJob`, `UpdateEcTrackAppRelationsInfoJob`.
      Si ottiene dal metodo comune del blocco geometria (punto 8), escludendo
      `UpdateEcTrackManualDataJob`, `UpdateEcTrackCurrentDataJob`, `UpdateEcTrack3DDemJob` e
      `SyncModelTaxonomyWhereJob`, più la coda.
- [ ] Nuovo metodo comune per il blocco geometria, usato da `updateDataChain()` e `reverse()`;
      `updateDataChain()` accoda gli stessi job, nello stesso ordine, di prima.
- [ ] Tracce con `osmid`: l'Action risponde con un errore che spiega che il verso va corretto su
      OpenStreetMap, e non scrive nulla.
- [ ] Catena con **solo scambi**: `GenerateEcTrackPBFBatch`, `UpdateEcTrackAwsJob`, più la
      reindicizzazione. Verificato il 24/09 che bastano, leggendo chi usa i campi scambiabili:
      - il JSON su AWS: `UpdateEcTrackAwsJob` rilegge la traccia dal DB e serializza
        `EcTrackResource`, che passa i campi DEM da `applyDemFields()` → `classifyField()`
        (`src/Http/Resources/EcTrackResource.php:41,62-73`). `from`/`to` passano così come sono;
      - le tile: `PBFGeneratorService.php:262-270` legge `distance` e `duration_forward` con la
        precedenza `manual_data` → `osm_data` → `dem_data`;
      - l'indice Elasticsearch: `from`, `to`, `duration_forward`, `ascent` (punti 4 e 5);
      - nessun altro: la stringa di ricerca per app (`EcTrack::getSearchableString()`) usa nome,
        descrizione, ref, osmid e tassonomie, e `AppConfigService` cita `duration_forward` solo come
        nome del filtro. `UpdateEcTrackAppRelationsInfoJob` riscrive layer, attività e stringa di
        ricerca, che non dipendono dagli scambi: non serve.
- [ ] In **nessuna** delle due catene: `UpdateEcTrackManualDataJob`, `UpdateEcTrackCurrentDataJob`,
      `UpdateEcTrackFromOsmJob`, `UpdateEcTrack3DDemJob`, `SyncModelTaxonomyWhereJob` (è il nome
      attuale di `UpdateModelWithGeometryTaxonomyWhere`, rinominato da oc:8487).
- [ ] Gli scambi scrivono **solo la colonna `properties`**, con un update mirato sulla riga, non con
      `save()`/`saveQuietly()` del modello intero. `save()` farebbe partire l'observer e la catena
      standard; `saveQuietly()` eviterebbe l'observer, ma farebbe comunque passare il modello da
      Eloquent, e la regola del package vuole che le geometrie non transitino dall'ORM.
- [ ] Dopo il commit, `reverse()` accoda la catena scelta e reindicizza la traccia. La
      reindicizzazione va dentro `DB::afterCommit(fn () => $track->fresh()->searchable())`, per due
      motivi verificati il 24/09:
      - Scout è **sincrono** e **non aspetta il commit**: `config/scout.php` non è pubblicato né nel
        package né in Forestas, quindi valgono i default di `vendor/laravel/scout/config/scout.php`
        (`'queue' => env('SCOUT_QUEUE', false)`, `'after_commit' => false`), e nel `.env` locale
        `SCOUT_QUEUE` non c'è. Stessa situazione su UAT, verificata in sola lettura il 24/09 con
        tinker in `php-forestasuat`: `scout.queue = false`, `scout.after_commit = false`, nessun
        `config/scout.php`. Chiamato dentro la transazione di Nova, `searchable()` scriverebbe
        subito sull'indice un dato che un rollback potrebbe annullare. `DB::afterCommit()` fuori da
        una transazione (artisan, API) esegue subito;
      - l'update mirato scrive sul DB e non sul modello in memoria: senza `fresh()`,
        `toSearchableArray()` leggerebbe i valori di prima dello scambio.
- [ ] **Un errore di Elasticsearch non deve far fallire l'inversione.** Il driver lancia
      un'eccezione se l'indicizzazione fallisce (`vendor/matchish/laravel-scout-elasticsearch/src/Engines/ElasticSearchEngine.php:44-53`,
      «Bulk update error»). Dopo il commit geometria e scambi sono già scritti: un'eccezione che
      arrivasse a Nova mostrerebbe un errore su un'inversione riuscita, e l'utente la rilancerebbe
      invertendo la traccia una seconda volta. Quindi, dopo il commit:
      - prima si accoda la catena, poi si reindicizza: un errore dell'indice non deve impedire
        l'accodamento;
      - la reindicizzazione sta in un `try/catch` che scrive l'errore nel log e non lo propaga. Nel
        caso peggiore la ricerca resta indietro su quella traccia fino al prossimo salvataggio.
- [ ] `EcTrack::toSearchableArray()`: `ascent` letto con `classifyField($this, 'ascent')['currentValue']`,
      con il cast `(int)`: da `manual_data` il valore può arrivare come stringa. Nessun reindex
      obbligatorio al rilascio: le tracce passano al valore corrente man mano che vengono
      reindicizzate, e quelle non toccate tengono il valore di oggi. Se si vuole l'indice allineato
      subito: `scout:import` sul modello `EcTrack` (o l'Action `ReindexAppScoutAction`).

### Test modificati o aggiunti

- [ ] L'elenco esatto dei job di entrambe le catene riflette le liste sopra: nessuna delle due
      contiene `UpdateEcTrackManualDataJob` né `UpdateEcTrackCurrentDataJob`.
- [ ] Il test «`manual_data` non azzerato dopo l'inversione» resta: esegue a mano
      `UpdateEcTrackDemJob` con il `DemClient` finto e verifica che gli override siano intatti.
      Non esegue più `UpdateEcTrackCurrentDataJob`, che non è nelle catene.
- [ ] La traccia viene reindicizzata una volta, sia con la geometria invertita sia con i soli
      scambi.
- [ ] Elasticsearch che fallisce: la catena è comunque accodata, l'Action risponde con il messaggio
      di successo e l'errore finisce nel log.
- [ ] `toSearchableArray()` restituisce come `ascent` il valore corrente: quello manuale se
      presente, altrimenti il DEM.
- [ ] Il test delle catene controlla l'elenco delle esclusioni dal blocco geometria, oltre
      all'elenco dei job.
- [ ] Traccia con `osmid`: errore, nessuna scrittura, nessun job accodato.
- [ ] `updateDataChain()` accoda gli stessi job di prima dell'estrazione del metodo comune.

La verifica a mano in Nova su Forestas locale resta come scritta il 23/09. Il dato locale è cambiato
da allora: il 24/09 769 tracce su 769 hanno `manual_data` valorizzato (non più 742 con `null`).

Il piano del terzo ciclo si apre con un **elenco unico dei requisiti validi**, preso dalle sezioni
del 23/09 e del 24/09. Le checkbox dei cicli precedenti non vanno eseguite.

### Out of scope, aggiornato

- `updateManualData()` è corretto da oc:8571. Il resto della pulizia del primo livello di
  `properties` è di oc:8642: `updateManualData()` e `updateCurrentData()` con i loro job, e la
  rimozione dalle catene standard.
- Il valore `ascent` nell'indice **non** è più fuori scope: è fatto qui (punto 5).
- Scout sincrono su tutta la piattaforma (locale e UAT: `scout.queue = false`): ogni salvataggio da
  Nova chiama Elasticsearch dentro la transazione, e se l'indice fallisce il salvataggio viene
  annullato. Passare alla coda è una scelta di piattaforma, da valutare a parte.

### Codice della PR da togliere o rinominare

Oltre a quanto già elencato il 23/09 in «Da togliere»:
- la classe `ReverseEcTrackGeometryAction` diventa **`ReverseTrackDirectionAction`**, e il test
  `ReverseTrackDirectionActionTest`. L'Action non inverte più sempre la geometria, e «Ec» serve solo
  a distinguere da una versione Ugc, che qui non esiste (precedenti: `UploadTrackFile`,
  `UpdateTracksOnAws`). Resta `EcTrackService::reverse()`, che sta nel service delle `EcTrack`;
- `use HasDemClassification` nell'Action: serviva solo a `getOverriddenFields()`;
- il docblock della classe, che descrive la catena completa e `forceGeometryChain`: va riscritto;
- i test sull'avviso degli override e su `forceGeometryChain`.

Restano: `GeometryComputationService::reverseGeometry()`, la registrazione in `EcTrack.php`
(`->sole()`, solo Administrator), `PermissionServiceProvider` in `tests/TestCase.php`, il ciclo su
`$models` in `handle()`, `InteractsWithQueue`/`Queueable` (coerenza col package, review del 22/09) e
le trappole in `.claude/rules/nova.md`.

### Moduli toccati, in aggiunta

- `src/Models/EcTrack.php`: `toSearchableArray()`, riga di `ascent`.
- `src/Services/Models/EcTrackService.php`: anche `updateDataChain()`, per l'estrazione del metodo
  comune del blocco geometria.

### Rischi, aggiornato

Dalla challenge del 24/09. Rischi reali gestiti: import da Drupal (punto 9), tracce OSM (punto 7),
catene che si allontanano (punto 8), documentazione a strati (elenco unico nel piano). L'indice
misto dopo il rilascio non è un rischio: le tracce non reindicizzate tengono il valore di oggi, e
il reindex completo è facoltativo (requisito su `ascent`). Rischi ipotetici accettati senza misure:
- tracce 2D o `LINESTRING` semplici non riportate al tipo originale da una seconda inversione (su
  Forestas sono tutte 3D e `MULTILINESTRING`);
- due scritture su `properties` della stessa traccia negli stessi pochi secondi;
- coda o Redis che non rispondono proprio al momento del commit;
- valori della traccia che cambiano fra l'apertura della finestra e l'esecuzione;
- nessun feature flag: l'Action arriva su tutti i consumer al bump, limitata ad Administrator.

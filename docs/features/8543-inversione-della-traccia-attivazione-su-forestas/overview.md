> Ticket: oc:8543

# Inversione della traccia: attivazione su Forestas

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

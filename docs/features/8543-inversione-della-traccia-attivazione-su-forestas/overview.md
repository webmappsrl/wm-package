> Ticket: oc:8543

# Inversione della traccia: attivazione su Forestas

## Cosa cambia

Viene aggiunta una nuova Nova Action su `EcTrack`, in `wm-package`, che inverte il verso di
percorrenza della geometria di una traccia (`ST_Reverse` sulla geometria PostGIS) e rilancia
automaticamente il ricalcolo dei dati che dipendono dal verso di percorrenza (ascent, descent,
ele_from, ele_to, duration_forward, duration_backward), riusando la data chain già esistente in
`EcTrackService`.

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
- [ ] L'inversione della geometria avviene via SQL puro (`UPDATE ... SET geometry =
      ST_Reverse(geometry) WHERE id = ?`, con l'id passato come binding `?`, mai interpolato nella
      stringa), mai tramite assegnazione Eloquent seguita da `save()`
- [ ] **Il ricalcolo dei dati derivati non può dipendere dal trigger automatico dell'observer.**
      `EcTrackService::updateDataChain()` parte solo se `$track->wasChanged('geometry')`, una
      condizione che si basa sul dirty-tracking di Eloquent — ma la scrittura via SQL puro del
      punto sopra non passa da Eloquent, quindi quel trigger non scatta mai. L'azione deve quindi
      chiamare esplicitamente il ricalcolo (sul model ricaricato dopo l'update SQL) subito dopo
      l'inversione, non fare affidamento su un ricalcolo "automatico". **Deciso in Fase:
      challenge:** l'azione richiama solo il sottoinsieme DEM (`EcTrackService::updateDemData()`
      o equivalente), non l'intera `EcTrackService::updateDataChain()` — che rigenera anche PBF,
      immagine profilo altimetrico, TaxonomyWhere e altri artefatti costosi, sproporzionati per
      un'operazione a priorità bassa senza casi noti su Forestas. Il metodo esatto si sceglie in
      write-plan tra le opzioni già presenti in `EcTrackService`.
- [ ] Se la traccia ha override manuali (`manual_data`) su uno dei campi sopra, l'azione **non li
      modifica automaticamente**: mostra un avviso Nova che segnala la presenza di override
      manuali potenzialmente non coerenti col nuovo verso, lasciando all'utente la decisione di
      correggerli (il dato non permette di sapere se il valore manuale era già riferito al verso
      corretto o a quello vecchio — non è un'inversione automaticamente risolvibile, vedi
      discussione in reverse-interaction)
- [ ] Permessi: nessuna restrizione `canSee()`/`canRun()` aggiuntiva — l'azione è visibile a chi
      può già modificare la traccia (`EcTrackPolicy::update()`: Admin, o Editor/Validator
      proprietario dell'app), stesso comportamento delle altre azioni già presenti su `EcTrack`
- [ ] Nome azione in inglese, wrappato in `__()`, con traduzione aggiunta in tutti i
      `resources/lang/*.json` esistenti del package (de, en, es, fr, it) — coerente con le altre
      azioni Nova di `EcTrack`
- [ ] **L'azione è vincolata a una singola traccia, non selezionabile in bulk/multi-select.**
      Nessuna azione Nova esistente su `EcTrack` ha oggi un guard contro selezioni multiple
      incoerenti (solo `DownloadUgcTrackAction`, su un altro modello, ne ha uno); vincolare
      l'azione a una sola risorsa evita il rischio di invertire N tracce per errore in un click,
      senza dover progettare un guard dedicato in questo ciclo. **Correzione emersa in write-plan:**
      il metodo Nova corretto per questo comportamento è `->sole()`, non `->standalone()` — in
      Laravel Nova (`Action.php:490,898`) `standalone()` significa l'opposto (azione eseguibile
      senza nessun modello selezionato, es. `UploadTrackFile`), mentre `sole()` vincola l'azione a
      esattamente una risorsa.
- [ ] Test Pest che verifica: geometria effettivamente invertita in DB dopo l'azione, e
      ascent/descent/ele_from/ele_to aggiornati ai valori restituiti da `MockDemClient` dopo il
      ricalcolo, per una traccia senza override manuali. **Nota (Fase: challenge):** nel package
      non esiste un'infrastruttura di test con calcolo DEM reale — ogni test su
      `EcTrackService::updateDemData` sostituisce `DemClient` con un mock a valori fissi
      (`AbstractEcTrackServiceTest`); questo test segue lo stesso pattern, non introduce una
      copertura che il resto del package non ha
- [ ] **Test Pest dedicato per il punto sul ricalcolo esplicito**: verifica che la data chain
      parta anche quando la scrittura della geometria avviene via SQL puro (cioè che il test
      fallirebbe se qualcuno, in futuro, riscrivesse l'azione assumendo che basti il salvataggio
      Eloquent) — è il punto più a rischio di regressione silenziosa del ticket

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
  coda (non sincrona), l'utente Nova vede la geometria invertita subito ma ascent/descent restano
  temporaneamente i vecchi valori finché il job non gira. Aggravante confermata nel codice:
  `updateDemData()` logga l'errore senza rilanciarlo (`Log::error` senza `throw`) — un job che
  fallisce (es. DEM non disponibile per la zona) appare "completato" su Horizon, senza retry né
  segnale visibile: è la stessa trappola già nota nel package (vedi
  `.claude/rules/job-e-import.md`, oc:8158/oc:8014). **Rischio accettato in Fase: challenge**,
  coerente con la priorità bassa del ticket e l'assenza di casi noti su Forestas: nessun
  messaggio di stato Nova o meccanismo di notifica aggiuntivo in questo ciclo.
- **Nessun lock su doppio click**: non esiste un mutex sul modello `EcTrack` nel package, quindi
  due click ravvicinati sulla stessa traccia mettono in coda due ricalcoli quasi simultanei.
  **Accettato in Fase: challenge**: l'azione standalone (non bulk, vedi Requisiti) è considerata
  mitigazione sufficiente per la priorità bassa di questo ticket; nessun lock dedicato in questo
  ciclo.

## Out of scope

- Attivazione su Forestas: la esegue Giuseppe Bonfanti separatamente, fuori da questo ciclo di
  lavoro (decisione confermata in call del 16/09/2026)
- `UgcTrack`: nessuna azione di reverse, coerente con GeoHub
- Correzione/swap automatico dei valori in `manual_data`: solo avviso, nessuna modifica automatica
- Rework della vecchia azione in GeoHub: resta invariata, si porta solo il comportamento in
  wm-package
- Feature flag per disattivare l'azione senza un nuovo deploy: non previsto in questo ciclo —
  rischio accettato in Fase: challenge (vedi Rischi)

## Moduli toccati

- `wm-package/src/Nova/Actions/ReverseEcTrackGeometryAction.php` (nuovo)
- `wm-package/src/Nova/EcTrack.php` (registrazione della nuova action in `actions()`)
- `wm-package/resources/lang/*.json` (de, en, es, fr, it — traduzione nome azione e messaggio di
  avviso override manuali)
- `wm-package/tests/Feature/Nova/Actions/` (nuovo test Pest)

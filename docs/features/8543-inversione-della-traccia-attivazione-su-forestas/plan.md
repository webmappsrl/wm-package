> Ticket: oc:8543

# Piano — Inversione della traccia: attivazione su Forestas

Tutti i file sono in `wm-package` (nessuna modifica a `maphub`, coerente con l'overview: l'attivazione
su Forestas resta fuori scope).

**Nota di correzione emersa scrivendo questo piano** (non c'era nell'overview): il requisito
"azione standalone, non selezionabile in bulk" va implementato con il metodo Nova `->sole()`, non
`->standalone()`. In Laravel Nova (`vendor/laravel/nova/src/Actions/Action.php:490,898`) i due
metodi hanno significati opposti a quello che il nome suggerisce nell'uso comune:
- `standalone()` marca un'azione che **non richiede nessun modello selezionato** (es.
  `UploadTrackFile`, che genera un template anche senza traccia selezionata)
- `sole()` marca un'azione riservata a **esattamente una risorsa**, mostrata inline nel detail
  (`showInline()->showOnDetail()`) — è questo il comportamento voluto per l'inversione

Usare `standalone()` per errore produrrebbe l'azione opposta a quella richiesta (nessuna traccia
necessaria, invece che una sola).

---

## Task 1 — Creare `ReverseEcTrackGeometryAction`

**File:** `src/Nova/Actions/ReverseEcTrackGeometryAction.php` (nuovo)

- `extends Laravel\Nova\Actions\Action`
- `name()`: ritorna `__('Reverse Track Geometry')`
- `handle(ActionFields $fields, Collection $models)`:
  1. Per il singolo model in `$models` (l'azione è vincolata a una traccia da `sole()`, quindi la
     collection ha sempre un solo elemento):
  2. Eseguire l'inversione via SQL puro con id come binding:
     ```php
     DB::statement('UPDATE ec_tracks SET geometry = ST_Reverse(geometry) WHERE id = ?', [$ecTrack->id]);
     ```
  3. Ricaricare il model dal DB (`$ecTrack->refresh()` o `EcTrack::findOrFail($ecTrack->id)`) —
     necessario perché la scrittura SQL puro non aggiorna l'istanza Eloquent in memoria.
  4. Rilevare eventuali override manuali sui campi impattati dal verso (`ascent`, `descent`,
     `ele_from`, `ele_to`, `duration_forward`, `duration_backward`) leggendo
     `$ecTrack->properties['manual_data']` (array indicizzato per campo, verificato in
     `src/Nova/Traits/HasDemClassification.php:22-40` — un valore non-null e non stringa vuota
     indica override). Raccogliere i nomi dei campi con override in un array `$overriddenFields`.
  5. Dispatchare il ricalcolo DEM in coda (**decisione di Fase: challenge**: solo il sottoinsieme
     DEM, non l'intera chain):
     ```php
     Bus::dispatch(new UpdateEcTrackDemJob($ecTrack));
     ```
     Nota: non chiamare `EcTrackService::updateDemData($ecTrack)` direttamente in modo sincrono —
     oggi in tutto il package quel metodo viene invocato solo dentro `UpdateEcTrackDemJob`
     (`src/Jobs/Track/UpdateEcTrackDemJob.php:18`, unico chiamante trovato). Chiamarlo in modo
     sincrono dentro la request Nova bloccherebbe la risposta HTTP sulla chiamata di rete al
     servizio DEM esterno (`config('wm-package.dem.host')`); dispatchare il job mantiene la
     coerenza con com'è invocato ovunque nel resto del package.
  6. Restituire il messaggio di risposta:
     - Se `$overriddenFields` è vuoto: `Action::message(__('Track geometry reversed. Recalculation in progress.'))`
     - Se non è vuoto: `Action::message(__('Track geometry reversed. Recalculation in progress. Warning: manual overrides present on: :fields — verify they still match the new direction.', ['fields' => implode(', ', $overriddenFields)]))`
       (Nova non ha un livello "warning" dedicato — solo `message()` e `danger()`, verificato in
       `Action.php`; usare `danger()` qui suggerirebbe un fallimento dell'operazione, che non è
       il caso, quindi si resta su `message()` con testo esplicito)

## Task 2 — Registrare l'azione su `EcTrack`

**File:** `src/Nova/EcTrack.php` (righe 94-110, metodo `actions()`)

Aggiungere alla lista restituita da `actions()`:
```php
(new ReverseEcTrackGeometryAction)->sole(),
```

Verificare l'import della classe in testa al file.

## Task 3 — Traduzioni

**File:** `resources/lang/{de,en,es,fr,it}.json`

Aggiungere le chiavi (inglese come chiave, valore = traduzione per ogni locale, pattern verificato
in `resources/lang/it.json:194,250,323`):
- `"Reverse Track Geometry"`
- `"Track geometry reversed. Recalculation in progress."`
- `"Track geometry reversed. Recalculation in progress. Warning: manual overrides present on: :fields — verify they still match the new direction."`

## Task 4 — Test Pest

**File:** `tests/Feature/Nova/Actions/ReverseEcTrackGeometryActionTest.php` (nuovo)

Pattern di riferimento: `tests/Feature/Nova/Actions/ImportEcPoiFromOsmActionTest.php` (invocazione
diretta di `->handle()` con `ActionFields` e `Collection`) e `tests/Unit/Nova/Actions/ExecuteEcTrackDataChainActionTest.php`
(uso di `Bus::fake()` per verificare i job dispatchati senza eseguirli).

Test richiesti:

1. **"invertisce la geometria e dispatcha il ricalcolo DEM"**
   - `Bus::fake()`
   - crea una `EcTrack` con geometria nota (non simmetrica, per poter verificare l'inversione)
   - esegue l'azione
   - verifica via query SQL diretta (`ST_AsText`) che la geometria in DB sia effettivamente
     invertita
   - `Bus::assertDispatched(UpdateEcTrackDemJob::class)`

2. **"il ricalcolo scatta anche se l'observer non si attiverebbe"** (test dedicato anti-regressione,
   il punto più a rischio secondo l'overview)
   - stessa azione, ma verifica esplicitamente che il job DEM venga dispatchato **anche se**
     `$track->wasChanged('geometry')` sarebbe `false` per una scrittura SQL pura (a differenza di
     `EcTrackService::updateDataChain()`, che dipende da quella condizione — verificato in
     `EcTrackService.php:327-338`). In pratica: verificare che il dispatch avvenga in modo
     esplicito dall'azione e non dipenda dall'observer `EcTrackObserver::updated()` — un modo
     concreto è verificare che il job venga dispatchato anche mockando/spiando che l'observer
     `updated()` non sia stato invocato (la scrittura via `DB::statement` non attraversa Eloquent,
     quindi l'observer non scatta comunque: il test deve fallire se in futuro l'azione venisse
     riscritta assumendo `$ecTrack->save()` al posto della query SQL pura senza il dispatch
     esplicito)

3. **"mostra un avviso se sono presenti override manuali"**
   - crea una `EcTrack` con `properties['manual_data']['ascent']` impostato
   - esegue l'azione
   - verifica che il messaggio di risposta contenga il riferimento al campo overridden

4. **"nessun avviso se non ci sono override manuali"**
   - crea una `EcTrack` senza `manual_data`
   - verifica che il messaggio non contenga il testo di warning

## Task 5 — Verifica manuale coerente con `.claude/rules/test.md`

Prima di considerare il task chiuso, eseguire `composer test` nel container e verificare che tutti
i test esistenti su `EcTrack`/Nova continuino a passare (nessuna regressione sulla registrazione
delle action in `EcTrack::actions()`, testata da altri file esistenti).

---

## Commit

Un solo commit per l'intero lavoro (nessuna migration, nessuna dipendenza tra i task):

```
feat(oc:8543): aggiungi azione Nova per invertire la geometria di una EcTrack
```

---
paths:
  - "src/Nova/**"
  - "src/TrailRegistry/Nova/**"
---

# Trappole: Nova

Si applica quando tocchi Resource, campi, action o card Nova del package.

- Sui campi Media si usa **sempre `->singleMediaRules()`, mai `->rules()`**: quest'ultimo valida
  l'intero array della collection e blocca **ogni** salvataggio del form, anche senza toccare il
  campo (oc:8247).
- `Images::make()` (Ebess) impone `['image']` non sovrascrivibile, e con Laravel 12 quella regola
  esclude gli SVG: per un SVG serve `Files::make()` (oc:8272).
- Nel callback `->store()` di un campo `File`, `return null` **distrugge il valore esistente** a
  ogni update senza file: si torna `true` per non toccarlo. E il callback gira prima del `save()`,
  quindi in creazione `$model->id` è `null` (oc:8175).
- `Multiselect::options()` accetta **un solo argomento**: il secondo è ignorato in silenzio
  (oc:7953).
- Il titolo di una Resource **non può essere una colonna enum** (500 in pagina): serve un metodo
  `title()`. E i modelli devono dichiarare nullable anche le colonne obbligatorie, perché Nova
  costruisce i campi su un'istanza vuota (oc:8489).
- Una classe Tailwind che Nova non usa nella propria UI **viene eliminata dal purge e non ha alcun
  effetto**: si usano solo classi verificate nel CSS di Nova, o CSS scoped (oc:7546).
- `<Teleport to="body">` è obbligatorio per un modale dentro i tab di Nova, altrimenti è
  disallineato (oc:7546).
- Il toast 422 di Nova mostra sempre una stringa fissa: un messaggio di validazione custom non è
  mostrabile senza patchare il vendor (oc:8247).
- I link costruiti a mano in un field usano `Nova::path()`: un path hardcoded perde il prefisso
  `/nova` (oc:8089).
- **Il package registra una sola policy** (`App`). Un consumer che monta la Resource `EcTrack`
  senza registrare `EcTrackPolicy` lascia Nova autorizzare chiunque — verificalo quando aggiungi
  una Resource EC a un consumer (oc:8181).
- Nei campi di un'Action, `$request->resourceId` è vuoto nella richiesta che risolve un
  `dependsOn()`: la tendina dipendente resta vuota — Nova manda `resources`, una lista di id,
  nella `PATCH` gestita da `ActionRequest`, quindi vanno letti entrambi (oc:8569).
- Un'Action istanziata a mano in un test ha `runCallback` nullo e risulta **sempre**
  autorizzata: il `canRun()` va preso dall'istanza che la Resource restituisce, altrimenti il
  test non verifica nulla (oc:8569).
- `->sole()` vincola un'azione a una singola risorsa **solo lato UI Nova**: il server
  (`DispatchAction::forModels()`) non lo applica, e una richiesta con più risorse selezionate
  arriva comunque a `handle()` con più elementi in `$models` — un'azione che assume
  `$models->first()` processa solo il primo mostrando comunque successo per tutti. `->standalone()`
  ha un significato opposto (azione eseguibile senza nessun modello selezionato): non sono
  intercambiabili nonostante il nome suggerisca il contrario (oc:8543).
- In una Nova Action, `Bus::dispatch()`/`Job::dispatch()` "nudo" può far leggere a un worker un
  dato non ancora committato: Nova avvolge `handle()` in una transazione DB, e le connessioni di
  coda hanno `after_commit => false` in `config/queue.php` — un dispatch senza `->afterCommit()`
  pusha il job in coda prima del commit (oc:8543).
- `Bus::chain($jobs)->dispatch()->afterCommit()` fallisce (`Call to a member function afterCommit()
  on null`): a differenza di `Job::dispatch()`, `PendingChain::dispatch()` non è fluente e non ha
  un metodo `afterCommit()` proprio — dispatcha subito e ritorna `null`. Solo il primo job di una
  catena viene effettivamente accodato (gli altri partono in base al suo esito), quindi va marcato
  lui: `$chain[0]->afterCommit(); Bus::chain($chain)->dispatch();` (oc:8543).

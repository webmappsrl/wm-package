> Ticket: oc:8968
> Overview approvata il 2026-11-11 · impronta `2e0969e2c1759a2690ddffb3bd4bfce6cda94dc0`

# Notes — La compilazione di un'istanza: gli endpoint del fascicolo

Le note della parte di forestas stanno nello shard:
`forestas/docs/features/8968-compilazione-istanza-endpoint-sus/notes.md`.

## Modifiche ai requisiti

### 2026-11-11 — La traccia non si carica come allegato
- **Requisito:** [R11](overview.md#r11)
- **Effetto:** modificato
- **Decide:** dev
- **Perché:** la traccia è il campo 68 del format e viaggia già nella creazione, che ne ha bisogno
  per restituire i dati tecnici ([R2](overview.md#r2)). Il campo 68 è obbligatorio nel format, e
  nella call del 22/10 la prima chiamata è «pochissimi dati [...] e il GPX». L'upload accetta quindi
  le cinque collection dei documenti e rifiuta `original_geometry`; la documentazione
  dell'upload lo dice come un fatto.
- **Scartato:** chiedere a chi integra se per lui il campo 68 rientrasse fra «gli allegati» del
  campo 66, come faceva una prima versione della documentazione. Non è una domanda da fare a
  Engineering, che si adegua al documento condiviso.
- **Testo nuovo:**
  > **Upload** — `POST api/v1/sus/trail-applications/{id}/attachments`: un allegato per chiamata,
  > nella collection media corrispondente fra le sei registrate da oc:8960, esclusa
  > `original_geometry`: la traccia viaggia solo nella chiamata di creazione
  > ([R2](overview.md#r2)). Accettato solo mentre l'istanza è in `under_compilation`.

### 2026-11-11 — Il tab di Nova si chiama «Dati API»
- **Requisito:** [R37](overview.md#r37)
- **Effetto:** modificato
- **Decide:** dev — applica [R47](overview.md#r47), approvato con l'overview
- **Perché:** il nome dello sportello non deve comparire nel package ([R47](overview.md#r47)), e
  l'etichetta «Dati SUS» era un residuo di oc:8960. Con lei sono stati rinominati
  `susDataFields()` e `susDataSchema()` in `apiDataFields()` e `apiDataSchema()`. Il cambio è
  visibile agli operatori, che prima leggevano «Dati SUS».
- **Testo nuovo:**
  > Il tab di Nova con i dati arrivati dal canale, che si chiama «Dati API» (era «Dati SUS»),
  > continua a funzionare esattamente come prima: diventa un consumatore dello schema, non più il
  > suo proprietario.

### 2026-11-11 — Le select del tab mostrano le etichette dei valori, `soggetto_custode` è testo
- **Requisito:** [R37](overview.md#r37)
- **Effetto:** modificato
- **Decide:** agente
- **Perché:** lo schema dichiara i valori ammessi di `soggetto_gestore` (47), `soggetto_rilevatore`
  (49) e `soggetto_manutentore` (53), che la validazione usa nelle regole `in:`
  ([R34](overview.md#r34)); la stessa dichiarazione dà a Nova le etichette, che prima per quei tre
  campi mancavano. `soggetto_custode` (58) si valida come stringa libera e si mostra come testo,
  mentre prima era una select senza valori. Le note di lavorazione non registravano questa
  differenza: è ricostruita dal codice (`ApplicationSchema::fields()` contro il vecchio
  `susDataSchema()`).
- **Testo nuovo:**
  > Il tab di Nova con i dati arrivati dal canale, che si chiama «Dati API» (era «Dati SUS»),
  > continua a funzionare come prima e diventa un consumatore dello schema, non più il suo
  > proprietario. Le select mostrano l'etichetta dei valori che lo schema ammette, e
  > `soggetto_custode` si mostra come testo.

### 2026-11-11 — Gli allegati si leggono nel fascicolo in Nova
- **Requisito:** [R57](overview.md#r57)
- **Effetto:** aggiunto
- **Decide:** dev
- **Perché:** i cinque allegati entrano solo dal canale API e non comparivano da nessuna parte:
  l'istruttore leggeva i dati dichiarati ma non i documenti su cui la domanda si fonda. È un
  cambiamento di Nova, che «Cosa cambia» e [R44](overview.md#r44) davano per intatta; la
  creazione d'ufficio invece non cambia.
- **Testo nuovo:**
  > Il fascicolo in Nova ha un tab «Allegati», in sola lettura come il resto di ciò che arriva dal
  > canale, con i documenti di ogni collection — relazione, scheda dati del sentiero,
  > autorizzazioni, piano di manutenzione, documentazione fotografica. Una collection vuota dice
  > «Non trasmesso» invece di sparire.

### 2026-11-11 — Un endpoint per leggere la domanda
- **Requisito:** [R58](overview.md#r58)
- **Effetto:** aggiunto
- **Decide:** dev
- **Perché:** l'endpoint di stato era la macro area 7 del tag 709, fuori dal perimetro
  dell'overview; è stato anticipato qui perché il codice che compone la risposta era già nella
  conferma. Il calcolo dell'esito del DEM (`demOutcome()`) è passato dalla conferma alla lettura.
  Il tag 709, alla macro area 7, diceva che l'endpoint di stato restituisce anche il codice
  riservato con il suo stato; il dev ha deciso di no, in linea con [R42](overview.md#r42).
- **Scartato:** fondere conferma e lettura in una chiamata sola — la prima conferma, le successive
  leggono. La chiamata con cui si legge una domanda la trasmetterebbe, se completa.
- **Testo nuovo:**
  > **Lettura** — `GET api/v1/sus/trail-applications/{id}`: restituisce `id`, `status`, i dati
  > tecnici (`dem_data`) e `dem_status` (`computed` o `processing`, con la regola di
  > [R24](overview.md#r24)), il codice del sentiero solo quando la domanda è approvata e il motivo
  > del rifiuto solo quando è respinta. Vale solo per le domande del canale API, come gli altri
  > endpoint con `{id}` ([R18](overview.md#r18)).

### 2026-11-11 — La conferma risponde solo l'esito
- **Requisito:** [R15](overview.md#r15)
- **Effetto:** modificato
- **Decide:** dev
- **Perché:** è la conseguenza dell'endpoint di lettura ([R58](overview.md#r58)): i dati tecnici
  si leggono da lì. `already_confirmed` dice se la transizione è avvenuta in quella chiamata,
  deciso sulla riga letta sotto lock.
- **Testo nuovo:**
  > Se la completezza è verificata, la conferma porta l'istanza in `under_review` e risponde con
  > l'esito: `id`, `status` e `already_confirmed`, che dice se la transizione è avvenuta in quella
  > chiamata. I dati tecnici si leggono con la lettura ([R58](overview.md#r58)).

### 2026-11-11 — La conferma ripetuta risponde con l'esito
- **Requisito:** [R16](overview.md#r16)
- **Effetto:** modificato
- **Decide:** dev
- **Perché:** la conferma non restituisce più il risultato finale completo
  ([R15](overview.md#r15)), quindi una conferma ripetuta restituisce lo stesso esito, con
  `already_confirmed` vero. Vale anche per due conferme contemporanee, perché la transizione si
  decide sulla riga letta sotto lock.
- **Testo nuovo:**
  > **La conferma è idempotente**: una seconda chiamata su un'istanza già `under_review` risponde
  > con l'esito, con `already_confirmed` vero, invece che con un errore. Senza, un timeout di rete
  > dopo la scrittura lascia il cittadino convinto di non aver trasmesso mentre l'operatore vede il
  > fascicolo in istruttoria.

### 2026-11-11 — «In elaborazione» si legge alla lettura, non alla conferma
- **Requisito:** [R23](overview.md#r23)
- **Effetto:** modificato
- **Decide:** dev
- **Perché:** la conferma non porta più i dati tecnici ([R15](overview.md#r15)); lo stato del
  calcolo lo dichiara la lettura ([R58](overview.md#r58)).
- **Testo nuovo:**
  > Lo stesso vale alla lettura della domanda: se il dato non c'è, la risposta lo dichiara in
  > elaborazione invece di tacere.

### 2026-11-12 — La traccia ha un limite di 5 MB
- **Requisito:** [R59](overview.md#r59)
- **Effetto:** aggiunto
- **Decide:** Forestas — il valore è quello del format (tag 709, macro area 6)
- **Perché:** 5 MB è il limite che il format fissa per ogni file del fascicolo, campo 68 compreso.
  La review temeva una traccia da 80 MB letta tutta in memoria; misurato sui 762 sentieri del
  Catasto, il più denso ha 8.585 punti, meno di 1 MB in GPX, e il 99% sta sotto i 4.650 punti. Il
  limite non serve alla memoria ma al contratto: prima esisteva solo nella configurazione del
  server (32 MB nel pool FPM), e chi integra non lo vedeva. «Out of scope» lasciava alla macro
  area 6 i vincoli degli allegati; questo riguarda la sola traccia della creazione.
- **Testo nuovo:**
  > Il file della traccia, nella chiamata di creazione, non supera i 5 MB, il limite che il
  > format fissa per ogni file del fascicolo: oltre, la risposta è un `422` sul campo `geometry`, e
  > la documentazione del parametro lo dichiara.

### 2026-11-12 — Il throttle copre quattro rotte
- **Requisito:** [R19](overview.md#r19)
- **Effetto:** modificato
- **Decide:** dev
- **Perché:** la lettura della domanda ([R58](overview.md#r58)) è la quarta rotta del canale e sta sotto lo stesso throttle.
- **Testo nuovo:**
  > Le quattro rotte portano un **throttle**: senza, gli endpoint più cari che avremmo — upload su
  > S3 e chiamata sincrona al servizio DEM — nascerebbero senza limite di frequenza.

### 2026-11-12 — Nova mostra i dati API e gli allegati
- **Requisito:** [R44](overview.md#r44)
- **Effetto:** modificato
- **Decide:** dev
- **Perché:** i tab «Dati API» e «Allegati» ([R37](overview.md#r37), [R57](overview.md#r57)) cambiano il codice della Resource Nova; il comportamento di Nova resta quello di prima.
- **Testo nuovo:**
  > **Il comportamento di Nova non cambia.** L'istanza creata d'ufficio continua a nascere in
  > `under_review` con il numero riservato, in un gesto solo. I dati del form del SUS esistono solo
  > se l'istanza arriva da lì, quindi un'istanza d'ufficio non ne ha e la regola di completezza non
  > la riguarda. Il codice di Nova cambia solo nella detail view, che mostra in sola lettura i tab
  > «Dati API» e «Allegati» ([R37](overview.md#r37), [R57](overview.md#r57)).

## Divergenze dal piano, task per task

### Task 1: Lo schema dei campi come fonte unica
`susDataFields()` e `susDataSchema()` sono diventati `apiDataFields()` e `apiDataSchema()`, e
l'etichetta del tab da «Dati SUS» a «Dati API»: vedi la voce del 2026-11-11 su
[R37](overview.md#r37). I campi non sono stati presi verbatim dal vecchio `susDataSchema()`, come
chiedeva il piano: le select di 47, 49 e 53 hanno ora i valori con le etichette, e
`soggetto_custode` è passato da select a testo (seconda voce su [R37](overview.md#r37)).

### Task 3: Le Form Request derivate dallo schema
- **`attributes()` nelle due Form Request**: senza, Laravel scriveva «Il campo duration forward è
  obbligatorio», con un nome che nel payload non esiste e che chi integra non può cercare nella
  documentazione.
- **Il file della traccia ha la regola `max` a 5 MB** (2026-11-12): vedi
  [R59](overview.md#r59).

### Task 6: La transizione dalla compilazione all'istruttoria
**`confirmCompilation()` restituisce un `bool`** invece di `void`: dice se la transizione è
avvenuta in quella chiamata, deciso sulla riga letta sotto lock. È ciò che permette
`already_confirmed` anche con due conferme contemporanee.

### Task 7: L'endpoint di creazione
- **Firma di `InvalidTrailGeometryException` cambiata** rispetto al piano, per portare il motivo
  del rifiuto fino alla risposta.
- **`tech_data` è diventato `dem_data` nella risposta** (lo stesso vale per la lettura, Task 9).
  `tech_data` è il nome del contratto verso il *fornitore* DEM (`DemClient::getTechData`,
  `clients.dem.tech_data_api`); nel nostro dominio quei valori si chiamano `dem_data` ovunque —
  colonna `properties.dem_data`, Field di Nova `properties->dem_data->…`, la pagina
  [dati-dem-e-valori-manuali.md](../../knowledge/dati-dem-e-valori-manuali.md). Nella risposta
  stava inoltre accanto a `dem_status`, che lo contraddiceva.

### Task 8: L'endpoint di upload di un allegato
Il piano prevedeva le fotografie più «le altre cinque collection», traccia compresa; l'upload ne
accetta quattro più le fotografie e rifiuta `original_geometry`. Vedi la voce del 2026-11-11 su
[R11](overview.md#r11).

### Task 9: L'endpoint di conferma
- **`ConfirmApplicationRequest` è stata aggiunta, e il piano non la prevedeva.** La conferma non ha
  corpo da validare, ma la Form Request porta il controllo di appartenenza al canale: una domanda
  nata d'ufficio risponde `404` come un identificatore sconosciuto, così non si possono
  distinguere le due cose scorrendo gli id.
- **`ApiChannelApplicationRequest`** è la base comune di conferma e lettura, con il solo controllo
  di appartenenza al canale. Non definisce `rules()`: Scramble, trovandolo anche vuoto, dichiara
  una risposta `422` che la lettura non può dare.
- **La conferma risponde solo l'esito** (`id`, `status`, `already_confirmed`) invece di
  `id`, `status`, `tech_data`, `dem_status`, e c'è un endpoint di lettura in più
  (`ShowApplicationController`, `ShowApplicationRequest`), con `demOutcome()` spostato lì: vedi le
  voci su [R58](overview.md#r58), [R15](overview.md#r15), [R16](overview.md#r16) e
  [R23](overview.md#r23).

### Task 11: Le rotte del canale
- **`role:Sus` aggiunto alle rotte dello shard**, fuori dai requisiti. `RestrictSusClient` impedisce
  al client dello sportello di uscire dal proprio prefisso, ma non impedisce a nessun altro di
  entrarci: con il solo `auth:api` un utente qualunque delle app mobile avrebbe potuto presentare
  domande.
- Le rotte sono quattro, non tre: c'è anche la lettura ([R58](overview.md#r58)). Il resto della
  parte dello shard è nelle note di forestas.

### Task 12: La documentazione
- **Test `ApplicationEndpointTranslationsTest` aggiunto**: il piano lo dava per esistente, ma i due
  test presenti controllavano solo `en` e `it`.
- **Chiave di traduzione `Application not found` rimossa**: era stata aggiunta e non la usa nessuno,
  perché il 404 nasce da `ModelNotFoundException`.

## Bug trovati

- **Difetto di autorizzazione su `validateUser()`** e i suoi quattro chiamanti: preesistente,
  escluso da questo branch e aperto come **oc:8970**.

## Decisioni

- **2026-11-11 — Il job usa `ApplicationDemService`.** `UpdateTrailApplicationDemJob::handle()`
  dipende ora da `ApplicationDemService` e non più dalla query SQL che aveva dentro: la scrittura è
  una sola, usata dal calcolo sincrono e dal job. Le note di lavorazione lo registravano fra le
  divergenze, ma il Task 4 del piano lo prevedeva già («Il job diventa un chiamante del service»).
- **2026-11-11 — Il numero esce solo a domanda approvata.** Il tag 709, alla macro area 7, diceva
  che l'endpoint di stato restituisce anche il codice riservato con il suo stato; il dev ha deciso
  di no, in linea con [R42](overview.md#r42) («il numero del sentiero non compare in nessuna
  risposta finché l'istanza non è accettata»).
- **2026-11-11 — Il `422` e il `409` della conferma non cambiano requisiti.** Il `422` è
  `{error, missing: {fields, attachments}}`: la forma strutturata di [R14](overview.md#r14) sta
  sotto `missing`, accanto all'`{error}` di [R20](overview.md#r20), come il Task 9 del piano già
  prevedeva. Il `409` arriva quando la domanda è già stata decisa (respinta o approvata) e non si
  può ritrasmettere: è lo «stato giusto» di [R18](overview.md#r18).

## Follow-up

- **I vincoli di formato e dimensione degli allegati** (5 MB per file) restano la macro area 6 del
  tag 709, come dichiara l'overview. Il limite di upload di PHP non è un ostacolo: il pool FPM di
  forestas (UAT e dev) impone `upload_max_filesize = 32M`. Il 2M visto in locale è il predefinito
  di PHP, perché il `php.ini` dello shard, letto da `php artisan serve`, non lo imposta: riguarda
  solo il locale, e si è scelto di lasciarlo così.

I rischi dell'overview rimasti aperti alla chiusura (2026-11-12). La destinazione è una proposta e
la decide il dev.

- **Il reset notturno rende il canale non collaudabile su UAT**: un'istanza creata il pomeriggio
  non esiste più la mattina dopo, e Engineering riceve `404` su un id che gli abbiamo dato noi.
  *Destinazione proposta:* un ticket per chi rilascia, da chiudere prima di consegnare l'ambiente a
  Engineering, collegato al tag 709.
  *Deciso dal dev, 2026-11-12:* non è un rischio. È il comportamento del collaudo, documentato
  nella pagina delle API; nessuna destinazione.
- **Del payload rifiutato restano gli esiti, non i valori**: una discussione con Engineering su
  *quale valore* è stato mandato non si chiude con i nostri log. *Destinazione proposta:* la
  pagina [operazioni-del-fascicolo-e-canale-dell-ente.md](../../knowledge/operazioni-del-fascicolo-e-canale-dell-ente.md),
  come limite dichiarato del canale.
  *Deciso dal dev, 2026-11-12:* non è un rischio, è la scelta del requisito sul log
  ([R6](overview.md#r6)); nessuna destinazione.
- **Il contratto si congela al primo collaudo con Engineering**: la divergenza sulle unità —
  chilometri e minuti interi contro i metri e gli `ORE:MINUTI` del format — va segnalata prima di
  quel collaudo. *Destinazione proposta:* il tag 709, come punto da portare a Engineering.
  *Deciso dal dev, 2026-11-12:* annotato nel tag 709, nella sezione «Divergenze da comunicare al
  SUS», che già elencava la divergenza sulle unità.
- **Quanto il calcolo sincrono del DEM allunghi la creazione non è misurato.** *Destinazione
  proposta:* un ticket di misura sul primo ambiente con il servizio DEM vero.
  *Deciso dal dev, 2026-11-12:* resta qui, fra i follow-up.
- **Gli allegati di un'istanza mai trasmessa restano su S3**, e su UAT il reset notturno lascia i
  file nel bucket senza record. *Destinazione proposta:* il tag 709, accanto alla domanda già
  aperta su come si chiude un'istanza rimasta in compilazione, perché la cancellazione che la
  risolverà dovrà togliere anche i file.
  *Deciso dal dev, 2026-11-12:* resta qui, fra i follow-up: la cancellazione delle istanze non
  esiste ancora.

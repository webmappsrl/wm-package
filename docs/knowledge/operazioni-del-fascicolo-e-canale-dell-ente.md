# Operazioni del fascicolo e canale dell'ente

Come un portale esterno — lo sportello unico di un ente — porta nel Catasto Sentieri una domanda di
accatastamento, e cosa di questo sta nel package e cosa nello shard. Il modello e il dominio sono in
[docs/resources/TrailRegistry.md](../resources/TrailRegistry.md); qui c'è il confine fra le due
parti e il perché, che vale per chiunque monti il package.

## Come funziona oggi

### Il confine

**Il package offre le operazioni del fascicolo. Lo shard ci monta sopra il canale del proprio ente.**

Le operazioni sono quattro, e non hanno nulla di specifico di un ente: aprire una domanda
(`StoreApplicationController`), aggiungerle un allegato (`StoreAttachmentController`), chiuderla
quando è completa (`ConfirmApplicationController`) e leggerne lo stato (`ShowApplicationController`).
Insieme a loro il package tiene lo schema dei campi, le Form Request che ne derivano, il calcolo dei
dati tecnici alla creazione e le annotazioni Scramble dei quattro endpoint
(`src/Http/Controllers/Api/TrailRegistry/`).

Il package **non registra nessuna rotta**. Lo shard decide, nelle proprie:

- il **prefisso** e i **nomi** delle rotte;
- il **client** che le chiama (l'utente, il suo ruolo, come si ottiene il token);
- i **middleware**: autenticazione, controllo del ruolo, limite di richieste, registro delle
  chiamate;
- la **pagina di documentazione** del canale, dove finiscono le annotazioni dei controller.

Una rotta punta direttamente al controller del package. Finché non serve cambiare nulla non si
scrive una sottoclasse; quando serve, si estende il controller come si fa con le Resource Nova.

```php
Route::post('/trail-applications', StoreApplicationController::class);
Route::get('/trail-applications/{application}', ShowApplicationController::class);
Route::post('/trail-applications/{application}/attachments', StoreAttachmentController::class);
Route::post('/trail-applications/{application}/confirm', ConfirmApplicationController::class);
```

### Lo schema dei campi si può ridefinire

I campi del modulo di domanda stanno in `ApplicationSchema`, e da lì derivano:

- la **validazione** della creazione (`StoreApplicationRequest`), compresa la decodifica dei campi
  strutturati e dei booleani, che segue il tipo dichiarato nello schema;
- l'elenco dei campi **obbligatori** che la conferma pretende (`TrailApplication::requiredApiFields()`);
- i campi mostrati nel pannello dei dati dell'istanza in Nova (`toNovaSchema()`).

I metodi non sono statici proprio perché lo shard possa sostituirlo. Registra la propria sottoclasse
nel container e chi consuma lo schema lo risolve con `app(ApplicationSchema::class)`, mai con `new`:

```php
$this->app->bind(ApplicationSchema::class, MioSchema::class);
```

La sottoclasse ridefinisce `fields()`, `itemRules()` o `version()`; il resto deriva da lì.
`ACTIVITIES` è una costante letta con `static::`, quindi una sottoclasse può cambiarla per
restringere o allargare le attività ammesse.

Tre cose da sapere se si ridefinisce:

- **`version()` si alza a mano.** Restituisce una stringa fissa (`'1'`) e la creazione la scrive in
  `api_data` accanto al payload (`ApplicationSchema::VERSION_KEY`): è l'unico modo per sapere, mesi
  dopo, con quali regole una riga è stata accettata. Se cambia ciò che la consegna deve contenere o
  come si valida e `version()` resta uguale, il dato perde il suo significato.
- **Le descrizioni degli endpoint non seguono lo schema.** I `#[BodyParameter]` dei controller
  descrivono i campi dello schema di default, con il numero di campo del format a cui rinviano. Uno
  schema ridefinito cambia la validazione ma non quelle descrizioni: chi lo ridefinisce deve
  estendere il controller e riscrivere le annotazioni, o la documentazione dirà il falso.
- **Una chiave che lo schema non dichiara non viene rifiutata.** Si conserva com'è in `api_data`,
  senza validarla: se l'ente aggiunge un campo al proprio modulo prima di noi, il dato arriva comunque
  e non si perde in silenzio.

### Cosa fa il package, e va tenuto presente dallo shard

- **Chi modifica risponde l'esito, chi legge legge.** La conferma restituisce solo com'è andata —
  `already_confirmed` dice se la domanda era già in istruttoria — e lo stato, i dati tecnici e il
  numero si leggono da `ShowApplicationController`. Una lettura non cambia nulla, e lo sportello la
  può ripetere in polling: il Catasto non ha modo di chiamare lui.
- **Il numero del sentiero esce solo a domanda approvata.** Prima la domanda ha già un codice
  riservato, ma l'istruttoria può cambiarlo: mostrato presto, lo sportello lo tratterebbe come
  definitivo.
- **La domanda del canale si riconosce dalla provenienza.** La creazione scrive `source = 'api'`, e
  l'upload, la conferma e la lettura rispondono 404 — come per un identificatore sconosciuto, per non lasciar
  scorrere gli identificatori — a una domanda con altra provenienza. Il controllo non guarda
  l'utente: due client diversi dello stesso shard vedrebbero le domande l'uno dell'altro.
- **I dati si scrivono una volta sola, alla creazione.** Non c'è un endpoint che li corregga, ed è
  una scelta: chi integra rifà la domanda. Vale per i dati e per la traccia, non per gli allegati, che
  si possono sostituire finché la domanda è in compilazione. Un endpoint di modifica dovrebbe
  rifare anche il calcolo dei dati tecnici e il numero riservato.
- **La traccia viaggia nella creazione**, non nell'upload: la collection `original_geometry` è
  esclusa dalle collection che `StoreAttachmentRequest` accetta. Da lì si ricava anche il profilo
  altimetrico, e ricaricarla a parte lascerebbe i dati tecnici della vecchia.
- **Le unità del payload** sono quelle del dominio, non quelle di un modulo: lunghezza in chilometri,
  tempi in minuti interi (vedi [dati-dem-e-valori-manuali.md](dati-dem-e-valori-manuali.md)). Se il
  modulo dell'ente chiede altro (metri, `ORE:MINUTI`) la divergenza si dichiara nella documentazione
  dell'endpoint, perché chi integra la scopre tardi.
- **Il calcolo dei dati tecnici è sincrono, con un timeout basso e nessun retry.** Se il servizio non
  risponde la domanda nasce lo stesso, con `dem_status` a `processing`, e i dati arrivano dal job.

## Perché così

- **Il package offre le operazioni, lo shard il canale** (oc:8968): le operazioni sul fascicolo non
  hanno niente di specifico di un ente, mentre prefisso, client, middleware e pagina di
  documentazione sì. Messe nello shard, le operazioni andrebbero riscritte per il secondo ente;
  messe nel package con le rotte, ogni shard si troverebbe un canale che non ha chiesto.
- **Lo schema dei campi in un posto solo** (oc:8968): i campi del modulo sono quelli che un ente
  ha concordato per il proprio portale, e un altro ente ne avrà altri. Se stessero scritti nella
  validazione, nella regola del fascicolo completo e nei Field di Nova, ognuno per conto proprio,
  cambiarli vorrebbe dire cambiare il package.
- **Il nome dello sportello non compare mai nel package** (oc:8968): né in classi, né in metodi,
  né in commenti, né nelle pagine come questa. La ragione, detta quando la regola è nata: *«mai il
  nome dello sportello: questo codice servirà anche Lombardia e Toscana»*. Un nome di ente dentro
  codice condiviso è un debito che pagherà il primo shard che monta il package per un altro ente. I
  controller si chiamano per quello che fanno al fascicolo; il canale prende il nome del cliente
  nello shard — rotte, client, pagine di documentazione — e solo lì.
- **La lettura è separata dalla conferma** (oc:8968): chi modifica risponde l'esito, chi legge
  legge. La conferma trasmette la domanda, e la transizione non si annulla: una chiamata che serve
  solo a sapere a che punto è una domanda non deve poterla trasmettere.

## Come ci siamo arrivati

- **Lettura e conferma in una sola chiamata** (oc:8968, scartata): la conferma avrebbe
  restituito anche stato e dati tecnici, e lo sportello l'avrebbe richiamata per sapere a che punto
  era la domanda. Scartata perché la stessa chiamata trasmette la domanda, se completa, e la
  transizione non si annulla.
- **Lo schema dei campi dentro la Resource Nova** (oc:8960, superata da oc:8968): i 27 campi del
  format erano scritti nella Resource, che li usava solo per mostrarli nella detail view. Con il
  canale servivano anche alla validazione e alla regola del fascicolo completo: sono usciti in
  `ApplicationSchema`, e la Resource ora li legge da lì.
- **La traccia caricabile anche come allegato** (oc:8968, scartata): `original_geometry` era fra le
  collection dell'upload. Scartata perché dalla traccia si ricavano i dati tecnici, e ricaricarla a
  parte avrebbe lasciato quelli della vecchia.

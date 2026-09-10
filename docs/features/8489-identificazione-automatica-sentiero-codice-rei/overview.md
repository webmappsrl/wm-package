> Ticket: oc:8489

# Identificazione automatica del sentiero — codice REI

## Legenda

| Termine | Cosa significa |
|---|---|
| **REI** | lo standard di numerazione dei sentieri del CAI, recepito dalla legge regionale sarda. È il formato del numero da proporre |
| **SUS** | Sportello Unico Sentieri — sistema esterno di Engineering per la Regione. Trasmette le istanze e **non conserva i dati** |
| **Catasto Sentieri** | questo sistema (forestas + `wm-package`). È il proprietario del dato |
| **accatastamento** | iscrizione ufficiale di un sentiero nel Catasto. **Deaccatastamento** = cancellazione, che libera il numero |
| **istanza** | la domanda di accatastamento di un nuovo sentiero, con la sua traccia GPX. Vive nel Catasto come modello separato dai sentieri; diventa `EcTrack` solo alla convalida |
| **`EcTrack`** | il modello del sentiero pubblicato, già nel package |
| **`stato_validazione`** | colonna di `ec_tracks`, solo in forestas: stato di validazione del sentiero (sette valori importati da Sardegna Sentieri) |
| **`ref`** | il numero del sentiero, oggi in `ec_tracks.properties`, in forma testuale (`Z-NU-B-535A`) |
| **`full_code`** | codice del settore territoriale, in `taxonomy_wheres.properties`. `ZNUB4` = `Z` Sardegna, `NU` Nuoro, `B` area, `4` settore |
| **area** | il raggruppamento indicato dalla quarta lettera (`B`) |
| **codice del sentiero** | `ZNUB535A`: il `full_code` del settore (5 caratteri) + numero (2 cifre) + variante se presente. Senza trattini |
| **variante** | l'ultima posizione del codice. **`0` significa «senza variante»** e in uscita si omette; le varianti vere sono `A`, `B`, `C`… `ZNUB535` e `ZNUB535A` sono due sentieri **indipendenti**, non uno la diramazione dell'altro |
| **dominio opzionale** | insieme di funzionalità del package che un progetto attiva o no con un interruttore. Qui: `trail_registry`, spento di default (oc:8492) |
| **prova a vuoto** (*dry-run*) | modalità in cui un comando mostra cosa farebbe senza scrivere niente |

## Cosa cambia

Il Catasto sa **proporre, riservare, confermare e liberare** il numero identificativo di un sentiero, invece di lasciarlo assegnare a mano.

Nasce inoltre il modello dell'**istanza** di accatastamento: la domanda con la sua traccia, che vive nel Catasto separata dai sentieri e diventa `EcTrack` solo quando l'istruttoria si chiude bene.

Il tutto dentro il dominio opzionale `trail_registry` del package: chi non lo attiva trova il package esattamente com'è oggi.

Il ciclo che si costruisce:

```
istanza + traccia
   → prevalidazione superata   → il numero viene RISERVATO
   → istruttoria Forestas
        respinta               → il numero viene LIBERATO
        approvata              → l'istanza diventa EcTrack, il numero è ASSEGNATO
   → deaccatastamento          → il numero viene LIBERATO
```

### Forma del codice

`ZNUB535A` — senza trattini, come i `full_code` in tassonomia.

| Parte | Esempio | Da dove viene |
|---|---|---|
| regione | `Z` | dal `full_code` del settore |
| provincia | `NU` | dal `full_code` del settore |
| area | `B` | dal `full_code` del settore |
| settore | `5` | dal `full_code` del settore |
| numero | `35` | **due cifre** |
| variante | `A` | `0` significa senza variante; altrimenti lettera maiuscola |

**I primi cinque caratteri sono il `full_code` del settore**, e si ricavano interamente dalla geometria: `ZNUB5` è già `ZNUB5`. Da assegnare resta soltanto la coda: due cifre più l'eventuale variante.

**`0` significa «senza variante», e in uscita si omette.** «*È implicito che l'ottava è zero e quando l'ottava è zero sul campo non si mette. Quindi in realtà tu puoi fare che se non è una variante metti sempre zero*» (Piccioli). Nei 576 codici reali quello zero infatti non compare mai — i 39 che terminano con `0` sono numeri tondi (`Z-NU-B-440` è settore `4`, numero `40`), non varianti.

| | `variant` | codice in uscita |
|---|---|---|
| senza variante | `0` | `ZNUB535` |
| prima variante | `A` | `ZNUB535A` |
| seconda variante | `B` | `ZNUB535B` |

Nell'assegnazione si prova per primo il codice senza variante e si passa alle lettere solo quando quello è occupato — ma è l'ordine di ricerca, non il significato di `0`.

Quando invece la variante c'è, è **sempre una lettera maiuscola**: «*è sempre una lettera, è sempre maiuscola*». Verificato sui dati: **nessuno** dei 576 codici ha una coda di quattro cifre, che è la forma che avrebbe una variante numerica. Su 576, 245 hanno la lettera e 331 no; 573 hanno la coda regolare a tre cifre, i fuori forma sono tre.

**La colonna non è mai `NULL`.** In PostgreSQL due `NULL` non si considerano uguali, e in un vincolo composito basta una colonna nulla perché il confronto dell'intera riga resti indeterminato: il database accetterebbe due volte lo stesso `ZNUB535`. Verificato dal vivo su una tabella con `unique (a, b)`, che ha inserito due righe identiche con la seconda colonna nulla. Il controllo applicativo — «se il nudo è preso, passa alla lettera» — previene l'errore quando le richieste arrivano una per volta, ma non quando ne arrivano due nello stesso istante: entrambe leggono «libero» prima che l'altra abbia scritto. È il vincolo del database a chiudere quella finestra, e con `NULL` non la chiuderebbe proprio nel caso più frequente, i 331 codici senza variante.

**Un sentiero con la lettera è indipendente da quello senza.** `ZNUB535A` non è una diramazione di `ZNUB535`: «*il 311A potrebbe esistere, ma […] non c'entra una mazza […] è indipendente, è proprio un'altra cosa, è un percorso indipendente*». Proporre un codice è quindi sempre la stessa operazione — cercare la prima posizione libera nello spazio `numero × variante` — e non due operazioni distinte a seconda che ci sia o no la lettera.

Lo spazio disponibile per settore è perciò di cento numeri per ventisette valori della variante (`0` più `A`–`Z`).

Sette caratteri senza variante, otto con. Questo **chiude il primo dei punti aperti** con Forestas: il ticket segnalava che «*la descrizione del tag parla di 8 caratteri con esempio `ZNUB200`, che però ne ha 7*». Non è una contraddizione — `ZNUB200` è `ZNUB2` più il numero `00`, senza variante.

Fonte: scrum del 08/09/2026 con Alessio Piccioli. Sul codice completo: «*Z regione, NU provincia, B area, 5 settore […] il numero alla fine è 535a […] il numero del sentiero dove il primo numero indica il settore che è 5*». Sulla forma breve: «*quella lì C402 […] perché C è l'area, 4 è il settore, 02 è il numero all'interno del settore*». Sui trattini: «*il codice REI standard è senza trattini*».

**Lo spazio dei codici appartiene al singolo settore**: cento numeri (`00`–`99`) per ventisette valori della variante (`0` più `A`–`Z`).

Effetto collaterale utile per il caricamento iniziale: anche i `ref` in forma breve portano il settore nella prima cifra — `206` è settore `2`, numero `06`. La prova a vuoto può quindi **confrontare** il settore letto dal codice con quello ricavato dalla geometria, e misurare così la qualità del dato senza lavoro aggiuntivo.

## Perché

Emerso come punto centrale nella call Forestas RES/SUS del 30/07/2026.

Alessio Piccioli: «*c'è un sistema di numerazione della sentieristica che è stabilito dalla legge regionale e naturalmente il catasto ce l'ha al suo interno per verificare che i numeri utilizzati sono numeri corretti. Addirittura potrebbe arrivare a proporre un numero […] chi è che dà i numeri dei sentieri? Tutti, se vai da qualsiasi parte, questo sembra che sia il problema più grosso*».

Alessio Saba descrive il ciclo di vita: «*si procede con l'assegnazione di un numero provvisorio […] per bloccare quella numerazione, evitare che magari da un'altra parte arrivi un'altra istanza a cui venga assegnato lo stesso numero. Quindi è il catasto che eroga e blocca dei numeri provvisori che poi, se l'accatastamento prosegue, diventano assegnati e definitivi. Avevamo anche previsto il caso in cui un numero precedentemente occupato […] venga liberato perché il sentiero […] non rispetta le manutenzioni e quindi viene deaccatastato, liberando il numero*».

È il servizio su cui poggiano oc:8490 (preistruttoria) e oc:8491 (validazione istanze): senza, quelle API non hanno alcun numero da proporre.

## Da cosa si parte — stato reale dei dati

Rilevato sul database di sviluppo, non ipotizzato.

**I settori esistono già.** 63 `taxonomy_wheres` con `full_code` valorizzato (`ZNUB4`, `ZCAC4`, …), 63 su 63. Importati da OSM2CAI con oc:8334. Il prefisso del codice non va ricostruito.

**I sentieri numerati esistono già**, e sono l'eredità della migrazione da Sardegna Sentieri: **576 `ec_tracks` su 791 hanno un `ref`**. Questi numeri sono occupati e vanno rispettati da subito.

**`ref` viene deprecato.** Serve una volta sola, per il caricamento iniziale del registro, e da lì si estrae **soltanto il numero** (e l'eventuale variante): il prefisso si ricalcola sempre dalla geometria. Dopo il caricamento il campo resta come dato storico e smette di essere mostrato, sostituito dal registro. Non c'è quindi alcuna doppia fonte di verità da tenere allineata.

Questo riduce di molto il problema delle cinque forme: al parser non si chiede di capire il prefisso da come il codice è scritto, ma solo di estrarre le ultime due cifre e l'eventuale variante — parte che è la stessa in tutte le forme (`Z-NU-B-535A`, `Z-NU-G-310-A`, `C-402`, `206`). E la falsa collisione `700` sparisce da sé: `D 700` e `T-700` diventano il numero `00` nei settori `7` di due aree diverse, ricavate dalle rispettive geometrie. Restano da misurare i 22 codici irregolari e i sentieri la cui geometria non ricade in alcun settore.

**I numeri esistenti hanno cinque forme diverse:**

| forma | esempio | quanti |
|---|---|---:|
| completa | `Z-NU-B-535A` | 361 |
| solo numero | `206`, `328A` | 90 |
| area e numero | `C-402`, `T-513A` | 66 |
| variante staccata | `Z-NU-G-310-A` | 37 |
| irregolare | | 22 |

Per 90 numeri il settore **non si legge dal codice**: va ricavato dalla geometria, incrociandola con i settori.

**Ci sono già 7 numeri duplicati** — `327`, `510`, `511`, `611`, `700`, `C-505A`, `Z-NU-G-643-A` — su 14 sentieri. Tre situazioni diverse: sentieri duplicati (`327`, `Z-NU-G-643-A`), collisioni reali (`510`, `511`, `611`), e una falsa collisione (`700`: `D 700` e `T-700` sono numeri diversi in settori diversi, scritti in forma breve). L'unicità che il ticket chiede **non esiste nei dati di partenza**.

**Lo stato di validazione non predice il possesso del numero:** `in_pre_accatastamento` ne ha 277 su 279 (99%), `percorribile` 143 su 223 (64%). Un numero è occupato se esiste, in qualunque stato sia il sentiero.

## Requisiti

- [ ] Dato un sentiero o la sua geometria, determinare il settore e usarne il `full_code` come prefisso del codice
- [ ] Proporre un numero libero **nel settore**, preferendo la contiguità numerica
- [ ] Comporre il codice in uscita nella forma `ZNUB535A`: senza trattini, `full_code` del settore più numero a due cifre più la variante, omessa quando vale `0`
- [ ] Usare `0` per indicare l'assenza di variante e `A`–`Z` per le varianti vere; rifiutare le cifre da `1` a `9`, che appartengono al modello dei sottosentieri
- [ ] Considerare occupati **sia i numeri assegnati sia quelli riservati**, in un unico registro
- [ ] Estrarre numero e variante dai `ref` già presenti, in tutte e cinque le forme; l'area si ricava sempre dalla geometria, mai dal testo del codice
- [ ] Riservare un numero, liberarlo quando l'istanza viene respinta o il sentiero deaccatastato, renderlo definitivo all'approvazione
- [ ] Due richieste concorrenti non ottengono mai lo stesso numero
- [ ] Forestas — non il richiedente — può sostituire a mano il numero proposto, scegliendo fra quelli liberi
- [ ] Verificare la correttezza formale di un codice esistente
- [ ] Il modello dell'istanza porta la traccia GPX, senza comparire in ricerca né in cartografia finché non è approvata
- [ ] Comando di normalizzazione dei numeri esistenti, **con scrittura** oltre alla prova a vuoto: carica nel registro i codici leggibili e produce l'elenco dei casi anomali da portare a Forestas
- [ ] Il formato del codice è impostabile da configurazione, non scritto nel codice
- [ ] A dominio `trail_registry` spento, il package si comporta esattamente come oggi

## Come si realizza

### Un solo service di dominio

Tutta la logica del catasto sta in **`TrailRegistryService`** (`src/Services/TrailRegistry/`). Non due service che si somigliano — uno "della numerazione" e uno "del registro" — ma un unico service di dominio:

| Funzione | Cosa fa |
|---|---|
| `resolveSector(geometria)` | trova il settore CAI in cui ricade la traccia e ne legge il `full_code` |
| `propose(geometria)` | restituisce il primo numero libero del settore. **Non scrive**. Se il settore è esaurito solleva un'eccezione di dominio esplicita, non restituisce vuoto: un `null` senza spiegazione si propaga fino al SUS come risposta non gestita, mentre «settore ZNUB5 esaurito» è un'informazione che Forestas deve avere comunque |
| `reserve(numero, istanza)` | blocca il numero per un'istanza. È l'unico punto in cui nasce una riga nel registro |
| `confirm(numero, sentiero)` | il numero riservato diventa definitivo e si lega al sentiero appena creato |
| `release(numero)` | il numero torna disponibile: istanza respinta, o sentiero deaccatastato |
| `availableNumbers(settore)` | l'elenco dei numeri liberi, che serve a Forestas per sostituire a mano il numero proposto |
| `validate(codice)` | dice se un codice scritto è formalmente corretto |

`propose()` e `reserve()` restano distinti: la preistruttoria (oc:8490) deve poter mostrare un numero **prima** di scriverlo — «*il numero si riserva solo se il controllo passa*». Se proporre scrivesse, ogni traccia scartata lascerebbe dietro un numero bloccato per sempre.

Fuori dal service resta solo la lettura di un codice già scritto (da `Z-NU-B-535A` alle sue tre parti): è una funzione pura, senza database né stato, e va provata da sola sui 576 numeri reali.

### Come si trova il settore

`resolveSector()` esegue una query PostGIS `ST_Intersects` fra la geometria e `taxonomy_wheres`, **ristretta ai soli settori CAI**: `source = 'osm2cai'` e presenza di `full_code`. Le due condizioni coincidono esattamente sui dati (63 record, 63 con `full_code`, nessuna eccezione in nessuna direzione), ma vanno messe entrambe: la prima dice l'origine, la seconda impedisce che un futuro record `osm2cai` incompleto entri silenziosamente.

Il filtro non è una rifinitura. Senza, la traccia interseca anche il proprio comune, la provincia e la regione — altri 420 poligoni amministrativi estranei alla numerazione:

| `source` | `admin_level` | record | con `full_code` |
|---|---|---:|---:|
| **`osm2cai`** | — | **63** | **63** |
| `osmfeatures` | 8 (comuni) | 377 | 0 |
| `osmfeatures` | 10 | 33 | 0 |
| `osmfeatures` | 6 (province) | 8 | 0 |
| `geohub_conf_32` | — | 7 | 0 |
| `osmfeatures` | 4 (regione) | 1 | 0 |
| `osmfeatures` | 9 | 1 | 0 |

**Da non riusare:** `GeometryModel::getOrderedTaxonomyWheres()` sembra fare al caso nostro e non lo fa. Legge la copia già calcolata in `properties['taxonomy_where']` — popolata da `UpdateModelWithGeometryTaxonomyWhere` interrogando OSMFeatures, un'altra sorgente rispetto a OSM2CAI — non tocca la geometria, restituisce **solo nomi** senza identificativi né `full_code`, e ordina per livello amministrativo, criterio senza rapporto con la numerazione CAI. Su una traccia mai elaborata restituisce l'elenco vuoto, in silenzio.

`resolveSector()` deve gestire tre esiti: un solo settore (il caso normale); più settori, perché un sentiero lungo li attraversa — il criterio è la lunghezza maggiore all'interno di ciascuno, che PostGIS calcola nella stessa query; nessun settore, che non è un errore da nascondere ma la risposta «non posso proporre un numero».

### Registro unico dei codici

Un numero riservato vivrebbe sull'istanza, un numero assegnato sul sentiero: due tabelle, e un vincolo di unicità non può stare a cavallo di due tabelle. La soluzione è un **registro unico**: ogni codice è una riga, con il suo stato e i riferimenti a chi lo detiene — l'istanza da cui è nato e, dopo l'approvazione, il sentiero a cui è assegnato.

Vantaggi: un solo posto dove il vincolo di unicità vive e viene fatto rispettare dal database; «occupati sia gli assegnati sia i riservati» diventa una singola interrogazione; la storia del numero resta leggibile anche dopo la liberazione.

**Il codice è rappresentato dalle sue colonne, non da una stringa.** `code` è solo la forma di uscita, calcolata dalle colonne e mai conservata: così non c'è nulla da tenere allineato.

| Colonna | Esempio | Perché |
|---|---|---|
| `region` | `Z` | oggi costante, ma è ciò che rende il registro adottabile da Lombardia e Toscana |
| `province` | `NU` | |
| `area` | `B` | |
| `sector` | `5` | le quattro insieme formano il `full_code`, cioè l'ambito di unicità |
| `number` | `35` | **intero**, due cifre |
| `variant` | `A` | `0` significa senza variante, altrimenti `A`–`Z`. **Mai `NULL`**: due `NULL` non collidono e il vincolo di unicità non proteggerebbe |
| `taxonomy_where_id` | | il settore da cui il prefisso è stato ricavato |
| `status` | `riservato` | `riservato`, `assegnato`, `liberato` o `conflitto` (doppione storico, escluso dal vincolo di unicità) |
| `trail_application_id` | | l'istanza per cui il codice è stato riservato |
| `ec_track_id` | | il sentiero a cui è stato assegnato all'approvazione |

**Due colonne distinte, non un riferimento polimorfico.** Il motivo principale è che così **si conservano entrambi i legami**: `trail_application_id` resta valorizzata anche dopo l'approvazione, quindi si sa sempre da quale domanda quel codice è nato. Con un riferimento polimorfico il puntatore all'istanza verrebbe sovrascritto da quello al sentiero, e quella storia andrebbe persa.

```
riserva        application: #42   ec_track: —       status: riservato
approvazione   application: #42   ec_track: #1180   status: assegnato
```

Secondo vantaggio: sono **chiavi esterne vere**, quindi è il database a impedire che un'istanza o un sentiero vengano cancellati lasciando nel registro un riferimento morto — garanzia che un riferimento polimorfico non può dare. E si evita la trappola della mappa dei tipi (`Relation::morphMap()` in `WmPackageServiceProvider`), dove il valore da scrivere è l'alias e non il nome completo della classe: un errore già capitato nel package sulla pivot `layerables`.

Il costo è che le due colonne vanno tenute coerenti con lo stato — riservato: istanza sì, sentiero no; assegnato: entrambe — e la coerenza si esprime con un vincolo di controllo sulla tabella, così a farla rispettare è il database e non solo il codice.

`full_code` (`ZNUB5`) e `code` (`ZNUB535A`) **non sono colonne**: si calcolano dalle prime sei e non si conservano. Vale la stessa regola per entrambe le stringhe — le colonne sono la rappresentazione, la stringa è solo uscita — così non esiste nulla da tenere allineato e la divergenza è impossibile per costruzione.

Le quattro lettere restano separate anche se `full_code` le riassume, perché rendono interrogabile lo spazio dei codici senza confronti su stringa. Due domande che Forestas porrà davvero:

- **«Quanti numeri sono assegnati in provincia di Nuoro?»** → un conteggio su `province`, colonna indicizzata
- **«Qual è lo spazio libero nell'area B di Nuoro?»** → `WHERE province = 'NU' AND area = 'B'`, raggruppato per settore

La seconda merita una nota: la risposta non è un singolo numero, perché il numero è unico **per settore**. È il quadro dei settori di quell'area, con il primo libero di ciascuno —

```
ZNUB1 → primo libero 08
ZNUB2 → primo libero 41
ZNUB3 → esaurito
ZNUB4 → primo libero 12
ZNUB5 → primo libero 36
```

— che è ciò che serve quando si sostituisce a mano un numero proposto: vedere lo spazio disponibile intorno, non un valore isolato.

`taxonomy_where_id` accanto alle colonne non è una ridondanza: il riferimento dice **da dove** il prefisso è stato ricavato e permette di accorgersi se quel settore cambia o viene rimosso da un reimport, mentre le colonne **congelano** il codice emesso. Senza le colonne, un reimport di OSM2CAI riscriverebbe retroattivamente codici già assegnati — inaccettabile per un numero che sta sulla segnaletica.

Il vincolo di unicità sta sulle colonne (`region` + `province` + `area` + `sector` + `number` + `variant`), non su una stringa: una stringa ricomposta male — uno spazio, una minuscola — lascerebbe passare un doppione che le colonne fermano.

`number` intero è ciò che rende realizzabile il criterio di prossimità: «il primo libero» e «il più vicino a quelli in uso» sono una riga di SQL su un intero, mentre su testo l'ordinamento metterebbe `10` prima di `9`. In uscita va riempito con lo zero davanti — `7` diventa `07` — perché il numero occupa sempre due cifre.

### Vincoli di forma nel database

L'unicità è affidata al database perché il controllo applicativo non regge le richieste concorrenti. Per la stessa ragione la **forma** delle colonne non resta solo in PHP: il comando di normalizzazione scriverà 620 righe leggendo codici storici in cinque forme diverse, tre dei quali già fuori standard, e una riga malformata che entra si scopre mesi dopo in un export.

| Colonna | Vincolo nel database | Limite applicato in PHP |
|---|---|---|
| `number` | `check (number between 0 and 99)` | — |
| `region` | una sola lettera maiuscola | — |
| `variant` | `0`–`9` oppure `A`–`Z` | **solo `0` e `A`–`Z`** |

Il margine su `variant` è deliberato e segue una distinzione precisa: **il database dichiara cosa è rappresentabile, il codice cosa è ammesso oggi.** Le cifre da `1` a `9` in quella posizione sono i sottosentieri del modello nazionale CAI (`3111`, `3112`: tratti dello stesso sentiero affidati a sezioni diverse), oggi fuori scope perché richiederebbero una relazione padre-figlio che qui non si costruisce — e verificato sui dati, nessuno dei 580 codici ha una coda di quattro cifre. Ma Piccioli ha indicato che possono esistere casi in cui quella posizione porta una numerazione invece di una variante: se un domani vanno ammessi, si allenta il controllo in PHP senza una migration su tabella popolata.

`number` e `region` restano stretti: lì non c'è alcun caso d'uso in attesa.

### Popolamento iniziale del registro

Il registro nasce vuoto e va riempito con i 576 numeri già esistenti, altrimenti il servizio proporrebbe numeri in uso. Sono numeri ufficiali e restano: «*Quelle devono rimanere, sono ufficiali*» (Piccioli).

**I doppioni prendono uno stato proprio, `conflitto`**, e restano nel registro con la loro regione vera.

In scrum era stata scelta un'altra strada — cambiare la prima lettera, quella della regione: «*Cambio la prima lettera […] tutte inizieranno con Z, quelle che iniziano con A sono doppioni*», con la risposta «*Cioè, come se fosse un'altra regione. Sì, sì, sì, nessun problema*». L'obiettivo era non avere righe che violano il vincolo di unicità, e **quell'obiettivo il vincolo parziale lo raggiunge già da sé**: l'indice unico vale solo sui codici attivi

```sql
create unique index on trail_registry_codes (region, province, area, sector, number, variant)
where status in ('riservato', 'assegnato');
```

e una riga in `conflitto` non entra nell'indice, quindi il database non la confronta con nessuno. Due `ZNUB535` in conflitto convivono senza errore; una in conflitto e una assegnata pure.

Perché è preferibile alla regione fittizia: `region` resta un dato veritiero; il doppione si trova con un filtro sullo stato invece che con una lettera anomala da conoscere; e non si consuma una lettera che domani potrebbe servire a una regione vera — `A` è perfettamente plausibile per la Lombardia o la Toscana, cioè per lo scenario che giustifica l'esistenza della colonna `region`.

Il meccanismo non è nuovo: il vincolo parziale serviva già perché una riga `liberato` deve poter restare in tabella mentre lo stesso numero viene riassegnato a un altro sentiero.

Non è la bonifica: «*un conto è pulire i dati, un conto con i dati puliti puoi lavorare a regime […] noi lavoriamo a regime adesso*». Sistemare davvero la situazione è «*una cosa che dobbiamo discutere con il cliente […] ci sono delle decisioni che dobbiamo prendere con lui per forza*» — un'attività separata con Forestas, fuori da questo ticket.

**L'ordine conta: prima si normalizza, poi si contano i doppioni.** «*Devi farlo dopo la normalizzazione […] perché potrebbe essere che hanno lo stesso numero ma sono su due settori diversi*». I sette duplicati rilevati finora sono un conteggio **testuale**, quindi provvisorio: il numero vero si conosce solo dopo aver ricavato il settore dalla geometria, e sarà più basso — `700` è già accertato essere due codici distinti in settori diversi. Produrre quel conteggio è uno dei compiti della prova a vuoto.

### Storia dei cambi di stato

Lo stato corrente sulla riga del registro non basta: un numero può essere liberato e riassegnato più volte — deaccatastamento e successivo riaccatastamento — e «chi ha liberato il numero 35, quando e perché» è una domanda che su un numero stampato sulla segnaletica qualcuno farà. Con tre colonne `reserved_at` / `assigned_at` / `released_at` il secondo ciclo sovrascrive il primo e la storia si perde.

Quindi una **tabella di storia in sola aggiunta**: una riga per ogni passaggio, con stato di partenza, stato di arrivo, quando, chi e **perché**. Il perché è la parte che vale: «liberato per deaccatastamento» e «liberato perché l'istanza è stata respinta» sono due fatti diversi che nello stato corrente si appiattiscono entrambi in `liberato`.

Nessun aggiornamento, nessuna cancellazione: le righe le scrive solo il service quando cambia stato. Effetto secondario utile: lo stato sulla riga del registro diventa verificabile, perché deve coincidere con l'ultimo passaggio registrato — se un giorno divergono, qualcosa ha scritto senza passare dal service.

**In Nova la storia si vede nel detail del codice come campo HTML di sola lettura** — `Text::make(...)->asHtml()->onlyOnDetail()`, stesso schema di `ConfigDetailPreviewRenderer` (oc:8181). Nessuna Resource dedicata alla storia, nessun `HasMany`: decisione esplicita del dev.

### Le Resource Nova del registro

**Index del registro dei codici** — cinque colonne:

| Colonna | Nota |
|---|---|
| **Codice** (`ZNUB535A`) | calcolato dalle colonne, ordinabile |
| **Denominazione** | il nome del sentiero se il codice è assegnato, altrimenti quello dell'istanza. È l'unico appiglio leggibile in un elenco di codici, e `name` esiste già per eredità da `GeometryModel` su entrambi: nessun campo nuovo. Va risolto con un accessore sul modello del codice, così vale anche fuori da Nova |
| **Stato** | `riservato` / `assegnato` / `liberato` |
| **Istanza** | link alla domanda: sempre presente |
| **Sentiero** | link all'`EcTrack`, vuoto finché il codice non è assegnato |

Il settore (`ZNUB5`) **non** è una colonna: sono i primi cinque caratteri del codice, già visibili. Resta però un **filtro**, perché è l'ambito in cui il numero è unico e «i codici del settore ZNUB5» è la domanda naturale. Filtri dell'index: provincia, area, settore, stato.

La provenienza non è una colonna del registro: vive sull'istanza (`source`).

**Detail del registro dei codici:**

| Campo | Nota |
|---|---|
| **Codice** | calcolato |
| **Denominazione** | come in index |
| **Regione, Provincia, Area, Settore, Numero, Variante** | le sei colonne scomposte: nella scheda del singolo codice hanno senso. La variante si mostra per il suo valore reale, `0` incluso — l'omissione riguarda il codice in uscita, non la colonna |
| **Stato** | |
| **Settore di riferimento** | link alla `TaxonomyWhere` da cui è stato ricavato il prefisso |
| **Istanza**, **Sentiero** | link |
| **Storia dei cambi di stato** | campo HTML di sola lettura |

**Nessun edit, nessuna creazione a mano.** `authorizedToCreate()` e `authorizedToUpdate()` a `false`: un codice non si modifica da un form. Cambiare `number` su una riga assegnata significherebbe cambiare un numero già comunicato al richiedente, e le sei colonne sono la rappresentazione del codice, non campi da compilare. Le righe le scrive solo il service.

L'unica modifica prevista dai requisiti — «Forestas sostituisce il numero proposto scegliendo fra quelli liberi» — non è un edit ma un'**Action** Nova disponibile solo sui codici in stato `riservato`: libera il vecchio e riserva il nuovo in **un'unica transazione**. Come due passi separati, nel mezzo il numero appena liberato potrebbe essere preso da un'altra richiesta.

### Il codice cambia intestazione, non valore

All'approvazione la riga del registro non viene riemessa: cambia lo stato e cambia il detentore, il codice resta identico.

```
riserva        status: riservato    application #42   ec_track —       ZNUB535
approvazione   status: assegnato    application #42   ec_track #1180   ZNUB535   ← stessa riga
```

Il numero non si tocca — è già stato comunicato al richiedente, cambiarlo sarebbe grave — e la storia resta leggibile: si vede da quale istanza quel codice è nato, anche a sentiero ormai accatastato.

### Unicità sotto richieste concorrenti

Garantita dal database, non da un controllo applicativo del tipo «guarda se esiste, poi scrivi» — che fra il guardare e lo scrivere lascia la finestra in cui due richieste passano entrambe.

**Chi perde riprova da sé, con un numero limitato di tentativi.** Il vincolo garantisce che due richieste non ottengano lo stesso numero, non che la seconda ne ottenga uno: senza ritentativo, sotto carico il SUS raccoglierebbe violazioni di unicità invece di numeri, senza modo di capire se riprovare. Con il ritentativo il perdente prende il numero successivo libero e non si accorge di nulla. Il limite ai tentativi evita di girare a vuoto quando il settore è davvero esaurito — caso che a quel punto ricade nell'eccezione di settore esaurito.

**Il vincolo è parziale: vale solo sui codici attivi**, riservati o assegnati. Una riga liberata resta in tabella con i suoi riferimenti, perché serve sapere chi aveva quel numero; se il vincolo valesse su tutte le righe, un numero liberato non sarebbe più riassegnabile a nessuno — l'opposto di ciò che il ticket chiede. PostgreSQL supporta i vincoli parziali, ma vanno scritti così fin dall'inizio: accorgersene dopo significa trovarsi in tabella dati che non li rispettano.

### Interpretazione dei numeri esistenti

Necessaria in **lettura** — senza, non sappiamo cosa è occupato, e il caso `700` mostra che due numeri diversi possono sembrare uguali.

**Il comando carica anche il registro** (decisione del 09/09, che sostituisce il «solo prova a vuoto» della prima stesura). L'ostacolo che giustificava la sola lettura era il trattamento dei doppioni, e quella decisione ormai c'è: prendono lo stato `conflitto`, che il vincolo di unicità parziale non guarda. La `--dry-run` resta, come modalità per vedere cosa accadrebbe prima di scrivere.

**Cosa entra e cosa no:**

| | esito |
|---|---|
| codice leggibile, un solo settore | entra come `assegnato` |
| codice leggibile, stessa posizione di un altro | entra come `conflitto` |
| **codice fuori forma** (coda irregolare, 3 casi) | **resta fuori**, elencato nel rapporto |
| **geometria fuori da ogni settore** | **resta fuori**: senza settore non c'è prefisso, quindi non esiste un codice da scrivere |
| tipo `sentiero` senza `ref`, con il codice nel nome (40 casi) | resta fuori, elencato: vedi «Da chiarire con Forestas» |

I casi anomali non sono un errore del comando: sono il suo prodotto. L'elenco va portato a Forestas, che decide come trattarli — «*ci sono delle decisioni che dobbiamo prendere con lui per forza*».

La riscrittura dei `ref` in `ec_tracks` resta fuori scope: il registro diventa la fonte, i `ref` restano come dato storico.

### Il modello dell'istanza — `TrailApplication`

**Nome:** `TrailApplication`, tabella `trail_applications`, in `src/Models/TrailRegistry/`. *Application* è il termine inglese per una domanda presentata a un'amministrazione, come in *planning application*; `TrailInstance` sarebbe stato un falso amico — in inglese e in programmazione «instance» è l'occorrenza di una classe, quindi si leggerebbe «un oggetto sentiero», l'opposto del significato voluto. Il nome è vincolato anche dalla colonna `trail_application_id` già fissata nel registro.

**Eredita da `MultiLineString`** (quindi da `GeometryModel`), non da `EcTrack`: riusa geometria PostGIS, esportazioni, relazione con l'App e `getOrderedTaxonomyWheres()`, senza ereditare indicizzazione nella ricerca, observer, preferiti e rigenerazione delle mappe vettoriali — che su un'istanza non ancora approvata la farebbero comparire nella ricerca dell'app e sulla cartografia pubblica.

Vive accanto a `stato_validazione`, non al suo posto: quella colonna descrive il ciclo di vita del **sentiero**, l'istanza descrive una domanda in corso. Un sentiero può essere deaccatastato senza che esista alcuna istanza.

| Colonna | Perché |
|---|---|
| `geometry` | la traccia proposta, PostGIS, dalla classe madre |
| `properties` JSON | i metadati della domanda: anagrafica del proponente, protocollo, tutto ciò che dipende dal **set minimo di dati scambiati**, ancora aperto (PDF di Piccioli + Excel di Schirru). Finché quel set non si chiude, ogni campo promosso a colonna è una migration da rifare |
| `source` | `api` o `office`: come è arrivata la domanda. Sta qui e non sul registro perché descrive la domanda, non il codice. Mai il nome dello sportello |
| `status` | **colonna** con enum PHP e indice, non una voce in `properties`: è il perno del ciclo di vita — `release()` scatta al passaggio a respinta, `confirm()` all'approvazione, e l'elenco delle domande da istruire in oc:8491 è un `WHERE` su questo campo. In JSON sarebbe un filtro senza indice e senza che il database possa rifiutare un valore non previsto. **Tre valori: in attesa di istruttoria, respinta, approvata** — vedi sotto perché non ce ne sono altri |
| `user_id` | chi ha **inserito** l'istanza nel Catasto: il client API quando arriva da API, l'utente autenticato quando nasce d'ufficio. Non è il proponente — il cittadino non ha un account qui e resta un dato in `properties`. Non è l'operatore che istruisce, che appartiene alla convalida (oc:8491) e finisce sull'`EcTrack` creato |
| FK verso il sentiero creato | colonna, non una voce in `properties`: vedi sotto |

**Nessun campo `kind` (sentiero / itinerario).** L'istanza *è* per definizione la domanda di accatastamento di un sentiero: un itinerario non si accatasta, non avendo un numero. Un campo che può assumere un solo valore legittimo non si scrive. Ne segue che alla conferma l'`EcTrack` creato deve nascere **con il tipo `sentiero` già attaccato**, non lasciato all'operatore: sul database ci sono 6 tracce che sono sentieri a tutti gli effetti (tutte con `ref`, 3.7 km medi) a cui manca solo la riga in pivot — prova che l'etichetta manuale si dimentica.

**La tabella nasce vuota e resta vuota per tutto questo ciclo:** le istanze vere arrivano con oc:8490. Estenderla dopo non costa alcuna migrazione di dati, quindi il minimo indispensabile qui è la scelta giusta e non un debito.

### Un'istanza esiste solo se prevalidata

**Non esistono istanze non prevalidate.** Se il controllo formale non passa, l'API rifiuta indicando l'errore e Nova non salva indicando l'errore: nulla viene scritto. Quindi ogni riga in `trail_applications` è già prevalidata e porta già il suo codice riservato.

Da cui due conseguenze:

- **Creazione dell'istanza e riserva del numero sono un'unica transazione.** Non esiste un'istanza senza codice nemmeno per un istante: se la riserva fallisce, l'istanza non viene scritta.
- **Lo stato dell'istruttoria ha tre valori**, non cinque: `in attesa di istruttoria`, `respinta`, `approvata`. «Presentata» e «prevalidata» non servono — la prima non è uno stato raggiungibile, la seconda è il fatto di avere una riga nel registro, che nasce solo da `reserve()`. Tenerne un valore nell'enum darebbe due fonti per lo stesso fatto, che prima o poi divergono.

`propose()` e `reserve()` restano comunque distinti: la preistruttoria di oc:8490 deve poter mostrare un numero **prima** di scrivere, altrimenti ogni traccia scartata lascerebbe dietro un numero bloccato per sempre.

### Le Resource Nova dell'istanza

**Index delle istanze** — sei colonne:

| Colonna | Nota |
|---|---|
| **Denominazione** | il `name` dell'istanza |
| **Codice** | il codice riservato: **sempre presente**, perché un'istanza non prevalidata non esiste |
| **Stato istruttoria** | in attesa / respinta / approvata |
| **Provenienza** | `api` / `office` |
| **Inserita da** | l'utente di `user_id` |
| **Presentata il** | `created_at` |

**Filtri: stato istruttoria e provenienza**, e nient'altro. Provincia e settore sono stati valutati e scartati: si leggerebbero dal codice riservato, quindi con un join sul registro, e l'unica alternativa — duplicare il prefisso sull'istanza — reintrodurrebbe il dato in due posti. Chi cerca per territorio parte dal registro, che quei filtri li ha.

**Edit delle istanze: rinviato.** Cosa sia modificabile dopo la presentazione dipende da una risposta di Forestas che non c'è ancora — vedi «Da chiarire con Forestas». In questo ciclo la Resource nasce senza form di modifica.

Il nodo è la geometria: se la traccia si può cambiare, il settore in cui ricade può cambiare, e il settore sono i primi cinque caratteri del codice **già riservato e già comunicato**. Si otterrebbe un'istanza con un numero di un settore in cui il sentiero non passa.

### Avanzamento dell'istruttoria: due Action sull'istanza

Il registro è immodificabile, quindi **l'avanzamento avviene sempre dall'istanza**, con due Action Nova. Nessuna Action «Libera numero» sul registro: sarebbe l'unica operazione capace di liberare un codice senza che sia accaduto nulla, e lascerebbe nella storia un passaggio privo di causa.

| Action | Cosa fa |
|---|---|
| **Approva** | crea l'`EcTrack`, gli attacca il tipo `sentiero`, replica i media, lega il codice al sentiero e lo porta ad `assegnato`. Tutto in una transazione |
| **Respingi** | porta l'istanza a `respinta` e libera il codice, con la causa scritta nella storia |

`release()` non è invocabile per sé: è la conseguenza di un atto — istanza respinta o sentiero deaccatastato — e la policy sta sull'azione che lo scatena, non sul metodo.

**Il SUS non libera nulla**: sulle proprie istanze può presentarle e leggerne l'esito.

Queste due Action erano attribuite a oc:8491 (istruttoria). **Decisione del dev: entrano qui**, perché senza di esse `confirm()` e `release()` resterebbero metodi senza innesco, verificati solo dai test e mai da un'esecuzione reale. oc:8491 costruirà sopra il banco di lavoro dell'istruttoria, non un meccanismo diverso.

### Dall'istanza approvata al sentiero

Il pattern esiste già nel package, ma **solo per i POI**: `src/Nova/Actions/ConvertUgcPoiToEcPoi.php`. Non esiste l'equivalente per i track. Da riusare:

- crea l'`EcPoi` con `name`, `geometry`, `user_id` di chi convalida, `app_id`
- **replica i media** con `replicate()` + `associate()`, in try/catch per non far cadere l'intera conversione su un allegato
- **idempotenza**: se il legame è già valorizzato salta e lo segnala, così una seconda esecuzione non duplica
- `saveQuietly()` per non far ripartire gli observer

Una divergenza deliberata: lì il legame vive in `properties['ec_poi_id']`, quindi non è una chiave esterna e nulla impedisce che punti a un record cancellato. Qui è una **colonna FK**, coerente con la scelta già fatta per `ec_track_id` nel registro.

### Sentiero o itinerario: il discriminante esiste già

Misurato sul database:

| tipo (`taxonomy_activities`) | tracce | con `ref` | senza `ref` | km medi |
|---|---:|---:|---:|---:|
| `sardegnasentieri:type:sentiero` | 614 | 574 | 40 | 5.1 |
| `sardegnasentieri:type:itinerario` | 147 | **0** | 147 | 29.1 |
| *(nessun tipo)* | 6 | 6 | 0 | 3.7 |

**Nessun itinerario ha un numero: zero su 147.** Il criterio è quindi il tipo, non la lunghezza — che ne era solo un'approssimazione (i sentieri numerati stanno in media su 5.1 km e nessuno supera i 40.4; gli itinerari arrivano a 250).

Perimetro del registro: **620 dentro** (614 sentieri + le 6 senza tipo, che diventano sentieri perché hanno un `ref` e solo i sentieri ne hanno), **147 itinerari fuori**. Dei 620, **580 portano un numero da caricare** e **40 sono sentieri senza numero**, che in questo ciclo si **misurano** in prova a vuoto senza scrivere: assegnare 40 numeri a tavolino è una decisione di Forestas su sentieri reali, e un numero emesso e poi ritirato è il caso che questa overview stessa dichiara grave.

La sede di quel discriminante è però impropria e va saputo: un'*activity* nel package dice **come** si percorre un tracciato (`trekking`, `mtb`, `horse`), non **cosa** è. Il valore ci è finito perché l'import di Sardegna Sentieri mappa i "types" di Drupal sulle activities — una scelta di import, non di modello. Resta la fonte per il caricamento iniziale delle 620, e da lì in avanti è l'essere passati per un'istanza a stabilire che un tracciato è un sentiero.

### Provenienza della prenotazione

Le prenotazioni **non sono «del SUS»**: sono prenotazioni, e il SUS è solo uno dei canali da cui arrivano. **Nel package non deve comparire la parola SUS** — è il nome dello sportello sardo, e questo codice servirà Lombardia e Toscana: una colonna o un valore di stato che dicesse `sus` sarebbe la Sardegna scritta dentro codice condiviso, lo stesso errore che si evita tenendo `region` come colonna invece di dare `Z` per scontato.

La provenienza è un attributo **dell'istanza**: colonna **`source`** su `trail_applications`, valori `api` e `office`, in inglese e senza il nome dello sportello. È il posto giusto perché descrive come è arrivata la domanda, non il codice — e perché **ogni prenotazione nasce da un'istanza**, anche quella fatta d'ufficio: un operatore che riserva un numero a mano crea comunque l'istanza, con il proprio `user_id`.

Il registro legge la provenienza dall'istanza, non ne conserva copia. Anche il caricamento iniziale delle 620 è `office`: non è arrivato da un'API, l'ha messo Forestas. Un terzo valore `import` avrebbe portato nel package un dato che parla di un import sardo.

`source` e `user_id` dicono lo stesso fatto in due forme e si controllano a vicenda.

**Il vincolo di controllo sul registro** resta quindi semplice, e va scritto giusto nella migration — accorgersene a tabella popolata significa trovarsi righe che il vincolo non accetta più:

| `status` | `trail_application_id` | `ec_track_id` |
|---|---|---|
| `riservato` | sempre obbligatorio | vuoto |
| `assegnato` | resta valorizzato dalla riserva | obbligatorio |

Non esiste il caso «riservato senza istanza»: chi ha riservato un numero si sa sempre, ed è `user_id` dell'istanza.

## Da chiarire con Forestas

Domande aperte, da porre prima di completare le parti che ne dipendono.

- **Cosa si può modificare in un'istanza dopo la presentazione, e in particolare la traccia.** Se la geometria cambia, può cambiare il settore e quindi il prefisso di un codice già riservato e già comunicato al richiedente. Tre risposte possibili, tutte legittime: la traccia non si tocca e si corregge presentando una nuova istanza; si tocca e il numero viene riemesso; si tocca solo restando nello stesso settore. **Decisione del dev: si aspettano le direttive del cliente**, e fino ad allora la Resource dell'istanza non ha form di modifica.
- **Ogni sentiero nasce da un'istanza, o d'ufficio si può creare un sentiero direttamente?** In `Catasto → Sentieri` la creazione esiste per eredità dalla Resource `EcTrack`: se Forestas la usa, nasce un sentiero che non è passato da nessuna istanza e a cui nessuno ha riservato un numero — quindi fuori dal registro, con il suo codice che risulta libero e proponibile a un'altra domanda. Le due risposte portano a due sistemi diversi: se ogni sentiero deve passare da un'istanza, la creazione diretta va disabilitata su quella Resource; se la creazione d'ufficio è legittima, serve un percorso che riservi il numero contestualmente, come già fa la creazione dell'istanza. **In questo ciclo non si decide**: si aspetta la risposta.
- **Criterio di prossimità**: cosa significa proporre un numero «vicino». In questo ciclo contiguità numerica dentro il settore; se intendessero vicinanza geografica del tracciato, va sostituito.
- **Durata della riserva** di un codice non confermato, e chi può liberarlo prima della scadenza.
- **Conferma formale del formato** del codice.
- **Come bonificare** doppioni e codici fuori forma.
- **Cosa significa, lato Drupal, un sentiero senza `ref`**: 40 sentieri portano il codice scritto nel nome invece che in `ref`, e 35 di questi sono in `in_revisione_validazione`. Non si sa se il `ref` sia stato rimosso deliberatamente, non sia mai stato compilato, o non venga importato in quello stato: sono tre cose diverse con tre trattamenti diversi. In questo ciclo si segnalano e non si toccano.

## Rischi

Rivisti dopo la challenge adversariale.

- **I doppioni in stato `conflitto` restano nel registro.** Non violano il vincolo di unicità perché è parziale, ma chi legge il registro deve sapere che sono dati storici in attesa di bonifica, non codici validi. Vanno inclusi nel rapporto della prova a vuoto, e il loro stato non va confuso con `liberato`: un codice liberato è riassegnabile, uno in conflitto è un problema da risolvere.
- **Il formato del codice resta da confermare formalmente con Forestas**, benché ora regga sui conti e sia confermato da Piccioli in scrum: `ZNUB535A`, otto caratteri con variante e sette senza, spiega l'apparente contraddizione del tag, e la coda numerica è di tre cifre in 576 casi su 576 — la prima è il settore, le altre due il numero. Rischio residuo: se il formato reale avesse una struttura diversa da «`full_code` del settore + numero + variante», la scomposizione in colonne andrebbe rifatta.
- **La base di partenza è sporca**, ma meno di quanto sembrasse: tre codici su 576 hanno una coda fuori forma, e i duplicati sono al massimo sette — conteggio testuale, da rifare dopo aver ricavato il settore dalla geometria. Mitigazione: i doppioni vanno in una regione fittizia, il resto entra come occupato, e la prova a vuoto misura quanto resta ambiguo.
- **Il prefisso si ricava sempre dalla geometria.** Se una traccia non ricade in alcun settore CAI, o ne attraversa più d'uno, l'attribuzione è incerta — e vale per tutti e 576 i numeri storici, non solo per quelli in forma breve. Da misurare con la prova a vuoto, che può incrociare il settore dedotto dal codice con quello dedotto dalla geometria.
- **Il criterio di prossimità non è definito.** In questo ciclo: contiguità numerica dentro il settore. Se Forestas intendesse vicinanza geografica del tracciato, il criterio va sostituito.
- **`ref` resta scrivibile finché non lo si toglie dall'interfaccia.** Il registro è l'unica fonte e `ref` è deprecato, ma finché il campo è visibile e modificabile qualcuno può scriverci un numero che il registro non conosce. Il rischio non è la divergenza dei dati — `ref` non viene più letto — ma la confusione di chi lo vede ancora. Va nascosto nello stesso ciclo in cui il registro entra in uso.
- **Riserve che non scadono mai.** Un'istanza che entra in prevalidazione e poi si arena non viene né respinta né approvata: il suo numero resta bloccato per sempre. Con la contiguità numerica come criterio, poche istanze abbandonate frammentano lo spazio dei numeri di un settore. La durata della riserva è uno dei punti aperti con Forestas.
- **Riuso immediato di un numero liberato.** Un sentiero deaccatastato può essere riaccatastato; se nel frattempo il numero è andato a un altro, la segnaletica sul terreno e le mappe cartacee puntano al sentiero sbagliato. Nessun periodo di quarantena previsto in questo ciclo.
- **Esaurimento di un settore.** Con la contiguità che riempie dal basso, un settore piccolo si satura: va definito cosa risponde il servizio quando non ci sono più numeri liberi.
- **Il perdente della concorrenza riceve un errore, non un numero.** Il vincolo del database garantisce che due richieste non ottengano lo stesso numero, non che la seconda ne ottenga uno: serve un secondo tentativo automatico, altrimenti sotto carico il SUS riceve errori invece di numeri.
- **Tracce che attraversano più settori.** Citato come caso limite, è in realtà il caso normale per un sentiero lungo. Il criterio adottato è la lunghezza maggiore dentro ciascun settore; se Forestas usa un criterio diverso (settore di partenza, per esempio), va sostituito.
- **`WM_TRAIL_REGISTRY_ENABLED` che sparisce dal `.env` di produzione.** Rischio già noto e documentato per oc:8492, ma qui il danno cresce: `php artisan optimize` nel deploy congela la configurazione, il dominio scompare e con esso la capacità di riservare numeri, mentre le tabelle restano piene e le istanze in corso diventano invisibili.
- **Sconfinamento fra ticket.** Il modello dell'istanza è nominato anche da oc:8491. Deciso di crearlo qui perché oc:8490 riserva il numero già in prevalidazione e avrebbe bisogno di un'istanza esistente. oc:8491 costruirà sopra questo modello, non un altro.

## Out of scope

- Le API che espongono il servizio al SUS (oc:8490, oc:8491)
- La riscrittura dei numeri **nel campo `ref` di `ec_tracks`**: il registro si popola, ma i `ref` storici non vengono toccati
- L'interfaccia Nova per l'istruttoria delle istanze (oc:8491)
- La prevalidazione automatica della traccia (oc:8490)
- **I sottosentieri numerati** (`3111`, `3112` per tratti affidati a sezioni diverse): esistono nel modello nazionale, ma «*in Sardegna secondo me non c'è […] non perdere tempo a generalizzare*» (Piccioli). Richiederebbero una relazione padre-figlio che qui non si costruisce: una cifra in posizione di variante va rifiutata. Verificato sui dati: nessuno dei 576 codici ne ha una
- **Scindere `stato_validazione` in stato di accatastamento e stato di percorribilità**: «*hanno sovrapposto in questo campo due cose differenti […] nel modello standard nazionale del CAI sono due campi diversi*». Da fare, ma «*non rientra nelle attività che devi fare adesso*» — va aperto un ticket dedicato
- L'aggancio a OpenStreetMap del sentiero accatastato: rinviato esplicitamente in scrum
- **La bonifica vera dei doppioni e dei codici fuori forma**: in questo ciclo vengono solo spostati fuori dallo spazio valido per far funzionare il sistema. Come sistemarli è «*una cosa che dobbiamo discutere con il cliente*» (Piccioli)

## Moduli toccati

Il registro, l'istanza, il service e il comando stanno tutti in **`wm-package`**, dominio `trail_registry`.

**Il menu `Catasto` con `Istanze` e `Registro dei codici` è il comportamento di default del dominio**: chi accende `trail_registry` lo vede senza scrivere una riga, perché il package registra le Resource e inietta la sezione da sé — stesso meccanismo già in uso per `Tools`. Un consumer che debba customizzare una di quelle Resource la estende e pubblica `config/wm-package.php` mettendo la propria classe in `nova_resources` al posto di quella del package: resta una sola Resource registrata.

In forestas si aggiunge **solo** la voce `Sentieri`, dichiarando una sezione `Catasto` a cui il package accoda le proprie due voci. Il menu `EC` resta invariato. La Resource `Sentiero` sta là e non qui perché il filtro è su un identificatore sardo (`sardegnasentieri:type:sentiero`), che nel package sarebbe la Sardegna scritta dentro codice condiviso. Nessuna Resource `Itinerario`: nel Catasto sta solo ciò che si accatasta. Dettaglio in `forestas/docs/features/8489-identificazione-automatica-sentiero-codice-rei/overview.md`.

Il dominio è già attivo in forestas (`WM_TRAIL_REGISTRY_ENABLED`, oc:8492): da qui arrivano solo gli stub di migration da pubblicare.

| File | Cosa |
|---|---|
| `database/migrations/trail_registry/` | stub delle tabelle: istanze, registro dei codici, storia dei cambi di stato |
| `src/Models/TrailRegistry/` | modello dell'istanza e modello del codice |
| `src/Services/TrailRegistry/` | `TrailRegistryService`, unico service di dominio: risoluzione del settore, proposta, riserva, conferma, liberazione, elenco dei liberi, verifica. Accanto, la lettura dei codici esistenti come funzione pura |
| `src/Commands/TrailRegistry/` | comando di normalizzazione, in prova a vuoto |
| `src/TrailRegistry/Nova/` | le Resource `Istanze` e `Registro dei codici`, elencate in `features.trail_registry.nova_resources` e registrate dal package **a dominio acceso**. **Il percorso non è negoziabile: fuori da `src/Nova`**, che `Nova::resourcesIn()` scandisce ricorsivamente registrando tutto anche a dominio spento (un test del package, `OptionalDomainRegistrationTest`, fallisce se qualcuno le sposta là) |
| `src/WmPackageServiceProvider.php` | inietta la sezione di menu `Catasto` con le due voci, **solo a dominio acceso**, con lo stesso meccanismo già usato per `Tools` (righe 557-660): se il consumer ha già una sezione con quel nome ci accoda le voci, altrimenti la crea |
| `config/wm-package.php` | sezione `features.trail_registry`: formato del codice, comandi, risorse Nova |
| `tests/Feature/TrailRegistry/` | test del ciclo di vita, della concorrenza e dell'interpretazione dei codici reali |
| `docs/resources/` | documentazione della funzionalità |

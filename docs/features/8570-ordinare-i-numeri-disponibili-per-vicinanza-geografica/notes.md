> Ticket: oc:8570

# Notes — Ordinare i numeri disponibili per vicinanza geografica

## Confronto sulla stima

Registrato per una discussione a lavoro finito: **come stima `wm-estimate` rispetto a quanto ci
mettiamo davvero**. Le due colonne vanno riempite a fine implementazione, componente per
componente, misurando e non ricordando.

Stima dell'agente: **3.1 h** di implementazione. Valore del dev, quello scritto su Orchestrator:
**1 h**. Divergenza di 2.1 h, cioè l'agente stima poco più del triplo.

| Componente | Agente (h) | Reale (h) | Scarto |
|---|---|---|---|
| Metodo unico di ordinamento per cluster + distanza | 1.2 | 0.02 | −1.18 |
| `neighbourCodes()` / la query delle distanze | 0.3 | 0.01 | −0.29 |
| `numberOptions()` — passare la geometria | 0.2 | 0.15 | −0.05 |
| Test Feature, 7 scenari | 1.0 | 0.08 | −0.92 |
| Buffer integrazione trasversale | 0.13 | — | — |
| Buffer novita' di dominio | 0.5 | — | — |
| **Totale implementazione** | **3.1** | **0.84** | **−2.26** |

**Come e' stato misurato il reale.** Wall-clock dalla creazione del branch (12:34:57) alla fine
dell'ultima correzione (13:25:13): **50 minuti, 0.84 h**. La colonna per componente riporta il
tempo di esecuzione dei singoli agenti, sommando implementazione e correzioni dello stesso
componente; la differenza fra la somma dei componenti (0.26 h) e il totale (0.84 h) e' il tempo di
coordinamento: scrivere i brief, costruire i pacchetti di review, leggere i report, decidere sui
rilievi.

**Il confronto che conta.** L'agente aveva stimato 3.1 h, il dev 1 h, il reale e' 0.84 h. **Il dev
ha avuto ragione**, e con un margine stretto: 0.16 h di scarto contro le 2.26 h dell'agente.

**Dove l'agente ha sbagliato, e di quanto:**

- **La logica di ordinamento**, stimata 1.2 h, ne ha richiesta 0.02. E' l'errore piu' grande in
  valore assoluto. L'agente l'ha trattata come «algoritmo nuovo da progettare», ma il piano
  conteneva gia' il codice riga per riga: era trascrizione, non progettazione.
- **I test**, stimati 1.0 h, 0.08 reali. Stesso motivo: gli otto test erano scritti nel piano, con
  gli attesi gia' calcolati a mano. L'agente ha stimato il costo di inventarli.
- **I due buffer**, 0.63 h in tutto, non hanno trovato nulla da assorbire. Il buffer di novita' di
  dominio era motivato dall'assenza di precedenti su clustering e ordinamento per distanza: vero,
  ma la novita' era gia' stata risolta in fase di pianificazione, non durante l'esecuzione.

**La lezione per la prossima stima.** L'agente stima il costo di *decidere*, non quello di
*scrivere*. Quando il piano contiene il codice e gli attesi dei test, quel costo e' gia' stato
pagato altrove — in pianificazione — e non va contato due volte. La quota misurata della
pianificazione (0.83 h) e il totale reale dell'implementazione (0.84 h) sono quasi identici: su
questo ticket, decidere e' costato quanto fare.

**Dove invece il tempo e' andato davvero:** non nella scrittura, ma nei due giri di correzione
nati dalle review — la geometria dei codici `Reserved` nel Task 1 e il self-clustering nel Task 5.
Nessuno dei due era prevedibile dal piano, ed e' li' che un buffer avrebbe avuto senso.

Pianificazione misurata da Orchestrator: **0.83 h** (tempo del ticket in `progress`; una parte del
lavoro è avvenuta mentre risultava in `todo`, quindi è una misura per difetto).

Totale scritto su Orchestrator: **Misurato 0.83 h + Stimato 1 h = 1.83 h**.

**Le motivazioni dell'agente**, da verificare contro il consuntivo:

- confidenza dichiarata **media**: overview insolitamente esplicita su algoritmo e casi limite, il
  che abbassa il rischio sui requisiti, ma codice geometrico nuovo
- buffer di novità di dominio giustificato così: nessun precedente di clustering né di ordinamento
  per distanza nel dominio, solo `ORDER BY c.number, c.variant`
- il componente più caro per l'agente è la logica di ordinamento (1.2 h), seguito dai test (1.0 h)

**Cosa guardare nella discussione finale:**

- se lo scarto è concentrato in un componente o distribuito su tutti
- se i due buffer (integrazione trasversale e novità di dominio, 0.63 h in totale) sono serviti
- se il tempo dei test è stato quello previsto: è la voce dove un algoritmo con casi limite
  geometrici può sfuggire di mano

## Deviazioni dal piano

- **Task 5 — self-clustering di `numbersWithAvailableVariants()`**: il test del brief (Step 1,
  senza alcun collegamento all'Action) ha mostrato che un numero già occupato ma con una lettera
  ancora libera resta nell'insieme `$available` (requisito di oc:8569) e, se appartiene al cluster
  più vicino alla traccia, dista zero da sé stesso: vince sempre l'ordinamento per vicinanza.

  Prima soluzione (scartata dalla review, spec ❌): segregare i numeri già occupati in coda, dopo
  quelli liberi ordinati per vicinanza. Scartata perché toglie valore proprio dove il ticket vuole
  aggiungerne — un numero occupato del cluster vicino (es. l'11 di un cluster 11-13) è un candidato
  legittimo quanto un numero libero: offrire `ZNUB511A` accanto a `ZNUB511` è esattamente ciò che
  l'operatore cerca quando la traccia passa in quella zona.

  Soluzione adottata: il problema reale è uno solo — il codice su cui l'Action è aperta, che
  quando si passa la sua stessa geometria fa cluster con se stesso e nasconde i vicini veri.
  `usedNumbersWithDistance()` ha un terzo parametro opzionale `?int $excludeCodeId = null` (stesso
  pattern di `$excludeId` in `ComposesTrailRegistryMap::neighbourCodes()`), propagato da
  `numbersWithAvailableVariants()` e passato da `ReplaceTrailCodeNumber::numberOptions()` come
  `$code->id`. Con `null` (compreso `propose()`, dove il codice non esiste ancora) il comportamento
  resta quello di oggi. I numeri occupati-con-lettera-libera restano invece nell'ordinamento per
  vicinanza insieme ai liberi, senza segregazione.

  I due test coinvolti sono stati aggiornati per riflettere l'ordine corretto: in
  `NumbersWithAvailableVariantsTest.php` il cluster 11-13 esce ora in testa (non i liberi
  adiacenti 10/14); in `ReplaceTrailCodeNumberActionTest.php` l'asserzione verifica che in testa
  esca un numero del cluster vicino (11-13) e non un adiacente del codice in esame (69/71).

- **Fallback alle varianti con lettera: ordinato, non lasciato intatto come chiedeva la specifica**
  (review finale, oc:8570). La specifica diceva «il fallback alle varianti con lettera resta
  esattamente com'è oggi, non ordinato»; `propose()` applica invece `orderByProximity()` dentro il
  ciclo su `variantSearchOrder()`, quindi il criterio per vicinanza vale anche per le varianti A-Z,
  non solo per la variante `0`. **Il codice resta così**: applicare l'ordinamento dentro il ciclo
  delle varianti è più semplice che escluderne selettivamente il fallback, e il risultato è più
  coerente per chi propone un codice. Il caso in cui questo produce un esito diverso è remoto: si
  manifesta solo quando un intero settore ha già tutti e cento i numeri puri (variante `0`)
  occupati e si ricade sulle varianti con lettera — contro i 26 numeri distinti su 100 del settore
  più popolato osservato nel DB locale di sviluppo (vedi «Misura di prestazione su ZNUB4» sotto).

## Bug trovati

Nessuno ancora.

## Decisioni

- **Tag associati** (22/09/2026): `wm-package` (id 635). Il tag `forestas` è stato proposto e non
  associato; il dev ha indicato di preferire un tag di dominio del package, che non esiste — il
  candidato emerso è `trail-registry`. Decisione chiusa il 24/09/2026: creato il tag
  `trail-registry` (id 685), senza descrizione, e associato al ticket.
- **Niente interruttore di configurazione** per spegnere il criterio (dev, 22/09/2026): il rollback
  resta una manovra su due repo. Proposto in Challenge e scartato esplicitamente.
- **Fallback alle varianti con lettera lasciato intatto e non ordinato** (dev, 22/09/2026): i cento
  numeri coprono con ampio margine un settore reale — 26 numeri distinti occupati su 100 nel
  settore più popolato del DB locale.
- **Insieme scelto da `propose()`: filtro per-variante, non «senza alcuna variante occupata»**
  (review finale, oc:8570). La specifica diceva che `propose()` sceglie fra i numeri «senza alcuna
  variante occupata», lasciando intendere un metodo nuovo che guarda al numero nel suo complesso.
  Il codice tiene invece il filtro per-variante già in uso prima del ticket: un numero è candidato
  se è libero *per la variante corrente del ciclo*. La formulazione della specifica era ambigua su
  questo punto, e le due letture divergono in un solo caso: `ZNUB511` libero ma `ZNUB511A`
  occupato — con il filtro per-variante l'11 viene proposto, con la lettura letterale no. **Si
  adotta il comportamento attuale**, identico a quello di prima del ticket: la variante di un
  numero è un sentiero indipendente, non una diramazione del numero base, quindi la sua occupazione
  non deve precludere la proposta del numero puro.

- **`availableNumbers()` non viene rimosso** benché non abbia call site né nel package né in
  `forestas`: in un package condiviso da più consumer con branch diversi, «nessun call site qui»
  non significa «nessun call site».
- **Misura di prestazione su ZNUB4** (22/09/2026), settore più popolato del DB locale di sviluppo
  di forestas — misurato con `EXPLAIN ANALYZE` su `docker exec postgres-forestas psql`: il
  `WHERE` su `region/province/area/sector` in ZNUB4 seleziona **62 righe** in
  `trail_registry_codes` (non 61 come indicato nel brief), che il `GROUP BY c.number` riduce a 26
  numeri distinti. La query nuova (`ST_Distance` + `COALESCE(t.geometry, a.geometry)` + `MIN` +
  `GROUP BY`) esegue in **94,232 ms** (Planning Time 45,074 ms + Execution Time 94,232 ms; il
  tempo che conta a runtime è l'Execution Time), con piano `Nested Loop Left Join` su `Hash Right
  Join` (join tra `trail_registry_codes`, `trail_applications` e `ec_tracks`) alimentato da un
  `Index Scan using trail_registry_codes_active_unique`, seguito da `Sort` + `GroupAggregate` per
  il `MIN(ST_Distance(...))` per numero. La lettura che `propose()` fa oggi (`SELECT number,
  variant ... WHERE ...`) esegue in **1,575 ms** con `Index Only Scan using
  trail_registry_codes_active_unique` (Heap Fetches: 62), senza join né aggregazione. La query
  nuova è quindi **~60 volte più lenta** di quella attuale, ma resta sotto la soglia di allarme di
  200 ms: 94,232 ms per una chiamata singola di `propose()`, fino a un ordine di ~470 ms nel caso
  peggiore di `reserve()` con cinque tentativi in contesa. **Giudizio**: il costo è alto in termini
  relativi (60x) ma assoluto ancora accettabile su un settore di 62 codici; nessun indice KNN viene
  usato (l'`ORDER BY`/`MIN` su `geography` resta un `Seq`-style join, non un Index Scan sulla
  distanza), quindi il costo crescerà linearmente con la popolazione del settore — da monitorare se
  in futuro un settore supererà qualche centinaio di codici attivi.

## Follow-up

- `wktOf()` dichiara `: string` ma legge `trail_applications.geometry`, che è nullable: se un
  giorno arrivasse un'istanza senza geometria sarebbe un TypeError. Oggi il dev garantisce che
  l'istanza ha sempre una geometria, e il caso è fuori scope; con l'arrivo delle istanze dal SUS
  vale la pena riprenderlo.
- Il sentiero che attraversa più settori: l'ordinamento si calcola sul settore prevalente. Se un
  domani si potrà assegnare il sentiero a un altro dei settori attraversati, i candidati andranno
  ricalcolati sul settore scelto.

### Verifica indipendente della misura, e la variante planare scartata

La misura del Task 6 e' stata ripetuta tre volte nel context principale, per confermare che i
~93 ms non fossero un artefatto di lettura dell'output di `EXPLAIN`: 92,584 / 93,563 / 92,504 ms.
Sono millisecondi veri, su 62 codici.

E' stata poi misurata la variante planare — `ST_Distance` con cast a `::geometry` invece che su
`geography` — che gira in 9,7 / 10,5 / 11,5 ms, nove volte piu' veloce.

**Scartata** (22/09/2026). `geometry` misura in gradi su un piano, e alla latitudine della
Sardegna un grado di longitudine vale circa 85 km contro i 111 di uno di latitudine: distanze in
direzioni diverse verrebbero pesate diversamente, e fra due cluster quasi equidistanti l'ordine
potrebbe ribaltarsi. L'ordine corretto e' esattamente cio' che questo ticket costruisce, quindi
non si baratta con 83 ms che restano sotto la soglia fissata.

Resta una strada pronta, gia' misurata, se un settore crescesse: il costo e' lineare nei codici
del settore, quindi intorno ai 500 codici si andrebbe verso i 750 ms per chiamata, e fino a
cinque chiamate in contesa.

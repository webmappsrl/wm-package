# Come un sentiero arriva ad avere il suo numero

Riguarda il codice del Catasto Sentieri — `ZNUB459` e simili — dal momento in cui un'istanza di
accatastamento viene creata a quello in cui l'operatore, se non gli va bene, lo cambia. Il dominio
nel suo insieme è in [docs/resources/TrailRegistry.md](../resources/TrailRegistry.md); qui c'è solo
il perché delle scelte sulla numerazione.

## Dove sta il meccanismo

Come funziona, passo per passo, è in
[docs/resources/TrailRegistry.md](../resources/TrailRegistry.md), sezione «Il criterio di
vicinanza». Qui non si ripete: questa pagina serve al **perché** di quelle scelte e a **cosa è già
stato provato**, che nella documentazione d'uso non hanno posto.

Una sola cosa va tenuta a mente leggendo il resto, perché è la sequenza che si fraintende più
facilmente: i gruppi si formano **per numeri contigui**, si sceglie quale gruppo guardare **con i
metri**, e dentro al gruppo si torna **ai numeri**. La geografia interviene in un passo solo, quello
di mezzo.

## Perché così

- **I gruppi si formano per numeri e non per metri** (oc:8570): raggrupparli per vicinanza
  geografica avrebbe richiesto una soglia — entro quanti metri due sentieri stanno insieme? — cioè
  un parametro da tarare e da rivedere a ogni cliente. La contiguità numerica non ha parametri, e
  rispecchia come la numerazione viene letta da chi lavora: «in quella zona hai usato la prima
  decina» descrive un insieme di numeri, non di metri.
- **Governa un gruppo solo** (oc:8570): far competere più gruppi produce un ordine che nessuno sa
  spiegare, e soprattutto risolve un problema che non esiste — chi vuole aprire una numerazione
  altrove non aspetta che il sistema lo indovini, apre l'Action e sceglie. Aprire una zona nuova è
  un gesto deliberato.
- **A parità vince il numero precedente** (oc:8570): «continua quella numerazione» non dice di
  saltare in avanti lasciando un buco dietro.
- **La distanza si misura su `geography`, non su `geometry`** (oc:8570): la variante planare è
  nove volte più veloce — misurata, 10 ms contro 93 su 62 codici — ma misura in gradi, e alla
  latitudine della Sardegna un grado di longitudine vale circa 85 km contro i 111 di uno di
  latitudine. Fra due gruppi quasi equidistanti in direzioni diverse l'ordine può ribaltarsi, e
  l'ordine giusto è tutto ciò che questa feature produce.
- **I numeri già occupati che hanno ancora una lettera libera restano in elenco** nell'Action e
  concorrono per vicinanza come gli altri (oc:8569, oc:8570): `ZNUB511A` accanto al gruppo 11-13 è
  un candidato sensato quanto il 10 o il 14.
- **La variante non è una diramazione del numero** (oc:8489): `ZNUB535` e `ZNUB535A` sono due
  sentieri indipendenti. Da qui l'ordine di ricerca nel fallback — si esauriscono i cento numeri in
  variante `0` prima di passare alla A — e il fatto che un numero resti proponibile anche se una
  sua variante è occupata.

## Cosa è già stato provato, e perché è stato abbandonato

- **Primo numero libero del settore** (oc:8570, superato): era il criterio originale. Rispettava il
  vincolo — la disponibilità — e ignorava il criterio, cioè la vicinanza, quindi il numero proposto
  era quasi sempre da correggere a mano. Su ZNUB4 proponeva 13 anche a un sentiero che passa
  cinquanta chilometri più a sud, accanto al 60.
- **Ogni vicino genera i propri candidati** (oc:8570, scartato in fase di analisi): sembra la
  formulazione naturale, ma è vuota — il vicino più prossimo genera tutti i numeri liberi e i
  successivi non arrivano mai al turno. Se un giorno qualcuno la ripropone, questo è il motivo per
  cui non funziona.
- **Numeri occupati in coda all'elenco** (oc:8570, scartato in review): risolveva il problema del
  codice che fa gruppo con se stesso, ma faceva trovare in fondo a cento voci il numero a chi
  cercava proprio di assegnare la variante di un sentiero esistente — l'opposto dello scopo. Il
  problema vero era un codice solo, non tutti.
- **Interruttore di configurazione per spegnere il criterio** (oc:8570, scartato): proposto per
  rendere il rollback un cambio nel `.env` invece di una manovra su due repo. Scartato dal dev.

## Quanto costa, e quando riguardarlo

Il calcolo delle distanze sta nel percorso di creazione di **ogni** istanza, e `reserve()` può
richiamare la proposta fino a cinque volte quando due istanze si contendono lo stesso numero.

Misurato su ZNUB4, il settore più popolato del database di sviluppo, 62 codici con geometria:
**~93 ms** contro gli ~1,6 ms della lettura che si faceva prima. Il piano è un `GroupAggregate` su
`Nested Loop Left Join` alimentato da `Index Scan`, senza `Seq Scan` — ma `ST_Distance` viene
calcolato riga per riga, senza indice KNN, quindi **il costo cresce linearmente con i codici del
settore**: intorno ai 500 codici si andrebbe verso i 750 ms per chiamata.

Se un settore dovesse crescere fino a lì, la strada già misurata è la variante planare, con la
cautela sull'ordine descritta sopra.

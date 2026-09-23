> Ticket: oc:8570

# Ordinare i numeri disponibili per vicinanza geografica

## Cosa cambia

I numeri disponibili di un settore smettono di essere restituiti in ordine numerico e vengono
ordinati **per vicinanza**: si guarda quali sentieri del settore stanno più vicini alla traccia in
esame, e dai loro numeri si derivano i candidati.

L'ordine serve in due punti, che lavorano su **insiemi diversi e restano diversi**: quello che
condividono è il criterio di ordinamento.

- **`propose()` cambia comportamento.** Oggi scorre i numeri da 0 a 99 e prende il primo libero
  che incontra; dopo, sceglie fra i numeri senza alcuna variante occupata **ordinati per cluster**
  e prende il primo di quell'ordine. Il criterio numerico sparisce, non resta accanto al nuovo
- **`numbersWithAvailableVariants()` cambia solo l'ordinamento, non ciò che seleziona.** Continua a
  includere i numeri già occupati che hanno almeno una variante libera — è così che l'operatore
  assegna `ZNUB413A` accanto a `ZNUB413` — e il Field `Select` dell'Action
  `ReplaceTrailCodeNumber` (oc:8569) non perde nessuna delle opzioni che offre oggi

## Perché

La numerazione dei sentieri segue la geografia dentro i numeri disponibili: la disponibilità è il
vincolo, la vicinanza è il criterio. Oggi il sistema rispetta il vincolo e ignora il criterio,
quindi il numero che propone è quasi sempre da correggere a mano.

Dalla call, Saba (~00:30): «magari il sistema mi ha proposto di dare il B283, ma io mi rendo conto
che a fianco ho il B211, il B212. Allora dico no, lo chiamo B213. Cioè, non essendoci
un'intelligenza che mi permette di dire "in quella zona hai usato la prima decina, quindi continua
quella numerazione", ha una logica applicativa banale, dice: ti do il primo disponibile».

È il secondo dei tre interventi elencati da Piccioli (~00:35): «vedere i sentieri vicini,
**migliorare l'algoritmo della scelta**, e darvi la possibilità di cambiare il codice assegnato».
Il primo è oc:8568, il terzo oc:8569: questo chiude la serie.

## La regola

Il confine resta il **settore**, individuato per intersezione geometrica come oggi: nessun raggio
da tarare, nessun parametro nuovo.

I numeri già usati nel settore si raggruppano in **cluster**, e l'algoritmo propone un numero del
cluster più vicino. Se serve un numero altrove, il validatore lo prende con l'Action
`ReplaceTrailCodeNumber`: **aprire una numerazione nuova è un gesto deliberato, non qualcosa che la
proposta automatica deve indovinare.**

1. i codici attivi del settore si raggruppano in cluster **per contiguità numerica**: 11, 12, 13 è
   un cluster; 16, 17 è un altro. Nessuna soglia, nessun parametro da tarare
2. si sceglie **il cluster più vicino** alla traccia in esame — la distanza è quella del suo codice
   più prossimo
3. i numeri liberi si ordinano per **distanza numerica** dai numeri di quel cluster, nelle due
   direzioni. **A parità vince il precedente**: attorno al cluster 11-13, con 10 e 14 entrambi
   liberi, esce il 10, poi il 14, poi il 9, poi il 15
4. l'ordine così ottenuto vale per **tutti** i numeri liberi del settore, quindi l'array resta
   completo per costruzione: un numero lontano dal cluster non sparisce, finisce in fondo

Il primo elemento è la proposta. Un solo cluster governa l'ordine: gli altri non entrano nel
calcolo, perché il caso «voglio iniziare una numerazione nuova» si risolve con la sostituzione
manuale, non con un ordinamento che provi a contemplarlo.

**L'ordine dev'essere deterministico**: a parità di distanza geografica fra due cluster decide il
numero più basso, così due chiamate consecutive restituiscono lo stesso array. Senza, il requisito
«stesso ordine nei due punti» non sarebbe verificabile — in un settore di sentieristica gli incroci
sono la norma, e due tracce che si toccano danno distanza zero.

**Se il settore non contiene sentieri si parte da 0**, cioè l'ordine coincide con quello numerico
di oggi.

## Requisiti

- [ ] I numeri già usati del settore si raggruppano in cluster per contiguità numerica
- [ ] Si sceglie il cluster più vicino alla traccia in esame; gli altri non entrano nel calcolo
- [ ] I numeri liberi si ordinano per distanza numerica dai numeri di quel cluster, nelle due
      direzioni; a parità vince il precedente
- [ ] **L'array resta completo**: l'ordinamento riguarda tutti i numeri liberi del settore, quindi
      nessuno sparisce. Ordinare non è filtrare
- [ ] L'ordine è deterministico: a parità di distanza geografica fra cluster decide il numero più
      basso, e due chiamate consecutive restituiscono lo stesso array
- [ ] `propose()` sceglie fra i numeri senza alcuna variante occupata e prende il primo
- [ ] `numbersWithAvailableVariants()` usa lo stesso ordinamento **mantenendo il proprio insieme**:
      i numeri con almeno una variante libera restano inclusi, e il Field `Select` dell'Action
      `ReplaceTrailCodeNumber` non perde nessuna delle opzioni che offre oggi
- [ ] Settore senza sentieri: si parte da 0, ordine numerico come oggi
- [ ] Il fallback alle varianti con lettera resta **esattamente com'è oggi**, non ordinato: i cento
      numeri coprono con ampio margine un settore reale (26 numeri distinti occupati su 100 nel
      settore più popolato del DB locale), quindi il caso non vale il costo
- [ ] Nessuna firma pubblica esistente del package cambia: i numeri senza variante occupata
      arrivano da un metodo nuovo
- [ ] Il criterio di ordinamento vive in un solo metodo, chiamato da entrambi i percorsi

## Rischi

- **Gira a ogni creazione di istanza, anche senza nessuno davanti.** Quando arriveranno le istanze
  dal SUS non ci sarà un operatore a correggere: una proposta sbagliata prenota comunque un numero,
  che resta riservato e lascia una riga nel registro. Mitigazione: oc:8569 è già rilasciato, quindi
  la correzione manuale esiste; questo ticket riduce la frequenza dell'errore, non lo elimina.
- **Costo del calcolo delle distanze nel percorso di creazione di ogni istanza.** Oggi `propose()`
  fa un'intersezione per il settore e una lettura dei codici; si aggiunge l'ordinamento per
  distanza su tutte le geometrie del settore. Mitigazione: una query sola, sul modello di
  `neighbourCodes()`, e misura su ZNUB4 — il settore più popolato del DB locale, 61 codici con
  geometria — prima e dopo. Da misurare il caso peggiore, non quello medio: `reserve()` richiama
  `propose()` fino a cinque volte in contesa, e un `ORDER BY` per distanza su geography non usa
  l'indice KNN.
- **La decina non è una regola scritta.** «In quella zona hai usato la prima decina» è una prassi,
  non un vincolo formale: l'ordinamento la approssima guardando i vicini, non può garantirla.
  Resta un suggerimento, e Bonfanti in call lo dice: «comunque sono suggerimenti che diamo. Poi
  l'operatore mette quello che vuole dalla select».
- **Codici senza geometria.** Un codice del registro privo sia di `ec_track_id` sia di
  `trail_application_id` non genera candidati. Non rompe nulla — `neighbourCodes()` già li esclude
  con `COALESCE(t.geometry, a.geometry) IS NOT NULL` — ma la sua zona resta senza suggerimenti.
- **Il sentiero potrebbe attraversare più settori.** Tutto si calcola sul settore individuato per
  intersezione, quello in cui la traccia si sviluppa di più. Se un domani l'operatore potesse
  assegnare il sentiero a un altro dei settori attraversati, i candidati sarebbero quelli del
  settore sbagliato. **Fuori scope**, ma è il primo punto da riprendere se quella possibilità verrà
  introdotta.

## Assunzioni

- **L'istanza ha sempre una geometria** (confermato dal dev, 22/09/2026). La colonna
  `trail_applications.geometry` è nullable nello schema e `wktOf()` dichiara `: string` senza
  controlli, ma il caso non si verifica: senza geometria `resolveSector()` fallirebbe già oggi,
  prima che questo ordinamento entri in gioco. Annotato nei follow-up, non gestito qui.

## Out of scope

- La sostituzione manuale del numero in sé, che è oc:8569: qui si cambia solo l'ordinamento
  applicato da `numbersWithAvailableVariants()`, il metodo che ne alimenta il Field `Select`
- La visualizzazione dei sentieri vicini sulla mappa, che è oc:8568
- Qualsiasi riassegnazione dei numeri già attribuiti
- L'ordinamento delle varianti: qui si ordinano i numeri
- La possibilità di assegnare il sentiero a un settore diverso da quello prevalente
- Il difetto di tipo di `wktOf()`, che appartiene al percorso a monte
- La rimozione di `availableNumbers()`: in un package condiviso «nessun call site qui» non
  significa «nessun call site», quindi il metodo resta
- L'ordinamento dei numeri in variante con lettera
- Un interruttore di configurazione per spegnere il criterio: deciso di non metterlo (dev,
  22/09/2026)

## Moduli toccati

Tutto in `wm-package`: il servizio del registro è del package e il criterio vale per ogni catasto
che lo monti. In `forestas` cambia solo il puntatore del submodule.

- `src/TrailRegistry/TrailRegistryService.php` — l'ordinamento per cluster è **scritto una volta
  sola**, in un metodo che riceve l'insieme dei numeri da ordinare e la geometria di riferimento.
  Lo chiamano entrambi i percorsi, su insiemi diversi: i numeri senza alcuna variante occupata per
  `propose()`, quelli con almeno una variante libera per `numbersWithAvailableVariants()`.
  Duplicarlo significherebbe che fra sei mesi qualcuno corregge il criterio in un punto solo, e il
  numero proposto non sarebbe più il primo del Field `Select`. `propose()` restituisce il primo
  elemento dell'array ordinato, mantenendo intatti `resolveSector()` e il ciclo di fallback sulle
  varianti
- `src/TrailRegistry/Models/Concerns/ComposesTrailRegistryMap.php` — `neighbourCodes()` ha già la
  query che unisce codice e geometria per settore con `COALESCE(t.geometry, a.geometry)`: è il
  punto da cui derivare l'ordinamento per distanza, oggi `ORDER BY c.number, c.variant`
- `src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php` — `numberOptions()` passa oggi il
  solo `fullCode` a `numbersWithAvailableVariants()`: deve fornire anche la geometria del
  `TrailRegistryCode` in esame, ricavata come fa `neighbourCodes()` con
  `COALESCE(t.geometry, a.geometry)`
- Test in `tests/Feature/TrailRegistry/` — due cluster in zone distanti con il più vicino che
  governa l'ordine, cluster con liberi equidistanti prima e dopo, settore con un solo codice,
  settore vuoto con ordine numerico, completezza dell'array, determinismo su due chiamate
  consecutive, settore esaurito

### Da verificare durante l'implementazione

`availableNumbers()` **non ha call site**: né nel package né in `forestas/app`. L'overview del
ticket lo indicava come il metodo da modificare, ma ciò che alimenta la select è
`numbersWithAvailableVariants()`, e `propose()` non usa nessuno dei due. Va deciso se il metodo si
elimina o se diventa il punto in cui vive il nuovo ordinamento.

> Ticket: oc:9061
> Overview approvata il 2026-11-26 · impronta `7a777bcfbf8e81e3d67cb691b7b7304c84427833`

# Notes — Soggetti privati (package)

## Modifiche ai requisiti

### 2026-11-26 — nome ed e-mail del privato passano da facoltativi a vietati
- **Requisito:** [R2](overview.md#r2)
- **Effetto:** aggiunto
- **Decide:** dev
- **Perché:** la sola facoltà di omettere il dato non impedisce al SUS di mandarlo, e il vincolo
  di protezione dei dati sarebbe rimasto affidato a cosa decide di mandare la controparte.
- **Scartato:** `nullable` e basta, che era la forma descritta nel ticket al momento della
  creazione. Scartata perché un privato con il nome sarebbe stato accettato e salvato in
  `api_data`.
- **Testo nuovo:**
  > Un soggetto con `subject: person` che porta `name` o `email` con un valore non vuoto riceve
  > `422`, e l'errore nomina il ruolo e la posizione nella lista contata da zero
  > (`surveying_subjects.0.name`). Un segnaposto testuale è un valore come un altro e rientra in
  > questo caso. Il rifiuto vale sia sui dati in ingresso dal canale, sia alla validazione della
  > conferma su un `api_data` già salvato.

### 2026-11-26 — la versione dello schema smette di seguire i ticket
- **Requisito:** [R6](overview.md#r6)
- **Effetto:** aggiunto
- **Decide:** dev
- **Perché:** `version()` era salita a `'2'` con oc:9038 e a `'3'` con oc:9039, due bump in due
  ticket della stessa famiglia, su un canale mai andato in uso: un contatore di ticket, non una
  versione. Un contratto che nessuno usa non si versiona.
- **Scartato:** alzarla a `'4'`, che è quello che il docblock di `version()` dice di fare quando
  cambia «come si valida». Scartato perché quel criterio ha senso solo quando esistono payload
  accettati con regole diverse da distinguere, e qui non esistono.
- **Testo nuovo:**
  > `version()` torna a `'1'` e resta ferma finché il canale è in costruzione. Il docblock dice
  > che si alza quando il contratto è in uso e un payload accettato con regole diverse va distinto
  > da un altro, non a ogni cambiamento delle regole.

## Divergenze dal piano, task per task

### Task 1: Le otto regole dei soggetti

Il piano prevedeva che i test esistenti continuassero a passare, «perché un ente con nome ed
e-mail è ancora valido». Non è andata così: **dodici test sono falliti**, perché i payload di
prova del dominio mandano un privato *con* il nome — a partire da `completeApplicationPayload()`
in testa a `PatchApplicationRequestTest`, che valeva per quasi tutti. La forma
`['name' => 'Mario Rossi', …, 'subject' => 'person']` è stata portata a `['subject' => 'person']`
in quattro file: `PatchApplicationRequestTest`, `PatchApplicationTest`, `ApproveTrailApplicationTest`,
`TrailApplicationApiDataTest`. Il test `accetta un soggetto persona fisica senza la chiave email`
è diventato `accetta un soggetto persona fisica senza nome ne email`, perché il caso che fissa è
cambiato.

Non è un effetto collaterale da nascondere: quei payload erano la forma che la pagina insegnava
fino a ieri, e il fatto che fossero ovunque è la misura di quanto il nome di un privato fosse dato
per scontato.

### Task 2: Il messaggio che il rifiuto porta con sé

I due test asserivano il testo italiano del messaggio. La suite del package gira in locale `en`,
quindi esce la voce di `resources/lang/en.json`: l'assertion è stata legata alla chiave con
`__()`, che è indipendente dalla lingua e verifica quello che conta davvero — che esca il
messaggio dello schema invece di quello di default di `prohibited_if`.

## Bug trovati

Nessuno finora.

## Decisioni

### 2026-11-26 — i messaggi di validazione dello schema vivono nello schema
Il messaggio del rifiuto deve uscire su due percorsi: `PatchApplicationRequest::messages()` per il
canale e `Validator::make()` dentro `TrailRegistryService::invalidValues()` per la conferma, che
oggi non vede i messaggi della Request. Invece di scriverlo due volte nasce
`ApplicationSchema::validationMessages()`, che porta con sé anche la voce `in` che oggi sta nella
Request.
**Scartato:** duplicare la riga nei due punti, che si sarebbero disallineati alla prima modifica.

### 2026-11-26 — `nullable` sul nome
`name` non ha `nullable`, a differenza dell'e-mail: senza, la regola `string` verrebbe applicata
anche a `null` e `{"name": null, "subject": "person"}` sarebbe rifiutato con «deve essere una
stringa», cioè un errore che non c'entra con la regola nuova. Emerso dalla challenge.

## Follow-up

### 2026-11-26 — la regola di privacy di Forestas vive nel package condiviso
`itemRules()` è un metodo unico di sedici righe: uno shard che volesse il nome dei privati
dovrebbe riscriverlo per intero. Oggi nessuno shard ridefinisce `ApplicationSchema`, quindi non
fa danno, ma la fonte della regola è la CR di questo cliente e il contratto è di tutti. Si
guarderà se e quando un secondo shard accenderà il Catasto.

> Ticket: oc:9039
> Overview approvata il 2026-11-25 · impronta `6e4df9c12b2083a6730b32029325bb4f0710f086`

# Notes — Soggetti dell'istanza come liste di persone (wm-package)

## Modifiche ai requisiti

### 2026-11-25 — i campi non dichiarati non si ignorano più: si rifiutano
- **Requisito:** [R4](overview.md#r4)
- **Effetto:** modificato
- **Decide:** dev
- **Perché:** stamattina la scelta era «li ignoriamo e basta», presa sul comportamento storico del
  canale, che conserva ciò che lo schema non dichiara (oc:8960). Durante la review è emerso che un
  soggetto con una chiave in più — `cf` compreso, cioè proprio il codice fiscale che questo ticket
  toglie per privacy — viene accettato e salvato in silenzio. Il dev ha allargato la regola a tutto
  il canale: «ogni cosa che manda il SUS deve essere validata e se mette una chiave o un valore non
  concordato deve andare in errore con la spiegazione».
- **Scartato:** chiudere solo l'oggetto del soggetto, lasciando il primo livello permissivo. Avrebbe
  dato due comportamenti diversi nello stesso payload, e a Engineering un `201` silenzioso proprio
  sui tredici campi che devono smettere di mandare.
- **Testo nuovo:**
  > I campi delle categorie e dei comuni spariscono dallo schema: i campi 42, 43, 44, 47, 48, 49,
  > 50, 51, 53, 54, 55, 56 e 58 non sono più dichiarati, e un payload che li contenga viene
  > rifiutato con un errore che li nomina, come ogni altra chiave non dichiarata (vedi
  > [R8](overview.md#r8)).

### 2026-11-25 — requisito nuovo: solo chiavi e valori concordati
- **Requisito:** [R8](overview.md#r8)
- **Effetto:** aggiunto
- **Decide:** dev
- **Perché:** «ogni cosa che manda il SUS deve essere validata e se mette una chiave o un valore non
  concordato deve andare in errore con la spiegazione» (25/11/2026). Vale al primo livello del
  payload e dentro i campi strutturati. Il principio nel dominio esisteva già ma applicato a mano e
  solo a `difficulty`, che rifiuta una chiave non attività con il messaggio «Il valore selezionato
  per difficulty non è valido», che non dice quale chiave.
- **Scartato:** la regola nativa `array:name,email,subject` di Laravel. Rifiuta le chiavi in più ma
  il messaggio è «Il campo managing_subjects.0 deve essere una lista», che non nomina la chiave: non
  soddisfa «con la spiegazione».
- **Testo nuovo:**
  > Una chiamata che porta una chiave non dichiarata dallo schema viene rifiutata con un errore che
  > nomina la chiave, sia al primo livello del payload sia dentro un campo strutturato. Per un
  > soggetto: «Il campo `managing_subjects.0` non ammette la chiave `cf`: un soggetto ha solo name,
  > email e subject». Lo stesso vale per un valore fuori dall'elenco ammesso.


## Divergenze dal piano, task per task

Nessuna, per ora.

## Bug trovati

Nessuno, per ora.

## Decisioni

### 2026-11-25 — i valori restano a `in:`, la Rule fa le sole chiavi
Il dev aveva chiesto che la stessa classe controllasse «identifier e valore associato». Una
consulenza su Fable, verificata nei punti che contano, ha mostrato che spostare i valori dentro la
Rule costa: Scramble ricava l'`enum` del documento OpenAPI proprio da `in:`, il percorso
`managing_subjects.0.subject` arriva gratis, e la semantica di `nullable`/`sometimes` andrebbe
riscritta. `in:` con un messaggio nostro elenca già i valori concordati, quindi il requisito è
soddisfatto comunque.
- **Scartato:** una Rule che tiene chiavi e valori. Avrebbe dichiarato l'elenco dei valori due
  volte — nella Rule e nella descrizione della pagina — perché per Scramble una Rule custom è
  opaca.


### 2026-11-25 — `themes` chiuso sui dieci tematismi validati
Cercando l'ultimo campo che sfuggiva a [R8](overview.md#r8) è emerso che `themes` (campo 45,
*Tematismo*) accettava qualunque contenuto: `['nullable','array']` e nessuna regola sugli elementi.
L'overview di oc:8960 lo dava come «elenco inesistente, da proporre a Forestas», ma l'elenco nel
frattempo è stato deciso: dieci identifier, validati da Alessio Saba e riportati da Alessio Piccioli
il 23/11/2026 — «ha validato questa lista e basta». Nella call del 19/11 era già stato fissato il
formato: «noi abbiamo bisogno poi dell'array degli identifier e basta, cioè i label chiaramente non
servono» (Piccioli), e Moreno Santucci per il cliente: «se li trasmettiamo, ignorate comunque i
label nostri, prendete solo l'identificativo».
I dieci, dati dal dev: `history-archaeology`, `mining-geology`, `transhumance`, `spirituality`,
`woods-forests`, `flora-fauna`, `waters`, `sea-coast`, `villages`, `rural-landscape`.
- **Scartato:** lasciarlo libero, o toglierlo dallo scambio come il codice fiscale. Il campo ha un
  elenco validato dal cliente: chiuderlo costa una costante.
- **Da sapere:** l'Excel del format validato descrive il campo 45 come testo libero, «Max 600».
  Quella riga è precedente alla decisione sugli identifier e non è stata riscritta: vale la
  decisione del 19-23/11.

### 2026-11-25 — un tetto alla lunghezza dei campi di testo
Ultimo buco rispetto a [R8](overview.md#r8): `from`, `to`, `intermediate_stop`,
`overlapping_routes` e i `name` dei soggetti erano `string` senza `max`, quindi il canale accettava
e depositava una stringa da megabyte. Il nome del sentiero aveva già `max:255`.
Cercata la fonte nell'Excel validato del format (`SUS-EVO_ANALOGHI_FORES_02_Format_Istanza
accatastamento_VALIDATA.xlsx`, foglio «DATI NUOVO SENTIERO»): **l'unico limite dichiarato è
`Max 600` sul campo 45**, che nel frattempo è diventato una lista di identifier. Per gli altri campi
il format non dice nulla.
Scelti quindi: `max:255` su toponimi e nomi dei soggetti, lo stesso che il nome del sentiero ha già;
`max:600` su `overlapping_routes`, unico campo descrittivo libero rimasto, prendendo il solo numero
che il format nomina.
- **Decide:** io, dichiarandolo al dev. Non è una regola del format né una decisione di call: se
  Forestas vorrà limiti diversi si cambia una costante.
- **Da sapere:** lo stesso Excel conferma i numeri dei campi messi sui soggetti — 47 gestore,
  49 rilevatore, 53 manutentore, 58 custode.


### 2026-11-25 — niente Rule object per la e-mail condizionata
L'overview dava per probabile una Rule object sul modello di `DifficultyMatchesActivities`.
Provato con il validator reale dentro `php-forestas`:
`required_if:managing_subjects.*.subject,organization` risolve l'indice fratello e produce il
messaggio «Il campo managing_subjects.0.email è obbligatorio quando managing_subjects.0.subject è
organization». Le regole Laravel normali bastano, e portano anche ruolo e posizione nel messaggio.
- **Scartato:** la Rule object. Agganciata al campo produrrebbe un errore sulla chiave
  `managing_subjects` **senza indice**, contraddicendo [R5](overview.md#r5).

### 2026-11-25 — i campi vecchi non si rifiutano
Portato al dev che [R4](overview.md#r4), come scritto nel ticket, era falso: `apiData()` fa
`Arr::except($this->getInputSource()->all(), NON_SCHEMA_INPUT)`, quindi un campo non dichiarato
dallo schema finisce comunque in `api_data`. Decisione del dev: «li ignoriamo e basta».
[R4](overview.md#r4) riscritto per dire solo che i campi spariscono dallo schema.
- **Scartato:** una regola che facesse cadere l'istanza con `422` se contiene uno dei tredici
  campi spariti. Sarebbe codice che vive solo finché Engineering si adegua, e che poi nessuno
  toglie.

### 2026-11-25 — nessun requisito sulle istanze aperte sotto lo schema v2
Verificato che un'istanza creata prima del rilascio non sarebbe più confermabile:
`missingForConfirmation()` pretenderebbe i campi nuovi, `api_data` si scrive solo alla creazione e
il canale non ha una rotta di update. Portato al dev come rischio; risposta: «i db sono di staging
ogni sera vengono brasati». Nessun requisito, nessuna nota nei Rischi.

### 2026-11-25 — `'<campo>.*' => ['array']` aggiunto in fase di piano
Non era nell'overview. Serve a rifiutare una lista di stringhe (`["Comune di Oliena"]`, la forma
del campo 54 di oggi) con un messaggio leggibile invece che con tre errori su chiavi inesistenti.

### 2026-11-25 — niente `list` né chiusura delle chiavi in più sui soggetti
Due revisori indipendenti hanno rilevato che un soggetto con chiavi in più — anche `cf` — viene
accettato e conservato, e che una mappa chiave→soggetto passa al posto di una lista
(`array` e `min:1` contano le chiavi, non l'indicizzazione). È coerente con
[R4](overview.md#r4): il canale conserva da sempre i campi che lo schema non dichiara. Non
corretto in questo ciclo perché chiuderlo (`array:name,email,subject` sull'elemento e `list` sul
campo) cambia il contratto con Engineering.
- **Scartato:** chiuderlo subito in fase di review. La decisione spetta al dev, e il punto è
  portato nei follow-up perché proprio il codice fiscale è il dato che questo ticket toglie per
  privacy: se Engineering lo mandasse dentro l'oggetto, lo salveremmo in silenzio.

### 2026-11-25 — cosa hanno trovato i due revisori, e cosa ho corretto
Nessun **Critical**: il codice di produzione soddisfa [R1](overview.md#r1)–[R7](overview.md#r7). I
difetti erano nei test, e sono stati corretti tutti nella stessa passata:
- il test «non considera obbligatori i campi che dipendono da un altro campo» era **vacuo**: i sei
  campi elencati non esistono più, `array_intersect` era vuoto per costruzione;
- il test della factory dichiarava «produce un payload che lo schema accetta», mentre il payload
  fallisce su otto chiavi scalari: rinominato e reso vero su tutte e quattro le liste;
- il test della sezione dei soggetti **non delimitava la sezione** (cercava otto spazi di
  indentazione che l'heredoc non conserva), quindi guardava tutto il resto della pagina;
- il test fra esempio `curl` e modulo di prova confrontava i soli nomi dei campi: ora estrae i due
  payload, li confronta e li valida contro le regole dello schema;
- tre `#[BodyParameter]` citavano la chiave d'errore del gestore invece della propria;
- la sezione dei soggetti si prendeva come sottosezioni `activities` e l'attività primaria.

## Follow-up

- **Le descrizioni dei campi del modulo FORES_02 non dovrebbero stare qui.** I
  `#[BodyParameter]` di `StoreApplicationController` descrivono campo per campo un modulo che è di
  Forestas, dentro il package condiviso: li ho scritti io con oc:8968 (commit `d066dc0c`) contro
  una divisione già decisa col dev, che il 25/11/2026 l'ha definito «un grave errore» e ha deciso
  di non correggerlo in questo ticket — «ormai continuiamo così poi si correggerà». La correzione
  riguarda i quindici campi già documentati così, non solo i quattro di oc:9039.
- **`"email": ""` su un soggetto `person` viene accettata** e depositata com'è: `nullable` non
  intercetta la stringa vuota e `required_if` non scatta. Non è un errore, ma è un valore che
  nessuno ha deciso di ammettere.

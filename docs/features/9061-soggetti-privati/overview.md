> Ticket: oc:9061
> Stato: chiusa il 2026-11-26 · approvata il 2026-11-26

# Soggetti privati: il canale rifiuta i dati personali delle persone fisiche

## Cosa cambia

Per un soggetto dichiarato **persona fisica** (`subject: person`) nome ed e-mail non si mandano
più, e il canale li **rifiuta** se arrivano valorizzati. Il soggetto resta nella lista del suo
ruolo portando la sola informazione che serve: che quel ruolo è coperto da un privato.

```json
"surveying_subjects": [ { "subject": "person" } ]
```

Per un **ente** (`subject: organization`) non cambia nulla: nome ed e-mail restano obbligatori.

```json
"managing_subjects": [
  { "name": "Comune di Domusnovas", "email": "protocollo@comune.example.it", "subject": "organization" }
]
```

Il divieto non è solo una facoltà di ometterli. Una lista che contiene
`{"name": "Mario Rossi", "subject": "person"}` riceve `422` su `surveying_subjects.0.name`, e
lo stesso vale per un **segnaposto testuale**: la stringa «campo oscurato per motivi di privacy»
è un valore come un altro e viene rifiutata insieme agli altri.

La regola vive in `ApplicationSchema::itemRules()`, che è la stessa fonte per la patch del
canale e per la validazione alla conferma: vale quindi in entrambi i momenti, anche su un
`api_data` già salvato.

## Perché

I dati identificativi di una persona fisica non devono finire nel Catasto. Lo dice la CR, e
lo conferma il documento dei 34 quesiti alla riga 24, dove la risposta elenca fra le soluzioni
«conservare nome ed e-mail della persona fisica solo sul SUS» e lo stato è «Confermato».

In call il 23/11/2026 Alessio Piccioli aveva proposto di mandare un segnaposto al posto dei
dati veri — «magari in quei tre campi lì uno può anche mettere il placeholder "campo oscurato
per motivi di privacy", nel nome e nell'email» — e, più avanti, «però il placeholder ce lo
dovete mettere». La proposta non è stata accolta: una stringa convenzionale andrebbe
riconosciuta nel codice, documentata e tenuta allineata fra noi e il SUS per sempre, e
porterebbe esattamente l'informazione che `subject: person` porta già da sé.

Resta aperta la parte del nome. La e-mail è già condizionata al tipo di soggetto dal lavoro
precedente (oc:9039), ma solo come facoltativa: un canale che *permette* di non mandare il
dato personale non *impedisce* che arrivi, e un vincolo di protezione dei dati che dipende da
cosa decide di mandare la controparte non è un vincolo.

Chi in Forestas deve sapere **chi** sia quel privato apre la pratica sul SUS: il riferimento è
sull'istanza, nella colonna `reference`, obbligatorio su questo shard.

## Punto di partenza

Letto sul codice il 26/11/2026, sul branch `feature/sus-2026-seconda-revisione`, a soggetti
(oc:9039) e patch (oc:9056) già realizzati.

**Le regole dei soggetti stanno in `ApplicationSchema::itemRules()`** e sono quattro righe per
ciascuno dei quattro ruoli (`src/TrailRegistry/Schema/ApplicationSchema.php:166-181`). Per il
gestore:

```php
'managing_subjects.*'         => ['array', new OnlyAllowedKeys(static::SUBJECT_KEYS)],
'managing_subjects.*.name'    => ['required', 'string', 'max:255'],
'managing_subjects.*.email'   => ['nullable', 'required_if:managing_subjects.*.subject,organization', 'email'],
'managing_subjects.*.subject' => ['required', 'in:organization,person'],
```

Le righe del nome sono la 167, la 171, la 175 e la 179; quelle dell'e-mail la 168, la 172, la
176 e la 180. `SUBJECT_KEYS` vale `['name', 'email', 'subject']` (`:91`) e la Rule
`OnlyAllowedKeys` rifiuta un oggetto che porti chiavi diverse: un soggetto può averne meno di
tre, non di più.

**Il nome non ha `nullable`, l'e-mail sì**, ed è il motivo per cui oggi `{"email": null}` passa.
Senza `nullable`, la regola `string` viene applicata anche a `null` e un `{"name": null}` verrebbe
rifiutato con «deve essere una stringa» — un errore che non c'entra con la regola nuova. Il nome
lo prende insieme al resto.

**La versione dello schema è un contatore di ticket, non una versione.** `version()` restituisce
`'3'` (`:99`), salita a `'2'` con oc:9038 e a `'3'` con oc:9039: due bump in due ticket della
stessa famiglia, su un canale che non è mai stato in uso. Finisce in `api_data` come
`_schema_version` (`:84`) e non esce dal canale — `ShowApplicationController.php:248` la toglie
dalla lettura, `PatchApplicationRequest::PROTOCOL_KEYS` la esclude dal corpo.

**Nessuno legge il `name` di un soggetto.** I quattro campi finiscono in `api_data`, colonna
`jsonb` con cast `array` (`src/TrailRegistry/Models/TrailApplication.php:135`), scritta così
com'è da `writeApiData()`/`mergeApiData()` senza normalizzazioni. In Nova diventano un `Code`
JSON costruito da `PropertiesPanel` (`src/Nova/Fields/PropertiesPanel.php:531-534`); l'API in
lettura restituisce `api_data` tale e quale (`ShowApplicationController::submittedData()`).
Non esistono viste, PDF, export, indicizzazione Elasticsearch, notifiche o log che leggano i
soggetti: un nome assente non rompe nulla a valle.

**La validazione alla conferma usa le stesse regole.** `TrailRegistryService::invalidValues()`
(`src/TrailRegistry/TrailRegistryService.php:1425-1440`) applica `$schema->rules()`
all'`api_data` salvato, e `closeCompilation()` (`:958-962`) solleva
`ApplicationIncompleteException` se ne esce qualcosa. I campi di `api_data` in Nova sono
`onlyOnDetail()` (`src/TrailRegistry/Nova/TrailApplication.php:225-229`), quindi un valore
rifiutato lì non si corregge a mano: lo rimanda il SUS con una patch.

**La documentazione dei soggetti sta nella patch, non nella creazione.** I quattro
`#[BodyParameter]` sono in `PatchApplicationController.php:172-176`, e ciascuno contiene già la
frase sull'e-mail: «La `email` è obbligatoria e deve essere un indirizzo valido quando
`subject` è `organization`; quando è `person` può mancare o valere `null`».

**I messaggi di validazione del canale sono in italiano** (`APP_LOCALE=it` nello shard) e
`PatchApplicationRequest::messages()` (`:263`) oggi ne personalizza uno solo, quello di `in`.
La traduzione di `prohibited_if` esiste già in `lang/it/validation.php:142` dello shard.

**I test mandano sempre soggetti completi.** `tests/Unit/TrailRegistry/ApplicationSchemaTest.php`
› `condiziona la e-mail del soggetto alla sua natura` (`:181-189`) asserisce oggi che
`managing_subjects.*.name` contenga `required` e `string`: è il punto in cui la regola vecchia è
fissata, e va riscritto. Gli altri test dei soggetti sono in `PatchApplicationRequestTest.php`
(`:140`, `:384`, `:400`, `:473`), `PatchApplicationTest.php:144` e
`TrailApplicationApiDataTest.php:100-130`.

## Requisiti

### <a id="r1"></a>**R1** — Un privato è accettato senza nome e senza e-mail

Un soggetto con `subject: person` è accettato sia quando le chiavi `name` ed `email` mancano
del tutto, sia quando valgono `null`, su tutti e quattro i ruoli.

*Fonte:* documento dei 34 quesiti, riga 24, risposta «Confermato» — «conservare nome ed e-mail
della persona fisica solo sul SUS».
**Verifica:** [ApplicationSchemaTest.php](../../../tests/Unit/TrailRegistry/ApplicationSchemaTest.php) › `vieta i dati personali del soggetto privato e li pretende dall ente` e [PatchApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/PatchApplicationRequestTest.php) › `accetta un privato senza nome ne e-mail su tutti e quattro i ruoli`, `accetta un privato con nome null o stringa vuota`

### <a id="r2"></a>**R2** — Un privato con nome o e-mail valorizzati è rifiutato

Un soggetto con `subject: person` che porta `name` o `email` con un valore non vuoto riceve
`422`, e l'errore nomina il ruolo e la posizione nella lista contata da zero
(`surveying_subjects.0.name`). Un segnaposto testuale è un valore come un altro e rientra in
questo caso. Il rifiuto vale sia sui dati in ingresso dal canale, sia alla validazione della
conferma su un `api_data` già salvato.

*Fonte:* decisione del dev del 26/11/2026, presa sapendo che la sola facoltà di omettere il dato
non impedirebbe al SUS di mandarlo. La richiesta opposta di Alessio Piccioli in call il
23/11/2026 — «però il placeholder ce lo dovete mettere» — non è stata accolta.
**Verifica:** [PatchApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/PatchApplicationRequestTest.php) › `rifiuta il nome di un privato, segnaposto compreso`, `rifiuta la e-mail di un privato` e, per la conferma, [ConfirmCompilationTest.php](../../../tests/Feature/TrailRegistry/ConfirmCompilationTest.php) › `spiega perche il dato di un privato non e ammesso anche alla conferma`

### <a id="r3"></a>**R3** — Un ente continua a richiedere nome ed e-mail

Un soggetto con `subject: organization` senza nome, o senza e-mail, o con un'e-mail non valida,
è rifiutato con `422` che nomina ruolo e posizione.

**Verifica:** [PatchApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/PatchApplicationRequestTest.php) › `pretende nome ed e-mail dall ente`, `segnala solo subject quando il soggetto non dichiara la propria natura`

### <a id="r4"></a>**R4** — Il rifiuto spiega perché, e dove si recupera il dato

Il messaggio del `422` di [R2](#r2) non si limita a nominare la regola: dice che il dato di un
privato non si trasmette al Catasto e che si recupera dal SUS attraverso il riferimento della
pratica.

*Fonte:* decisione del dev del 26/11/2026 — chi legge quel messaggio è Engineering, che in call
aveva chiesto l'opposto.
**Verifica:** [PatchApplicationRequestTest.php](../../../tests/Feature/TrailRegistry/PatchApplicationRequestTest.php) › `spiega perche il dato di un privato non e ammesso` e [ConfirmCompilationTest.php](../../../tests/Feature/TrailRegistry/ConfirmCompilationTest.php) › `spiega perche il dato di un privato non e ammesso anche alla conferma`

### <a id="r5"></a>**R5** — La documentazione del canale descrive la regola

I quattro `#[BodyParameter]` dei ruoli di soggetti dicono che per un `person` nome ed e-mail non
si mandano e che se arrivano la risposta è `422`, con la stessa precisione con cui oggi
descrivono la regola dell'e-mail. Gli esempi dei ruoli che mostrano un privato non portano più
il nome.

**Verifica:** [PatchApplicationController.php](../../../src/Http/Controllers/Api/TrailRegistry/PatchApplicationController.php) › i quattro `#[BodyParameter]` dei ruoli di soggetti; che la pagina generata ne rispecchi la forma lo verifica forestas tests/Feature/Sus/SusApiDocumentationTest.php › `pubblica la forma vera degli elementi di una lista di soggetti`

### <a id="r6"></a>**R6** — La versione dello schema smette di seguire i ticket

`version()` torna a `'1'` e resta ferma finché il canale è in costruzione. Il docblock dice che
si alza quando il contratto è in uso e un payload accettato con regole diverse va distinto da un
altro, non a ogni cambiamento delle regole.

*Fonte:* decisione del dev del 26/11/2026 — un contratto che nessuno usa non si versiona, e i due
bump precedenti (oc:9038, oc:9039) contavano ticket.
**Verifica:** [ApplicationSchemaTest.php](../../../tests/Unit/TrailRegistry/ApplicationSchemaTest.php) › `tiene ferma la versione dello schema finche il canale e in costruzione`

## Rischi

**La regola va scritta su quattro ruoli, non su uno.** Dimenticarne uno non rompe nessun test
esistente, perché oggi il nome è obbligatorio ovunque e i test mandano soggetti completi.
Mitigazione: un test che copra tutti e quattro i ruoli, non solo il gestore.

**Due privati nello stesso ruolo diventano indistinguibili** nel dato che riceviamo: restano due
elementi della lista, si distinguono per posizione e nient'altro. È la conseguenza voluta di non
ricevere dati personali, e i dati veri sono sul SUS; va scritto nella pagina delle API perché
nessuno lo scambi per una perdita accidentale.

## Out of scope

**Un servizio che restituisca i dati dei privati.** Moreno Santucci lo aveva ipotizzato in call
il 23/11/2026 — «questo significa che anche noi dobbiamo esporre un servizio per permettervi di
recuperare questi dati» — ma Forestas li prende dal SUS a mano, con il riferimento della pratica.

**La leggibilità dei soggetti in Nova**, che ha un ticket suo e riguarda tutti i campi
dell'istanza.

**Il codice fiscale**, già tolto dallo scambio con oc:9039.

## Moduli toccati

Toccati davvero, da `git diff --name-only`:

- `src/TrailRegistry/Schema/ApplicationSchema.php` — le otto regole `*.name` e `*.email` di
  `itemRules()`, il nuovo `validationMessages()`, e `version()` con il suo docblock
- `src/Http/Requests/TrailRegistry/PatchApplicationRequest.php` — `messages()` delega allo schema
- `src/TrailRegistry/TrailRegistryService.php` — `invalidValues()` passa i messaggi dello schema
  al proprio Validator
- `src/Http/Controllers/Api/TrailRegistry/PatchApplicationController.php` — i quattro
  `#[BodyParameter]` dei ruoli
- `resources/lang/{it,en,de,es,fr}.json` — la voce del messaggio del rifiuto, nelle cinque lingue
- `tests/Unit/TrailRegistry/ApplicationSchemaTest.php`,
  `tests/Feature/TrailRegistry/PatchApplicationRequestTest.php`,
  `tests/Feature/TrailRegistry/ConfirmCompilationTest.php` — i test nuovi
- `tests/Feature/TrailRegistry/{PatchApplicationTest,ApproveTrailApplicationTest,TrailApplicationApiDataTest}.php`
  — i payload di prova portati alla forma nuova

La parte di documentazione rivolta a chi integra — la pagina delle API e il modulo di prova —
sta nello shard: `forestas/docs/features/9061-soggetti-privati/overview.md`.

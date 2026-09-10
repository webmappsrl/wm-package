> Ticket: oc:8489

# Notes — Identificazione automatica del sentiero, codice REI

## Stato al 08/09/2026

Pianificazione interrotta a fine giornata. **Overview completa e approvata**, rivista dopo la challenge e dopo la lettura di entrambe le trascrizioni.

**Prossimo passo: la stima** (obbligatoria, il ticket è di tipo Feature), poi il piano di implementazione. Nessuna riga di codice scritta, nessun branch creato, nessun commit.

Ticket su Orchestrator: assegnato a Giuseppe, `estimated_hours` = **6** (scritto il 09/09/2026). Lo stato letto via API il 09/09 è `todo`, non `progress` come annotato l'08/09.

## Stima (09/09/2026)

Proposta dalla scomposizione: 2.82h misurate di pianificazione + 12h stimate = 15h, confidenza bassa (prima entità del suo genere nel package). **Il dev ha fissato il totale a 6h**, che è la stima valida. Lo scarto è deliberato e va tenuto presente: se l'implementazione sfonda le 6h, la causa più probabile è uno fra il vincolo `unique` parziale su PostgreSQL, il ritentativo sul perdente della concorrenza, o il rapporto della prova a vuoto sui 576 codici reali — le tre voci senza precedente locale da cui copiare.

## Sessione 09/09/2026 — modello dell'istanza definito

Overview aggiornata: sezione «Il modello dell'istanza» riscritta, più tre sezioni nuove (conversione istanza→sentiero, discriminante sentiero/itinerario, provenienza della prenotazione).

Decisioni prese, tutte del dev:

1. **`TrailApplication` / `trail_applications`** — `application` è il termine inglese per una domanda a un'amministrazione; `instance` scartato come falso amico
2. **Struttura**: `geometry` + `properties` JSON per i metadati, `status` come colonna con enum e indice, `user_id` di chi inserisce
3. **`user_id`** = client API quando arriva da API, utente autenticato quando nasce d'ufficio. Mai il proponente (non ha account), mai l'operatore che istruisce
4. **Nessun campo `kind`**: l'istanza è per definizione la domanda di un sentiero. Ne segue che alla conferma il tipo `sentiero` va attaccato dalla conversione
5. **`source` (`api`/`office`) sul registro**, non sull'istanza — le prenotazioni d'ufficio non hanno istanza. Il caricamento iniziale è `office`, non un terzo valore `import`
6. **Nel package non deve comparire la parola SUS**: è il nome dello sportello sardo, il codice servirà Lombardia e Toscana
7. **Il vincolo di controllo va riscritto** legandolo a `source`: `api`+`riservato` esige l'istanza, `office`+`riservato` no. La forma scritta in overview prima di oggi avrebbe rifiutato una riga legittima
8. **Le prenotazioni SUS sono le righe `riservato` del registro**, nessuna terza tabella: l'istanza ha una relazione inversa, non una copia del numero
9. **Le 6 tracce senza tipo diventano sentieri**, criterio «ha un `ref`» — nessun itinerario ne ha
10. **Migrazione delle `in_pre_accatastamento` a istanze: rinviata** su decisione del dev. Se poi si decide che erano istanze, i loro codici passano da `assegnato` a `riservato` con un `UPDATE`: il numero non si tocca, il rinvio non costa nulla di irreversibile
11. **Due risorse Nova in forestas**, `Sentieri` e `Itinerari`, che espongono lo stesso modello `EcTrack` con `indexQuery()` filtrata e `uriKey()` distinti — non due view SQL, e non nel package (il filtro è su un identificatore sardo). Più una voce di residuo per chi resta senza tipo, perché il dato arriva da Drupal e l'etichetta può tornare a mancare

## Dati misurati il 09/09/2026

Il re-import ha cambiato i conteggi rispetto all'08/09: **767** `ec_tracks` (era 791), **580** con `ref` (era 576).

**Per tipo:**

| tipo | tracce | con `ref` | senza `ref` | km medi |
|---|---:|---:|---:|---:|
| `sardegnasentieri:type:sentiero` | 614 | 574 | 40 | 5.1 |
| `sardegnasentieri:type:itinerario` | 147 | 0 | 147 | 29.1 |
| *(nessun tipo)* | 6 | 6 | 0 | 3.7 |

**Per `stato_validazione`:** `in_pre_accatastamento` 282 (280 con `ref`), `in_revisione_validazione` 236 (138), `percorribile` 192 (139), `non_percorribile` 23 (16), vuoto 19 (0), `non_verificato` 14 (6), `validato` 1 (1).

**Settori attraversati** (`ST_Intersects` sui 63 settori con `full_code`):

| settori | sentieri numerati | tracce senza `ref` |
|---:|---:|---:|
| 1 | 508 | 106 |
| 2 | 64 | 58 |
| 3 | 8 | 11 |
| 4–8 | 0 | 12 |

Nessuna traccia resta fuori da ogni settore.

**Lunghezze:** i 580 numerati stanno su 5.1 km medi, massimo **40.4**, nessuno oltre i 50. Le 187 senza `ref` stanno su 24.0 km medi, massimo 249.9, 15 oltre i 50.

Conseguenze per il piano:

- **Soglia di rifiuto in prevalidazione: più di 3 settori attraversati.** Nessun sentiero vero la supera, gli itinerari arrivano a 8. «Più di un settore» è invece un criterio sbagliato: 64+8 sentieri numerati lo violerebbero
- **Le 40 tracce di tipo `sentiero` senza `ref` hanno il codice scritto nel nome** — `(G 110)`, `(G 201)`, `( B 442 )`, `(D 180 A)` — e stanno per **35 su 40** in `in_revisione_validazione`, 3 in `percorribile`, 2 in `in_pre_accatastamento`. Non sono sentieri da numerare con `propose()`: sono codici che non sono arrivati in `ref`. **In questo ciclo si segnalano e non si normalizzano**: la prova a vuoto li elenca, nessuna scrittura, perché prima va capito lato Drupal cosa significhi l'assenza del `ref` (vedi punti aperti)
- **L'indice GiST su `taxonomy_wheres.geometry` è un requisito, non un'ottimizzazione**: la query di intersezione sulle tracce lunghe ha richiesto **oltre due minuti** durante queste misure. In prevalidazione `resolveSector()` deve rispondere entro il tempo di una chiamata API

## Challenge del 09/09/2026

Due revisori adversariali, uno per overview. Rilievi principali e cosa si è deciso.

**Overview del package:**

- **Le tre misure che deciderebbero la fattibilità sono rimandate alla prova a vuoto** — quante geometrie cadono fuori da ogni settore, quanti settori dedotti divergono dal `ref`, quanti doppioni restano dopo la normalizzazione — cioè al termine dell'implementazione che dovrebbero condizionare. Proposto di misurarle subito con tre query. **Decisione del dev: si vedono in fase di codice, dopo la normalizzazione.** Il rischio resta scritto: la migration porta il vincolo unique parziale e il CHECK, che sono la parte che costa più correggere a tabella popolata
- **FK dal dominio opzionale verso `ec_tracks`, che è core**: una volta popolata la tabella, spegnere l'interruttore non riporta il package «esattamente com'è oggi» — le FK bloccano la cancellazione di un `EcTrack` accatastato. Da tenere presente, non risolto
- Altri rilievi da valutare in fase di piano: `0` come sentinella in una colonna che ospita `A`–`Z` (esiste una terza via, `NOT NULL DEFAULT ''`); assenza di CHECK su `number`, `variant`, `region`; nessuna operazione atomica di *scambio* per la sostituzione a mano del numero (liberare e riservare sono due passi); nessuna storia del numero, solo lo stato corrente; quale lettera per la regione fittizia dei doppioni, dato che `A` è plausibile per una regione vera; nessun requisito di autorizzazione su `release()`

**Overview di forestas:**

- **`Nova::resourceForModel()` memoizza un `first()` sulla collezione**: più Resource sullo stesso modello rendono arbitraria la Resource canonica, da cui passano i sette campi relazione verso `EcTrack` (cinque in `Ente`, uno in `EcPoi`, uno in `TaxonomyWarning`). Attenuato dalla struttura scelta dal dev (vedi sotto): `Tracce` resta nel menu `EC`, quindi `app/Nova/EcTrack.php` non rischia di essere cancellata come classe morta
- **`syncTrackTaxonomies()` fa un `sync()` pieno delle activity e `syncTrackType()` ricompone subito dopo**: chi riordina quelle righe fa perdere il tipo a tutti i tracciati. E `syncTrackType()` interpola `$response->type` senza validarlo, quindi un valore diverso a monte crea in silenzio una nuova `TaxonomyActivity`. **Il dev ha deciso di non correggerlo in questo ticket**: resta segnalato nei rischi dell'overview di forestas come primo posto da guardare se la voce `Sentieri` risulta vuota o dimezzata
- `indexQuery()` non è una policy; creare da `Sentiero` non attacca il tipo; gli elenchi relazione continuano a mescolare sentieri e itinerari

## Struttura del menu Nova — decisione del dev (09/09/2026)

Il menu **`EC` resta invariato** (`POI`, `Tracce`, `Enti`, `Layers`, `Feature Collections`): nessuna voce rimossa, `Tracce` continua a mostrare tutti i tracciati.

Nasce un menu **`Catasto`** con tre voci: `Sentieri` (Resource `Sentiero`, forestas), `Istanze` e `Registro dei codici` (Resource del package, dominio `trail_registry`).

**Nessuna Resource `Itinerario`**: nel Catasto sta solo ciò che si accatasta, e un itinerario non ha un codice REI. Gli itinerari si vedono in `EC → Tracce`.

Due conseguenze utili di questa impostazione: `EcTrack` conserva una voce di menu viva (quindi non verrà cancellata per errore), e le due Resource sullo stesso modello scendono da tre a due.

Le Resource del registro vanno in `src/TrailRegistry/Nova/` del package, **non** sotto `src/Nova/`, che `Nova::resourcesIn()` scandisce ricorsivamente registrando tutto anche a dominio spento.

## Fonti consultate

| Fonte | Cosa ha dato |
|---|---|
| Call Forestas RES/SUS del 30/07/2026 | il ciclo di vita del numero: erogazione, blocco provvisorio, conferma, liberazione per deaccatastamento. **Non** contiene formato, variante, criterio di prossimità, durata della riserva |
| Scrum Piccioli–Bonfanti del 08/09/2026 | le risposte autorevoli su formato, variante, trattini, doppioni. È la fonte che ha corretto l'overview |
| `docs/integrazione-sus/index.html` (repo forestas) | il flusso a quattro attori, la sequenza dei ticket, «le istanze restano nel Catasto come modello separato» |
| Database di sviluppo forestas | 63 settori, 576 codici reali, cinque forme, sette duplicati testuali |

## Decisioni prese

1. **Si procede senza attendere le risposte di Forestas.** Il formato è impostabile da configurazione, così una risposta diversa è un cambio di configurazione e non una riscrittura
2. **La fase provvisoria resta `stato_validazione`** sull'`EcTrack`. Nessun nuovo modello di sentiero — decisione esplicita del dev, da rivedere solo su sua indicazione
3. **Un solo service di dominio**, `TrailRegistryService`, non due che si somigliano. `resolveSector()` è una sua funzione, non una classe a sé
4. **Il modello dell'istanza si crea in questo ticket**, non in oc:8491 dove pure è nominato: oc:8490 riserva il numero già in prevalidazione e avrebbe bisogno di un'istanza esistente
5. **L'istanza eredita da `MultiLineString`**, non da `EcTrack`: riusa la geometria PostGIS senza portarsi dietro indicizzazione, observer, preferiti e mappe vettoriali
6. **Il registro è la fonte unica; `ref` viene deprecato** — serve una volta per il caricamento iniziale, poi resta come dato storico e smette di essere mostrato
7. **Codice senza trattini**, forma `ZNUB535A`
8. **`0` significa «senza variante»**, mai `NULL` — con `NULL` il vincolo di unicità non protegge (verificato dal vivo sul database)
9. **Due colonne separate** per i riferimenti, `trail_application_id` e `ec_track_id`, non un riferimento polimorfico: si conservano entrambi i legami e sono chiavi esterne vere
10. **I doppioni vanno in una regione fittizia**, così il vincolo di unicità resta attivo ovunque
11. **Il comando di normalizzazione esiste solo in prova a vuoto** in questo ciclo

## Correzioni fatte all'overview

Sei punti emersi dalle trascrizioni, più due errori miei trovati per altra via.

| Cosa | Come era | Come è |
|---|---|---|
| struttura del codice | prefisso `ZNUB`, numero a 3 cifre, spazio per **area** | prefisso `ZNUB5` (`full_code` intero), numero a **2 cifre**, spazio per **settore** |
| variante | facoltativa, generica | `0` = senza variante, altrimenti lettera maiuscola, mai `NULL`, omessa in uscita |
| varianti numeriche | non trattate | fuori scope, da rifiutare |
| `stato_validazione` | non trattato | mescola accatastamento e percorribilità: fuori scope, **ticket da aprire** |
| doppioni | marcati in conflitto, vincolo non applicato | spostati in una regione fittizia, vincolo attivo ovunque |
| numeri esistenti | — | ufficiali, restano |
| `getOrderedTaxonomyWheres()` | dato per riusabile | **non lo è**: legge una copia già calcolata, restituisce solo nomi, ordina per livello amministrativo. Segnalato in overview come da non riusare |
| selezione dei settori | `ST_Intersects` su tutte le `taxonomy_wheres` | ristretto a `source = 'osm2cai'` con `full_code`: 63 record contro 483 |

## Dati misurati sul database

Rilevati durante la pianificazione, utili al piano e alla stima.

- **63** settori con `full_code`, tutti e soli quelli con `source = 'osm2cai'`
- **576** `ec_tracks` su 791 hanno un `ref`
- coda dei codici: **573 su 576** regolare a tre cifre, **3** fuori forma
- **245** hanno la variante, 331 no
- **zero** codici con coda a quattro cifre: nessuna variante numerica esiste
- **7** duplicati, ma è un conteggio **testuale** — quello vero si conosce solo dopo aver ricavato il settore dalla geometria, e sarà più basso (`700` è già accertato essere due settori diversi)
- prova di `resolveSector()` su due casi: `332A` → `ZSUD3`, `Z-SS-G-310-A` → `ZSSG3`. In entrambi la cifra del settore scritta nel codice **coincide** con quella dedotta dalla geometria, e ciascuno interseca un solo settore

## Punti ancora aperti con Forestas

- **Criterio di prossimità**: in questo ciclo contiguità numerica dentro il settore. Se intendessero vicinanza geografica del tracciato, va sostituito
- **Durata della riserva** di un codice non confermato, e chi può liberarlo prima della scadenza
- **Conferma formale del formato**, benché ora regga sui conti e sia confermato da Piccioli
- **Come bonificare** doppioni e codici fuori forma: «*una cosa che dobbiamo discutere con il cliente*»
- **Cosa significa, su Drupal, un sentiero senza `ref`.** I 40 sentieri di tipo `sentiero` senza `ref` portano il codice scritto nel nome (`(G 110)`, `( B 442 )`, `(D 180 A)`) e stanno per 35 su 40 in `in_revisione_validazione`. Non si sa se il `ref` sia stato rimosso deliberatamente su Drupal, non sia mai stato compilato, o non venga importato in quello stato: sono tre cose diverse con tre trattamenti diversi. **Decisione del dev: in questo ciclo si segnalano e non si normalizzano** — la prova a vuoto li elenca, nessuna scrittura. Va chiesto a Forestas prima di decidere se il codice nel nome vale come codice assegnato

## Da fare in un ticket separato

- **Scindere `stato_validazione`** in stato di accatastamento e stato di percorribilità: nel modello nazionale CAI sono due campi distinti, qui sono sovrapposti. Piccioli: «*non rientra nelle attività che devi fare adesso, però se vuoi già mettere un ticket che ce lo teniamo aperto*»
- **Nascondere `ref` dall'interfaccia** quando il registro entra in uso

## Piano scritto il 09/09/2026

Due file, uno per repo:

- `wm-package/docs/features/8489-.../plan.md` — 12 task: enum, migration delle tre tabelle, parser dei `ref`, modelli, `resolveSector`, `propose`, il ciclo riserva/conferma/liberazione con la storia, `validate`, comando in prova a vuoto, Resource e Action Nova, registrazione del dominio e menu, documentazione
- `forestas/docs/features/8489-.../plan.md` — 5 task: comando che attacca il tipo alle 6 tracce, Resource `Sentiero`, sezione di menu `Catasto`, pubblicazione degli stub, aggiornamento del contesto

**Il piano del package va eseguito prima**: i task 2 e 3 di forestas dipendono dalle sue Resource.

Tre difetti corretti in autorevisione dopo la prima stesura:

1. uno stub conteneva un valore sbagliato lasciato di proposito come «verifica di attenzione»: rimosso, era un placeholder mascherato
2. le Resource del package referenziavano `\App\Nova\EcTrack` e `\App\Nova\User`, che vivono nel consumer: sostituite con `Nova::resourceForModel()`
3. il requisito «formato impostabile da configurazione» non era soddisfatto da `validate()`, che usava una regex scritta a mano: aggiunto lo step che compone la regex da `code_format`

## Rapporto della prova a vuoto sui dati reali — 09/09/2026

Eseguito su forestas locale (copia dei dati veri) dopo aver pubblicato ed eseguito le quattro
migration del dominio. Comando: `wm-package:trail-registry-normalize --dry-run`.

| Misura | Risultato | Cosa ci si aspettava |
|---|---|---|
| geometrie **fuori da ogni settore** | **0** su 580 | ignoto: era il rischio piu' grave |
| **settore discordante** fra codice e geometria | **13** tracce | ignoto |
| **codici in conflitto** | **14**, su 32 tracce | «meno di 7» |
| codici non interpretabili | **0** | 3 |

**Nessuna geometria cade fuori dai settori.** Era il rischio che avrebbe potuto far crollare
l'impianto: se molte tracce non avessero avuto un settore, il prefisso non sarebbe stato
ricavabile e la scomposizione del codice in colonne andava rifatta. Zero su 580.

**I conflitti sono piu' del doppio di quelli attesi, non meno — la previsione dell'overview era
sbagliata.** Era scritto che il conteggio testuale di sette sovrastimava; e' il contrario: la
normalizzazione **rivela** collisioni che il confronto sul testo non vedeva, perche' due codici
scritti in forme diverse (`Z-NU-T-511C` e `511C`) sono la stessa posizione.

**Prestazione, misurata sul database reale:** la query di `resolveSector()` con l'indice GiST gira
in **652 ms** (`Index Scan using taxonomy_wheres_geometry_gist`), contro gli **oltre due minuti**
misurati in fase di pianificazione senza indice. Circa duecento volte piu' veloce.

**Difetto del comando trovato eseguendolo sui dati veri:** il rapporto dichiara «codici letti: 562»
ma sono le **posizioni distinte**, non i codici letti, che sono 580 (580 tracce - 32 coinvolte nei
conflitti + 14 posizioni = 562). L'etichetta inganna e va corretta.

## Da chiedere a Forestas — i 14 codici in conflitto

Non sono la stessa cosa: si dividono in quattro casi, con trattamenti presumibilmente diversi.
Questo elenco e' materiale da portare al cliente.

### 1. Duplicati esatti — 6 codici, 12 tracce

Geometria **identica al centimetro** (stesso hash MD5 del WKT): lo stesso sentiero e' entrato due
volte perche' il codice era scritto in due forme.

| Tracce | Codici | km |
|---|---|---|
| 1 / 354 | `511C`, `T-511C` | 1.45 |
| 40 / 350 | `611`, `T-611` | 7.17 |
| 352 / 363 | `G-611`, `611` | 6.31 |
| 355 / 370 | `G-611A`, `611A` | 1.39 |
| 351 / 366 | `G-611B`, `611B` | 0.50 |
| 407 / 457 | `327`, `327` | 4.88 |

In cinque casi su sei uno dei due porta la lettera dell'area (`G-`, `T-`) e l'altro no.
**Domanda al cliente:** si cancella uno dei due, e quale? La forma con la lettera dell'area o
quella senza?

### 2. Quasi-duplicati — 5 codici

Stesso sentiero, tracciato due volte con GPX leggermente diversi.

| Tracce | Codici | km | Nota |
|---|---|---|---|
| 53 / 58 | `101`, `G-101` | 3.70 / 3.71 | stesso percorso, nome riformulato |
| 219 / 693 | `T-513A`, `513A` | 0.24 / 0.45 | «Monte Cresia» entrambi |
| 349 / 746 | `T-114`, `Z-NU-T-114` | 5.07 / 5.07 | stessa lunghezza, forme diverse del codice |
| 353 / 692 | `T-513`, `513` | 4.62 / 1.31 | «Nolau» entrambi, uno tronco |
| 704 / 717 | `Z-NU-G-643-A` × 2 | 1.14 / 1.13 | stesso codice per intero |

**Domanda al cliente:** quale tracciato e' quello buono? Qui la scelta non e' indifferente come
nei duplicati esatti: le geometrie differiscono, e in un caso (353/692) di un fattore tre.

### 3. Collisioni vere — 3 codici

Sentieri **diversi** che rivendicano la stessa posizione.

| Tracce | Codici | km | Nota |
|---|---|---|---|
| 56 / 593 | `Z-SU-D-131`, `Z-SU-D-331` | 5.19 / 5.99 | codici **scritti diversi** che collidono lo stesso |
| 158 / 441 | `Z-SU-D-322`, `D 322` | 6.34 / 4.17 | sentieri distinti, stesso codice |
| 348 / 490 | `C-505A` × 2 | 0.40 / 0.29 | sentieri distinti, stesso codice |

**Il caso 56 / 593 e' il piu' interessante e va posto per primo.** I due codici sono scritti
diversi — `131` e `331` — e collidono comunque, perche' la coda a tre cifre vuole la prima cifra
come settore e la geometria dice che il settore e' lo stesso per entrambi. Se il formato
confermato e' quello, **uno dei due codici e' sbagliato in origine**. E' anche una verifica
indiretta del formato stesso: se Forestas dicesse che non c'e' errore, allora la nostra lettura
della coda e' sbagliata.

**Domanda al cliente:** a chi resta il numero, e a chi se ne assegna uno nuovo?

### 4. Un caso storico — 1

Tracce 345 e 713: `ex T-110` (10.91 km) e `T-110` (21.01 km). Qualcuno ha gia' usato la
convenzione **«ex»** per marcare un tracciato dismesso e sostituito.

**Domanda al cliente:** esiste una convenzione per i codici dismessi? Se «ex» e' quella, il
registro dovrebbe conoscerla invece di trattare quella riga come un conflitto.

### 5. I 13 settori discordanti

Tredici tracce hanno, nel codice scritto, una cifra di settore diversa da quella che si ricava
dalla loro geometria. Alcuni dei conflitti qui sopra ne sono una **conseguenza**: le tracce con
codice `611` (settore scritto 6) cadono geometricamente nel settore 5, dove il numero 11 e' gia'
occupato legittimamente da un altro sentiero (traccia 416, codice `511`).

**Domanda al cliente:** quando codice e geometria non concordano, chi ha ragione? Se vale la
geometria, quei tredici codici vanno riemessi; se vale il codice scritto, e' il perimetro dei
settori a essere impreciso.

## Esito dell'assegnazione dei numeri — 09/09/2026, da portare a Forestas

Il registro e' stato popolato per la prima volta sui dati reali (copia locale), con
`wm-package:trail-registry-normalize --force`.

| | |
|---|---|
| tracce esaminate | **580** |
| codici **assegnati** | **562** |
| codici **in conflitto** | **18** |
| geometrie fuori da ogni settore | **0** |
| codici illeggibili | **0** |
| settore in disaccordo con la geometria | **13** |
| settori usati | 44 |

**Nessun sentiero e' rimasto senza codice per colpa della geometria**, ed e' il risultato piu'
importante: era il rischio che avrebbe potuto far crollare l'impianto, perche' senza settore il
prefisso non e' ricavabile.

Nota sui numeri: la prova a vuoto diceva «14 conflitti», la scrittura ne conta 18, e non e' una
discordanza. La prova contava le **posizioni** contese, la scrittura conta le **righe** che hanno
perso la corsa: una posizione contesa da cinque tracce produce quattro conflitti, non uno.

### I 18 codici in conflitto, con il contendente

Chi ha vinto la posizione e' la riga `assegnato`; le altre sono `conflitto`. Nel backoffice si
trovano cercando il codice: la ricerca del registro mostra tutte le righe che occupano la stessa
posizione.

| Codice | Traccia in conflitto | Assegnato alla traccia |
|---|---|---|
| `ZORT511` | 350, 352, 363, 416 | 40 |
| `ZORT511B` | 366, 375 | 351 |
| `ZNUC505A` | 490 | 348 |
| `ZNUG101` | 58 | 53 |
| `ZNUG643A` | 717 | 704 |
| `ZNUT114` | 746 | 349 |
| `ZNUT511C` | 354 | 1 |
| `ZORG611A` | 370 | 355 |
| `ZORT513` | 692 | 353 |
| `ZORT513A` | 693 | 219 |
| `ZSUD322` | 441 | 158 |
| `ZSUD327` | 457 | 407 |
| `ZSUD331` | 593 | 56 |
| `ZSUT110` | 713 | 345 |

**Chi ha vinto lo ha deciso l'ordine di lettura (id della traccia crescente), non un criterio di
merito.** E' una cosa da dire a Forestas: se per loro conta quale delle due tracce tiene il
numero, la scelta va rifatta con il loro criterio. Riassegnare e' facile — il registro si
ricostruisce in pochi secondi — finche' nessun codice e' stato comunicato all'esterno.

### Le cinque domande per Forestas

Le categorie sono descritte in dettaglio nella sezione «Da chiedere a Forestas» piu' sopra. In
sintesi, cosa serve sapere:

1. **Duplicati esatti** (6 casi, geometria identica al centimetro): si cancella una delle due
   tracce, e quale? In cinque casi su sei la differenza e' solo la lettera dell'area nel codice
   (`G-611` contro `611`).
2. **Quasi-duplicati** (5 casi): quale tracciato e' quello buono? Qui le geometrie differiscono, e
   in un caso (tracce 353 e 692, «Nolau») di un fattore tre.
3. **Collisioni vere** (3 casi): sentieri diversi che rivendicano lo stesso codice — a chi resta
   il numero, e a chi se ne assegna uno nuovo?
4. **Il caso `ZSUD331`** merita di essere posto per primo: le tracce 56 e 593 hanno codici
   **scritti diversi** (`Z-SU-D-131` e `Z-SU-D-331`) che collidono lo stesso, perche' la prima
   cifra della coda e' il settore e la geometria dice che il settore e' lo stesso. Se il formato
   confermato e' quello, **uno dei due codici e' sbagliato in origine** — ed e' anche una verifica
   indiretta del formato: se Forestas dicesse che non c'e' errore, sarebbe la nostra lettura della
   coda a essere sbagliata.
5. **I 13 settori discordanti**: quando il codice scritto e la geometria non concordano, chi ha
   ragione? Se vale la geometria quei codici vanno riemessi; se vale il codice, e' il perimetro
   dei settori a essere impreciso. Alcuni conflitti sono una conseguenza di questo: le tracce con
   codice `611` cadono geometricamente nel settore 5, dove l'11 e' gia' occupato.

Piu' la domanda gia' aperta sui **40 sentieri senza `ref` che portano il codice nel nome**
(`(G 110)`, `( B 442 )`): non sono entrati nel registro, e prima va capito lato Drupal cosa
significhi l'assenza del `ref`.

## Condizione di go-live: nessun codice in conflitto

**I conflitti sono uno stato transitorio, non una condizione a regime.** Decisione del dev del
10/09/2026: prima di andare in produzione, Forestas deve chiarire come risolverli.

Il motivo, che il solo conteggio non rende: verificato sul database, **nessuno dei 18 sentieri in
conflitto ha un codice valido altrove**. Una riga `conflitto` non e' un codice con un problema —
e' una **rivendicazione respinta**, e il sentiero che la porta e' rimasto **senza numero** perche'
la posizione l'ha presa un altro.

Quindi la lettura corretta del registro oggi e':

| | |
|---|---|
| sentieri con un codice valido | 562 |
| sentieri **senza** codice | **18** |

**Ai 18 non si assegna un numero nuovo**, e non per pigrizia: chi ha vinto la posizione l'ha
deciso l'ordine di lettura, non un criterio di merito, e in sei casi su quattordici le due tracce
sono lo **stesso sentiero** entrato due volte. Assegnare un numero al «perdente» creerebbe un
codice per un sentiero che non dovrebbe esistere. Prima si cancellano i doppioni, poi si vede
quanti restano davvero scoperti — probabilmente una manciata, non diciotto.

Le domande da porre sono gia' scritte qui sopra, in «Da chiedere a Forestas»: quattro categorie
(duplicati esatti, quasi-duplicati, collisioni vere, il caso storico `ex T-110`), piu' i 13
settori discordanti.

**Come verificare che la condizione sia soddisfatta**, prima del rilascio:

```sql
select count(*) from trail_registry_codes where status = 'conflict';
```

Deve tornare zero.

## Stato dei lavori al 09/09/2026 — riprendere da qui

**Fatto, nel package (`wm-package`), branch
`feature/oc-8489-identificazione-automatica-sentiero-codice-rei`:**

Tutti e 13 i task del piano sono implementati e revisionati. **117 test verdi**, PHPStan pulito su
`src/TrailRegistry` (zero errori). Nessun commit: i file sono nel working tree, come da regola del
progetto.

| Task | Cosa |
|---|---|
| 1 | enum di stato |
| 2 | tre tabelle, indice unico **parziale**, vincoli di forma, indice GiST |
| 3 | lettura della coda numerica dai `ref` storici |
| 4 | modelli, con `code`/`fullCode`/`denomination` calcolati |
| 5 | `resolveSector()` in PostGIS |
| 6 | `propose()` e `availableNumbers()` |
| 7 | riserva, conferma, liberazione, sostituzione, con la storia dei passaggi |
| 8 | verifica formale di un codice |
| 9 | comando in prova a vuoto |
| 10 | due Resource Nova, tre Action, sei filtri |
| 11 | registrazione del dominio e menu `Catasto` |
| 12 | documentazione — **da fare** |
| 13 | scrittura nel registro |

Piu', fuori piano: ricerca e ordinamento per codice sulla Resource del registro.

**Fatto, in forestas:** le quattro migration del dominio sono pubblicate ed **eseguite** sul
database locale. Backup dello schema pre-migration in
`/private/tmp/.../scratchpad/backup/schema-pre-8489.sql`.

**Da fare domani:**

1. **Task 12 del package**: la documentazione (`docs/resources/TrailRegistry.md`, aggiornamento di
   `OptionalDomains.md` e del `CLAUDE.md` del package).
2. **I cinque task del piano di forestas**: comando che attacca il tipo alle 6 tracce senza tipo,
   Resource `Sentiero`, sezione di menu `Catasto` con la voce `Sentieri`, verifica del gate delle
   migration, aggiornamento del `CLAUDE.md` di forestas.
3. **I commit**, che spettano al dev: nessuno e' stato eseguito, in nessuno dei due repo.
4. **Portare a Forestas** le domande qui sopra.

**Un'avvertenza per chi riprende:** il gate PHPStan del package e' rosso, con 998 errori — ma
**erano gia' tutti li' prima di questo lavoro**: zero in `src/TrailRegistry`, e sul
`WmPackageServiceProvider` si e' passati da 8 errori a 7 (verificato con `git stash`). Non e' un
problema introdotto dalla feature.

## Deviazioni dal piano

Nessuna: l'esecuzione non è ancora iniziata.

## Lo stato `conflitto` rimosso dal registro (10/09/2026)

**Decisione del dev, dopo che la tabella delle anomalie era in piedi:** un sentiero la cui
posizione è già di un altro non entra affatto nel registro. Il registro contiene solo codici
realmente portati da qualcuno; il caso irrisolto vive come anomalia `codice_conteso`.

**Perché** — le due tabelle raccontavano lo stesso fatto due volte, e l'anomalia lo racconta
meglio: sentiero, contendente e codice suggerito stanno sulla stessa riga, mentre nel registro il
legame andava ricostruito cercando il codice e confrontando le sei colonne a occhio. Il registro,
in cambio, torna a essere per costruzione un elenco di codici veri: nessun filtro da applicare per
non vedere righe che codici non sono.

**Cosa è cambiato:**

- `TrailCodeStatus` ha tre casi (`reserved`, `assigned`, `released`), non più quattro. I CHECK di
  `trail_registry_codes.status` e `trail_registry_code_events.to_status` seguono.
- `writeExistingCodeRow()` non ripiega più su una riga di scarto: torna `null`.
- `TrailCodeRegistrationOutcome::conflict()` porta `holder`, la riga altrui che occupa la
  posizione, cercata subito dopo il rifiuto dell'indice unico. È l'unico modo per dire al
  chiamante con chi è il conflitto, ora che non c'è una riga propria da confrontare.
- `contestedCodeAnomalies()` è sparito: l'anomalia si costruisce dall'esito
  (`contestedAnomaly()`), non più rileggendo il registro.
- La guardia di idempotenza torna a essere `TrailCodeStatus::active()`.

**Conseguenza voluta sull'idempotenza:** un sentiero conteso non lascia riga, quindi a ogni
esecuzione ritenta. Se nel frattempo il conflitto si è sciolto — l'altro sentiero deaccatastato, o
il codice corretto alla fonte — il numero gli spetta senza alcun intervento. Se regge, il
tentativo fallisce di nuovo e l'anomalia viene riscritta identica (`rewriteAnomalies()` cancella e
riscrive a ogni giro). Il bug delle 18 righe diventate 36 non può ripresentarsi: non si scrive
nulla da moltiplicare.

**Nota per il go-live:** la condizione non è più `select count(*) from trail_registry_codes where
status='conflict'`, che ora è sempre zero per costruzione, ma
`select count(*) from trail_registry_anomalies where type='codice_conteso'`.

## Il criterio delle anomalie (10/09/2026)

**Regola fissata dal dev:** nella lista delle anomalie vanno **solo i sentieri rimasti senza
numero che dovrebbero averlo**. Non è un registro di imperfezioni: è la coda di lavoro di ciò che
manca.

Conseguenza immediata: `codice_nel_nome` è stato rimosso. Quei 37 sentieri il numero ce l'hanno —
il fatto che il codice stesse nel nome invece che nella proprietà dedicata lo dice già la colonna
`Provenienza` del registro (`TrailCodeOrigin::Nome`), che è anche filtrabile. Tenerlo anche fra le
anomalie ripeteva un fatto leggibile altrove e allungava una lista che deve restare corta per
essere letta.

Rimossa con esso la colonna `suggested_code`, che serviva solo a quel tipo.

`codeInNameAnomalies()` è diventato `registerCodesFromName()`: legge e registra, e l'unica anomalia
che può ancora generare è `codice_conteso`, quando la posizione letta dal nome è già di un altro.

**Nota su una svista di percorso:** la richiesta di risolvere invece di segnalare era già arrivata
prima («se si può risolvere, l'informazione ce l'abbiamo, io la risolverei»), e nel Task 16 era
stata applicata a metà — il codice veniva registrato ma l'anomalia restava.

## Nel registro solo codici senza dubbi (10/09/2026)

**Regola del dev, più stretta della precedente:** un sentiero che ha un'anomalia **non entra nel
registro**. Il registro è l'elenco dei codici su cui non pende alcun dubbio; tutto il resto è
lista di lavoro.

Ne conseguono due cambi di comportamento:

- **Settore discordante bloccante.** `registerExistingCode()` restituisce il nuovo esito
  `sectorMismatch` senza scrivere: un codice che contraddice la geometria sarebbe un dato di cui
  non si saprebbe quale metà credere. Vale anche per l'import, non solo per il comando.
- **Geometrie doppie rilevate prima del ciclo**, non più a fine corsa: serve saperlo in anticipo
  per saltare quei sentieri. Nessuno dei gemelli prende un numero finché non si sa quale sia
  quello buono.

Effetto misurato sui dati reali: registro 586 codici (550 dal campo, 36 dal nome), anomalie 34 su
34 sentieri distinti — 18 geometrie doppie, 9 codici contesi, 7 settori discordanti. I contesi
**scendono da 20 a 9** e i discordanti da 13 a 7, perché molte posizioni erano occupate proprio da
tracce che ora restano fuori: liberata la posizione, chi prima perdeva ottiene il suo numero.
Verificato con una join: **zero** codici appartengono a un sentiero che ha un'anomalia.

## Le anomalie si descrivono in lettura (10/09/2026)

La colonna `detail` (frase già composta) è stata sostituita da `context` (jsonb, i soli dati) più
`AnomalyDetailRenderer`, che compone l'HTML con un modello di frase per tipo.

**Perché in lettura:** correggere una parola è modificare una classe PHP, e ogni riga già scritta
si aggiorna al primo caricamento della pagina. Con la frase salvata in colonna occorrerebbe
rilanciare la normalizzazione su tutti i sentieri, e le righe vecchie resterebbero indietro. È
anche l'unico assetto che consente di tradurre i messaggi.

Cosa mostra ciascun modello, deciso con il dev:

| Tipo | Contenuto |
|---|---|
| `codice_conteso` | il codice e **chi ce l'ha assegnato** (non «contendente»: quello il numero lo porta già) |
| `settore_discordante` | il codice nei dati e quello che la piattaforma comporrebbe dalla geometria |
| `geometria_duplicata` | **tutti** i gemelli, non solo il primo |
| `codice_illeggibile` | il codice illeggibile e il primo numero libero nel settore, come suggerimento |
| `fuori_da_ogni_settore` | il solo codice nei dati |

Il numero proposto per gli illeggibili si calcola **a fine corsa**, non dentro il ciclo: a registro
incompleto suggerirebbe un numero che verrebbe occupato pochi record dopo.

Ogni riga porta il collegamento alla **scheda alla fonte** (`properties.forestas.url`, path
configurabile con `features.trail_registry.source_url_property`): le correzioni si fanno lì, e
l'importazione successiva le riporta indietro da sé. L'URL di modifica diretta di Drupal è stato
valutato e scartato: si comporrebbe da `forestas.source_id`, ma lo schema (`/node/{id}/edit`) non è
verificabile da qui e su una piattaforma non nostra non si deduce.

## La spiegazione in cima all'elenco (10/09/2026)

`TrailRegistryNoticeCard` mostra sopra la tabella delle anomalie cosa sono quelle righe, la regola
che le governa e il significato dei cinque tipi.

Il componente è registrato con una **render function in JS puro**
(`resources/js/domains/trail_registry.js`), non con un bundle compilato: il Vue che Nova carica è
la build runtime-only — un template come stringa non verrebbe compilato — mentre `Vue` come
globale è garantita, ed è la stessa su cui le card già compilate del package fanno `externals`.
Lo script è caricato da `registerEnabledDomains()` solo a dominio acceso, con la stessa convenzione
delle route: `resources/js/domains/<dominio>.js`.

**Verificata a schermo** (10/09, screenshot del dev): la card si rende correttamente. Nel
guardarla è emerso che il testo era rimasto indietro — nominava la colonna «Contendente», poi
rinominata «Sentiero collegato» e spostata nella sola scheda — ed è stato riallineato.

## Due 500 in pagina, entrambi da trappole di Nova (10/09/2026)

Trovati nei log (`storage/logs/laravel-2026-09-10.log`), non dai test:

1. **`public static $title = 'type'`** → *Object of class TrailRegistryAnomalyType could not be
   converted to string*, `vendor/laravel/nova/src/Resource.php:416`. Nova prende quell'attributo
   per intitolare il record e lo converte in stringa: un enum non si converte. Sostituito con un
   metodo `title()`.
2. **`AnomalyDetailRenderer::render()` con `type` nullo** → `UnhandledMatchError`. Nova costruisce
   i campi anche su un'istanza vuota, per ricavare le colonne dell'elenco prima di avere le righe.
   Non ancora visto in pagina, ma sarebbe scattato al primo elenco vuoto.

Alla radice di entrambi la stessa dichiarazione ottimista: il modello dichiarava `type`,
`ec_track_id`, `id` e `created_at` come non nulli, mentre su un'istanza vuota lo sono. Corretta —
ed è stato PHPStan a non lasciar passare la mezza misura. Regressioni in
`TrailRegistryAnomalyResourceTest`.

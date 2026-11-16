> Ticket: oc:8989
> Stato: approvata il 2026-11-16

# Percorribilità dei sentieri nel dominio Catasto

Questa overview copre la parte che vive nel **wm-package**. La parte dello shard è in
`forestas/docs/features/8989-percorribilita-dei-sentieri-da-osm2cai-nel-dominio-catasto/overview.md`.

## Cosa cambia

Il dominio Catasto Sentieri acquisisce la **percorribilità**: lo stato di un sentiero sul terreno,
con data, autore, nota e storico delle variazioni. Si inserisce da Nova e si legge dalle API.

Il package fornisce il meccanismo, non i valori: quali stati esistano lo decide ogni shard.

## Perché

Alessio Piccioli, scrum del 29/10/2026: «io voglio che si prenda il modello che abbiamo usato per
osm2cai per la percorribilità, e che si porti dentro il wm-package che a loro volta se lo ritrovano
di qua». Confermato il 16/11: «va preso del codice che vive dentro osm2cai e va portato».

Si porta il **concetto** della feature, non il suo codice: lo studio di osm2cai2 ha trovato tre
difetti che non vanno replicati (due vie di scrittura di cui una salta lo storico, cronologia
mostrata come JSON grezzo, conteggi sbagliati per confronto su stringhe).

## Punto di partenza

- `src/TrailRegistry/` contiene il dominio Catasto: `TrailRegistryCode` (tabella
  `trail_registry_codes`, con `belongsTo(EcTrack::class, 'ec_track_id')`) e `TrailApplication`.
- `TrailRegistryCodeEvent` è una tabella append-only con `from_status`, `to_status`, `reason`,
  `user_id`, `created_at`, mostrata nella detail view da `CodeHistoryRenderer`: è il pattern di
  storico già in uso nel dominio.
- `TaxonomyTerm` (tabella `taxonomy_terms`) ha `identifier`, `name`/`description` jsonb, `icon`,
  `sort_order`, `single_choice`, `properties` jsonb. Il pivot `taxonomy_termables` è morph,
  ha `public $timestamps = false` e un `unique(['taxonomy_term_id','termable_type','termable_id'])`:
  non può tenere storia né data.
- `TrailRegistryAnomalyTypes` legge i tipi dichiarati da uno shard in
  `wm-package.features.trail_registry.anomaly_types`: è il meccanismo delle anomalie **dell'import**
  e resta tale.
- Nel package non esiste oggi nessun dato di percorribilità.

## Requisiti

- <a id="r1"></a>**R1** — Un sentiero ha sempre uno stato di percorribilità, scelto fra i termini
  della famiglia che lo shard ha configurato. «Non si sa» è uno di quei termini, non l'assenza del
  dato: ogni sentiero ne ha uno fin dal primo import.
- <a id="r2"></a>**R2** — Ogni variazione dello stato registra la data, l'utente che l'ha fatta e
  una nota, e resta consultabile in ordine cronologico insieme alle precedenti. Lo stato corrente è
  l'ultima registrazione.
- <a id="r12"></a>**R12** — La storia dei cambi di stato è una tabella sola per tutte le
  classificazioni a scelta singola del sentiero, non una per classificazione: quale stato sia
  cambiato lo dice una colonna. Il primo uso è la percorribilità; la validazione con data e autore
  entrerà in un lavoro successivo senza toccare lo schema.
- <a id="r13"></a>**R13** — Nel dettaglio del sentiero la storia si legge **tutta insieme e in
  ordine di data**: i cambi di stato e gli eventi del codice catastale agganciato a quel sentiero,
  in un'unica sequenza. Oggi quelle righe stanno in due posti e nessuno le vede insieme.
- <a id="r3"></a>**R3** — La percorribilità si cambia da un punto solo: non esiste una via che la
  modifichi senza registrare l'evento. Vale anche per i processi automatici — un import che scrive
  direttamente il legame fra sentiero e termine lascerebbe lo stato cambiato senza storia, ed è il
  difetto di osm2cai che non si replica.
- <a id="r15"></a>**R15** — Un sentiero ha un solo stato per classificazione anche quando due
  persone lo aggiornano insieme. Il database non lo garantisce — il vincolo del pivot impedisce di
  legare due volte lo stesso termine, non due termini diversi della stessa famiglia — quindi lo
  garantisce la scrittura: si blocca il sentiero, si rilegge lo stato corrente, si verifica che sia
  ancora quello da cui si parte, e solo allora si scrive. Senza, due salvataggi ravvicinati lasciano
  due stati attaccati: l'API ne espone due, il conteggio delle card supera il totale dei sentieri, e
  chi legge «lo stato» ne ottiene uno dei due senza sapere che ce n'è un altro.
- <a id="r14"></a>**R14** — Ogni evento dice da dove viene: una persona, un processo automatico con
  la sua sorgente, o l'allineamento iniziale. Serve perché l'autore è vuoto in tutti i casi tranne
  il primo, e senza la provenienza una cronologia scritta in gran parte da un processo orario
  sarebbe una sequenza di righe senza nome.
- <a id="r4"></a>**R4** — Il package non conosce i valori, e il nome della famiglia lo legge da una
  sola chiave di configurazione, che ha un default funzionante: un dominio acceso funziona senza che
  lo shard configuri niente. Nessun identifier di termine compare nel codice del package, e uno
  shard può avere un numero qualsiasi di stati, con i nomi che preferisce, cambiando quella chiave.
- <a id="r5"></a>**R5** — Nel dettaglio della traccia in API lo stato corrente esce dove escono tutte
  le classificazioni, sotto `properties.taxonomies`, con nome tradotto e icona come gli altri
  termini; data, autore e nota dell'ultima registrazione escono in un blocco solo accanto,
  `properties.walkability`, con le chiavi `last_update`, `user` e `description`. L'autore esce come
  **id**, non come nome: il JSON è pubblico, e il nome di una persona che lavora per l'ente,
  associato a un giudizio sul territorio, una volta uscito non si ritira. La cronologia completa non
  esce in API.
- <a id="r6"></a>**R6** — In Nova la cronologia si legge come elenco, non come JSON.
- <a id="r7"></a>**R7** — Uno shard può dichiarare **incongruenze catastali**: casi in cui due
  informazioni pubblicate dal catasto sullo stesso sentiero non reggono insieme. Sono una famiglia
  distinta dalle anomalie dell'import, che restano com'erano.
- <a id="r8"></a>**R8** — Il package fornisce le card con cui uno shard mostra lo stato della
  percorribilità nella propria dashboard: i numeri delle incongruenze catastali e la distribuzione
  dei sentieri per stato. Lo shard le monta, non le riscrive. La distribuzione **lascia fuori dal
  grafico i sentieri senza percorribilità nota** e li scrive accanto come numero: finché il dato
  non è a regime sono la maggioranza — 454 su 625 alla partenza — e dentro la barra
  schiaccerebbero la composizione dei sentieri che un dato ce l'hanno, rendendo la card inutile
  proprio nel periodo in cui serve guardarla. È anche la regola che il wireframe ha già fissato per
  il semaforo del sito, dove il livello sconosciuto non entra nei conteggi.
- <a id="r9"></a>**R9** — Il contributo è additivo: uno shard che non attiva la percorribilità trova
  il package come prima. Vale anche per quello che si monta sulle Resource generali: l'Action, il
  renderer della cronologia e le card esistono solo a dominio acceso, e dove è spento la dashboard
  resta quella che era invece di diventare una pagina vuota.
- <a id="r10"></a>**R10** — La percorribilità la inserisce solo chi può già usare il Catasto in
  Nova, cioè i ruoli di `TrailRegistryPolicy::ROLES` (`Administrator` ed `Editor`): nessun secondo
  criterio di accesso dentro lo stesso dominio. L'Action lo verifica nel proprio `canRun()`, perché
  un `canRun()` salta l'autorizzazione della Resource.
- <a id="r11"></a>**R11** — **Accendere il dominio basta.** Chi mette l'interruttore del Catasto e
  esegue le migration si ritrova la percorribilità funzionante: la famiglia e i suoi termini
  esistono, la famiglia è accesa per le app esistenti sul modello delle tracce ed è marcata perché
  le app future la ricevano. Senza quest'ultimo passaggio il dato si inserisce, si salva, si vede
  in Nova e **non esce in API né in Elasticsearch**, senza nessun errore: `DetailTaxonomiesService`
  scarta i termini delle famiglie non accese per quell'app, e solo in Nova esiste un fallback che
  mostra le famiglie che nessuna app ha acceso.

## Rischi

- **Cambiare la scala è economico solo in aggiunta.** [R4](#r4) dice che uno shard può avere un
  numero qualsiasi di stati cambiando una chiave, ed è vero finché si aggiunge: un termine nuovo è
  una riga. Ma gli eventi puntano ai termini con chiavi esterne senza cascata — scelta voluta,
  perché la storia non si cancelli in silenzio — quindi **togliere o accorpare uno stato su cui
  esiste già storia richiede una migrazione dei dati**, non una riga di configurazione. Vale dal
  secondo giorno in poi. *Mitigazione:* dirlo qui, e marcare i termini come ritirati invece di
  cancellarli.
- **`--fresh` del comando delle tassonomie smette di funzionare.** Quel comando fa
  `DB::table('taxonomy_terms')->delete()`; con le chiavi esterne degli eventi, su un database che
  ha anche un solo evento quel `delete` solleva una violazione. È l'effetto voluto — meglio un
  errore che una storia cancellata — ma oggi l'opzione esiste e qualcuno la userà in locale,
  trovandosi davanti un errore SQL senza capire perché. *Mitigazione:* un messaggio che lo spieghi.
- **La storia unita del sentiero di [R13](#r13) nasce incompleta nel caso normale.** L'assegnazione
  del codice non viene registrata quando è l'import a riconoscerlo — `writeExistingCodeRow()` crea
  il codice già assegnato senza passare dal metodo che scrive l'evento — e su forestas quello è il
  percorso della quasi totalità dei sentieri. La timeline comincia quindi dalla validazione, non
  dal numero. *Mitigazione:* è un follow-up annotato; finché non è fatto, [R13](#r13) va letto come
  «tutto ciò che è stato registrato», non «tutto ciò che è successo».
- **Le incongruenze catastali nascono come famiglia con un elemento solo.** Hanno la stessa forma
  dei tipi di anomalia — stessa struttura, stesso punto di configurazione, stessa interfaccia — e
  la differenza fra le due famiglie («un'anomalia si corregge alla fonte, un'incongruenza dentro
  Forestas») è vera ma non leggibile dal codice: nulla impedisce a un tipo di finire nella lista
  sbagliata. *Mitigazione:* la distinzione va scritta dove si dichiarano i tipi, non solo qui.

## Out of scope

- I valori della percorribilità e il loro smistamento dall'import: stanno nello shard.
- «Temporaneamente interrotto» come stato: un'interruzione ha una scadenza e un punto, uno stato no.
  È il «campo avviso» deciso allo scrum del 29/10, altro ticket.
- Ricalcolo automatico della percorribilità da altri campi: il dato è e resta manuale.

## Moduli toccati

Previsti:

- `src/TrailRegistry/Models/` — modello degli eventi di percorribilità
- `src/TrailRegistry/Services/` — scrittura dello stato e dell'evento in transazione
- `src/TrailRegistry/Nova/Actions/` — Action di inserimento, con `canRun()` su
  `TrailRegistryPolicy::allows()`
- `src/TrailRegistry/Nova/` — renderer della cronologia nella detail view
- `src/TrailRegistry/Anomalies/` — famiglia delle incongruenze catastali
- `src/TrailRegistry/Nova/Cards/` — card delle incongruenze e della distribuzione per stato,
  accanto a `TrailRegistryNoticeCard`
- `database/migrations/trail_registry/` — stub della tabella degli eventi
- `src/Services/DetailTaxonomiesService.php` o punto equivalente — uscita in API
- `tests/Unit/TrailRegistry/`, `tests/Feature/TrailRegistry/`

> Ticket: oc:8569

# Sostituire il numero dal dettaglio dell'istanza, con la variante

## Cosa cambia

L'azione che sostituisce il numero di un codice riservato **si sposta** dalla scheda del
codice nel registro al dettaglio dell'istanza di accatastamento, ed è estesa alle varianti.

La scelta avviene in due campi:

1. **Numero** — il numero del sentiero nel settore, etichettato a tre cifre come lo legge
   l'operatore (`213`, non `13`). Contiene sia i numeri liberi sia quelli già esistenti;
   sono esclusi solo i numeri **saturi**, quelli cioè per cui né il numero puro né una
   delle ventisei lettere è ancora libera.
2. **Variante** — le combinazioni ancora libere per il numero scelto, con «nessuna
   variante» come prima opzione fra le altre. Se il numero puro è già preso, «nessuna
   variante» semplicemente non compare.

Il bottone viene tolto dalla scheda del codice nel registro: il registro continua a
mostrare tutti i codici, ma la sostituzione si fa dall'istanza.

## Perché

È il terzo dei tre interventi elencati da Piccioli in call (~00:35): «rispetto a questa cosa
che vi abbiamo presentato, tre variazioni sul tema. Numero uno, vedere i sentieri vicini;
numero due, migliorare l'algoritmo della scelta; e numero tre, **darvi la possibilità di
cambiare il codice assegnato**».

Il caso della variante è posto da Saba (~00:30): «magari il sistema mi ha proposto di dare
il B283, ma io mi rendo conto che a fianco ho il B211, il B212. Allora dico no, lo chiamo
B213» — la numerazione segue le decine per zona. Oggi quel gesto non è possibile: l'azione
esiste solo sul registro e accetta solo numeri interi.

Il gesto avviene mentre l'operatore guarda la mappa dell'istanza: mandarlo nel registro dei
codici significa fargli perdere il contesto su cui sta decidendo.

## Perché due campi e non una lista sola

Per ogni numero esistono fino a ventisette combinazioni (il numero puro più ventisei
lettere): una lista unica sarebbe illeggibile in un settore popolato.

Il punto però non è solo la lunghezza. Trattando «nessuna variante» come una delle opzioni
del secondo campo, **la categoria «numero occupato» sparisce**: resta una regola sola —
*mostra le combinazioni libere* — e i casi che sembravano speciali ne diventano conseguenze.

| Stato del numero `213` | Cosa mostra il secondo campo |
|---|---|
| Del tutto libero | nessuna variante, A, B, C… |
| Il numero puro è preso (caso di Saba) | A, B, C… senza «nessuna variante» |
| Il numero è libero ma la A esiste | nessuna variante, B, C… |
| Tutte le combinazioni prese | il numero non compare nel primo campo |

L'operatore non deve classificare in anticipo la propria intenzione: arriva sapendo «questo
sentiero sta vicino al 211 e al 212», sceglie il numero, e scopre lì cosa è disponibile.

## Requisiti

- [ ] L'azione di sostituzione è disponibile dal dettaglio dell'istanza di accatastamento
- [ ] L'azione è rimossa dalla scheda del codice nel registro, e il test che ne verifica la
      presenza lì è aggiornato
- [ ] Il primo campo elenca i numeri del settore che hanno **almeno una combinazione
      libera**, con etichetta a tre cifre (settore + numero)
- [ ] Il secondo campo elenca le combinazioni libere del numero scelto, con «nessuna
      variante» come opzione, e si aggiorna quando il primo cambia
- [ ] Il server rifiuta comunque una combinazione già presente nel registro, anche se
      l'interfaccia l'avesse proposta
- [ ] Il controllo sullo stato del codice avviene **dentro** la transazione, sulla riga
      bloccata con `lockForUpdate()`: una sostituzione non può passare su un codice
      diventato `Assigned` nel frattempo
- [ ] La seconda tendina offre solo varianti **lettera**, ma il conteggio di cosa è
      occupato guarda tutte le righe esistenti per quel numero, comprese eventuali
      varianti numeriche d'archivio
- [ ] L'azione risale al codice tramite `activeCode()` sull'istanza, mai con un `find()`
      sull'id grezzo della richiesta, che dopo lo spostamento è l'id dell'istanza
- [ ] L'azione opera su una sola istanza per volta
- [ ] `NumberOccupiedException` e la transizione non valida risalgono come diniego
      leggibile, non come errore di sistema
- [ ] La sostituzione resta ammessa solo su un codice `Reserved`: dopo la promozione a
      sentiero (`Assigned`) il numero non si tocca più
- [ ] Un'istanza priva di codice attivo produce un diniego comprensibile, non un errore
- [ ] Il numero abbandonato torna libero subito, come già avviene
- [ ] La sostituzione continua a registrare chi l'ha eseguita
- [ ] Le etichette passano da `__()` e le chiavi vanno in `resources/lang/it.json` e
      `resources/lang/en.json` — **non** in `lang/`, dove verrebbero ignorate in silenzio

## Rischi

- **Il codice attivo dell'istanza può mancare.** `TrailApplication::activeCode()` è una
  `HasOne` filtrata sugli stati attivi: per un'istanza respinta restituisce `null`.
  L'azione deve gestirlo, non assumerlo.
- **Il secondo campo dipende dal primo.** Se non si aggiorna al cambio del numero,
  l'operatore può salvare una combinazione che crede libera. Il vincolo va verificato lato
  server, non solo nell'interfaccia — `dependsOn()` è ergonomia, l'invariante sta nel
  service.
- **La concorrenza resta possibile.** Fra il momento in cui la select viene popolata e il
  salvataggio, un'altra istanza può prenotare la stessa combinazione. Regge l'indice unico
  parziale già presente sulla tabella: serve che l'errore risalga come diniego leggibile.
- **Lo spostamento tocca un test esistente.**
  `TrailRegistryNovaResourcesTest.php:42` verifica che l'azione sia sulla Resource del
  codice: va aggiornato, non cancellato.
- **`availableNumbers()` cambia significato.** È un metodo pubblico di un service del
  package. I chiamanti trovati sono solo l'azione stessa e due test, ma un consumer che
  lo usasse riceverebbe un elenco più ampio senza nessun segnale.
- **Le chiavi di traduzione sono le stringhe italiane.** Con `__('Sostituisci numero')`
  una chiave mancante in `en.json` mostra italiano a un utente inglese, e nulla lo
  segnala: va verificato a occhio.
- **Dopo un revert, i dati restano.** Questa è la prima funzione che scrive varianti
  diverse da `'0'` per via manuale: un `ZNUB213A` creato nel frattempo sopravvive al
  revert e il codice revertato non sa più modificarlo.

## Out of scope

- L'algoritmo che propone il numero per prossimità invece del primo libero — è oc:8570, e
  oggi `TrailRegistryService::propose()` prende il primo libero da 0 a 99 nel settore
- La visualizzazione dei sentieri vicini sulla mappa dell'istanza — è oc:8568
- La sostituzione di un codice già assegnato, che resta vietata
- **Uscire dal settore.** La sostituzione avviene dentro il settore del codice corrente,
  che resta quello determinato per intersezione geometrica. Un sentiero al confine può
  quindi ricadere in un settore diverso da quello dei suoi vicini, e in quel caso il
  numero che l'operatore cerca non compare in tendina. Cambiare settore significa
  cambiare il codice, non solo il numero: è un ticket a sé
- Qualunque notifica al richiedente sul cambio di numero: nelle call non se n'è parlato, e
  la numerazione è materia di FoReSTAS

## Moduli toccati

Tutto in `wm-package`: il registro e l'azione appartengono al package.

| File | Cosa cambia |
|---|---|
| `src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php` | due campi invece di uno, con il secondo dipendente dal primo; opera partendo dall'istanza |
| `src/TrailRegistry/TrailRegistryService.php` | `availableNumbers()` diventa ciò che il nome promette — le combinazioni disponibili, varianti comprese — invece della sola fetta con `variant = '0'`; si aggiunge il metodo per le combinazioni libere di un numero dato; `replaceNumber()` accetta la variante e sposta il guard dentro la transazione con `lockForUpdate()` |
| `tests/Feature/TrailRegistry/ProposeTest.php`, `ApproveTrailApplicationTest.php` | si adeguano al nuovo significato di `availableNumbers()` |
| `src/TrailRegistry/Nova/TrailApplication.php` | registra l'azione, risalendo al codice attivo |
| `src/TrailRegistry/Nova/TrailRegistryCode.php` | rimuove la registrazione dell'azione |
| `resources/lang/it.json`, `resources/lang/en.json` | chiavi delle nuove etichette |
| `tests/Feature/TrailRegistry/` | sostituzione con numero; con variante su numero già preso; rifiuto di una combinazione presente; diniego su codice `Assigned`; approvazione concorrente fra apertura e salvataggio; diniego su istanza senza codice attivo; id di istanza e codice divergenti; assenza dell'azione sulla Resource del codice |

## Copertura delle fonti

Delle undici call individuate fra il 07/09 e il 21/09 ne sono state lette otto: non è stata
leggibile, per dimensione, quella che sembra la sessione FoReSTAS principale del 17/09
pomeriggio. Le decisioni qui sopra che non hanno una citazione a fianco sono state prese
con il dev in fase di pianificazione, non trovate nelle trascrizioni.

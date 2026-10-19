> Ticket: oc:8165

# Tracce UGC: tratti dritti lunghi e origine della traccia

## Cosa cambia

Due correzioni al modo in cui il package guarda una traccia UGC. Nessuna delle due riguarda il
passaporto, che resta tutto nel consumer: sono difetti del dato prodotto qui, che il passaporto
si limita a far emergere perché è il primo a usarlo per certificare qualcosa.

### Il tratto dritto lungo non vale più come percorso

Quando il GPS smette di registrare — spento, batteria, galleria, segnale assente — la traccia
salvata non si interrompe: i punti tenuti vengono uniti in ordine in un unico pezzo, quindi fra
l'ultimo punto prima del buco e il primo dopo resta un **segmento dritto**. È una scelta
deliberata di oc:8719 («i buchi sono di 18-193 s, il segmento dritto basta»), presa rifiutando di
agganciare il buco alla rete dei sentieri.

Il matching di oc:8773 tratta quel segmento come qualunque altro tratto percorso: le tappe che
attraversa risultano toccate. Per buchi brevi è giusto, e dà il beneficio del dubbio a chi ha
camminato davvero. Per buchi lunghi no: una linea dritta di chilometri attraversa tappe intere e
le marca come percorse a chi in mezzo era in macchina o in treno.

Il matching impara a distinguerli: **un tratto dritto più lungo della lunghezza di un segmento
vale solo per i suoi due capi**, cioè per i segmenti che stanno entro la distanza massima dal
punto in cui il segnale si è perso e da quello in cui è tornato. Del mezzo non si sa nulla, e una
tappa attraversata di traverso — lontana da entrambi i capi — non prende niente. Nessun parametro
nuovo: la soglia è la lunghezza di segmento già configurata (`segment_length_meters`, 400 m).

### `origin()` smette di sbagliare su venti tracce

`UgcTrack::origin()` (oc:8747) decide se una traccia è registrata o importata da tre indizi:
`_gpxType`, `locations` e, in mancanza d'altro, `distanceFilter`. Quell'ultimo ramo è sbagliato:
`distanceFilter` è un'impostazione del GPS che può restare valorizzata anche su una traccia
importata, mentre ciò che distingue una UGC track registrata è avere i punti grezzi. Nel
frattempo oc:8764 (`wm-core`) ha introdotto `properties.import`, il campo esplicito che le UGC
importate da file portano con sé, e che `origin()` non legge.

La regola diventa: `import` presente → importata; `_gpxType` presente → importata; `locations`
non vuote → registrata; in ogni altro caso importata.

## Perché

**Sul tratto dritto.** Il consumer — il passaporto di Cammini d'Italia — sta per usare i segmenti
toccati per certificare che una persona ha percorso una tappa, e un dato di certificazione non
può venire da un tratto che nessuno ha registrato.

I dati reali dicono che non è un caso di scuola. Sulle 267 tracce UGC del database di sviluppo ci
sono circa 400.000 salti fra punti consecutivi, con il 95% sotto i 21 metri — ma **il salto più
lungo misura 17,4 km**, e sta su una UGC track registrata, non su un file importato. Fra le sole
registrate, 20 su 134 hanno almeno un salto oltre i 400 metri e 12 salti superano il chilometro.

**Su `origin()`.** Il metodo classifica male 20 tracce su 267. Non è un problema solo del
passaporto: è il badge «Origin» che il gestore legge in Nova a dire la cosa sbagliata, dal giorno
in cui è stato introdotto.

## Requisiti

### Tratti dritti lunghi

- [ ] Nel calcolo dei segmenti toccati, un tratto fra due punti consecutivi più lungo della
      lunghezza di segmento configurata marca solo i segmenti entro la distanza massima dai suoi
      due capi
- [ ] Una tappa che quel tratto attraversa restando lontana da entrambi i capi non prende nulla
- [ ] Nessun parametro nuovo: la soglia è la lunghezza di segmento già esistente
- [ ] **Le prestazioni non peggiorano**: il confronto resta a pezzi di `CHUNK_VERTICES` vertici.
      Misurato su questo database, passare a un confronto per singolo lato costa 305 s contro
      16,9 s sulla stessa traccia, a parità di risultato — diciotto volte tanto. I tratti lunghi
      sono 214 su 399.290, quindi si trattano a parte
- [ ] Il comportamento sui tratti brevi resta identico: i risultati già calcolati cambiano solo
      dove c'è un salto lungo
- [ ] I dati già calcolati si riallineano con `wm:match-ugc-tracks`, senza un command nuovo

### Origine della traccia

- [ ] `UgcTrack::origin()` legge `properties.import` (oc:8764) come primo indizio
- [ ] Il ramo `distanceFilter` viene rimosso: in mancanza di indizi la traccia è importata
- [ ] Il badge «Origin» in Nova riflette la correzione senza modifiche proprie

## Rischi

- **Cambia un risultato già in produzione.** Le tracce con un salto lungo perderanno segmenti che
  oggi risultano toccati, e 20 tracce cambieranno badge da «registrata» a «importata». È la
  correzione voluta, ma va detta: sono dati che qualcuno può aver già guardato.
- **Un tratto appena sotto la soglia resta valido per intero.** La regola ha un gradino: un salto
  di 399 metri vale tutto, uno di 401 vale due segmenti. Accettato: ogni soglia ha un gradino, e
  questo sta a una distanza che si percorre in pochi minuti.
- **Il limite non distingue il motivo del salto.** Un segnale perso in galleria e un tratto fatto
  in auto producono lo stesso dato. Distinguerli vorrebbe dire guardare i tempi, cosa che
  oc:8719 ha già scartato dopo aver verificato che penalizzava le pause legittime.
- **Le 20 tracce riclassificate potrebbero essere UGC track vere** di persone che hanno
  l'impostazione valorizzata e i punti grezzi persi per altra via. Non verificabile dai dati: in
  quel caso quelle persone non vedranno quelle UGC track contare nel passaporto, e dovranno
  passare dalla certificazione cartacea.

## Out of scope

- **Agganciare il buco alla rete dei sentieri** per ricostruire il percorso probabile: già
  scartato da oc:8719, legherebbe il package ai dati di un singolo progetto.
- **Regole basate sul tempo fra punti consecutivi**: scartate da oc:8719, toglievano punti buoni
  dopo ogni pausa.
- **Registrare nei dati dove sono i buchi**: non serve a questa regola, che li riconosce mentre
  calcola.
- **Riempire `properties.import` sulle tracce importate prima di oc:8764**: il dato del file non
  esiste più, e per loro restano gli indizi.

## Moduli toccati

- `src/Services/Models/UgcTrackMatchingService.php` — il limite dentro la query che calcola i
  segmenti toccati
- `src/Models/UgcTrack.php` — `origin()`
- `tests/` — copertura delle due regole, e verifica che i tratti brevi non cambino
- `docs/knowledge/` — aggiornamento della pagina sulla corrispondenza UGC ↔ tappe, e una pagina
  su come si distingue una traccia registrata da una importata: oggi la regola vive sparsa fra il
  codice di `wm-package` e `wm-core/docs/knowledge/ugc.md`, e dal package non si trova

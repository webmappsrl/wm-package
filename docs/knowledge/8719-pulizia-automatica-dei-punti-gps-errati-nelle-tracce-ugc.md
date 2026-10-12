# Pulizia dei punti GPS delle tracce UGC

## Come funziona oggi

L'app registra in `properties.locations` ogni posizione ricevuta dal telefono, senza filtrarla, e
manda come `geometry` la linea che le unisce. Quando il GPS perde il segnale arrivano due tipi di
posizioni con accuracy alta:

- **posizioni stimate**: il telefono prosegue con l'ultima velocità e direzione note; l'accuracy
  cresce a ogni punto (circa +800 m) ma i punti restano vicini al percorso;
- **posizioni da antenna**: il telefono restituisce la posizione dell'antenna cellulare o Wi-Fi,
  sempre la stessa con la stessa accuracy (DB di sviluppo di camminiditalia, 07/10/2026: nella UgcTrack 169 la stessa
  coordinata con accuracy 300 m compare 8 volte), anche a 10 km dal percorso.

`Wm\WmPackage\Services\Models\UgcTrackCleanupService` è l'unico posto con le regole:

- **sempre scartato**: coordinate non numeriche, non finite o fuori range, oppure (0,0) (l'app
  inizia la geometria con `[0, 0, 0]` se parte senza posizione);
- **sospetto**: accuracy oltre `ugc_track_max_accuracy_meters` (default 40);
- un sospetto **si scarta solo se** dista più di `ugc_track_max_deviation_meters` (default 50) dal
  tratto fra il punto tenuto precedente e il primo punto buono successivo; in testa e in coda si
  misura dall'unica ancora.

La pulizia gira sull'evento `saving` della UgcTrack, prima di `creating`/`updating`: il cammino
calcolato in `UgcObserver::created()` vede già la geometria pulita. Il command per le tracce
esistenti ricalcola le località ma **non il cammino**. Le stesse regole valgono per le statistiche
dell'immagine di condivisione e per la vista Nova.

`properties.locations` non si modifica mai: la geometria si ricalcola sempre da lì. Le tracce
senza `locations` non vengono toccate. La geometria pulita arriva sull'app alla sincronizzazione
successiva: l'app confronta la geometria del server con la sua copia e la sostituisce se diversa.

## Perché così

Le misure citate qui vengono dal DB di sviluppo di camminiditalia (07/10/2026).

- **Accuracy e distanza insieme** (oc:8719): l'accuracy dice che un punto è sospetto, la distanza
  dal percorso dice se è davvero sbagliato. Con la sola accuracy la 169 perdeva 122 punti su
  1016, molti a 5-25 m dal percorso; con le due condizioni ne perde 31, tutti salti veri.
- **Nessuna regola su tempo e distanza fra punti consecutivi** (oc:8719): l'app non salva le
  pause, e un buco di tempo seguito da uno spostamento è identico a un segnale perso. Un filtro
  sui salti scartava punti giusti dopo ogni pausa, anche a catena (verificato sulla traccia 198).
- **Pulizia al salvataggio, non in coda** (oc:8719): un job in coda arrivava dopo il calcolo del
  cammino, fatto sulla geometria sporca.
- **Confronto con tolleranza** (oc:8719): la geometria inviata dall'app ha 9 decimali, quella
  ricostruita 8; con `ST_Equals` esatto 39 tracce identiche al millimetro risultavano cambiate. Si
  confronta con `ST_HausdorffDistance` ≤ 10⁻⁶ gradi.
- **Dal punto scartato non si recupera nulla** (oc:8719): coordinate dell'antenna, velocità
  calcolata sul salto, direzione vuota, quota copiata dal punto precedente. L'unico dato vero è il
  tempo. I punti hanno solo i 9 campi del plugin `@capacitor-community/background-geolocation`:
  nessuna bussola né sensore.

## Come ci siamo arrivati

- **Solo accuracy, soglia 40 m** (oc:8719, superata): scartava punti giusti nei paesi, dove il
  telefono dichiara un'accuracy pessimistica ma la posizione è corretta.
- **Filtro sui salti per velocità implicita** (oc:8719, scartato): vedi «Perché così».
- **Job in coda per la pulizia delle tracce nuove** (oc:8719, superato dalla pulizia su
  `saving`).
- **Aggancio del buco alla tappa o alla rete dei sentieri** (oc:8719, non fatto): i buchi sono di
  18-193 s, il segmento dritto basta, e legherebbe il package a dati di un singolo progetto.

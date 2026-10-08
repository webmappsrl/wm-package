# Dati tecnici delle tracce UGC (`properties.stats`)

Specifica del calcolo di distanza, tempi e velocità di una UgcTrack registrata (oc:8742). Il
server la applica a ogni traccia salvata; l'app (wm-core, oc:8743) la rifà **identica** per le
tracce non ancora sincronizzate. Chi implementa il calcolo deve poterlo fare leggendo solo questa
pagina: se un passo non è chiaro qui, è un errore della pagina. Il codice di riferimento è
`Wm\WmPackage\Services\Models\UgcTrackStatsService` (punti tenuti:
`UgcTrackCleanupService::keptFlags()`), i casi di test condivisi sono in
`tests/fixtures/ugc-track-stats/`.

Gli esempi svolti usano la UgcTrack 174 del DB di sviluppo di camminiditalia (08/10/2026): 418
punti in `properties.locations`, una camminata di circa 9 km in montagna con GPS disturbato in più
tratti.

## 1. Formato di `stats`

Valori reali della traccia 174 (le chiavi DEM sono quelle restituite dal servizio DEM sulla
geometria pulita):

```json
"stats": {
  "distance": 9.37,
  "ascent": 313,
  "descent": 610,
  "ele_min": 935,
  "ele_max": 1456,
  "ele_from": 1236,
  "ele_to": 939,
  "duration": 165,
  "duration_moving": 144,
  "avg_speed": 3.4,
  "max_speed": 6.5,
  "computed_at": "2026-10-08T11:42:47Z"
}
```

| Chiave | Unità | Tipo | Decimali | Chi la calcola |
|---|---|---|---|---|
| `distance` | km | `float` (mai `null`) | 2 | server e app |
| `duration` | minuti | `int` o `null` | 0 | server e app |
| `duration_moving` | minuti | `int` o `null` | 0 | server e app |
| `avg_speed` | km/h | `float` o `null` | 1 | server e app |
| `max_speed` | km/h | `float` o `null` | 1 | server e app |
| `computed_at` | data e ora UTC, ISO 8601 al secondo (`YYYY-MM-DDTHH:MM:SSZ`) | `string` | — | server (l'app può scriverla per sé) |
| `ascent`, `descent` | m | numero o `null` | come li dà il DEM (interi) | solo server, servizio DEM |
| `ele_min`, `ele_max`, `ele_from`, `ele_to` | m s.l.m. | numero o `null` | come li dà il DEM (interi) | solo server, servizio DEM |

- Le chiavi ci sono **tutte**, sempre: un valore che non si può calcolare è `null`, mai `0` e mai
  chiave assente. Fa eccezione il caso «nessun dato» (meno di 2 punti tenuti, o nessun
  `locations`): lì manca l'intero `stats`.
- Nel JSON un `float` con parte decimale nulla esce senza decimali (`"max_speed": 4`, non `4.0`):
  `4` e `4.0` sono lo stesso valore, chi legge non deve distinguerli. In TypeScript è comunque
  `number`.
- Unità e nomi di `distance`, `ascent`, `descent`, `ele_*` sono gli stessi di `dem_data` delle
  EcTrack (vedi [Dati DEM e valori manuali](dati-dem-e-valori-manuali.md)). `duration` invece
  **non** è `duration_forward`: è il tempo reale registrato, non una stima.

## 2. Passi comuni

Si eseguono in quest'ordine, sulla lista `locations` = `properties.locations` così com'è
(nell'ordine dell'array, senza riordinare per `time`).

Ogni punto ha i campi del plugin di geolocalizzazione dell'app; qui servono:

| Campo | Unità | Note |
|---|---|---|
| `latitude`, `longitude` | gradi decimali WGS84 | |
| `time` | millisecondi dall'epoch Unix (es. `1779353763310`) | |
| `accuracy` | metri | precisione orizzontale dichiarata dal telefono |
| `speed` | km/h | si usa così com'è, **nessuna conversione** |

**«Numerico»** in questa pagina vuol dire: un numero finito, oppure una stringa che rappresenta un
numero (il server, con `is_numeric()` di PHP, accetta anche `"12.5"` e `" 12.5"` e li usa come
numeri). L'app manda sempre numeri, quindi in TypeScript basta
`typeof v === 'number' && Number.isFinite(v)`. `null`, assente, booleano, stringa vuota: non
numerico.

### (a) Punti tenuti

La regola è quella della pulizia GPS di oc:8719 (motivazioni in
[Pulizia dei punti GPS delle tracce UGC](8719-pulizia-automatica-dei-punti-gps-errati-nelle-tracce-ugc.md)),
con due parametri: `max_accuracy` (default 40 m) e `max_deviation` (default 50 m). Qui per intero.

Ogni punto è di uno di tre tipi:

- **invalido**: non è un oggetto; `latitude` o `longitude` non numerica; `|latitude| > 90` o
  `|longitude| > 180`; oppure `latitude == 0` **e** `longitude == 0`. Sempre scartato.
- **sospetto**: valido, con `accuracy` numerica, `accuracy >= 0` e `accuracy > max_accuracy`
  (strettamente maggiore: 40 con soglia 40 è buono).
- **buono**: valido e non sospetto. Un `accuracy` assente, non numerico o negativo rende il punto
  buono: non c'è modo di giudicarlo, quindi si tiene.

Poi si scorrono i punti in ordine:

```text
leftAnchor = null                       // ultimo punto tenuto finora
for i in 0..n-1:
  if tipo[i] == invalido: kept[i] = false; continue
  if tipo[i] == buono:    kept[i] = true;  leftAnchor = p[i]; continue
  // sospetto
  rightAnchor = primo punto di tipo «buono» con indice > i, oppure null
  if leftAnchor == null and rightAnchor == null: kept[i] = false; continue
  from = leftAnchor ?? rightAnchor;  to = rightAnchor ?? leftAnchor
  kept[i] = distanzaPuntoSegmento(p[i], from, to) <= max_deviation
  if kept[i]: leftAnchor = p[i]         // un sospetto tenuto diventa ancora sinistra
```

L'ancora destra è sempre un punto **buono** (mai un sospetto, anche se poi verrebbe tenuto);
l'ancora sinistra è l'ultimo punto **tenuto**, che può essere un sospetto.

`distanzaPuntoSegmento(P, A, B)` in metri, con una proiezione piana locale centrata su P
(R = 6.371.000 m, angoli in radianti):

```text
c  = cos(rad((P.lat + A.lat + B.lat) / 3))
ax = rad(A.lon - P.lon) * c * R;   ay = rad(A.lat - P.lat) * R
bx = rad(B.lon - P.lon) * c * R;   by = rad(B.lat - P.lat) * R
dx = bx - ax;  dy = by - ay;  L2 = dx*dx + dy*dy
t  = L2 > 0 ? clamp(-(ax*dx + ay*dy) / L2, 0, 1) : 0
return hypot(ax + t*dx, ay + t*dy)
```

Con una sola ancora (A = B) è la distanza dal punto A. Il risultato di questo passo è la lista
`kept` dei punti tenuti, **nello stesso ordine** di `locations`.

### (b) Tratto

Un **tratto** è la coppia di due punti tenuti consecutivi in `kept`: `(kept[i-1], kept[i])` per
`i = 1 .. kept.length-1`. Con k punti tenuti i tratti sono k − 1. I punti scartati non esistono
più: se fra due tenuti c'erano tre scartati, i due tenuti formano un unico tratto.

### (c) Lunghezza del tratto: haversine

R = 6.371.000 m, angoli convertiti in radianti (`rad(x) = x * π / 180`):

```text
φ1 = rad(A.lat);  φ2 = rad(B.lat)
Δφ = φ2 - φ1;     Δλ = rad(B.lon - A.lon)
h  = sin(Δφ/2)² + cos(φ1) · cos(φ2) · sin(Δλ/2)²
d  = 2 · R · asin(min(1, √h))          // metri
```

Il `min(1, …)` evita `NaN` quando l'arrotondamento porta `√h` appena sopra 1.

### (d) Δt del tratto

```text
Δt = (B.time - A.time) / 1000           // secondi, con decimali
```

Il tratto ha un **Δt valido** solo se `A.time` e `B.time` sono numerici **e** `Δt > 0`. Un tratto
senza Δt valido (tempo mancante, uguale o all'indietro) **conta nella distanza** ma è ignorato da
tutto ciò che usa il tempo: `duration_moving`, `avg_speed` e la velocità dei tratti.

Per un tratto con Δt valido la **velocità del tratto** è:

```text
v = d / Δt * 3.6                        // km/h
```

### (e) Tratto in movimento

Un tratto con Δt valido è **in movimento** se `v >= moving_min_speed` (default 1 km/h; il confine
è incluso: 1,0 km/h è movimento).

### (f) Tratto con GPS buono

Un tratto è **con GPS buono** se entrambi i suoi punti hanno `accuracy` numerica e
`accuracy <= max_accuracy` (default 40 m; il confine è incluso). Un `accuracy` assente rende il
tratto **non** buono: attenzione, è l'opposto della regola (a), dove un `accuracy` assente fa
tenere il punto. Un `accuracy` negativo invece è numerico e ≤ `max_accuracy`: il tratto è buono.

### Arrotondamento

Il server arrotonda con `round()` di PHP: **metà lontano dallo zero** (2,5 → 3; 1,45 a un decimale
→ 1,5), e con un pre-arrotondamento a 15 cifre significative che fa uscire «giusti» i valori che in
binario non sono esatti (1,005 a due decimali → 1,01). In JavaScript **non** usare:

- `Math.round(x * 10**d) / 10**d`: 1,005 → 1,00 e 0,285 → 0,28 (il prodotto in binario vale
  100,49999…); su numeri negativi arrotonda la metà verso +∞ (−2,5 → −2);
- `x.toFixed(d)`: 1,45 → "1.4", 2,675 → "2.67", e restituisce una stringa.

Funzione che riproduce `round()` di PHP 8.4 (verificata su 200.000 valori casuali con 0, 1 e 2
decimali, positivi e negativi, nessuna differenza):

```ts
function phpRound(x: number, decimals: number): number {
  const f = 10 ** decimals;
  const y = Number((Math.abs(x) * f).toPrecision(15));
  return (Math.sign(x) * Math.round(y)) / f;
}
```

I valori di `stats` sono tutti ≥ 0, quindi in pratica conta il pre-arrotondamento, non il segno.
L'arrotondamento si fa **una volta sola, alla fine**, sul valore calcolato con tutti i decimali:
mai sommare valori già arrotondati.

## 3. Le chiavi, una per una

In ogni pseudocodice `kept` è la lista del passo (a), `segments` i tratti del passo (b) con
`d` (metri), `dtValid`, `dt` (secondi), `v` (km/h).

Prima di tutto:

```text
if locations non è un array o è vuoto: nessuno stats
kept = puntiTenuti(locations)
if kept.length < 2: nessuno stats
```

### `distance`

- **Dati**: coordinate dei punti tenuti.
- **Formula**: somma delle lunghezze haversine di tutti i tratti, **anche** quelli senza Δt valido.
- **Pseudocodice**:

  ```text
  meters = 0
  for s in segments:
    meters += s.d
  distance = phpRound(meters / 1000, 2)
  ```

- **Arrotondamento**: 2 decimali, in km.
- **`null`**: mai (se c'è `stats`, c'è la distanza; può valere `0`).
- **Traccia 174**: 381 punti tenuti, 380 tratti, 9.367,4785 m → 9,3674785 km → **9.37**.

### `duration`

- **Dati**: `time` dei punti tenuti.
- **Formula**: tempo dell'**ultimo** punto tenuto con `time` numerico meno quello del **primo**
  punto tenuto con `time` numerico, nell'ordine dell'array (non il minimo e il massimo). Le soste
  sono comprese.
- **Pseudocodice**:

  ```text
  times = [p.time for p in kept if numerico(p.time)]
  if times.length < 2: duration = null
  else:
    seconds = (times[last] - times[0]) / 1000
    duration = seconds > 0 ? phpRound(seconds / 60, 0) : null   // intero
  ```

- **Arrotondamento**: al minuto intero (0 decimali).
- **`null`**: meno di 2 punti tenuti con `time` numerico, oppure ultimo tempo ≤ primo tempo.
- **Traccia 174**: (1.779.363.658.165 − 1.779.353.763.310) / 1000 = 9.894,855 s = 164,91 min →
  **165**.

### `duration_moving`

- **Dati**: tratti con Δt valido e la loro velocità.
- **Formula**: somma dei Δt dei tratti in movimento (passo e), in qualunque condizione di GPS: il
  tempo è reale anche quando la posizione è disturbata.
- **Pseudocodice**:

  ```text
  movingSeconds = 0;  hasTimed = false
  for s in segments:
    if not s.dtValid: continue
    hasTimed = true
    if s.v >= moving_min_speed: movingSeconds += s.dt
  duration_moving = hasTimed ? phpRound(movingSeconds / 60, 0) : null   // intero
  ```

- **Arrotondamento**: al minuto intero.
- **`null`**: nessun tratto con Δt valido. Se ci sono tratti con Δt valido ma nessuno in
  movimento vale `0`, non `null`.
- **Traccia 174**: 380 tratti con Δt valido; 14 sotto 1 km/h (1.257,372 s, le soste); 366 in
  movimento per 8.637,483 s = 143,96 min → **144**.

### `avg_speed`

- **Dati**: tratti in movimento con GPS buono.
- **Formula**: metri diviso secondi dei soli tratti che sono **insieme** con Δt valido, in
  movimento (e) e con GPS buono (f), in km/h. Si sommano metri e secondi e si divide alla fine:
  **non** è la media delle velocità dei tratti.
- **Pseudocodice**:

  ```text
  goodMeters = 0;  goodSeconds = 0
  for s in segments:
    if not s.dtValid or s.v < moving_min_speed: continue
    if goodAccuracy(s.from) and goodAccuracy(s.to):
      goodMeters += s.d;  goodSeconds += s.dt
  avg_speed = goodSeconds > 0 ? phpRound(goodMeters / goodSeconds * 3.6, 1) : null
  ```

  con `goodAccuracy(p) = numerico(p.accuracy) and p.accuracy <= max_accuracy`.
- **Arrotondamento**: 1 decimale, in km/h.
- **`null`**: nessun tratto in movimento con GPS buono (per esempio una traccia senza `accuracy`
  su nessun punto).
- **Traccia 174**: dei 366 tratti in movimento, 110 (2.538,161 s) hanno almeno un punto con
  accuracy > 40 m e restano fuori; i 256 rimasti fanno 5.740,2308 m in 6.099,322 s →
  3,3881 km/h → **3.4**.

### `max_speed`

- **Dati**: campo `speed` dei punti tenuti; se nessuno lo ha, la velocità dei tratti.
- **Formula**: percentile `max_speed_percentile` (default 95) con il metodo nearest-rank (sezione
  4) sui valori di `speed` dei punti tenuti che sono numerici e `>= 0` (lo `0` conta). Se **nessun**
  punto tenuto ha uno `speed` così, lo stesso percentile sulle velocità `v` di **tutti** i tratti
  con Δt valido (anche quelli fermi e quelli con GPS disturbato). Basta un solo `speed` valido per
  usare il campo `speed`, non le posizioni.
- **Pseudocodice**:

  ```text
  speeds = [p.speed for p in kept if numerico(p.speed) and p.speed >= 0]
  if speeds is empty:
    speeds = [s.v for s in segments if s.dtValid]
  q = percentile(speeds, max_speed_percentile)     // null se speeds è vuoto
  max_speed = q == null ? null : phpRound(q, 1)
  ```

- **Arrotondamento**: 1 decimale, in km/h.
- **`null`**: nessuno `speed` valido e nessun tratto con Δt valido.
- **Non è il massimo.** È la velocità superata solo dal 5% dei campioni: chi la confronta con il
  massimo di un altro strumento vede un valore più basso. Su tracce di pochi punti può risultare
  perfino più bassa di `avg_speed`: è voluto, non si corregge (vedi sezione 10).
- **Traccia 174**: 381 valori di `speed` validi (da 0 a 890,25 km/h: il picco è un salto GPS
  rimasto sul percorso); rango ⌈0,95 × 381⌉ = ⌈361,95⌉ = 362; il 362° valore in ordine crescente
  è 6,53347332 → **6.5**. Con il massimo semplice sarebbe stato 890,3.

### `computed_at`

- **Dati**: l'orologio del server al momento del calcolo.
- **Formula**: data e ora UTC al secondo, formato `YYYY-MM-DDTHH:MM:SSZ` (senza millisecondi).
- **Pseudocodice**: `computed_at = new Date().toISOString().slice(0, 19) + 'Z'`
- **Arrotondamento**: troncato al secondo. **`null`**: mai.
- Non è un dato per l'utente: il server lo usa come versione del calcolo, per non far scrivere
  al job DEM un dislivello vecchio (sezione 9). I casi di test condivisi non lo contengono. Quando
  l'app sincronizza, il server ignora lo `stats` che riceve e ne calcola uno suo.
- **Traccia 174**: `2026-10-08T11:42:47Z` nell'esecuzione di verifica; cambia a ogni calcolo.

## 4. Percentile nearest-rank

```text
percentile(values, p):          // p in (0, 100]
  if values is empty: return null
  sorted = values ordinati in modo crescente come numeri
  n = sorted.length
  rank = max(1, ceil(p / 100 * n))
  rank = min(rank, n)
  return sorted[rank - 1]       // indice da 0
```

- Nessuna interpolazione: il risultato è sempre uno dei valori.
- In JavaScript `Array.prototype.sort()` senza comparatore ordina come stringhe (`[10, 9]` resta
  `[10, 9]`): usare `sort((a, b) => a - b)`.
- Calcolare il rango come `p / 100 * n`, nello stesso ordine. In virgola mobile 0,95 × 7 fa
  6,6499999…, quindi ⌈⌉ = 7: lo stesso in PHP e in JavaScript, che usano gli stessi `double`.

Esempio: valori `[3.1, 4.0, 2.2, 5.5, 3.8, 4.4, 3.9, 12.0, 4.1, 3.5]`, p = 95.
Ordinati: `[2.2, 3.1, 3.5, 3.8, 3.9, 4.0, 4.1, 4.4, 5.5, 12.0]`, n = 10,
rango = ⌈0,95 × 10⌉ = ⌈9,5⌉ = 10 → **12.0**. Con 10 valori il 95° percentile coincide con il
massimo: su tracce brevi il percentile non toglie i picchi. Con 20 valori il rango è 19 e il valore
più alto resta fuori.

## 5. Chiavi DEM

`ascent`, `descent`, `ele_min`, `ele_max`, `ele_from`, `ele_to` le calcola **solo il server**, con
il servizio DEM (`dem.maphub.it`, lo stesso client delle EcTrack: `EcTrackService::fetchDemTechData()`)
sulla geometria pulita, in 2D: le quote vengono dal modello del terreno, non dal GPS del telefono.

- Le richiede `UpdateUgcTrackDemStatsJob`, sulla coda `dem`, accodato dopo il commit quando nasce
  uno `stats` con le chiavi DEM tutte `null` (traccia nuova, punti cambiati, command). Tre
  tentativi, a 60 s e 300 s.
- Il job scrive in SQL **solo** le sue sei chiavi dentro `stats`, senza toccare i valori locali e
  senza far ripartire gli observer. Un valore che il servizio non restituisce resta `null`.
- **L'app non le replica.** Per una traccia non sincronizzata l'app mostra i valori locali e lascia
  vuoto il dislivello (o lo calcola dalla quota GPS, sapendo che è un altro numero, sezione 10);
  per una traccia sincronizzata legge `stats` dal server. Le chiavi DEM `null` vogliono dire «non
  ancora pronte» o «servizio non disponibile»: l'app mostra «non disponibile», mai `0`.
- Traccia 174 (servizio DEM interrogato sui 381 punti tenuti): `ascent` 313, `descent` 610,
  `ele_min` 935, `ele_max` 1456, `ele_from` 1236, `ele_to` 939. Con la quota GPS l'app mostrava
  481 m di salita.

## 6. Casi limite

| Caso | Risultato |
|---|---|
| Traccia senza `locations`, o `locations` vuoto (file caricati GPX/KML/GeoJSON, app 3.1.2–3.1.6) | nessuno `stats`; se c'era, il server lo toglie |
| Meno di 2 punti tenuti (anche se `locations` ne ha di più) | nessuno `stats` |
| `time` assente su tutti i punti tenuti | `distance` calcolata; `duration`, `duration_moving`, `avg_speed` `null`; `max_speed` dal campo `speed` se c'è, altrimenti `null` |
| `time` assente su alcuni punti | i tratti che li toccano contano solo nella distanza; `duration` va dal primo all'ultimo punto tenuto **con** `time` |
| `time` non crescente fra due punti (uguale o all'indietro) | quel tratto conta nella distanza, è ignorato per tempi e velocità; `duration` è `null` se l'ultimo tempo è ≤ del primo |
| `speed` assente su tutti i punti tenuti | `max_speed` = percentile delle velocità dei tratti con Δt valido |
| `speed` negativo su un punto (il telefono non la conosce) | quel valore si ignora, come se mancasse |
| `speed` = 0 | conta come valore valido |
| `accuracy` assente su un punto | il punto si tiene (buono, passo a); i tratti che lo toccano restano fuori da `avg_speed` |
| `accuracy` assente su tutti i punti | `avg_speed` `null`, il resto calcolato |
| `accuracy` negativo su un punto | il punto si tiene (buono, passo a) e i tratti che lo toccano sono con GPS buono (passo f) |
| Nessun tratto in movimento (tutti < 1 km/h) | `duration_moving` 0, `avg_speed` `null` |
| `accuracy` esattamente uguale a `max_accuracy` | punto buono e tratto con GPS buono (confine incluso) |
| Velocità del tratto esattamente uguale a `moving_min_speed` | in movimento (confine incluso) |

## 7. Parametri

| Parametro | `config/wm-package.php` | Variabile d'ambiente | `GEOLOCATION.record.stats` | Default | Perché |
|---|---|---|---|---|---|
| Accuracy massima (m) | `ugc_track_max_accuracy_meters` | `UGC_TRACK_MAX_ACCURACY_METERS` | `max_accuracy` | 40 | oc:8719: sopra i 40 m il punto è sospetto; qui decide anche quali tratti entrano nella media. Metà delle tracce ha il GPS entro 40 m per il 100% del tempo, il 90% per almeno il 99% |
| Distanza massima dal percorso (m) | `ugc_track_max_deviation_meters` | `UGC_TRACK_MAX_DEVIATION_METERS` | `max_deviation` | 50 | oc:8719: un sospetto entro 50 m dal percorso è una posizione giusta |
| Percentile della velocità massima | `ugc_track_max_speed_percentile` | `UGC_TRACK_MAX_SPEED_PERCENTILE` | `max_speed_percentile` | 95 | su 39 tracce con più di 100 punti, nessun valore assurdo (il massimo semplice ne dava 14) |
| Velocità minima del movimento (km/h) | `ugc_track_moving_min_speed_kmh` | `UGC_TRACK_MOVING_MIN_SPEED_KMH` | `moving_min_speed` | 1 | sotto 1 km/h ci sono le soste; fra 1 e 2 km/h le salite ripide |

- Un valore vuoto, non numerico o ≤ 0 (per il percentile anche > 100) fa valere il default.
- La normalizzazione la fa solo il server: legge la config, applica i default qui sopra e scrive
  in `config.json` i valori già validi. L'app li usa così come arrivano.
- L'app legge i quattro valori da `config.json`, in `GEOLOCATION.record.stats`, presente solo
  quando l'App ha la registrazione attiva (`GEOLOCATION.record`). Se mancano, l'app usa i default
  di questa tabella.
- Il `config.json` non si rigenera da solo quando cambia una variabile d'ambiente: si scrive al
  salvataggio dell'App o con `UpdateAppConfigJob`. Dopo un cambio va rigenerato.

## 8. Casi di test condivisi

In `wm-package/tests/fixtures/ugc-track-stats/`, un file JSON per caso e un `README.md` che li
elenca:

| File | Cosa verifica |
|---|---|
| `01-cammino-regolare.json` | 11 punti, 10 m ogni 10 s |
| `02-sosta-distance-filter.json` | sosta registrata come un solo tratto lento: fuori dal tempo effettivo |
| `03-gps-disturbato-tenuto.json` | punto con accuracy 45 m tenuto: i suoi tratti non entrano nella media |
| `04-punto-scartato.json` | punto con accuracy 3000 a 50 km scartato; picco di `speed` 890 ignorato dal percentile |
| `05-due-punti-di-cui-uno-scartato.json` | un punto (0,0) scartato: nessuno `stats` |
| `06-senza-speed.json` | nessun `speed`: `max_speed` dal percentile delle velocità dei tratti (rango 20 su 21: 7,2 km/h, non il massimo 9,0) |
| `07-time-mancante-o-non-crescente.json` | un punto senza `time` e un tratto con Δt = 0: contano nella distanza, non in tempi e velocità |
| `08-sospetto-scartato.json` | sospetto (accuracy 45) a 81 m dal percorso fra i due punti buoni vicini, in mezzo alla traccia: scartato |
| `09-nessun-tratto-buono.json` | sospetti (accuracy 41 e 60) sul percorso, tenuti, alternati a punti buoni: `avg_speed` `null` |

Nel caso 09 non tutti i punti possono essere sospetti: senza un punto buono non c'è ancora destra,
e il primo sospetto, senza ancora sinistra, verrebbe scartato (passo a).

Formato:

```json
{
  "description": "testo",
  "params": {"max_accuracy": 40, "max_deviation": 50, "max_speed_percentile": 95, "moving_min_speed": 1},
  "locations": [{"time": 0, "latitude": 43.0, "longitude": 13.0, "accuracy": 5, "altitude": 100, "speed": 4}],
  "expected_kept": [true],
  "expected": {"distance": 0.1, "duration": 2, "duration_moving": 2, "avg_speed": 3.6, "max_speed": 4.0}
}
```

- `expected_kept`: un booleano per punto di `locations`, risultato del passo (a).
- `expected`: le cinque chiavi calcolate in locale (`distance`, `duration`, `duration_moving`,
  `avg_speed`, `max_speed`), **senza** `computed_at` e senza chiavi DEM;
  `null` quando non c'è `stats` (meno di 2 punti tenuti).
- In wm-package li legge `tests/Unit/Services/UgcTrackStatsFixturesTest.php`. In wm-core (oc:8743)
  il test fa lo stesso: per ogni file applica `params`, calcola i punti tenuti e le chiavi, e li
  confronta con `expected_kept` ed `expected` per uguaglianza esatta (i valori sono già
  arrotondati).
- Una regola che cambia si cambia nei casi e nel codice di **entrambi** i repo. Ogni valore di
  `expected` va controllato a mano con questa pagina, non copiato dall'output PHP.

## 9. Quando si ricalcola

- **Al salvataggio** (`UgcTrackGeometryCleanupObserver`, evento `saving`, sia store sia edit sia
  Nova):
  - traccia nuova, o `properties.locations` diverse da quelle nel DB → geometria ripulita e
    `stats` ricalcolato da capo, chiavi DEM a `null`; dopo il commit parte il job DEM;
  - punti uguali (cambio di layer, nome, form) → `stats` resta quello del DB, dislivello compreso;
  - lo `stats` mandato dal client non si salva mai: vince il calcolato o il salvato;
  - senza `locations`, o con meno di 2 punti tenuti → `stats` tolto.
  - Se un salvataggio cambia le properties e le chiavi DEM sono ancora tutte `null` (DEM fallito in
    precedenza), il job DEM riparte.
- **Con il command** `wm:clean-ugc-track-geometry` (opzioni `--dry-run`, `--app-id`, `--queue`):
  accoda `CleanUgcTrackGeometryJob` per **ogni** traccia con `locations`, anche quelle con la
  geometria già pulita. Il job ricalcola `stats`; conserva i valori DEM se sono tutti presenti e la
  geometria non è cambiata, altrimenti richiede il DEM. Dopo il deploy:
  `php artisan wm:clean-ugc-track-geometry --dry-run`, poi senza `--dry-run`. Il dry-run dice
  quante tracce cambierebbero geometria, quante riceverebbero `stats` e quante, con meno di 2
  punti tenuti, resterebbero senza.
- **Il job DEM** (`UpdateUgcTrackDemStatsJob`) riceve il `computed_at` dello `stats` per cui è stato
  accodato e scrive solo se nel DB c'è ancora quel `computed_at`: se nel frattempo i punti sono
  cambiati, il suo dislivello è vecchio e un altro job è già in coda. Il controllo si ripete nel
  `WHERE` dell'UPDATE.
- **Scritture concorrenti delle properties.** Un job che legge la traccia, aspetta e poi salva
  tutte le properties azzererebbe le chiavi DEM scritte nel frattempo. `UpdateModelWithGeometryTaxonomyWhere`
  (località) rilegge la traccia dal DB subito prima di salvare. `ResolveUgcLayerJob` di
  camminiditalia lascia ancora una finestra di pochi millisecondi: se capita, le chiavi DEM tornano
  `null` e il command le richiede al passaggio successivo.

## 10. Perché così

Le misure vengono dal DB di sviluppo di camminiditalia (08/10/2026: 116 tracce con punti GPS,
156.651 tratti) e dalla documentazione pubblica di Strava.

- **Calcolo sui soli punti tenuti** (oc:8742): l'app calcolava sui punti grezzi e sulla 174
  mostrava 1.056,6 km/h di velocità massima e 18,7 km/h di media.
- **Velocità massima come 95° percentile del campo `speed`** (oc:8742): la pulizia scarta i punti
  lontani dal percorso, non quelli che saltano in avanti lungo il percorso, quindi i picchi restano
  (174: 890 km/h nel campo `speed`, 75 km/h fra due punti consecutivi).
- **`speed` già in km/h** (oc:8742): il suo 95° percentile coincide con quello calcolato dalle
  posizioni in km/h.
- **Tempo effettivo con la sola soglia di velocità** (oc:8742): l'app registra un punto solo dopo
  10 m di spostamento, quindi da fermi non registra e ogni sosta diventa un unico tratto lungo e
  lento (traccia 176: 26 minuti per 10 m, 0,02 km/h). Basta la soglia di 1 km/h a toglierla.
- **Media sui soli tratti con GPS buono** (oc:8742): dove il GPS è disturbato ma i punti restano
  entro 50 m dal percorso, la pulizia li tiene e la distanza cresce. Traccia 104: 15,9 km/h dalle
  posizioni, 4,8 sui tratti con accuracy ≤ 40 m; traccia 34: 11,7 contro 5,1. Il tempo effettivo
  invece comprende i tratti disturbati: il tempo è reale anche quando la posizione non lo è.
- **Dislivello dal DEM** (oc:8742): la quota GPS oscilla e il rumore si somma come salita. Traccia
  121, 28 km lungo la costa fra 1 e 15 m di quota: 536 m di salita dal GPS, 123 dal DEM. Strava fa
  lo stesso per i dispositivi senza altimetro barometrico.

## 11. Limiti noti

- **Immagine di condivisione subito dopo la sincronizzazione.** Finché il job DEM non ha girato,
  `ascent` è `null` e l'immagine di condivisione non mostra il dislivello (la colonna si salta).
  Prima di oc:8742 lo mostrava sempre, calcolato dalla quota GPS.
- **Profilo altimetrico e dati tecnici in Nova.** Il profilo disegna la quota GPS della geometria,
  mentre salita, discesa e quote del blocco dati tecnici vengono dal DEM: i due possono non tornare
  (per esempio una salita evidente nel profilo e un `ascent` più basso nel blocco).

## Come ci siamo arrivati

- **Massimo semplice** (oc:8742, scartato): su 39 tracce con più di 100 punti dava valori assurdi in
  14 casi sul campo `speed` e in 26 sulle velocità fra punti consecutivi (il metodo che Strava
  documenta).
- **Massimo su finestra mobile di 60 s** (oc:8742, scartato): valori assurdi ancora in 5 tracce.
- **Tempo effettivo togliendo le pause oltre 120 s** (oc:8742, scartato): sulla 174 gli intervalli
  lunghi coprono centinaia di metri (10 minuti per 221 m), cioè cammino senza segnale, e la regola
  li toglieva dal tempo effettivo.
- **Dislivello dalla quota GPS** (oc:8742, scartato): vedi «Perché così». Resta solo nell'app per le
  tracce non sincronizzate: prima e dopo la sincronizzazione il dislivello può cambiare, ed è
  accettato.
- **`duration_forward` del DEM come tempo** (oc:8742, scartato): è una stima del tempo di
  percorrenza a piedi calcolata sulla geometria; di una traccia registrata il tempo vero si conosce.
- **Correggere `max_speed` quando è sotto `avg_speed`** (oc:8742, scartato): succede solo su tracce
  di 2–7 punti (6 su 124), registrazioni di prova; una correzione sarebbe una regola in più da
  replicare nell'app per un caso senza valore.

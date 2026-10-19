# Origine di una traccia UGC: registrata o importata

## Come funziona oggi

Una UGC track può essere nata in due modi: **registrata** camminando con l'app, oppure
**importata** da un file (GPX, KML, GeoJSON) che l'utente carica. La distinzione non è una
colonna del database: la calcola `UgcTrack::origin()`, che restituisce `'recorded'` o
`'imported'` guardando `properties`, in cascata — il primo indizio che risponde decide.

| Ordine | Indizio | Esito |
|---|---|---|
| 1 | `properties.import` presente | importata |
| 2 | `properties._gpxType` presente | importata |
| 3 | `properties.locations` array non vuoto | registrata |
| 4 | nessuno dei tre | importata |

Da dove vengono i tre indizi:

- **`import`** lo scrive l'app al salvataggio di un file caricato (oc:8764, in `wm-core`:
  `buildUgcImportInfo` in `utils/ugc-import-info.ts`). Contiene formato, nome, dimensione e data
  del file, creator del GPX, numero di punti, geometrie del file, presenza di tempo e quota. È
  il solo indizio esplicito, e per questo decide per primo — anche sopra `locations`, perché un
  GeoJSON esportato da una registrazione e poi ricaricato porta ancora i punti grezzi del file.
- **`_gpxType`** (`"trk"` o `"rte"`) non lo scrive nessuno di proposito: lo aggiunge
  `@tmcw/togeojson` convertendo un GPX. Per questo compare solo sui GPX e non su KML o GeoJSON,
  che passano da altri rami dello stesso metodo dell'uploader.
- **`locations`** sono i punti grezzi del GPS, con accuracy e quota. Li salva solo una
  registrazione, e dalla versione 3.1.7 dell'app in poi.

**Chi la usa:** il badge «Origin» nel dettaglio Nova della UgcTrack (oc:8747), e il passaporto
di Cammini d'Italia (oc:8165), che certifica una tappa solo dalle UGC track registrate — una
traccia disegnata su una mappa non prova che qualcuno l'abbia percorsa.

**Limite noto:** le tracce importate prima di oc:8764 non hanno `import`, quindi per loro
valgono solo gli indizi 2-4.

## Perché così

- **La regola sta nel package e non nel consumer** (oc:8165): la leggono sia il badge Nova sia
  il passaporto, e una seconda copia della stessa regola divergerebbe alla prima modifica.
- **`import` prima di tutto** (oc:8165): è l'unico dato scritto apposta per dire «questa viene
  da un file». Gli altri due sono tracce lasciate di passaggio da una libreria o dal GPS, e si
  possono trovare anche dove non dovrebbero.
- **Senza punti grezzi, importata** (oc:8165): ciò che distingue una UGC track è che il
  telefono abbia registrato dei punti. In mancanza, non c'è niente che provi una registrazione.

## Come ci siamo arrivati

- **`distanceFilter` come prova di registrazione** (oc:8747, superata da oc:8165). La prima
  versione della regola, in mancanza di altri indizi, considerava registrata una traccia che
  avesse `properties.distanceFilter`, un'impostazione del GPS. Abbandonata perché quel valore
  può restare valorizzato anche su un dato importato, quindi non prova nulla.

  Il prezzo è noto e accettato: le registrazioni fatte con le versioni **3.1.2-3.1.6** dell'app,
  che avevano `distanceFilter` ma non ancora `locations` (arrivati con la 3.1.7), da oc:8165
  risultano importate. Su camminiditalia sono **20 tracce su 267**, riconoscibili perché
  portano la versione dell'app in `properties.device.appVersion` (3.1.3 ×16, 3.1.2 ×3, 0.0.1
  ×1). Per quelle persone quelle UGC track non contano nel passaporto, e il badge in Nova le
  mostra come importate. La decisione è stata presa con questi dati davanti: senza i punti
  grezzi una traccia non si dice registrata.

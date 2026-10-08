# Casi di test condivisi per i dati tecnici delle tracce UGC (oc:8742)

Questi file sono la **fonte comune** per `wm-package` (PHP, `UgcTrackStatsService`) e per
`wm-core` (TypeScript, oc:8743): lo stesso calcolo, scritto in due linguaggi, deve dare gli
stessi valori su questi punti. Se una regola cambia, si cambiano qui i casi e in entrambi i repo
il codice.

## Formato

```json
{
  "description": "testo",
  "params": {"max_accuracy": 40, "max_deviation": 50, "max_speed_percentile": 95, "moving_min_speed": 1},
  "locations": [{"time": 0, "latitude": 43.0, "longitude": 13.0, "accuracy": 5, "altitude": 100, "speed": 4}],
  "expected_kept": [true],
  "expected": {"distance": 0.1, "duration": 2, "duration_moving": 2, "avg_speed": 3.6, "max_speed": 4.0}
}
```

- `params`: i parametri della pulizia e del calcolo (metri, percentile, km/h).
- `expected_kept`: un booleano per punto, `true` se la pulizia GPS di oc:8719 lo tiene.
- `expected`: solo le cinque chiavi calcolate in locale (`distance`, `duration`,
  `duration_moving`, `avg_speed`, `max_speed`); **senza** `computed_at` e senza le chiavi DEM
  (`ascent`, `descent`, `ele_*`), che si calcolano sul server. `null` quando i punti tenuti sono
  meno di 2.

## Casi

| File | Cosa verifica |
|---|---|
| `01-cammino-regolare.json` | 11 punti, 10 m ogni 10 s |
| `02-sosta-distance-filter.json` | sosta registrata come un solo tratto lento: fuori dal tempo effettivo |
| `03-gps-disturbato-tenuto.json` | punto con accuracy 45 m tenuto: i suoi tratti non entrano nella media |
| `04-punto-scartato.json` | punto con accuracy 3000 a 50 km scartato; picco di speed 890 ignorato dal percentile |
| `05-due-punti-di-cui-uno-scartato.json` | un punto (0,0) scartato: `expected` è `null` |
| `06-senza-speed.json` | nessun punto con `speed`: `max_speed` è il 95° percentile delle velocità dei 21 tratti (7,2 km/h, non il massimo 9,0) |
| `07-time-mancante-o-non-crescente.json` | un punto senza `time` e un tratto con Δt = 0: contano nella distanza, non nei tempi e nelle velocità |
| `08-sospetto-scartato.json` | punto con accuracy 45 a circa 81 m dal percorso fra i due punti buoni vicini: scartato, con il suo `speed` di 50 |
| `09-nessun-tratto-buono.json` | sospetti (accuracy 41 e 60) sul percorso, tenuti, alternati a punti buoni: nessun tratto con GPS buono, `avg_speed` `null` |

## Come rigenerarli

I file da 01 a 05 sono stati prodotti con uno script usa e getta (non versionato) che costruisce i punti,
chiama `UgcTrackCleanupService::keptFlags()` e `UgcTrackStatsService::localStats()` e scrive il
JSON. **Non fidarsi dell'output PHP**: ogni valore di `expected` va ricontrollato a mano con le
regole di `docs/knowledge/dati-tecnici-delle-tracce-ugc.md` (haversine con R = 6.371.000 m,
tratto in movimento se ≥ `moving_min_speed`, media solo sui tratti con GPS buono, percentile
nearest-rank). Se calcolo a mano e PHP divergono, c'è un errore nella regola o nel codice.

I file da 06 a 09 invece sono stati scritti da uno script (non versionato) che applica le regole
della pagina senza chiamare il PHP, e poi confrontati con il PHP. I punti tenuti stanno tutti sullo stesso
meridiano (longitudine 13), dove l'haversine vale R · Δφ: 0,00009° = 10,0075 m, 0,00045° =
50,0377 m, 0,0009° = 100,0754 m, quindi ogni valore si ricontrolla a mano in poche righe.

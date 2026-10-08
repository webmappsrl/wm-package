> Ticket: oc:8742

# Notes — UGC: velocità, tempo e dislivello calcolati dal server sui punti GPS puliti

## Divergenze dal piano, task per task

### Task 1 e 2: points_total e points_discarded tolti da stats

Dopo aver visto i dati in Nova il dev ha chiesto di toglierli: nessun uso li richiede (l'app mostra
distanza, dislivello, tempi e velocità, non quanti punti sono stati scartati). Erano nel formato
proposto nella descrizione del ticket. Tolti da `UgcTrackStatsService::localStats()`, dai test,
dai casi condivisi in `tests/fixtures/ugc-track-stats/` e dalla pagina di conoscenza.

### Task 3 e 4: eseguiti insieme

I test dell'observer usano il job DEM e quelli del job usano lo `stats` creato dall'observer:
un'unica esecuzione. Tre differenze dal piano: la coda `dem` si imposta nel costruttore del job
(`public $queue` va in conflitto con il trait `Queueable`); il test «computed_at diverso» verifica
che il servizio non venga chiamato; i 3 test di `ImportAppJobConfigRefreshBatchesTest` che leggono
`job_batches` rimettono il dispatcher vero con `Bus::swap`, perché il `Bus::fake` globale del job DEM
rompeva `Bus::findBatch()`.

In review è emerso che `UpdateModelWithGeometryTaxonomyWhere` rileggeva la traccia all'inizio,
chiamava osmfeatures e poi salvava tutte le properties, cancellando i valori DEM scritti nel
frattempo. Il job ora rilegge la traccia subito prima di salvare e cambia solo `taxonomy_where`
(test `UpdateModelWithGeometryTaxonomyWhereFreshReadTest`).

### Task 7: dati tecnici in un blocco sotto la mappa

Al posto dei due campi testo «GPS cleanup» (oc:8719) e «Technical data», il dev ha chiesto un
blocco del campo mappa, come il profilo altimetrico: opzione nuova di `FeatureCollectionMap`
(`technicalData()`), disegnata dal componente Vue sotto la mappa. Il campo «GPS cleanup» è stato
tolto perché ripeteva in testo ciò che la mappa mostra con i tratti ricostruiti.

### Task 7: profilo altimetrico sulle tracce con tratti ricostruiti

Non previsto dal piano. Il profilo compare solo se la mappa ha una linea sola, o una linea con
`slopeChart: true` (oc:8662); i tratti ricostruiti di oc:8719 lo facevano sparire proprio sulle
tracce con punti scartati. `UgcTrack::getFeatureCollectionMap()` segna la linea della traccia con
`slopeChart: true`.

### Task 9: verifica sulla traccia 174 senza test automatico

Lo spec chiedeva un test sulla 174 con dati reali. Non è nel repo, per non versionare i punti GPS di
un utente: la verifica è stata fatta sul DB locale (9,37 km, 165 e 144 min, 3,4 e 6,5 km/h; DEM
313/610/935/1456/1236/939). L'esempio JSON dell'overview è rimasto quello scritto prima del calcolo:
ha ancora `points_total`/`points_discarded`, la distanza 9,4 e le quote di partenza e arrivo
1303/994 (quota GPS); i valori giusti sono nella pagina di conoscenza.

## Decisioni

- Tag Orchestrator associati a oc:8742: `wm-package` (635), `camminiditalia` (495).
- Branch creati da `develop` in camminiditalia e in wm-package, non da `Passaporto`.
- Stima non fatta, su richiesta del dev.
- Casi condivisi in `tests/fixtures/ugc-track-stats/` (minuscolo, cartella già tracciata), non
  `tests/Fixtures` come nel piano.
- La specifica fissa l'arrotondamento «metà lontano da zero» di PHP: `Math.round` di JavaScript
  dà risultati diversi sui valori che finiscono in ,5.
- `ResolveUgcLayerJob` di camminiditalia ha lo stesso schema di salvataggio del job delle località,
  ma fra lettura e salvataggio fa solo query locali: non toccato; il command è la rete di sicurezza.
- Test del package eseguiti file per file: su `develop` la suite intera non parte (circa 95 file
  usano `Tests\TestCase`, che nel package non esiste) e 20 file falliscono già.

## Bug trovati

- Le registrazioni delle app 3.1.2–3.1.6 non hanno `locations`: il salvataggio
  (`modal-save.component.ts`) costruiva le properties da zero e scartava i punti. Corretto in
  webmapp-app con il commit `a24c5ce2` (20/11/2025), dalla versione 3.1.7. Quelle tracce restano
  senza `stats`.
- I test UGC chiamano davvero `osmfeatures.maphub.it` (preesistente, non toccato).

## Follow-up

- Casi condivisi per i rami di ripiego (punto sospetto scartato, `time` mancante, `speed` assente,
  `accuracy` assente), utili al porting in oc:8743.

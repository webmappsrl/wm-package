> Ticket: oc:8719

# Notes — Pulizia automatica dei punti GPS errati nelle tracce UGC

## Divergenze dal piano, task per task

### Task 1: regola di scarto con distanza dal tratto

Il piano scartava ogni punto con accuracy oltre la soglia. Dopo la prima pulizia della 169 il dev
ha visto che molti punti scartati stavano sul percorso (vedi «Decisioni»). Ora un punto con
accuracy oltre 40 m si scarta solo se dista più di 50 m dal tratto fra il punto tenuto precedente
e il primo punto buono successivo (in testa e in coda: dall'unica ancora). Nuova chiave
`ugc_track_max_deviation_meters`. Il servizio classifica l'intero array (`keptFlags()`) invece del
singolo punto (`isUsable()` rimosso). In più, dalla review finale: punti con coordinate non finite
o fuori range scartati, soglie vuote o a 0 riportate al default, quota del punto precedente al
posto di 0 se manca `altitude`.

### Task 6: legenda nel campo mappa

Fuori piano, richiesta dal dev dopo la verifica in Nova: `FeatureCollectionMap::legend([...])`,
riquadro in basso a sinistra, calcolato per le tracce UGC solo nella pagina di dettaglio (la prima
versione lo calcolava per ogni riga dell'elenco). Colore e tratteggio dei tratti ricostruiti sono
costanti di `Wm\WmPackage\Models\UgcTrack`.

### Task 4: confronto delle geometrie con tolleranza

Il piano confrontava la geometria salvata con quella pulita con `ST_Equals`, che è esatto. Il
dry-run sul DB di sviluppo ha elencato 96 tracce invece delle 57 attese: le geometrie inviate
dall'app hanno 9 decimali, l'EWKT ricostruito ne ha 8, quindi tracce senza nessun punto scartato
risultavano diverse di mezzo millimetro (traccia 2: distanza di Hausdorff 5·10⁻⁹ gradi). Il command
le avrebbe riscritte senza motivo.

Ora `wouldChange()` e il guard dell'`UPDATE` in `CleanUgcTrackGeometryJob` considerano uguali due
geometrie con `ST_HausdorffDistance` ≤ `UgcTrackCleanupService::SAME_GEOMETRY_TOLERANCE_DEGREES`
(10⁻⁶ gradi, circa 10 cm). Aggiunto un test che riproduce il caso. Dopo la correzione il dry-run
elenca 57 tracce.

### Task 5: due modifiche al test di Nova

- `AbstractUgcResource::fields()` usa `App\Nova\User`, che esiste solo nei progetti che usano il
  package: il test definisce una sottoclasse di `AbstractUserResource` e un `class_alias`, protetto
  da `class_exists` come quello di `App\Models\User` in `tests/Pest.php`.
- Un modello appena creato ha `geometry` come espressione SQL: il test usa `$track->fresh()`.

## Bug trovati

- **«km prima» del dry-run contava i dislivelli**: `ST_Length` su una geometria 3D in PostGIS
  include la componente verticale (169: 30,71 km contro 28,94 km in pianta), mentre «km dopo» è
  in pianta. Corretto con `ST_Force2D`.

- **Confronto esatto delle geometrie** (vedi Task 4): 39 tracce identiche al millimetro sarebbero
  state riscritte dal command.

## Decisioni

- **Bypass PHPStan approvato dal dev** (2026-10-07T11:09:00Z, responsabilità del dev): «Errori PHPStan
  preesistenti su righe non modificate da oc:8719 (docblock di UgcTrack e
  WmPackageServiceProvider), presenti anche su develop.» Sono 19 errori: 7 nel docblock
  `@property` di `src/Models/UgcTrack.php` (righe 19-25) e 12 in `src/WmPackageServiceProvider.php`.
  L'unico errore introdotto dal lavoro («Unsafe usage of new static()» in
  `UgcTrackCleanupService::make()`) è stato corretto con `new self`.

- **Regola di scarto cambiata dopo l'approvazione del piano** (richiesta del dev, analizzando la 169
  a Castel del Monte): l'accuracy da sola scartava punti giusti. Nella 169, dei 45 tratti
  ricostruiti molti avevano i punti scartati entro 5–25 m dal percorso (punti 999–1002: accuracy
  68–116 m, distanza massima 14 m; punto 338: accuracy 889 m, distanza 1 m), mentre i salti veri
  stanno a centinaia di metri o chilometri. Ora un punto con accuracy oltre 40 m si scarta solo se
  si allontana più di 50 m (configurabile) dal tratto fra i punti tenuti vicini. Simulazione: 169
  da 122 a 31 punti scartati (29,0 km), 174 da 123 a 38 (9,6 km), 223 da 11 a 4 (24,7 km).

- **Legenda sulla mappa** (richiesta del dev dopo aver visto la 169 pulita in Nova): senza
  legenda il tratteggio arancione non si capisce. Scelta una legenda generica nel campo
  `FeatureCollectionMap` (`->legend([...])`) invece di una frase nel riepilogo: chi guarda la
  mappa cerca la spiegazione lì, e la possono usare anche le altre mappe Nova.
- **Pulizia reale sul DB di sviluppo** lanciata su richiesta del dev: 19 tracce, nessun errore;
  la 169 passa da 1016 a 985 punti.

- Tag Orchestrator associati in `environment-setup`: `camminiditalia` (id 495). `26Q4` assegnato in
  automatico da Orchestrator alla creazione.
- Il dettaglio di un tratto ricostruito compare al passaggio del mouse (`tooltip`), non al click:
  il popup al click del campo mappa mostra solo un titolo e «Vai alla risorsa». Approvato dal dev
  insieme al piano.
- Il controllo «cammino calcolato sulla geometria pulita» non ha un test automatico:
  `UgcService::resolveLayerByProximity()` cerca nel pivot `layerable_type = 'App\Models\EcTrack'`
  scritto nel codice, che nei test del package andrebbe ricostruito a mano. Resta una verifica
  manuale sulla traccia 223 (layer 54 sulla geometria grezza, 68 su quella pulita). Approvato dal
  dev insieme al piano.

## Follow-up

- `tests/Unit/Policies/UgcTrackPolicyTest.php` e `tests/Feature/Import/ImportUgcTrackJobTest.php`
  estendono il `TestCase` del progetto che usa il package e non girano nel package da solo.
  Lanciati da camminiditalia, `ImportUgcTrackJobTest` passa; `UgcTrackPolicyTest` fallisce sul
  caso «Validator può modificare», perché camminiditalia nega la modifica al Validator con la
  propria policy (oc:8575). Il fallimento è preesistente e non dipende da oc:8719.
- **Procedura di deploy, per ogni progetto che aggiorna wm-package**: lanciare
  `php artisan wm:clean-ugc-track-geometry --dry-run`, rivedere l'elenco (punti scartati, km prima
  e dopo), poi lanciarlo senza `--dry-run` (accoda un job per traccia sulla coda `default`, che
  ricalcola anche le località). Riavviare Horizon dopo il deploy, perché carichi le classi nuove.
  Il cammino (`layer_id`) delle tracce esistenti non viene ricalcolato.
- Da fare a mano: verifica sul dispositivo (registrare, sincronizzare, controllare che l'app
  mostri la traccia pulita) e cammino della 223 su una traccia nuova.
- Fuori ticket, segnalato al dev che non vuole aprire un ticket: l'app scarica ogni 60 secondi
  tutte le UGC dell'utente con `locations` (sul DB di sviluppo, mediana 221 KB a chiamata,
  massimo 6,4 MB).
- Parcheggiati dalla review: confronto `wouldChange()` costoso su tracce molto lunghe (0,74 s per
  3829 punti); tooltip «0 min» se manca `time`; quota non finita nell'EWKT.

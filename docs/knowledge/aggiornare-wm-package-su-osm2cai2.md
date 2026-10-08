# Aggiornare wm-package su osm2cai2

Cosa sapere prima di aggiornare il submodule `wm-package` su osm2cai2. Ogni voce indica il ticket da
cui viene. La procedura generale delle migration resta quella di
[docs/howto/migration-wm-package.md](../howto/migration-wm-package.md).

## Come funziona oggi

**Distanza dal package.** osm2cai2 è molto indietro rispetto al package: l'aggiornamento porta
molto più di quanto elencato qui ed è un'attività a sé, con il suo collaudo (oc:8718).

**Store UGC e retry dell'app (oc:8718).** Dopo l'aggiornamento lo store UGC aggiorna invece di
creare quando l'uuid esiste già, anche per i POI, unisce le properties e scarta le foto doppie:
vedi [duplicati-ugc-e-retry-dell-app.md](duplicati-ugc-e-retry-dell-app.md).

Le colonne proprie di osm2cai2 (`validated`, `validator_id`, `validation_date`, `geohub_id`,
`raw_data`, `description`, `metadata`) non vengono toccate dallo store.

**Command `osm2cai:fix-duplicated-ugc-pois` di osm2cai2.** Gira ogni giorno alle 06:00, solo in
report (`app/Console/Kernel.php`). Dopo l'aggiornamento non dovrebbero più nascere nuovi duplicati
dei POI. I duplicati già esistenti restano gestiti da quel command (copie marcate e assegnate
all'utente tecnico `duplicated-ugc-pois@webmapp.it`, non cancellate).

**Command `wm:fix-duplicated-ugc` del package (oc:8718).** Unisce gli UGC (tracce e POI) con lo
stesso uuid nel più vecchio, archivia le copie in `ugc_duplicates_archive` e le **cancella**. Di
default lavora su tracce e POI: su osm2cai2 i POI duplicati li gestisce già il suo command, con
una strategia diversa (copie marcate, non cancellate), quindi va deciso se lanciarlo con
`--type=tracks` o affidare anche i POI al package e togliere lo scheduling di quello di
osm2cai2. Su osm2cai2, **prima di `--execute`**:
- **riferimenti esterni**: il command non li sposta. `ugc_media.ugc_track_id` ha una FK verso
  `ugc_tracks` con `ON DELETE SET NULL` (migration `2024_10_21_144824_create_ugc_media_table`):
  cancellare una copia stacca i suoi `ugc_media`. Vanno spostati sul padre, o il command va esteso;
- **colonne di validazione**: il command unisce solo `properties` e `name`. Se una copia è validata
  (`validated`, `validator_id`, `validation_date`) e il padre no, cancellando la copia la
  validazione si perde. Controllare i gruppi del report prima di applicare;
- **geometria**: la colonna di osm2cai2 nasce da una migration propria, non dallo stub del package
  (`2024_10_16_153212_create_ugc_tracks_table`, resa 3D da
  `2024_10_29_134430_add_z_dimension_to_ugc_tracks_geometry`) ed è nullable
  (`2025_01_29_155106_set_geometry_column_in_ugc_tracks_nullable`): il command **non** riconosce le
  geometrie nulle, calcola distanza 0 e unisce il gruppo. Se il padre è senza geometria e una copia
  ce l'ha, quella buona finisce solo nell'archivio. Prima di `--execute` controllare con
  `select id from ugc_tracks where geometry is null` i gruppi del report;
- il DB di osm2cai2 non è mai stato controllato per uuid duplicati: lanciare prima il report;
- backup del DB, poi report (tabella a schermo), verifica, e solo allora `--execute`; ogni lancio e ogni gruppo con l'esito finiscono nel log `storage/logs/duplicated-ugc-*.log` (14 giorni), cosa è stato unito resta in `ugc_duplicates_archive`.

**Migration.** Lo stub `create_ugc_duplicates_archive_table` è nella root del package, quindi è
obbligatorio: va pubblicato con `wm-package:publish-missing-migrations` anche se il command non
viene lanciato.

**Test.** `phpunit.xml` di osm2cai2 non imposta `DB_DATABASE` e usa il DB del `.env`: i test del
package lanciati da osm2cai2 scrivono sul DB di sviluppo (vedi [testare-il-package.md](testare-il-package.md)).

## Perché così

- **Riferimenti esterni non gestiti dal command** (oc:8718): su `develop` di camminiditalia non
  ce ne sono; gestirli in modo generico era un costo per un caso che lì non si presenta. Su
  osm2cai2 invece `ugc_media` c'è: va affrontato al momento dell'aggiornamento.
- Il resto delle scelte (aggiornare invece di rifiutare, unione delle properties, archivio) è in
  [duplicati-ugc-e-retry-dell-app.md](duplicati-ugc-e-retry-dell-app.md).

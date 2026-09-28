> Ticket: oc:8539

# Notes — Dominio Catasto Sentieri estendibile dagli shard

## Divergenze dal piano, task per task

### Task 2: Resource Nova registrate dallo shard

- **`TrailRegistryCode::uriKey()` fissato a `trail-registry-codes`.** Il default di Nova ricava la
  chiave dal nome della classe: una sottoclasse con un nome diverso avrebbe avuto un'altra chiave,
  mentre `Models/Concerns/ComposesTrailRegistryMap.php` usa `trail-registry-codes` scritta a mano.
  Fissarla tiene insieme i due. Dopo la review la chiave è fissa anche su `TrailApplication`
  (`trail-applications`, scritta a mano nello stesso file) e su `TrailRegistryAnomaly`
  (`trail-registry-anomalies`), per coerenza.
- **`tests/Feature/TrailRegistry/MainMenuInjectionTest.php` registra le tre Resource nel test.**
  Il package non le registra più, quindi senza questa riga il menu «Catasto» del test risultava
  vuoto. La registrazione sta nel test, non nel package.

### Task 3: Registro dei tipi di anomalia

- **I confronti `===` con l'enum in `AnomalyMapLegendRenderer` e nel modello restano.** Sono
  sicuri: una stringa non è mai uguale a un caso dell'enum, e il `match` ha un ramo `default`.
  La conseguenza: un tipo dello shard che ha una traccia riceve la legenda della mappa del catasto.

### Task 4: Provenienza delle anomalie e normalize limitato al catasto

- **Nessuna migration aggiuntiva.** Per richiesta del dev (28/09/2026): niente stub
  `add_source_to_trail_registry_anomalies_table`, le modifiche (colonna `source` NOT NULL senza
  default, `ec_track_id` nullable, rimozione del `CHECK` sul `type`) sono entrate direttamente
  nello stub `create` (`zz_2026_09_10_000001_create_trail_registry_anomalies_table.php.stub`).
  Su un DB dove la tabella esiste già (es. UAT) lo schema va aggiornato a mano dal team: lo stub
  `create` esce subito grazie a `hasTable` e non applica le modifiche.

## Bug trovati

## Decisioni

## Follow-up

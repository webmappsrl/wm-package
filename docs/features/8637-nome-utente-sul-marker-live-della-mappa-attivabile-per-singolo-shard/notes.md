> Ticket: oc:8637

# Notes — Nome utente sul marker live della mappa attivabile per singolo shard

## Divergenze dal piano, task per task

### Task 1 flag in config lettura in Layer test dei due stati
- **Dove girano i test.** Il piano indicava `php-forestas`, che non era attivo. Inoltre sia
  `php-forestas` sia `php-camminiditalia` montano `../wm-package` (la copia del package accanto ai
  progetti, in quel momento sul branch di oc:8463 con modifiche non committate), **non** il submodule
  `camminiditalia/wm-package` in cui si lavorava. Su scelta del dev, i test sono stati eseguiti in un
  container temporaneo che monta il submodule e usa il Postgres di camminiditalia (DB `wm_package`,
  ruolo `wm_package`, già presenti):
  ```bash
  docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia \
    -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 \
    vendor/bin/pest tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php
  ```
  `phpunit.xml.dist` dichiara `DB_HOST=db` senza `force`, quindi la variabile passata con `-e` vince.
- **Fase RED: 3 test falliti su 4, non 4.** `test_flag_enabled_falls_back_to_default_label_without_link_when_user_is_missing`
  passa già prima dell'implementazione: con utente assente il risultato atteso (tooltip generico, nessun
  link) coincide con quello del flag spento. Resta come regressione del ramo "flag acceso".
- **Commento in più.** Riscritto anche il commento sul link (`Layer.php`, ramo `if ($showLiveUserIdentity && $user)`),
  che parlava del flag "rimesso a true senza toccare altro": ora descrive il comportamento con il flag
  acceso (link anche senza nome).
- **PHPStan limitato ai file toccati** (`src/Models/Layer.php`, `AnalyticsService.php`, `config/wm-package.php`):
  0 errori sul codice nuovo; 11 rilievi preesistenti in `Layer.php` su codice non toccato (`$appends`,
  `$bbox`, `getQueryStringAttribute()`, `App\Models\User`, `$associatedApps`).
- `build/report.junit.xml` viene riscritto da ogni run dei test: ripristinato alla versione di HEAD.

## Bug trovati
- `tests/Feature/LayerGetFeatureCollectionMapEcPoisTest.php`: 3 test su 3 falliscono nell'ambiente
  standalone (`App\Models\EcTrack` non trovato, `properties` stringa in `LayerService.php:158`,
  `geometry` null nella factory EcPoi). Estranei a oc:8637 (nessuno passa dalle righe toccate); non
  corretti in questo ciclo.

## Decisioni
- **Modifiche richieste dopo l'approvazione del piano (review formale `wm-review-ticket`, 25/09/2026):**
  - parametro rinominato su richiesta del dev per ricondurlo alla famiglia analytics (stesso schema di
    `analytics_shard_name` ← `ANALYTICS_SHARD_NAME`): config `analytics_show_live_user_identity`, env
    `ANALYTICS_SHOW_LIVE_USER_IDENTITY`;
  - lettura con `filter_var(..., FILTER_VALIDATE_BOOLEAN)` al posto di `(bool)`: `"off"`/`"no"` accendevano
    il flag (fail-open su un flag di privacy); nessun fallback in `config()`, la chiave viene dal file;
  - link alla scheda utente costruito con `Nova::path()` invece di `nova/` fisso (stesso approccio di
    `AnomalyDetailRenderer`, `TopUgcCreators`);
  - test: helper `userPositionFeatures()` spostato in cima e usato anche dai test di default, costanti
    `ANONYMOUS_TOOLTIP`/`MISSING_USER_ID`, test rinominato `test_default_config_falls_back_to_default_label_when_user_is_missing`,
    nuovi test: chiave presente nel config, nessuna query su `users` a flag spento, riga senza `user_id`
    a flag acceso, valori `off`/`no`/`false`/`0`/vuoto, path Nova personalizzato. Suite del file: 14/14.
- Controllo via variabile d'ambiente per shard, non impostazione App in Nova (scrum 23/09/2026).
  Supera la scelta di oc:8586 (valore fisso nel codice in attesa di parere legale): confermato dal dev.
- Un solo flag per nome e link; nessuna distinzione per ruolo.
- Con il flag acceso, se l'utente esiste il link c'è anche senza nome (decisione del dev).
- `user_id` da PostHog non verificato: rischio accettato, dichiarato nel commento del codice.
- Nessun tag aggiunto al ticket, né di ambiente né di contenuto (scelta del dev).

## Follow-up
- PR del consumer camminiditalia da mergiare insieme al bump del gitlink, non prima.
- Autorizzazione per singolo layer sull'endpoint `/nova-vendor/feature-collection-map/{model}/{id}` (rischio noto da oc:8586).
- Verifica lato server dell'identità negli eventi PostHog `userMoved`.
- Stima: nessun componente classificato come scrittura pura si è rivelato una decisione aperta durante l'esecuzione.

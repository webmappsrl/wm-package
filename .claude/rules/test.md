---
paths:
  - "tests/**"
---

# Trappole: test

Si applica quando scrivi o modifichi un test del package.

- **Il database di test del package è `wm_package`**, dichiarato in `phpunit.xml.dist`. Un
  `phpunit.xml` locale ha la precedenza sul `.dist`: se lo crei senza le righe `DB_*`, la suite
  punta al database dell'`.env` e `RefreshDatabase` lo svuota — è già successo su `forestas`
  (oc:8182).
- **Un test che crea un'App con `native_app_deep_link_enabled = true` scrive sul registro SFTP
  condiviso**: è la regola in cima, non una sfumatura (oc:8251).
- **Un test che non dichiara il `TestCase` giusto fallisce in silenzio**: package e consumer hanno
  due `TestCase` diversi, e i consumer non registrano l'autoload del package. Il dettaglio è in
  [docs/knowledge/testare-il-package.md](docs/knowledge/testare-il-package.md).
- Nelle Action invocate direttamente nei test `request()->user()` è **sempre `null`** (si bypassa
  il kernel HTTP): si usa `auth()->user()`, identico in produzione (oc:8486).
- `Bus::fake()` non impedisce l'acquisizione del lock di un job `ShouldBeUnique`: gira in
  `PendingDispatch::shouldDispatch()`, prima che il Dispatcher fake sostituisca quello vero. Se il
  job ha `uniqueVia()` su Redis (es. `UpdateAppConfigJob`, `BuildAppPoisGeojsonJob`), un test sotto
  `Bus::fake()` dipende comunque da Redis reale — isola con
  `config(['cache.stores.redis.driver' => 'array'])` (oc:8564).
- `Bus::fake()` non onora `afterCommit()`: sotto fake un job accodato dopo il commit parte subito,
  anche se la transazione va in rollback. Si verifica `$job->afterCommit === true`, non lo scarto
  (oc:8571).
- `EcTrackFactory` valorizza `osmid` a caso nel 70% dei casi: un test che crea tracce con la factory
  e non fissa `osmid` ottiene a caso una traccia OSM, con precedenze e catene diverse — passa
  `'osmid' => null` (oc:8543).
- I test del package non registrano `ScoutServiceProvider`: `EngineManager` non è un singleton e un
  engine registrato con `extend()` si perde alla chiamata successiva — registra il singleton nel
  `setUp()` del test (oc:8543).
- I test in `tests/Unit/Services/EcTrackService/` non si lanciano per file singolo:
  `AbstractEcTrackServiceTest` è nello spazio dei nomi `Tests\…`, che `autoload-dev` non mappa — usa
  `vendor/bin/pest tests/Unit/Services/EcTrackService --filter=<Classe>` (oc:8543).

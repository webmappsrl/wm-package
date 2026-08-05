> Ticket: oc:8158

# Piano implementativo — Import GeoHub: estensione UGC (POI, Track, Media) e utenti autori UGC

Riferimento: `overview.md` nella stessa cartella (wm-package) e in `maphub/docs/features/8158-.../overview.md`.

Ordine dei task pensato per rispettare le dipendenze reali (ruolo prima degli import, POI/Track prima dei Media, Media prima della sincronizzazione pivot).

---

## wm-package

### 1. Migration stub: ruolo `Contributor` (pre-seedato)

- File: `database/migrations/zz_2026_XX_XX_000001_add_contributor_role.php.stub` (naming coerente con `zz_2026_06_26_000001_add_editor_role.php.stub` di oc:8042)
- `insertOrIgnore` sulla tabella `roles` (`name: 'Contributor', guard_name: 'web'`) — **non** `Role::firstOrCreate()` (side-effect cache Spatie in transazione PostgreSQL, stessa lezione di oc:8042)
- Idempotente: eseguibile più volte senza errori
- Commit: `feat(oc:8158): add Contributor role migration stub`

### 2. `RolesAndPermissionsService::seedDatabase()` — aggiungere Contributor

- File: `src/Services/RolesAndPermissionsService.php`
- Aggiungere `Role::firstOrCreate(['name' => 'Contributor'])` nella stessa lista di Administrator/Editor/Validator/Guest
- Verifica: `tests/Unit/Services/RolesAndPermissionsServiceTest.php` (aggiornare asserzioni esistenti se elencano i ruoli attesi)
- Commit: `feat(oc:8158): add Contributor to RolesAndPermissionsService seed`

### 3. Migration stub: tabella `ugc_media` + pivot

- File: nuovo `database/migrations/*.stub` (o due file separati per tabella e pivot, secondo convenzione esistente in `wm-package/database/migrations/`)
- Schema mirror 1:1 da GeoHub (verificato in fase di overview): `id`, `user_id` (FK `users`, NOT NULL), `app_id` (FK `apps`, NOT NULL, integer), `name`, `geometry` (`geography(Point,4326)`), `relative_url`, `properties` (jsonb), `created_at`/`updated_at`
- Pivot `ugc_media_ugc_poi` (`ugc_media_id`, `ugc_poi_id`), `ugc_media_ugc_track` (`ugc_media_id`, `ugc_track_id`) — nessuna colonna extra, mirror GeoHub
- Indice su `properties->geohub_id` (jsonb expression index) — miglioria rispetto al pattern esistente su `ugc_pois`/`ugc_tracks` (che non ce l'ha), giustificata perché qui il check di idempotenza gira ad ogni singolo record del batch invece che una volta per app
- Commit: `feat(oc:8158): add ugc_media table and pivot migrations`

### 4. Modello `UgcMedia`

- File: `src/Models/UgcMedia.php` (nuovo)
- Mirror struttura `UgcPoi`/`UgcTrack`: estende classe geometria Point (stesso pattern `UgcPoi`), `fillable = ['user_id','app_id','name','geometry','relative_url','properties']`, implementa `UserOwnedModelInterface`, relazione `author(): BelongsTo(User::class,'user_id')`
- Relazioni `belongsToMany` verso `UgcPoi` (pivot `ugc_media_ugc_poi`) e `UgcTrack` (pivot `ugc_media_ugc_track`)
- Override `newFactory()` se si crea una factory di test (trappola nota `HasPackageFactory`, vedi `wm-package/CLAUDE.md`)
- Commit: `feat(oc:8158): add UgcMedia model`

### 5. Config `wm-geohub-import.php` — nuove entry `import_mapping`

- File: `config/wm-geohub-import.php`
- Aggiungere `ugc_poi`, `ugc_track`, `ugc_media` a `import_mapping`, seguendo la struttura di `ec_poi`/`ec_track`/`ec_media` (namespace, job, geohub_table, identifier `properties->geohub_id` per poi/track; per `ugc_media` l'identifier può essere `properties->geohub_id` anch'esso se il campo `properties` viene popolato con `geohub_id`)
- **Non** aggiungere le tre chiavi a `default_dependencies['app']` — restano opt-in (decisione da challenge, vedi overview)
- Commit: `feat(oc:8158): add ugc_poi/ugc_track/ugc_media import mapping config`

### 6. `GeohubImportService` — ordine import, autori UGC, mapping app_id

- File: `src/Services/Import/GeohubImportService.php`
- Aggiungere `ugc_poi`, `ugc_track`, `ugc_media` a `MODEL_IMPORT_ORDER` (dopo `ec_media`, coerente con l'ordine logico: contenuti editoriali prima, UGC dopo)
- Nuovo metodo `assignContributorRole(User $user): void`, mirror esatto di `assignEditorRole()` (righe 500-513): assegna `Contributor` solo se `$user->roles->isEmpty()`. **Non** serve il fallback `seedDatabase()` per la creazione del ruolo (già garantito esistente dalla migration del task 1), ma mantenerlo per coerenza difensiva con `assignEditorRole()`
- Nuovo metodo (o riuso di `checkUserExistence()` con parametro esplicito) per mappare l'autore UGC: **non** deve chiamare la logica che forza `user_id = owner app` — chiama `checkUserExistence($geohubUserId)` per creare/trovare l'utente locale, poi `assignContributorRole($user)`, e ritorna l'id locale da usare come `user_id` reale del record UGC
- Verifica: nessun test qui direttamente, coperto dai test dei job (task 12)
- Commit: `feat(oc:8158): add UGC import order and Contributor author assignment`

### 7. Job `ImportUgcPoiJob` / `ImportUgcTrackJob`

- File: nuovi `src/Jobs/Import/ImportUgcPoiJob.php`, `ImportUgcTrackJob.php`
- Estendono `BaseImportJob` ma **overridano `transformData()`** per:
  - non ereditare la forzatura `user_id = App::find($this->data['app_id'])->user_id` (comportamento corretto solo per EC)
  - mappare `app_id` GeoHub (stringa numerica, es. `'49'`) → `apps.id` locale (già noto dal contesto del job, passato in `$this->data['app_id']` come intero locale — il valore GeoHub serve solo per il filtro della query `fetchData`, non per il campo da scrivere)
  - mappare `user_id` GeoHub → utente locale via il metodo del task 6, assegnando `Contributor`
- Override del punto di ingresso "create-only": prima di chiamare `GeohubImportService::importData()` (che fa update-or-create), verificare se esiste già un record locale con lo stesso `properties->geohub_id` — se sì, **skip** (nessun fill, nessun save), se no, procedi alla creazione. Questo richiede un metodo dedicato (es. `GeohubImportService::importDataCreateOnly()`) invece di riusare `importData()` invariato — vedi Rischi in overview (percorso separato, isolato)
- Se `user_id` GeoHub è nullo: log `warning` con l'id GeoHub del record, skip del singolo record (non lanciare eccezione, non bloccare il batch)
- `getGeometryType()`: `'POINT Z'` per Poi, tipo linea corretto per Track (mirror `ImportEcPoiJob`/`ImportEcTrackJob`)
- Commit: `feat(oc:8158): add ImportUgcPoiJob and ImportUgcTrackJob with real-author mapping and create-only semantics`

### 8. Job `ImportUgcMediaJob` + download file

- File: nuovo `src/Jobs/Import/ImportUgcMediaJob.php`, eventuale nuovo servizio `src/Services/Import/UgcMediaImportService.php` se la logica di download giustifica una classe dedicata (mirror `EcMediaImportService`, ma senza Spatie Media Library)
- Costruzione URL pubblico: `config('wm-package.clients.geohub.host').'/storage/'.$relativeUrl` (stesso pattern verificato in `EcMediaImportService::processEcMediaImport()`)
- Download del file e salvataggio su storage locale Maphub (disco configurato, es. `Storage::disk(config('filesystems.default'))->put(...)`), scrittura del nuovo `relative_url` locale sul record `UgcMedia`
- **Gestione esplicita del fallimento**: se il download fallisce (host irraggiungibile, 404, content-type non immagine), loggare `warning` con il `geohub_id` del media e **continuare** con gli altri record del batch — non inghiottire l'eccezione silenziosamente come fa `ImportEcMediaJob::handle()` oggi, ma anche non propagarla e far fallire l'intero job Horizon
- Stesso mapping autore reale + create-only del task 7 (`UgcMedia` ha il proprio `user_id`/`app_id`, indipendente da POI/Track)
- Commit: `feat(oc:8158): add ImportUgcMediaJob with explicit download failure logging`

### 9. Sincronizzazione pivot media↔POI/Track

- File: `src/Jobs/Import/ImportUgcMediaJob.php` (in `processDependencies()`) o un job dedicato dispatchato dopo il completamento del batch media
- Per ogni `UgcMedia` importato, leggere le righe pivot su GeoHub (`ugc_media_ugc_poi`, `ugc_media_ugc_track` per il `geohub_id` del media), risolvere gli id locali di `UgcPoi`/`UgcTrack` corrispondenti (via `properties->geohub_id`) e fare `attach()` (non `sync()`, per non cancellare eventuali associazioni già presenti) sulle pivot locali
- Idempotenza: verificare esistenza della riga pivot prima di `attach()` (stesso pattern `alreadyExists` di `associateLayersWithEcPoi()`, oc:8043)
- Commit: `feat(oc:8158): sync ugc_media pivot to local ugc_poi/ugc_track`

### 10. `ImportAppJob` — dependencies opt-in per UGC

- File: `src/Jobs/Import/ImportAppJob.php`
- Aggiungere branch per `ugc_poi`, `ugc_track`, `ugc_media` in `queueEntityImport()`/dependencies handling, **senza** aggiungerli a `default_dependencies['app']` (restano richiamabili solo via `--dependencies=...,ugc_poi,ugc_track,ugc_media`)
- Garantire l'ordine di dispatch: POI e Track prima di Media (i job Media leggono le pivot che referenziano `geohub_id` di POI/Track già importati) — se il batch dispatch è asincrono, usare `Bus::batch(...)->then(...)` per incatenare il batch media dopo il completamento del batch poi/track, invece del fire-and-forget attuale (bug potenziale identificato in challenge se non gestito)
- Commit: `feat(oc:8158): wire ugc_poi/ugc_track/ugc_media as opt-in ImportAppJob dependencies`

### 11. Risorsa Nova `UgcMedia`

- File: `src/Nova/UgcMedia.php` (nuovo)
- Solo index/detail base (nessuna azione custom), campi: `id`, `name`, `author` (via relazione), `app`, thumbnail/link a `relative_url`, `created_at`
- Commit: `feat(oc:8158): add base Nova resource for UgcMedia`

### 12. Verifica `AppClassificationService`/`GeometryComputationService`

- File: `src/Services/Models/App/AppClassificationService.php`, `src/Services/GeometryComputationService.php` (o path corretto verificato in fase di overview)
- Investigare se i metodi che referenziano `UgcMedia` sono chiamati da un punto attivo (route, comando, job schedulato, altra classe non-test)
- **Se raggiungibili**: correggere l'assunzione `app_id` come stringa SKU → integer FK, allineando al nuovo schema
- **Se dead code**: nessuna modifica, aggiungere un commento breve o una nota in `notes.md` (non nel codice, per non introdurre commenti superflui) che documenta la verifica fatta
- Commit (solo se modifica necessaria): `fix(oc:8158): align AppClassificationService to real UgcMedia schema`

### 13. Test Pest

- File: `tests/Feature/Import/ImportUgcPoiTest.php`, `ImportUgcTrackTest.php`, `ImportUgcMediaTest.php` (nuovi)
- Casi da coprire:
  - Import crea `UgcPoi`/`UgcTrack`/`UgcMedia` con `user_id` = autore reale GeoHub (non il proprietario dell'app) — assert esplicito, non solo conteggio
  - Autore importato riceve `Contributor` solo se non ha già un ruolo
  - Proprietario app mantiene `Editor`, invariato
  - Re-import dello stesso `geohub_id` non aggiorna il record esistente (create-only) — modificare un campo locale, ri-lanciare l'import, assert che il campo modificato sia rimasto invariato
  - Record con `user_id` GeoHub nullo viene skippato con log, senza bloccare gli altri record del batch
  - Fallimento download media viene loggato e non blocca il batch (mock HTTP fallito)
  - Pivot `ugc_media_ugc_poi`/`ugc_media_ugc_track` sincronizzate correttamente
- Commit: `test(oc:8158): add Feature tests for UGC import pipeline`

---

## maphub (repo principale)

### 14. Pubblicazione migration

```bash
php artisan wm-package:publish-missing-migrations --dry-run
php artisan wm-package:publish-missing-migrations
php artisan migrate
```

- Verificare che le migration di `ugc_media`, pivot, e ruolo `Contributor` siano pubblicate in `database/migrations/`
- Commit: `feat(oc:8158): publish wm-package migrations for UGC and Contributor role`

### 15. Stub Nova locale `UgcMedia`

- File: `app/Nova/UgcMedia.php` (nuovo)
```php
namespace App\Nova;

use Wm\WmPackage\Nova\UgcMedia as WmNovaUgcMedia;

class UgcMedia extends WmNovaUgcMedia {}
```
- Commit: `feat(oc:8158): add UgcMedia Nova resource stub`

### 16. Menu Nova

- File: `app/Providers/NovaServiceProvider.php`
- Aggiungere `use App\Nova\UgcMedia;` e `MenuItem::resource(UgcMedia::class)` nello stesso blocco di `UgcPoi`/`UgcTrack` (righe 61-62)
- Commit: `feat(oc:8158): register UgcMedia in Nova menu`

### 17. Verifica end-to-end manuale

```bash
php artisan wm:import-from-geohub app 49 --dependencies=ec_poi,ec_track,taxonomy_activity,taxonomy_theme,taxonomy_poi_types,layer,ec_media,ugc_poi,ugc_track,ugc_media
```

- Controlli (da overview, sezione Verifica):
  - `ugc_pois`: 47 righe attese
  - `ugc_tracks`: 74 righe attese
  - `ugc_media`: 18 righe attese
  - Pivot media↔POI/Track coerenti
  - Campione di record con `user_id` corrispondente all'autore reale GeoHub (non il proprietario app)
  - Autori con ruolo `Contributor` (solo se senza ruolo preesistente)
  - Owner con ruolo `Editor` invariato
  - Rilancio dello stesso comando non modifica i record già importati (create-only)

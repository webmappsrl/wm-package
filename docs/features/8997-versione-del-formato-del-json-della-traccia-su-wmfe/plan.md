> Ticket: oc:8997
> Stato: da approvare

# Versione del formato del JSON della traccia su wmfe — piano (wm-package)

> **Per chi esegue:** questo piano si esegue task per task con
> `superpowers:executing-plans`. I blocchi «Commit» sono **istruzioni testuali, non azioni**:
> in questo progetto non si committa durante l'esecuzione, il primo commit lo fa il dev alla
> chiusura. Durante il lavoro `overview.md` non si tocca: ogni cambiamento a un requisito va in
> `notes.md`, sezione «Modifiche ai requisiti».

**Obiettivo:** il JSON del dettaglio di una traccia su `wmfe` esiste in più versioni di formato
scritte insieme — `legacy` al suo indirizzo di sempre e `v1` in `tracks/v1/{id}.json` con le sole
`taxonomies` — e quali versioni scrivere lo dichiara lo shard.

**Architettura:** la forma corrente (`v1`) è quella canonica e si costruisce per intero; le
versioni precedenti sono trasformazioni applicate a ritroso, **una classe per versione**, secondo
il metodo di versionamento di Stripe adottato in [R4](overview.md#r4). `EcTrackPropertiesBuilder`
calcola i dati una volta sola e produce le properties canoniche;
`LegacyTrackPropertiesTransformation` le riporta indietro di un passo ricalcolando le tre forme
vecchie delle tassonomie. `EcTrackResource` resta l'unico punto che serializza, perché il filtro di
`JsonResource` fa parte della forma del file; `UpdateEcTrackAwsJob` scrive un file per versione
attiva e fallisce se una scrittura fallisce.

**Stack:** PHP >8.1, Laravel 12, Pest/PHPUnit, Nova 5.7.6, dischi Laravel Filesystem (`wmfe`).

**Spec:** [overview.md](overview.md) — i requisiti si citano come link, es. [R3](overview.md#r3).

**Repo gemello:** [forestas/docs/features/8997-…/plan.md](../../../../docs/features/8997-versione-del-formato-del-json-della-traccia-su-wmfe/plan.md).
Si esegue **dopo** questo, e il merge segue lo stesso ordine: prima il package, poi lo shard che
bumpa il puntatore del submodule.

## Vincoli globali

- Identificatori nel codice **sempre in inglese**; commenti, docblock e documentazione in italiano.
- PHP minimo `>8.1`: mai `const` dentro un trait.
- Nessun commit durante l'esecuzione (`git add`, `git commit`, `git push` non si eseguono mai).
- Mai `env()` fuori da `config/`: la configurazione si legge con `config()`.
- **La serializzazione del file passa sempre da `EcTrackResource`.** `JsonResource::resolve()`
  applica `filter()`, che reindicizza ogni array annidato con chiavi tutte numeriche
  (`vendor/laravel/framework/src/Illuminate/Http/Resources/ConditionallyLoadsAttributes.php:92`,
  `return $numericKeys ? array_values($data) : $data;`). Un `json_encode()` diretto salta quel
  passaggio e cambia la forma del file: `related_pois` con un POI scartato in mezzo diventerebbe
  un oggetto `{"0":…,"2":…}` invece di una lista, e lo stesso vale per `taxonomy_where` con id
  numerici. Sarebbe una violazione di [R1](overview.md#r1) invisibile fino alle app installate.
- Prima di lanciare la suite, verificare l'isolamento del database come da
  `.claude/rules/test.md`; il package punta a `wm_package` da `phpunit.xml.dist`.
- Comando dei test, da `wm-package/` dentro il container dello shard:
  `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest <path>'`.
- Nessuna migration, nessuna tabella, nessuna cache: [R5](overview.md#r5) vieta ogni stato oltre ai
  file stessi. In review finale si verifica che non ne sia comparso nessuno.

## Review Focus

Casi che la spec implica e che nessun test di task copre di per sé:

1. **Versione dichiarata ma sconosciuta** (`WMFE_TRACK_VERSIONS=legacy,v9`): deve far fallire la
   scrittura con un errore leggibile, non scrivere su un prefisso che nessuno legge. Test nel
   Task 2. Attenzione all'effetto oltre la scrittura: vedi il Task 6, dove la card Nova non deve
   mandare in errore la detail view per un refuso nel `.env`.
2. **Dichiarazione vuota o assente**: resta attiva la sola `legacy`, cioè per gli shard che non
   dichiarano nulla non cambia niente. Test nel Task 2.
3. **Cancellazione di una traccia con una versione non più attiva su S3**: [R7](overview.md#r7)
   chiede di cancellare **tutte** le versioni conosciute, non solo quelle in scrittura. Test nel
   Task 3.
4. **Una delle due `put` fallisce**: il job deve fallire ([R12](overview.md#r12)). Test nel Task 5.
   Da sapere, e da annotare in `notes.md`: oggi una `put` fallita lascia proseguire la catena dei
   job accodati da `EcTrackService` (`src/Services/Models/EcTrackService.php:367-368` e
   `:419-421`); con l'eccezione, esauriti i tre tentativi di Horizon, quei job non partono.
5. **Una traccia senza `taxonomy_where` nel jsonb**: la trasformazione a ritroso non deve inventare
   chiavi vuote che oggi non ci sono — `GeoJsonService::removeInvalidProperties()` scarta gli array
   vuoti, e un `taxonomy_where: []` comparso dal nulla è una modifica di forma. Test nel Task 4.
6. **Shard con la sola `v1` attiva**: `getTrackGeojson()` legge sempre la `legacy`, quindi
   `EcTrackController::getGeojson()` non trova il file e accoda un job a ogni richiesta
   (`src/Http/Controllers/Api/EcTrackController.php:25-30`), senza che quel file nasca mai. Oggi
   non riguarda nessuno shard; va deciso prima che uno accorci la finestra, e annotato in
   `notes.md`.

---

### Task 1: La forma del file senza versione, catturata come dato di test

Prima di toccare qualunque cosa: la prova che [R1](overview.md#r1) non venga violata dal refactor.
È un approval test — cattura la forma di oggi e la confronta con quella di domani — quindi **non
fallisce al primo giro**, ed è voluto: il suo valore è nei task successivi.

Il test legge **il file che il job scrive** su un disco finto, non l'array che una classe
restituisce: è il file che le app leggono, ed è lì che il filtro di `JsonResource` ha già agito.

**File:**
- Create: `tests/Feature/Track/TrackJsonShapeTest.php`
- Create: `tests/fixtures/track/<versione>-shape.json` (prodotto dallo step 3)

**Interfaces:**
- Consuma: `UpdateEcTrackAwsJob::handle()` e `StorageService` così come sono oggi.
- Produce: `TrackJsonShapeTest::shapeOf(array $json): array`, la firma delle chiavi usata da
  tutti i task successivi.

- [ ] **Step 1: Costruire una traccia ricca e deterministica**

La factory non basta: non dà media, POI correlati né `dem_data`, e ha parti casuali (`osmid` con
`optional(0.7)`, da 1 a 3 linee in `database/factories/EcTrackFactory.php:21,42`). Il caso di test
usa `insertEcTrackWithGeometry` come `tests/Feature/EcTrackGeojsonTaxonomiesTest.php:33-36` e
valorizza esplicitamente: `properties->taxonomy_where` con **id numerici** (forma `'81' => [...]`,
come a `EcTrackGeojsonTaxonomiesTest.php:178`), almeno una `TaxonomyActivity` e una
`TaxonomyWhere` attaccate, `dem_data` e `manual_data`, due media e **tre POI correlati di cui uno
con `osmfeatures_id` valorizzato** — quello che `getRelatedPois()` scarta
(`src/Http/Resources/EcTrackResource.php:88-89`), e che è il caso in cui la reindicizzazione di
`JsonResource` si vede.

- [ ] **Step 2: Scrivere il test**

`shapeOf()` è ricorsiva e restituisce la sola struttura, non i valori: per un array associativo le
chiavi **ordinate alfabeticamente** con la firma del loro contenuto, per una lista il tipo del
primo elemento preceduto da un marcatore che la distingue da un oggetto, per uno scalare il nome
del tipo. La distinzione lista/oggetto è il punto: è lì che la reindicizzazione si manifesta.

Il test fa `Storage::fake('wmfe')`, esegue `(new UpdateEcTrackAwsJob($track))->handle(new StorageService)`,
legge il file dal disco finto e confronta `shapeOf(json_decode($contents, true))` con la fixture.

I **valori** non si confrontano: [R1](overview.md#r1) promette la forma, e dice esplicitamente che
i valori seguono il dato.

- [ ] **Step 3: Catturare la fixture dal codice attuale**

Run: `docker exec php-forestas bash -lc 'cd wm-package && WM_CAPTURE_SHAPE=1 vendor/bin/pest tests/Feature/Track/TrackJsonShapeTest.php'`
Expected: il test scrive `tests/fixtures/track/<versione>-shape.json` e passa. La variabile è letta dal
solo test, che a fixture assente la scrive invece di fallire.

- [ ] **Step 4: Verificare che la fixture contenga ciò che deve provare**

Run: `docker exec php-forestas bash -lc 'cd wm-package && for k in taxonomies taxonomy taxonomy_where taxonomyWheres related_pois image_gallery; do printf "%s %s\n" "$k" "$(grep -o "\"$k\"" tests/fixtures/track/<versione>-shape.json | wc -l)"; done'`
Expected: ogni chiave almeno `1`. Una a zero significa che il caso di test non la produce, e va
completato prima di andare avanti: una fixture che non contiene le chiavi vecchie non prova niente.
(`grep -c` conterebbe le righe, non le occorrenze: su un JSON scritto su una riga sola darebbe `1`
per tutto.)

- [ ] **Step 5: Rilanciare senza la variabile, due volte**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest tests/Feature/Track/TrackJsonShapeTest.php && vendor/bin/pest tests/Feature/Track/TrackJsonShapeTest.php'`
Expected: PASS due volte di fila. Due giri servono a smascherare una fixture instabile: se il
secondo fallisce, nel caso di test è rimasto qualcosa di casuale.

- [ ] **Step 6: Commit** — `test(oc:8997): forma del JSON della traccia catturata prima del versionamento`

---

### Task 2: Le versioni del file e quali sono attive

Realizza [R11](overview.md#r11) e la finestra di [R3](overview.md#r3).

**File:**
- Create: `src/Enums/WmfeTrackVersion.php`
- Modify: `config/wm-package.php` (accanto a `shard_name`)
- Create: `tests/Unit/Enums/WmfeTrackVersionTest.php`

**Interfaces:**
- Produce:
  - `enum WmfeTrackVersion: string` con i casi `Legacy = 'legacy'` e `V1 = 'v1'`, **in ordine
    cronologico**: l'ultimo caso dichiarato è la forma canonica
  - `public function pathPrefix(): string` — `''` per `Legacy`, `'v1/'` per `V1`
  - `public static function active(): array` — `list<WmfeTrackVersion>` dalla configurazione
  - `public static function current(): WmfeTrackVersion` — l'ultimo caso dell'enum, la forma
    canonica di [R4](overview.md#r4)

- [ ] **Step 1: Scrivere i test**

```php
public function test_active_returns_declared_versions_in_declaration_order(): void
{
    config(['wm-package.wmfe_track_versions' => 'legacy,v1']);
    $this->assertSame([WmfeTrackVersion::Legacy, WmfeTrackVersion::V1], WmfeTrackVersion::active());
}

public function test_active_falls_back_to_legacy_when_declaration_is_missing(): void
{
    config(['wm-package.wmfe_track_versions' => null]);
    $this->assertSame([WmfeTrackVersion::Legacy], WmfeTrackVersion::active());
}

public function test_active_throws_on_unknown_version(): void
{
    config(['wm-package.wmfe_track_versions' => 'legacy,v9']);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('v9');
    WmfeTrackVersion::active();
}

public function test_current_is_the_newest_known_version(): void
{
    $this->assertSame(WmfeTrackVersion::V1, WmfeTrackVersion::current());
}

public function test_path_prefix_of_legacy_is_empty(): void
{
    $this->assertSame('', WmfeTrackVersion::Legacy->pathPrefix());
    $this->assertSame('v1/', WmfeTrackVersion::V1->pathPrefix());
}
```

Più: spazi attorno alle virgole tollerati, duplicati ridotti a uno, stringa vuota trattata come
dichiarazione assente.

- [ ] **Step 2: Lanciare i test e vederli fallire**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest tests/Unit/Enums/WmfeTrackVersionTest.php'`
Expected: FAIL, `Class "Wm\WmPackage\Enums\WmfeTrackVersion" not found`.

- [ ] **Step 3: Scrivere l'enum**

`active()` legge `config('wm-package.wmfe_track_versions')`, divide sulla virgola, toglie gli spazi,
scarta i vuoti, deduplica mantenendo l'ordine e risolve ogni voce con `self::tryFrom()`: a `null`
lancia `InvalidArgumentException` con dentro il valore rifiutato e l'elenco di quelli validi. Lista
vuota: `[self::Legacy]`. `current()` è l'ultimo di `self::cases()`.

- [ ] **Step 4: Aggiungere la chiave di configurazione**

In `config/wm-package.php`, sotto `shard_name`:

```php
// oc:8997: versioni del JSON della traccia scritte su wmfe, in ordine, separate da virgola.
// Sta in una variabile d'ambiente e non nel config pubblicato dallo shard: quello congelerebbe
// uno snapshot dell'intero file e le chiavi future del package non lo raggiungerebbero più.
// Vuota o assente: resta attiva la sola `legacy`, il file senza versione che leggono le app
// installate. Un nome sconosciuto fa fallire la scrittura, non scrivere su un prefisso muto.
'wmfe_track_versions' => env('WMFE_TRACK_VERSIONS', 'legacy'),
```

- [ ] **Step 5: Lanciare i test**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest tests/Unit/Enums/WmfeTrackVersionTest.php'`
Expected: PASS.

- [ ] **Step 6: Commit** — `feat(oc:8997): versioni del JSON della traccia e dichiarazione per shard`

---

### Task 3: `StorageService` conosce la versione

Realizza l'indirizzo di [R1](overview.md#r1) e [R2](overview.md#r2), la cancellazione di
[R7](overview.md#r7) e la lettura di [R8](overview.md#r8).

La firma di `storeTrack()` e il suo unico chiamante cambiano **nello stesso task**: un terzo
parametro obbligatorio con il chiamante fermo lascerebbe il job rotto fino al Task 5.

**File:**
- Modify: `src/Services/StorageService.php` — `getTrackPath()`, `storeTrack()`,
  `getTrackGeojson()`, `deleteTrack()`
- Modify: `src/Jobs/Track/UpdateEcTrackAwsJob.php:62` — passa `WmfeTrackVersion::Legacy`,
  nient'altro per ora
- Create: `tests/Unit/Services/StorageService/TrackVersionsTest.php`

**Interfaces:**
- Consuma: `WmfeTrackVersion` dal Task 2.
- Produce:
  - `private function getTrackPath(int $trackId, WmfeTrackVersion $version): string`
  - `public function storeTrack(int $trackId, $contents, WmfeTrackVersion $version): string|false`
  - `public function deleteTrack(int $trackId): bool`
  - `getTrackGeojson(int $trackId, int $appId): ?string` — firma invariata, legge la `Legacy`

- [ ] **Step 1: Scrivere i test**

```php
public function test_store_track_writes_legacy_at_the_unversioned_path(): void
{
    $this->service->storeTrack(769, '{}', WmfeTrackVersion::Legacy);
    Storage::disk('wmfe')->assertExists('/webmapp/tracks/769.json');
}

public function test_store_track_writes_v1_under_its_own_prefix(): void
{
    $this->service->storeTrack(769, '{}', WmfeTrackVersion::V1);
    Storage::disk('wmfe')->assertExists('/webmapp/tracks/v1/769.json');
}

public function test_delete_track_removes_every_known_version(): void
{
    // anche con la sola `legacy` dichiarata attiva
    config(['wm-package.wmfe_track_versions' => 'legacy']);
    Storage::disk('wmfe')->put('/webmapp/tracks/769.json', '{}');
    Storage::disk('wmfe')->put('/webmapp/tracks/v1/769.json', '{}');

    $this->service->deleteTrack(769);

    Storage::disk('wmfe')->assertMissing('/webmapp/tracks/769.json');
    Storage::disk('wmfe')->assertMissing('/webmapp/tracks/v1/769.json');
}

public function test_get_track_geojson_reads_the_legacy_file(): void
{
    Storage::disk('wmfe')->put('/webmapp/tracks/769.json', '{"legacy":true}');
    Storage::disk('wmfe')->put('/webmapp/tracks/v1/769.json', '{"legacy":false}');
    $this->assertSame('{"legacy":true}', $this->service->getTrackGeojson(769, 1));
}
```

Setup come `StoreFileTest`: `Storage::fake('wmfe')` e `new StorageService`.

- [ ] **Step 2: Lanciare i test e vederli fallire**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest tests/Unit/Services/StorageService/TrackVersionsTest.php'`
Expected: FAIL su `test_store_track_writes_v1_under_its_own_prefix` e
`test_delete_track_removes_every_known_version`. Gli altri due **passano già oggi**: PHP ignora gli
argomenti in più su un metodo non variadico, e il percorso senza versione è quello di sempre. È il
comportamento atteso, non un test scritto male.

- [ ] **Step 3: Implementare**

`getTrackPath()` compone `getShardBasePath()."tracks/".$version->pathPrefix()."{$trackId}.json"`.
`deleteTrack()` cicla **`WmfeTrackVersion::cases()`**, non `active()`, e cancella ogni file
esistente: una versione uscita dalla scrittura ha comunque i suoi file su S3, e
[R7](overview.md#r7) dice che vanno via tutti. Ritorna `false` se una cancellazione fallisce —
sapendo che `EcTrackObserver::deleted()` (`src/Observers/EcTrackObserver.php:112`) oggi quel valore
lo scarta, e questo lavoro non lo cambia.
`getShardBasePath()` **non si tocca**: da lì passa anche `WmfePathGenerator::getBasePath()` per i
media.

- [ ] **Step 4: Lanciare i test, e quello del Task 1**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest tests/Unit/Services/StorageService/ tests/Feature/Track/TrackJsonShapeTest.php'`
Expected: PASS. Il test di forma deve essere ancora verde: finora non è cambiato niente di ciò che
finisce nel file.

- [ ] **Step 5: Verificare che nessun chiamante sia rimasto indietro**

Run: `docker exec php-forestas bash -lc 'cd wm-package && grep -rn "storeTrack\|getTrackPath" src/'`
Expected: solo `StorageService` e `UpdateEcTrackAwsJob`, quest'ultimo già con la versione.

- [ ] **Step 6: Commit** — `feat(oc:8997): path, scrittura e cancellazione per versione su wmfe`

---

### Task 4: La forma canonica e la trasformazione a ritroso

Realizza [R4](overview.md#r4), [R2](overview.md#r2) e [R5](overview.md#r5). È il cuore del lavoro,
ed è il punto in cui il metodo di Stripe si applica davvero: **la forma corrente si costruisce per
intero, la precedente è una classe a sé che la riporta indietro di un passo**. Il giorno che la
`legacy` muore si cancella quella classe e nient'altro.

**File:**
- Create: `src/Http/Resources/EcTrackPropertiesBuilder.php`
- Create: `src/Http/Resources/TrackVersions/LegacyTrackPropertiesTransformation.php`
- Modify: `tests/Unit/Http/Resources/EcTrackResourceDemPriorityTest.php` — segue
  `applyDemFields()` sul builder
- Create: `tests/Feature/Track/EcTrackPropertiesBuilderTest.php`
- Create: `tests/Unit/Http/Resources/LegacyTrackPropertiesTransformationTest.php`

**Interfaces:**
- Consuma: `WmfeTrackVersion`; `EcTrack::getDetailGeojson()`; `GeoJsonService`,
  `GeometryComputationService`, `MediaResource`, `RelatedEcPoiResource`, il trait
  `HasDemClassification`; `TaxonomyWhereDisplayService` (dentro la sola trasformazione).
- Produce:
  - `EcTrackPropertiesBuilder::__construct(EcTrack $track)`
  - `public function current(): array` — le properties canoniche, senza le tre forme vecchie
  - `public function rawContext(): array` — i dati grezzi che una trasformazione può servire:
    `properties.taxonomy_where` com'è nel jsonb, le attività e le where del modello
  - `public function geometry(): array` — la geometria forzata a linestring, che
    [R6](overview.md#r6) vuole calcolata in un punto solo
  - `public function build(array $versions): array` — mappa `string $version->value => array properties`
  - `LegacyTrackPropertiesTransformation::apply(array $current, array $rawContext, EcTrack $track): array`

- [ ] **Step 1: Scrivere i test del builder**

```php
public function test_current_has_only_the_taxonomies_key(): void
{
    $properties = $this->builder->current();

    $this->assertArrayHasKey('taxonomies', $properties);
    $this->assertArrayNotHasKey('taxonomy', $properties);
    $this->assertArrayNotHasKey('taxonomy_where', $properties);
    $this->assertArrayNotHasKey('taxonomyWheres', $properties);
}

public function test_build_returns_only_the_requested_versions_in_order(): void
{
    $both = $this->builder->build([WmfeTrackVersion::Legacy, WmfeTrackVersion::V1]);
    $this->assertSame(['legacy', 'v1'], array_keys($both));
}

public function test_legacy_differs_from_v1_only_by_the_old_taxonomy_keys(): void
{
    $both = $this->builder->build([WmfeTrackVersion::Legacy, WmfeTrackVersion::V1]);

    $this->assertEqualsCanonicalizing(
        ['taxonomy', 'taxonomy_where', 'taxonomyWheres'],
        array_keys(array_diff_key($both['legacy'], $both['v1']))
    );
    $this->assertSame([], array_diff_key($both['v1'], $both['legacy']));
    $this->assertSame($both['v1']['taxonomies'], $both['legacy']['taxonomies']);
}

public function test_shared_data_is_computed_once(): void
{
    // `getDetailGeojson()` chiamato una volta sola anche chiedendo due versioni
}
```

Il confronto delle chiavi è **senza ordine** (`assertEqualsCanonicalizing`): l'ordine naturale
dipende da dove ciascuna nasce — `taxonomy_where` dal jsonb, `taxonomy` e `taxonomyWheres` da
`GeoJsonService` (`src/Services/GeoJsonService.php:49-59`) — e non è parte del contratto.
Il caso di test deve avere attività **e** where valorizzate: senza attività `taxonomy` sparisce del
tutto, perché `removeInvalidProperties()` scarta un array con tutti i valori vuoti
(`GeoJsonService.php:164-165`).

L'ultimo test si scrive con un doppio parziale del modello che conta le chiamate a
`getDetailGeojson()`.

- [ ] **Step 2: Scrivere i test della trasformazione**

```php
public function test_apply_adds_back_the_three_old_forms(): void
{
    $legacy = LegacyTrackPropertiesTransformation::apply($current, $rawContext, $track);

    $this->assertArrayHasKey('taxonomy', $legacy);
    $this->assertArrayHasKey('taxonomy_where', $legacy);
    $this->assertArrayHasKey('taxonomyWheres', $legacy);
}

public function test_apply_does_not_invent_keys_the_track_has_not(): void
{
    // traccia senza `taxonomy_where` nel jsonb e senza attività:
    // la trasformazione non aggiunge `taxonomy_where: []` né `taxonomy`
}

public function test_apply_leaves_every_other_key_untouched(): void
{
    $legacy = LegacyTrackPropertiesTransformation::apply($current, $rawContext, $track);
    $old = array_flip(['taxonomy', 'taxonomy_where', 'taxonomyWheres']);
    $this->assertSame($current, array_diff_key($legacy, $old));
}
```

- [ ] **Step 3: Lanciare i test e vederli fallire**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest tests/Feature/Track/EcTrackPropertiesBuilderTest.php tests/Unit/Http/Resources/LegacyTrackPropertiesTransformationTest.php'`
Expected: FAIL, classi assenti.

- [ ] **Step 4: Scrivere `EcTrackPropertiesBuilder`**

Il costruttore calcola **una volta sola** i dati condivisi: `getDetailGeojson()`, la geometria a
linestring, `removeInvalidProperties()`, `applyDemFields()`, `name` tradotto, `roundtrip`,
`related_pois`, `feature_image` e `image_gallery`.

`current()` restituisce quell'array **senza** `taxonomy`, `taxonomy_where` e `taxonomyWheres`, e
senza passare da `applyTaxonomyWhereDisplay()`: quel metodo esiste per produrre le due chiavi
vecchie (`src/Models/Abstracts/GeometryModel.php:245-246`) e appartiene quindi alla trasformazione,
non al percorso condiviso.

`rawContext()` conserva ciò che serve a una trasformazione per ricalcolarle: il
`properties.taxonomy_where` grezzo del jsonb e le relazioni già caricate.

`build()` parte da `current()` e applica le trasformazioni a ritroso, una per versione, fino alla
più vecchia richiesta; restituisce solo le versioni chieste, nell'ordine chiesto.

Il trait `HasDemClassification` si sposta sul builder.

- [ ] **Step 5: Scrivere `LegacyTrackPropertiesTransformation`**

Riceve le properties correnti e il contesto grezzo e **ricalcola** le tre chiavi: `taxonomy` e
`taxonomyWheres` come fa `GeoJsonService` (`:49-59`), `taxonomy_where` e `taxonomyWheres` filtrati
da `TaxonomyWhereDisplayService` come fa `applyTaxonomyWhereDisplay()`. Una chiave che quel dato
non produce non si aggiunge: `removeInvalidProperties()` oggi scarta gli array vuoti, e una chiave
vuota comparsa dal nulla è una modifica di forma.

Nel docblock, due righe: questa classe è un modulo di versione nel senso di
[R4](overview.md#r4), si cancella intera quando la `legacy` esce di scena, e non va chiamata da
nessuno che non sia `build()`.

- [ ] **Step 6: Spostare il test DEM esistente**

`tests/Unit/Http/Resources/EcTrackResourceDemPriorityTest.php:16-20` estende `EcTrackResource` con
una sottoclasse anonima per raggiungere `applyDemFields()`. Il metodo ora sta sul builder: il test
va riscritto contro `EcTrackPropertiesBuilder`, senza perdere nessuno dei casi che copre.

- [ ] **Step 7: Lanciare i test nuovi e quello spostato**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest tests/Feature/Track/EcTrackPropertiesBuilderTest.php tests/Unit/Http/Resources/'`
Expected: PASS.

- [ ] **Step 8: Commit** — `refactor(oc:8997): forma canonica delle properties e trasformazione verso legacy`

---

### Task 5: L'involucro del Feature e la scrittura di tutte le versioni

Realizza [R6](overview.md#r6) e [R12](overview.md#r12). Qui il test di forma del Task 1 diventa il
guardiano vero: è il task in cui il file può cambiare senza che nessuno se ne accorga.

**File:**
- Modify: `src/Http/Resources/EcTrackResource.php`
- Modify: `src/Jobs/Track/UpdateEcTrackAwsJob.php:47-63`
- Create: `tests/Feature/Track/UpdateEcTrackAwsJobTest.php`

**Interfaces:**
- Consuma: `EcTrackPropertiesBuilder`, `WmfeTrackVersion::active()`, `StorageService::storeTrack()`.
- Produce: `EcTrackResource::__construct(EcTrack $track, ?WmfeTrackVersion $version = null)` — a
  versione assente vale `Legacy`, così ogni chiamante esistente continua a ottenere quello che
  otteneva. `toArray()` resta la serializzazione di una versione, e **resta l'unico punto che
  produce il JSON del file**.

- [ ] **Step 1: Scrivere i test del job**

```php
public function test_job_writes_one_file_per_active_version(): void
{
    config(['wm-package.wmfe_track_versions' => 'legacy,v1']);
    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    Storage::disk('wmfe')->assertExists("/webmapp/tracks/{$track->id}.json");
    Storage::disk('wmfe')->assertExists("/webmapp/tracks/v1/{$track->id}.json");
}

public function test_job_writes_only_the_declared_versions(): void
{
    config(['wm-package.wmfe_track_versions' => 'v1']);
    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    Storage::disk('wmfe')->assertMissing("/webmapp/tracks/{$track->id}.json");
    Storage::disk('wmfe')->assertExists("/webmapp/tracks/v1/{$track->id}.json");
}

public function test_job_fails_when_a_write_fails(): void
{
    // StorageService doppiato: `storeTrack` ritorna false sulla seconda versione
    $this->expectException(\RuntimeException::class);
    (new UpdateEcTrackAwsJob($track))->handle($storage);
}

public function test_the_two_files_share_type_geometry_and_id(): void
{
    config(['wm-package.wmfe_track_versions' => 'legacy,v1']);
    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    $legacy = json_decode(Storage::disk('wmfe')->get("/webmapp/tracks/{$track->id}.json"), true);
    $v1 = json_decode(Storage::disk('wmfe')->get("/webmapp/tracks/v1/{$track->id}.json"), true);

    $this->assertSame($legacy['type'], $v1['type']);
    $this->assertSame($legacy['geometry'], $v1['geometry']);
    $this->assertSame($legacy['properties']['id'], $v1['properties']['id']);
}
```

L'ultimo confronta `properties.id`: un `id` di primo livello nel Feature non esiste, e
[R6](overview.md#r6) lo nomina perché è l'identificativo del contenuto, non per la posizione.

- [ ] **Step 2: Lanciare i test e vederli fallire**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest tests/Feature/Track/UpdateEcTrackAwsJobTest.php'`
Expected: FAIL — oggi il job scrive un file solo e non guarda l'esito della `put`.

- [ ] **Step 3: Riscrivere `EcTrackResource`**

Prende la versione nel costruttore, istanzia il builder una volta e serializza: involucro
(`type`, `geometry`) dal builder, `properties` della versione chiesta. Niente logica di calcolo,
che sta tutta nel builder. La Resource **resta** l'unico punto che serializza, perché il filtro di
`JsonResource` fa parte della forma del file (vedi i vincoli globali).

- [ ] **Step 4: Riscrivere `handle()`**

Un ciclo sulle versioni attive: per ciascuna, una `EcTrackResource` con quella versione, e
`storeTrack($id, $resource->toJson(), $version)`. Una `put` che ritorna `false` fa lanciare una
`RuntimeException` con dentro id e versione, così Horizon ritenta e, finiti i tentativi, il job
risulta fallito ([R12](overview.md#r12)). Il fallimento interrompe il ciclo: un file aggiornato e
uno fermo è lo stato che il ritentativo sistema, perché riscrivere entrambi è idempotente.

Il builder può essere costruito una volta e passato alle Resource, se il profilo delle query lo
giustifica; se complica la firma pubblica, due istanze vanno bene — il calcolo caro è dentro
`getDetailGeojson()`, e il test «calcolato una volta sola» del Task 4 dice dove va messo il
confine.

- [ ] **Step 5: Lanciare i test del job, del Task 4 e del Task 1**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest tests/Feature/Track/ tests/Unit/Http/Resources/'`
Expected: PASS. Se fallisce `TrackJsonShapeTest`, è [R1](overview.md#r1) che si è rotto: si
risale alla chiave o alla lista diventata oggetto, **non si riscrive la fixture**.

- [ ] **Step 6: Commit** — `feat(oc:8997): il job scrive una versione per file e fallisce su scrittura fallita`

---

### Task 6: Gli URL delle versioni in Nova

Realizza [R9](overview.md#r9).

**File:**
- Modify: `src/Nova/Cards/ApiLinksCard/src/EcTrackApiLinksCard.php:11-22`
- Create: `tests/Unit/Nova/EcTrackApiLinksCardTest.php`

**Interfaces:**
- Consuma: `WmfeTrackVersion::active()` e `pathPrefix()`.

- [ ] **Step 1: Scrivere il test**

Con `WMFE_TRACK_VERSIONS=legacy,v1` la card espone due link, con `label` `Track JSON` e
`Track JSON v1` e `url` `…/tracks/769.json` e `…/tracks/v1/769.json`. Con la sola `legacy`
dichiarata, un link solo, con la `label` di oggi.

Un test in più, che è il motivo per cui questo task ha un test: con
`WMFE_TRACK_VERSIONS=legacy,v9` la card **non** lancia e mostra il solo link senza versione. La
card la istanzia la detail view della traccia (`src/Nova/EcTrack.php:256`, e lo shard a
`app/Nova/EcTrack.php:395`): un refuso nel `.env` non deve mandare in errore la scheda di ogni
sentiero. [R11](overview.md#r11) chiede che un nome sconosciuto faccia fallire **la scrittura**,
non la lettura di un link.

- [ ] **Step 2: Lanciare il test e vederlo fallire**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest tests/Unit/Nova/EcTrackApiLinksCardTest.php'`
Expected: FAIL, un link solo.

- [ ] **Step 3: Implementare**

Il costruttore cicla `WmfeTrackVersion::active()` e compone un link per versione riusando
`pathPrefix()` — l'URL non si ricompone a mano una seconda volta. La `label` della `legacy` resta
`Track JSON`, invariata: è quella che il team ha in mano oggi. L'`InvalidArgumentException` di
`active()` si cattura qui e si ripiega su `[WmfeTrackVersion::Legacy]`.

- [ ] **Step 4: Lanciare il test**

Run: lo stesso dello step 2.
Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oc:8997): un link per versione attiva nella detail view della traccia`

---

### Task 7: Documentazione del meccanismo

[R10](overview.md#r10) dice che il meccanismo vive qui: chi monta il package deve trovarne scritto
l'uso senza leggere il diario della feature.

**File:**
- Create: `docs/knowledge/8997-versioni-del-json-della-traccia-su-wmfe.md`
- Modify: `CLAUDE.md` — una riga nella tabella «Conoscenza»

- [ ] **Step 1: Scrivere la pagina**

Cosa copre: i due indirizzi e cosa c'è dentro ciascuno; `WMFE_TRACK_VERSIONS` e cosa succede se
manca o se porta un nome sconosciuto; la finestra di due versioni e come la si accorcia o allunga;
**come si aggiunge una versione nuova**, che è il punto per cui la pagina esiste — si aggiunge il
caso all'enum come ultimo (diventa la canonica), la versione di ieri diventa una classe di
trasformazione a ritroso, e si lancia l'Action Nova «Update Tracks on AWS» della Resource
`EcTrack` (`ExecuteEcTrackDataChainAction` con il solo `UpdateEcTrackAwsJob`) per il bootstrap dei
file;
il vincolo che la serializzazione passi da `EcTrackResource`, con il perché; il limite dei tre
canali (file, Elasticsearch, `pois.geojson`), di cui solo il primo è versionato.

Il **perché** della direzione a ritroso non si ricopia qui: una riga e il link a
[R4](overview.md#r4).

- [ ] **Step 2: Verificare i link**

Run: `docker exec php-forestas bash -lc 'cd wm-package/docs/knowledge && grep -oE "\]\(\.\.[^)]+\)" 8997-versioni-del-json-della-traccia-su-wmfe.md'`
Expected: ogni path stampato esiste sul filesystem.

- [ ] **Step 3: Commit** — `docs(oc:8997): versioni del JSON della traccia su wmfe`

---

### Task 8: Suite intera e controllo dei documenti

- [ ] **Step 1: Verificare l'isolamento del database**

Come da `.claude/rules/test.md`. Se non è verificabile, la suite **non si lancia** e si chiede al
dev.

- [ ] **Step 2: Lanciare la suite del package**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/pest'`
Expected: PASS. Ogni fallimento preesistente va riportato per nome, non ignorato.

- [ ] **Step 3: PHPStan e Pint sui soli file toccati**

Run: `docker exec php-forestas bash -lc 'cd wm-package && vendor/bin/phpstan analyse src/Enums/WmfeTrackVersion.php src/Http/Resources/EcTrackPropertiesBuilder.php src/Http/Resources/TrackVersions/ src/Http/Resources/EcTrackResource.php src/Jobs/Track/UpdateEcTrackAwsJob.php src/Services/StorageService.php src/Nova/Cards/ApiLinksCard/src/EcTrackApiLinksCard.php'`
Expected: nessun errore nuovo. Pint si lancia sui singoli file, mai sull'intero repo.

- [ ] **Step 4: Annotare in `notes.md` quello che la review deve sapere**

Tre voci, tutte emerse in revisione del piano: l'effetto di [R12](overview.md#r12) sulle catene di
job accodate da `EcTrackService`; il caso di uno shard con la sola `v1` attiva, che farebbe
accodare un job a ogni richiesta dell'endpoint; e il commento ormai falso in
`tests/Feature/EcTrack/TrackDetailForestasTest.php:163` dello shard, che va corretto lì.

- [ ] **Step 5: Controllo dei documenti della feature**

Run: `/Users/bongiu/Documents/claude-marketplace/plugins/wm-skills/scripts/feature-docs-check.sh docs/features/8997-versione-del-formato-del-json-della-traccia-su-wmfe prima-del-commit`
Expected: nessun errore.

- [ ] **Step 6: Commit** — nessuno. Qui il lavoro sul package è finito e si passa allo shard.

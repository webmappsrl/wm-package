> Ticket: oc:8586

# Privacy: rimuovere il nome utente reale dal marker live sulla mappa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Il marker live che indica la posizione in tempo reale di un utente su un cammino mostra sempre un tooltip anonimo generico e non è più cliccabile verso la pagina Nova dell'utente, per tutti i ruoli.

**Architecture:** Il comportamento oggi vive interamente in `Layer::getFeatureCollectionMap()`: per ogni posizione live risolve `user_id` a uno `User` (nome/cognome) e genera `tooltip`/`link` condizionalmente. La fix rimuove quella risoluzione e la generazione condizionale, lasciando sempre il testo di fallback anonimo già esistente e nessuna property `link`. Nessun cambiamento a route, middleware, KPI aggregato o schema DB — solo al contenuto del payload GeoJSON di questo singolo metodo, più la rimozione di un `console.log` che oggi ne loggava il payload completo nel browser.

**Tech Stack:** Laravel (PHP), Pest/PHPUnit per i test backend, Vue 3 per il campo Nova `FeatureCollectionMap`.

**Spec:** [docs/features/8586-privacy-nome-utente-marker-live/overview.md](overview.md)

## Global Constraints

- Nessuna modifica a route/middleware dell'endpoint del campo Nova (nessuna autorizzazione per-record aggiunta) — fuori scope, rischio accettato e documentato in overview.md.
- Nessun parametro nuovo su `getFeatureCollectionMap()` per un futuro gate per ruolo — solo un follow-up testuale in notes.md a fine lavoro, nessun codice predisposto ora.
- Nessuna traduzione (i18n) del testo anonimo: resta la stessa stringa PHP hardcoded già esistente, `'Posizione utente (ultimi 30 minuti)'` — nessuna chiamata `__()`/`trans()` introdotta.
- `AnalyticsService::queryUniqueUsers()` (KPI aggregato "utenti sul cammino") non viene toccato.
- Nessuna migrazione DB, nessuna modifica di schema.
- Nessun bump del submodule `wm-package` né deploy in camminiditalia in questo piano — lo gestisce il dev manualmente dopo il merge.
- Documentazione, commenti e messaggi di commit in italiano (regola del `CLAUDE.md` di questo repo); i commit usano lo scope `oc:8586`.
- **Nessuna esecuzione automatica dei test in questa sessione**: `docker exec laravel-camminiditalia php artisan test wm-package/tests/...` fallisce con `Class "Wm\WmPackage\Tests\TestCase" not found` (quel namespace non è in `autoload-dev` del repo principale, verificato empiricamente). I comandi di test in questo piano sono per riferimento — il dev li esegue a mano nell'ambiente che preferisce (es. container `php-forestas` con `composer test`, per cui questo repo è pensato secondo il suo `CLAUDE.md`).

## Review Focus

- User risolvibile ma con `name`/`surname` vuoti → deve restare anonimo e senza `link` esattamente come uno user non risolvibile (Task 1, test dedicato).
- Nessuna posizione live recente (nessun utente sul cammino in questo momento) → `getFeatureCollectionMap()` non deve fallire e deve continuare a restituire le altre feature (tracce, EcPoi, taxonomy_wheres) — già coperto da test esistenti che restano invariati, verificare che restino verdi dopo la modifica (Task 2).
- Il componente Vue non deve rompersi quando `featureProps.link` è `undefined` per un marker live (prima poteva essere presente per utenti risolti) — nessuna suite di test automatici esiste per questo componente in questo repo (verificato in fase di ricerca): copertura solo tramite verifica manuale nel browser (Task 3).
- Il KPI aggregato "utenti sul cammino" non deve cambiare comportamento — non esiste nel repo un test che lo eserciti insieme a questa modifica specifica; mitigato non toccando affatto `AnalyticsService::queryUniqueUsers()` (Global Constraints) e lasciandolo fuori da ogni task.
- Rimuovere il lookup di `\Wm\WmPackage\Models\User` non deve rompere il `try/catch` di sicurezza intorno ad esso — la rimozione elimina l'intero blocco (compreso il `catch`), non lascia un `try` orfano: verificato nel diff finale di Task 1, nessun test dedicato necessario (è un controllo strutturale sul codice, non un comportamento a runtime).

---

### Task 1: Riscrivere i test esistenti per il nuovo comportamento anonimo (TDD — devono fallire prima della fix)

**Files:**
- Modify: `tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php:126-153` (rinomina/riscrittura di `test_position_shows_user_nominativo_and_link_when_user_id_is_present`)
- Modify: `tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php:181-209` (rinomina/riscrittura di `test_position_keeps_link_when_user_is_found_but_nominativo_is_blank`)

**Interfaces:**
- Consumes: `Layer::getFeatureCollectionMap()` (esistente, invariata come firma — `array`), `AnalyticsService::getRecentUserPositions()` (non toccata in questo task).
- Produces: nessuna nuova interfaccia — solo asserzioni aggiornate che Task 2 dovrà far passare.

- [ ] **Step 1: Sostituisci il test `test_position_shows_user_nominativo_and_link_when_user_id_is_present`**

Nel file `tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php`, individua questo blocco (righe 126-153):

```php
    /**
     * user_id è una property nuova sull'evento userMoved (oc:8159 follow-up): quando presente e
     * risolvibile a uno User esistente, il marker mostra il nominativo (name + surname) invece
     * del testo anonimo di default ed è cliccabile verso la pagina Nova dello user.
     */
    public function test_position_shows_user_nominativo_and_link_when_user_id_is_present(): void
    {
        $user = User::factory()->create(['name' => 'Maria', 'surname' => 'Rossi']);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_values(array_filter(
            $geojson['features'],
            fn ($f) => isset($f['properties']['checkpointRouteColors'])
        ));

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame('Maria Rossi', $userPositionFeatures[0]['properties']['tooltip']);
        $this->assertStringContainsString('nova/resources/users/'.$user->id, $userPositionFeatures[0]['properties']['link']);
    }
```

Sostituiscilo con:

```php
    /**
     * oc:8586 (privacy, misura cautelativa in attesa di parere legale): anche quando user_id è
     * risolvibile a uno User esistente, il marker resta anonimo e senza link — nessuna
     * distinzione di ruolo, il nominativo reale e il link alla pagina Nova dell'utente non
     * vengono più mostrati a nessuno.
     */
    public function test_position_shows_anonymous_label_and_no_link_even_when_user_id_is_resolvable(): void
    {
        $user = User::factory()->create(['name' => 'Maria', 'surname' => 'Rossi']);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_values(array_filter(
            $geojson['features'],
            fn ($f) => isset($f['properties']['checkpointRouteColors'])
        ));

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame('Posizione utente (ultimi 30 minuti)', $userPositionFeatures[0]['properties']['tooltip']);
        $this->assertArrayNotHasKey('link', $userPositionFeatures[0]['properties']);
    }
```

- [ ] **Step 2: Sostituisci il test `test_position_keeps_link_when_user_is_found_but_nominativo_is_blank`**

Nello stesso file, individua questo blocco (righe 181-209):

```php
    /**
     * user_id risolve a uno User reale ma con name/surname vuoti (es. riga creata senza
     * cognome) — il tooltip ricade sul testo anonimo (nessun nominativo da mostrare), ma il
     * link resta presente: lo user esiste ed è comunque identificabile su Nova, il link non
     * deve dipendere dalla stringa del nominativo.
     */
    public function test_position_keeps_link_when_user_is_found_but_nominativo_is_blank(): void
    {
        $user = User::factory()->create(['name' => '', 'surname' => null]);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_values(array_filter(
            $geojson['features'],
            fn ($f) => isset($f['properties']['checkpointRouteColors'])
        ));

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame('Posizione utente (ultimi 30 minuti)', $userPositionFeatures[0]['properties']['tooltip']);
        $this->assertStringContainsString('nova/resources/users/'.$user->id, $userPositionFeatures[0]['properties']['link']);
    }
```

Sostituiscilo con:

```php
    /**
     * oc:8586: user_id risolve a uno User reale ma con name/surname vuoti — prima di questa fix
     * il link restava comunque presente perché gated solo su $user risolto, non sul nominativo.
     * Ora il marker è anonimo e senza link in ogni caso, questo scenario non fa più differenza
     * rispetto a uno user con nome compilato.
     */
    public function test_position_shows_anonymous_label_and_no_link_when_user_has_blank_name(): void
    {
        $user = User::factory()->create(['name' => '', 'surname' => null]);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_values(array_filter(
            $geojson['features'],
            fn ($f) => isset($f['properties']['checkpointRouteColors'])
        ));

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame('Posizione utente (ultimi 30 minuti)', $userPositionFeatures[0]['properties']['tooltip']);
        $this->assertArrayNotHasKey('link', $userPositionFeatures[0]['properties']);
    }
```

- [ ] **Step 3: Verifica che i due test riscritti falliscano contro il codice attuale (non ancora modificato)**

Il codice di produzione in `Layer.php` non è stato ancora toccato in questo task: `test_position_shows_anonymous_label_and_no_link_even_when_user_id_is_resolvable` deve fallire su `assertSame('Posizione utente (ultimi 30 minuti)', ...)` (oggi riceverebbe `'Maria Rossi'`), e `test_position_shows_anonymous_label_and_no_link_when_user_has_blank_name` deve fallire su `assertArrayNotHasKey('link', ...)` (oggi il link è presente).

Esegui con l'ambiente che usi per i test di wm-package (vedi Global Constraints — non eseguito automaticamente in questa sessione):

```bash
vendor/bin/pest tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php
```

Expected: 2 FAIL (i due test riscritti), 2 PASS (gli altri due test del file, invariati).

- [ ] **Step 4: Non committare ancora**

Questo task lascia intenzionalmente i test rossi — il commit avviene alla fine di Task 2, quando il codice di produzione li fa passare (evita un commit con una suite rossa in storia).

---

### Task 2: Rendere il marker live sempre anonimo e senza link in `Layer::getFeatureCollectionMap()`

> ⚠️ L'implementazione ha deviato da questo task dopo la review: [notes.md](notes.md#decisione-post-review-logica-preservata-dietro-flag-hardcoded-supera-una-decisione-della-challenge)

**Files (nota: il codice reale è a `497-559` dopo la deviazione post-review, vedi rimando sopra):**
- Modify: `src/Models/Layer.php:497-556` (blocco di generazione delle posizioni live dentro `getFeatureCollectionMap()`)
- Modify: `src/Services/PostHog/AnalyticsService.php:454-456` (solo il docblock di `getRecentUserPositions()`, nessun cambio di codice)

**Interfaces:**
- Consumes: `AnalyticsService::getRecentUserPositions(Layer $layer): list<array{lat: float, lng: float, user_id: ?int}>` — firma invariata, il campo `user_id` continua a essere presente nel payload per un eventuale uso futuro, semplicemente non viene più letto dal chiamante.
- Produces: `Layer::getFeatureCollectionMap(): array` con, per ogni posizione live, `properties.tooltip` sempre uguale a `'Posizione utente (ultimi 30 minuti)'` e nessuna chiave `properties.link`. Firma del metodo invariata — nessun consumer di Task 3 dipende da altro.

- [ ] **Step 1: Sostituisci il blocco di generazione delle posizioni live**

In `src/Models/Layer.php`, individua questo blocco (righe 497-556, subito dopo il blocco delle `taxonomyWheres` e prima del `return` finale):

```php
        $recentPositions = app(AnalyticsService::class)->getRecentUserPositions($this);

        // user_id è una property nuova sull'evento userMoved (oc:8159 follow-up): non garantita su
        // ogni punto (utente non autenticato, o evento registrato prima che l'app la iniziasse a
        // inviare) — batch lookup, non una query per posizione, e nominativo/link sono un
        // arricchimento facoltativo: senza user_id risolvibile il marker resta quello anonimo di
        // sempre, nessun comportamento esistente cambia.
        // \Wm\WmPackage\Models\User, non la classe App\Models\User importata sopra (usata solo da
        // layerOwner()): stessa tabella, nessuna differenza di dati per id/name/surname, ma la
        // classe del package è autoloadabile anche nella suite standalone di wm-package.
        // try/catch dedicato: getRecentUserPositions() protegge già i propri errori DB/PostHog
        // (vedi il \Throwable lì), ma questo lookup gira fuori da quella protezione — senza questo
        // catch, un blip transitorio di Postgres qui romperebbe l'intero getFeatureCollectionMap()
        // (tracce, EcPoi, taxonomy_wheres compresi) per un arricchimento puramente cosmetico.
        try {
            $userIds = array_values(array_unique(array_filter(array_column($recentPositions, 'user_id'))));
            $usersById = $userIds === []
                ? collect()
                : \Wm\WmPackage\Models\User::whereIn('id', $userIds)->get(['id', 'name', 'surname'])->keyBy('id');
        } catch (\Throwable $e) {
            Log::error('getFeatureCollectionMap(): user_id lookup failed', ['layer_id' => $this->id, 'error' => $e->getMessage()]);
            $usersById = collect();
        }

        foreach ($recentPositions as $position) {
            $user = isset($position['user_id']) ? $usersById->get($position['user_id']) : null;
            $nominativo = $user ? trim("{$user->name} {$user->surname}") : '';

            $properties = [
                'tooltip' => $nominativo !== '' ? $nominativo : 'Posizione utente (ultimi 30 minuti)',
                'pointFillColor' => 'rgba(34, 197, 94, 0.9)',
                'pointStrokeColor' => 'rgba(255, 255, 255, 1)',
                'pointStrokeWidth' => 3,
                'pointRadius' => 8,
                // Anelli concentrici (bullseye), non solo colore diverso: gli EcPoi sono un
                // cerchio pieno senza questa property — un utente daltonico o una stampa in
                // scala di grigi distingue comunque i due marker dal profilo (anelli vs pieno),
                // non solo dalla tinta. Nessuna modifica al componente Vue: checkpointRouteColors
                // è già supportato da FeatureCollectionMap.vue::getFeatureStyle() per un altro
                // caso d'uso (percorsi multi-tratta).
                'checkpointRouteColors' => ['rgba(255, 255, 255, 1)', 'rgba(34, 197, 94, 0.9)'],
            ];

            // Gated su $user (utente risolto), non su $nominativo: uno user con name/surname
            // vuoti resta comunque un utente reale e cliccabile, solo senza un nome da mostrare
            // nel tooltip — il link non deve dipendere dal fatto che la stringa risultante sia
            // non vuota.
            if ($user) {
                $properties['link'] = url('nova/resources/users/'.$user->id);
            }

            $this->addFeaturesForMap([[
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$position['lng'], $position['lat']],
                ],
                'properties' => $properties,
            ]]);
        }
```

Sostituiscilo con:

```php
        $recentPositions = app(AnalyticsService::class)->getRecentUserPositions($this);

        // oc:8586: il marker live è sempre anonimo, per tutti gli utenti e indipendentemente dal
        // ruolo di chi guarda la mappa — misura cautelativa richiesta dal cliente in attesa di un
        // parere legale sulla visualizzazione della posizione degli utenti. Non risolviamo più
        // user_id a un nominativo né generiamo un link verso la pagina Nova dell'utente: la
        // property user_id resta disponibile su ogni posizione (vedi getRecentUserPositions())
        // per un eventuale uso futuro, semplicemente non viene più letta qui.
        foreach ($recentPositions as $position) {
            $properties = [
                'tooltip' => 'Posizione utente (ultimi 30 minuti)',
                'pointFillColor' => 'rgba(34, 197, 94, 0.9)',
                'pointStrokeColor' => 'rgba(255, 255, 255, 1)',
                'pointStrokeWidth' => 3,
                'pointRadius' => 8,
                // Anelli concentrici (bullseye), non solo colore diverso: gli EcPoi sono un
                // cerchio pieno senza questa property — un utente daltonico o una stampa in
                // scala di grigi distingue comunque i due marker dal profilo (anelli vs pieno),
                // non solo dalla tinta. Nessuna modifica al componente Vue: checkpointRouteColors
                // è già supportato da FeatureCollectionMap.vue::getFeatureStyle() per un altro
                // caso d'uso (percorsi multi-tratta).
                'checkpointRouteColors' => ['rgba(255, 255, 255, 1)', 'rgba(34, 197, 94, 0.9)'],
            ];

            $this->addFeaturesForMap([[
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$position['lng'], $position['lat']],
                ],
                'properties' => $properties,
            ]]);
        }
```

Nota: questo rimuove anche l'intera query `\Wm\WmPackage\Models\User::whereIn(...)` — non serve più risolvere alcun utente per costruire il marker, un beneficio collaterale (una query DB in meno per ogni caricamento della mappa live).

- [ ] **Step 2: Aggiorna il docblock di `getRecentUserPositions()` in `AnalyticsService.php`**

In `src/Services/PostHog/AnalyticsService.php`, individua queste righe (454-456 circa) dentro il docblock del metodo:

```php
     * `user_id` (nullable) è l'id applicativo dello user, non l'id anonimo PostHog `person_id`
     * (quest'ultimo è solo una chiave di join interna, scartata prima del return) — usato dal
     * chiamante (Layer::getFeatureCollectionMap()) per mostrare nominativo e link invece del
     * marker anonimo di default, quando disponibile.
```

Sostituiscile con:

```php
     * `user_id` (nullable) è l'id applicativo dello user, non l'id anonimo PostHog `person_id`
     * (quest'ultimo è solo una chiave di join interna, scartata prima del return) — non più letto
     * dal chiamante (Layer::getFeatureCollectionMap()) dopo oc:8586: il marker live è sempre
     * anonimo, il campo resta nel payload solo per un eventuale uso futuro.
```

- [ ] **Step 3: Esegui i test del file per verificare che ora passino tutti**

```bash
vendor/bin/pest tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php
```

Expected: 4 PASS (i due riscritti in Task 1 ora passano, i due invariati continuano a passare).

- [ ] **Step 4: Esegui l'intera suite del package per verificare l'assenza di regressioni**

```bash
composer test
```

Expected: tutti i test verdi (nessuna regressione — nessun altro file nel repo referenzia `tooltip`/`link` di questo endpoint, verificato in fase di ricerca).

- [ ] **Step 5: Commit**

```bash
git add src/Models/Layer.php src/Services/PostHog/AnalyticsService.php tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php
git commit -m "fix(oc:8586): rendi anonimo il marker live, rimuovi nominativo e link utente"
```

---

### Task 3: Rimuovere il `console.log` che espone il payload completo nel browser

**Files:**
- Modify: `src/Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue:426`

**Interfaces:**
- Consumes: nessuna — modifica isolata a una riga di logging, nessuna interfaccia di Task 1/2 coinvolta.
- Produces: nessuna nuova interfaccia. Comportamento visivo della mappa invariato (il log non influenzava il rendering).

- [ ] **Step 1: Rimuovi la riga di log**

In `src/Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue`, individua (riga 426, dentro `loadGeoJSON()`):

```js
                const data = await response.json();
                console.log('GeoJSON loaded:', data);
                applyGeoJSONData(data);
```

Sostituiscilo con:

```js
                const data = await response.json();
                applyGeoJSONData(data);
```

Non toccare gli altri `console.log` presenti nel file (righe 405, 667, 757): non loggano dati utente sensibili (URL, esito screenshot, cambio URL) e sono fuori dallo scope di questo ticket.

- [ ] **Step 2: Ricompila il campo Nova**

Il `dist` del campo è versionato (vedi `CLAUDE.md` del package) — dalla cartella del campo, non dalla root:

```bash
cd src/Nova/Fields/FeatureCollectionMap && npm run prod
```

- [ ] **Step 3: Verifica manuale nel browser**

Nessuna suite di test automatici esiste per questo componente in questo repo (verificato in fase di ricerca). Apri in Nova il detail di un Layer con il campo `FeatureCollectionMap` e una posizione live attiva (o simulala), apri la console DevTools del browser, ricarica la mappa e verifica che non compaia più la riga `GeoJSON loaded: {...}` con il payload completo. Verifica anche che il marker anonimo (introdotto in Task 2) sia visibile e che il click su di esso non apra più nessuna pagina Nova (nessun `link` in `properties`).

- [ ] **Step 4: Verifica che il `diff` non contenga prop spurie nel `dist`**

```bash
git diff --stat src/Nova/Fields/FeatureCollectionMap/dist
```

Expected: solo le righe corrispondenti alla rimozione del `console.log` (nessuna differenza di formattazione/versione degli strumenti di build non legata a questa modifica).

- [ ] **Step 5: Commit**

```bash
git add src/Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue src/Nova/Fields/FeatureCollectionMap/dist
git commit -m "fix(oc:8586): rimuovi console.log che espone il payload GeoJSON completo"
```

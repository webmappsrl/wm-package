> Ticket: oc:8568

# Sentieri vicini nella mappa — piano di implementazione

> **Per chi esegue:** REQUIRED SUB-SKILL: usa `superpowers:subagent-driven-development` (consigliata)
> oppure `superpowers:executing-plans` per eseguire il piano task per task. Gli step usano checkbox
> (`- [ ]`) per il tracciamento.

**Obiettivo:** la mappa di un codice del registro disegna anche gli altri sentieri del suo settore,
con numero e variante scritti sulla mappa oltre la soglia di zoom.

**Approccio:** il modello compone le feature dei vicini in un'unica query SQL (nessun `ST_AsGeoJSON`
per riga); un nuovo campo Nova con bundle proprio — schema di `SignageMap` — avvolge
`FeatureCollectionMap` e, sull'evento `map-ready`, sposta le feature dei vicini su un layer con
`declutter` e ricalcola l'inquadratura senza di loro. Il componente condiviso non si tocca.

**Stack:** PHP 8.1+ / Laravel / Nova 5, PostgreSQL + PostGIS, Pest, Vue 3 + OpenLayers 9,
Laravel Mix.

**Spec:** [overview.md](overview.md) — approvata dal dev, contiene requisiti, rischi e out of scope.

## Vincoli globali

- **Repo:** tutto in `wm-package`. Nessun file nel repo `forestas`.
- **PHP minimo `>8.1`:** mai `const` dentro un trait.
- **Geometrie PostGIS sempre via SQL puro**, mai attraverso l'ORM.
- **Traduzioni** in `resources/lang/*.json` del package, non in `lang/`.
- **`npm run prod` si esegue dentro la cartella del campo**, mai dalla root: dalla root risale al
  consumer e fallisce con `ERR_REQUIRE_ESM`.
- **Ogni campo Nova custom con build CSS porta il proprio `postcss.config.js`** (`module.exports = {}`).
- **Test:** `docker exec -it php-forestas bash`, poi da `wm-package/` `vendor/bin/pest`.
  L'isolamento del DB è garantito da `phpunit.xml.dist` (`DB_DATABASE=wm_package`): non creare un
  `phpunit.xml` locale.
- **Commit:** scope `feat(oc:8568)` / `fix(oc:8568)`, messaggi in italiano. **I blocchi `git` di
  questo piano sono istruzioni per il dev, non comandi da eseguire in autonomia.**
- **Cronometro:** all'inizio e alla fine di ogni task esegui `date -u +"%H:%M:%S"` e riporta il
  tempo in `notes.md`, nella tabella «preventivo contro consuntivo».

## Struttura dei file

| File | Responsabilità |
|---|---|
| `src/TrailRegistry/Models/TrailRegistryCode.php` | accessor `label` (numero+variante), `neighbourFeatures()`, wiring in `getFeatureCollectionMap()` |
| `src/TrailRegistry/Models/Concerns/ComposesTrailRegistryMap.php` | `neighbourCodes()`: la query unica che porta geometrie ed etichette |
| `src/TrailRegistry/Nova/MapLegendRenderer.php` | voce di legenda condizionale per i vicini |
| `src/TrailRegistry/Nova/Fields/TrailRegistryMap.php` | dichiara il componente proprio; docblock riscritto |
| `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/` | nuova cartella del campo: toolchain, wrapper Vue, `dist` |
| `tests/Feature/TrailRegistry/TrailRegistryCodeMapTest.php` | test dei vicini |

---

### Task 1: l'etichetta di un codice

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-il-test-delletichetta-sta-in-un-file-che-esisteva-gi)


**Files:**
- Modify: `src/TrailRegistry/Models/TrailRegistryCode.php` (accessor `code()` alle righe 80-89)
- Test: `tests/Feature/TrailRegistry/TrailRegistryCodeTest.php` (se non esiste, crearlo)

**Interfaces:**
- Produces: `TrailRegistryCode::$label` (string) — numero a due cifre più variante, con `'0'` che
  significa «senza variante» e non compare mai. Esempi: `62`, `62A`, `05`.
- Consumes: niente.

**Perché:** oggi la regola sulla variante `'0'` vive solo dentro `code()`. L'etichetta della mappa ne
ha bisogno, e riscriverla significherebbe averne due copie che prima o poi divergono.

- [ ] **Step 1: scrivi il test che fallisce**

In `tests/Feature/TrailRegistry/TrailRegistryCodeTest.php`:

```php
<?php

use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

beforeEach(function () {
    runTrailRegistryStubs();
    Bus::fake();
});

it('compone l etichetta con numero e variante, e omette la variante 0', function () {
    $senzaVariante = TrailRegistryCode::findOrFail(makeCode([
        'status' => TrailCodeStatus::Reserved,
        'number' => 62,
        'variant' => '0',
    ]));

    $conVariante = TrailRegistryCode::findOrFail(makeCode([
        'status' => TrailCodeStatus::Reserved,
        'number' => 62,
        'variant' => 'A',
    ]));

    $unaCifra = TrailRegistryCode::findOrFail(makeCode([
        'status' => TrailCodeStatus::Reserved,
        'number' => 5,
        'variant' => '0',
    ]));

    expect($senzaVariante->label)->toBe('62')
        ->and($conVariante->label)->toBe('62A')
        ->and($unaCifra->label)->toBe('05');
});

it('il codice completo continua a usare la stessa regola della variante', function () {
    $code = TrailRegistryCode::findOrFail(makeCode([
        'status' => TrailCodeStatus::Reserved,
        'region' => 'Z', 'province' => 'NU', 'area' => 'B', 'sector' => '5',
        'number' => 62,
        'variant' => 'A',
    ]));

    expect($code->code)->toBe('ZNUB562A')
        ->and($code->code)->toEndWith($code->label);
});
```

Aggiungi in testa al file l'import `use Illuminate\Support\Facades\Bus;`.

- [ ] **Step 2: lancia il test e verifica che fallisca**

```bash
docker exec -it php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter='etichetta con numero e variante'"
```

Atteso: FAIL, perché `label` non esiste e l'accessor restituisce `null`.

- [ ] **Step 3: scrivi l'accessor e fai passare `code()` da lì**

In `src/TrailRegistry/Models/TrailRegistryCode.php`, subito dopo `fullCode()` (riga 84) aggiungi:

```php
    /**
     * Numero e variante, come si scrivono su una mappa: il numero a due cifre
     * e la variante quando c'e'. La variante `0` significa «senza variante» e
     * non compare mai.
     *
     * Vive qui e non nel punto in cui serve perche' e' l'unico posto che sa
     * cosa significa `'0'`: duplicare quella regola vuol dire cambiarne una
     * sola quando cambiera'.
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => sprintf(
            '%02d%s',
            $this->number,
            $this->variant === '0' ? '' : $this->variant,
        ));
    }
```

E riscrivi `code()` perché la usi, invece di ripetere la regola:

```php
    protected function code(): Attribute
    {
        return Attribute::get(fn () => $this->fullCode.$this->label);
    }
```

- [ ] **Step 4: lancia i test e verifica che passino**

```bash
docker exec -it php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryCodeTest.php"
```

Atteso: PASS. Poi l'intera suite del dominio, perché `code()` è usato altrove:

```bash
docker exec -it php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Feature/TrailRegistry"
```

Atteso: PASS, nessuna regressione.

- [ ] **Step 5: commit** *(istruzione per il dev)*

```bash
git add src/TrailRegistry/Models/TrailRegistryCode.php tests/Feature/TrailRegistry/TrailRegistryCodeTest.php
git commit -m "feat(oc:8568): l'etichetta di un codice vive in un accessor solo"
```

---

### Task 2: la query dei vicini

**Files:**
- Modify: `src/TrailRegistry/Models/Concerns/ComposesTrailRegistryMap.php` (in fondo, dopo `geojsonFrom()` alla riga 131)
- Test: `tests/Feature/TrailRegistry/TrailRegistryCodeMapTest.php`

**Interfaces:**
- Consumes: `TrailRegistryCode::$label` dal Task 1.
- Produces: `neighbourCodes(string $region, string $province, string $area, string $sector, int $excludeId): array<int, object>`
  — ogni riga ha `id` (int), `number` (int), `variant` (string), `ec_track_id` (?int),
  `trail_application_id` (?int), `geojson` (string, GeoJSON non decodificato).

**Perché una query sola:** `geojsonFrom()` fa un giro al database per ogni id. Con 62 vicini sarebbero
altrettanti round-trip prima che la mappa compaia.

**Perché si parte dal `full_code` e non da un'intersezione:** il settore scritto nel codice **è già** il
risultato di quell'intersezione, calcolato quando il codice è nato.

**Perché `COALESCE(t.geometry, a.geometry)`:** `ec_track_id` è nullable — un codice `Reserved` nato da
un'istanza non ancora approvata non ha sentiero, e la sua geometria sta sull'istanza. Se il codice ha
entrambi, vince il sentiero.

- [ ] **Step 1: scrivi il test che fallisce**

In fondo a `tests/Feature/TrailRegistry/TrailRegistryCodeMapTest.php`:

```php
it('trova i vicini attivi del settore, con la geometria del sentiero o dell istanza', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $inEsame = makeCode([
        'status' => TrailCodeStatus::Assigned,
        'taxonomy_where_id' => $sectorId,
        'number' => 62,
    ]);

    $vicinoAssegnato = makeCode([
        'status' => TrailCodeStatus::Assigned,
        'taxonomy_where_id' => $sectorId,
        'number' => 63,
    ]);

    $vicinoRiservato = makeCode([
        'status' => TrailCodeStatus::Reserved,
        'taxonomy_where_id' => $sectorId,
        'number' => 64,
    ]);

    $rilasciato = makeCode([
        'status' => TrailCodeStatus::Released,
        'taxonomy_where_id' => $sectorId,
        'number' => 65,
    ]);

    // Geometrie: l'helper crea le righe, non le geometrie.
    foreach (TrailRegistryCode::query()->whereIn('id', [$inEsame, $vicinoAssegnato, $vicinoRiservato, $rilasciato])->get() as $c) {
        if ($c->ec_track_id !== null) {
            DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', ['MULTILINESTRING Z((1 1 0, 2 2 0))', $c->ec_track_id]);
        }
        if ($c->trail_application_id !== null) {
            DB::statement('UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', ['MULTILINESTRING Z((3 3 0, 4 4 0))', $c->trail_application_id]);
        }
    }

    $code = TrailRegistryCode::findOrFail($inEsame);
    $vicini = $code->neighboursForTest();

    $numeri = array_map(fn ($r) => (int) $r->number, $vicini);
    sort($numeri);

    expect($numeri)->toBe([63, 64])
        ->and(array_filter($vicini, fn ($r) => $r->geojson === null))->toBe([]);
});
```

Perché il test possa chiamare un metodo `protected`, aggiungi in coda a `TrailRegistryCode` un
metodo pubblico sottile — che serve anche al Task 3:

```php
    /** @return array<int, object> */
    public function neighboursForTest(): array
    {
        return $this->neighbourCodes(
            $this->region, $this->province, $this->area, $this->sector, (int) $this->id,
        );
    }
```

> Se preferisci non aggiungere un metodo di comodo, sostituiscilo con una closure legata
> (`Closure::bind`) nel test. Il metodo esplicito è più leggibile e non costa nulla a runtime.

- [ ] **Step 2: lancia il test e verifica che fallisca**

```bash
docker exec -it php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter='trova i vicini attivi'"
```

Atteso: FAIL con «Call to undefined method … neighbourCodes()».

- [ ] **Step 3: scrivi la query nel trait**

In `src/TrailRegistry/Models/Concerns/ComposesTrailRegistryMap.php`, dopo `geojsonFrom()`:

```php
    /**
     * Gli altri codici attivi dello stesso settore, con la loro geometria:
     * sono i «vicini» che si disegnano sulla mappa perche' chi sceglie un
     * numero possa vedere quelli gia' presi li' intorno.
     *
     * **Una query sola**, con le geometrie gia' dentro: `geojsonFrom()` ne
     * farebbe una per riga, e in un settore popolato sono decine di round-trip
     * prima che la mappa compaia.
     *
     * Il settore si legge dalle colonne e non si ricalcola per intersezione:
     * il `full_code` scritto nel codice **e'** il risultato di quel calcolo,
     * fatto quando il codice e' nato.
     *
     * La geometria viene dal sentiero quando c'e', altrimenti dall'istanza:
     * `ec_track_id` e' nullable, e un codice riservato da un'istanza non
     * ancora approvata non ha sentiero. Senza il ripiego sparirebbe dalla
     * mappa proprio il vicino piu' recente.
     *
     * Gli stati sono quelli che **occupano** una posizione
     * ({@see TrailCodeStatus::active()}): sono gli stessi che popolano la
     * select del «sostituisci numero», e devono restare allineati.
     *
     * @return array<int, object>
     */
    protected function neighbourCodes(
        string $region,
        string $province,
        string $area,
        string $sector,
        int $excludeId,
    ): array {
        $ecTracks = config('wm-package.ec_track_table', 'ec_tracks');

        $active = array_map(
            fn (TrailCodeStatus $s) => $s->value,
            TrailCodeStatus::active(),
        );

        $placeholders = implode(',', array_fill(0, count($active), '?'));

        return DB::select(
            <<<SQL
            SELECT
                c.id,
                c.number,
                c.variant,
                c.ec_track_id,
                c.trail_application_id,
                ST_AsGeoJSON(COALESCE(t.geometry, a.geometry)) AS geojson
            FROM trail_registry_codes c
            LEFT JOIN {$ecTracks} t ON t.id = c.ec_track_id
            LEFT JOIN trail_applications a ON a.id = c.trail_application_id
            WHERE c.region = ?
              AND c.province = ?
              AND c.area = ?
              AND c.sector = ?
              AND c.status IN ({$placeholders})
              AND c.id <> ?
              AND COALESCE(t.geometry, a.geometry) IS NOT NULL
            ORDER BY c.number, c.variant
            SQL,
            array_merge([$region, $province, $area, $sector], $active, [$excludeId]),
        );
    }
```

Aggiungi in testa al trait l'import:

```php
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
```

- [ ] **Step 4: lancia i test e verifica che passino**

```bash
docker exec -it php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Feature/TrailRegistry"
```

Atteso: PASS, compreso il nuovo test. Se il `Released` comparisse fra i numeri, il filtro sugli
stati è sbagliato; se comparisse `62`, manca l'esclusione del codice in esame.

- [ ] **Step 5: commit** *(istruzione per il dev)*

```bash
git add src/TrailRegistry/Models/Concerns/ComposesTrailRegistryMap.php src/TrailRegistry/Models/TrailRegistryCode.php tests/Feature/TrailRegistry/TrailRegistryCodeMapTest.php
git commit -m "feat(oc:8568): i vicini di un settore in una query sola"
```

---

### Task 3: i vicini sulla mappa

**Files:**
- Modify: `src/TrailRegistry/Models/TrailRegistryCode.php` (`getFeatureCollectionMap()`, righe 145-163)
- Test: `tests/Feature/TrailRegistry/TrailRegistryCodeMapTest.php`

**Interfaces:**
- Consumes: `neighbourCodes()` dal Task 2, `$label` dal Task 1.
- Produces: feature GeoJSON con `properties.neighbour === true` e `properties.label`. **Il
  componente Vue del Task 5 riconosce i vicini da `properties.neighbour`: il nome è un contratto
  fra i due task.**

**Ordine di disegno:** i vicini vanno **prima** del sentiero e dell'istanza, che devono restare
sopra — vale la stessa ragione già scritta per i settori in `sectorFeatures()`.

- [ ] **Step 1: scrivi il test che fallisce**

```php
it('disegna i vicini del settore con numero e variante, sotto la traccia in esame', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $inEsame = makeCode([
        'status' => TrailCodeStatus::Assigned,
        'taxonomy_where_id' => $sectorId,
        'number' => 62,
    ]);

    $vicino = makeCode([
        'status' => TrailCodeStatus::Assigned,
        'taxonomy_where_id' => $sectorId,
        'number' => 63,
        'variant' => 'A',
    ]);

    foreach (TrailRegistryCode::query()->whereIn('id', [$inEsame, $vicino])->get() as $c) {
        DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', ['MULTILINESTRING Z((1 1 0, 2 2 0))', $c->ec_track_id]);
    }

    $collection = TrailRegistryCode::findOrFail($inEsame)->getFeatureCollectionMap();

    $vicini = array_values(array_filter(
        $collection['features'],
        fn (array $f) => ($f['properties']['neighbour'] ?? false) === true,
    ));

    expect($vicini)->toHaveCount(1)
        ->and($vicini[0]['properties']['label'])->toBe('63A')
        ->and($vicini[0]['properties']['tooltip'])->toContain('63A');

    // I vicini stanno sotto: il sentiero in esame e' disegnato dopo.
    $indiceVicino = array_search($vicini[0], $collection['features'], true);
    $indiceSentiero = null;
    foreach ($collection['features'] as $i => $f) {
        if (str_contains($f['properties']['tooltip'] ?? '', 'Sentiero')) {
            $indiceSentiero = $i;
        }
    }

    expect($indiceVicino)->toBeLessThan($indiceSentiero);
});

it('non disegna il codice in esame fra i suoi vicini', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $inEsame = makeCode([
        'status' => TrailCodeStatus::Assigned,
        'taxonomy_where_id' => $sectorId,
        'number' => 62,
    ]);

    $code = TrailRegistryCode::findOrFail($inEsame);
    DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', ['MULTILINESTRING Z((1 1 0, 2 2 0))', $code->ec_track_id]);

    $collection = $code->fresh()->getFeatureCollectionMap();

    $vicini = array_filter(
        $collection['features'],
        fn (array $f) => ($f['properties']['neighbour'] ?? false) === true,
    );

    expect($vicini)->toBe([]);
});
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

```bash
docker exec -it php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter='vicini del settore con numero'"
```

Atteso: FAIL — nessuna feature ha `neighbour`.

- [ ] **Step 3: componi le feature dei vicini**

In `src/TrailRegistry/Models/TrailRegistryCode.php`, aggiungi dopo `sectorFeatures()`:

```php
    /**
     * Gli altri sentieri del settore: tratto sottile e tenue, perche' sono
     * contesto e non devono competere con la traccia in esame.
     *
     * L'etichetta e' `label` — numero e variante — e non il codice intero:
     * regione, provincia e area sono costanti nel contesto, e il settore e'
     * gia' leggibile sulla mappa, dove i suoi confini sono disegnati.
     *
     * `neighbour` nelle properties e' il contratto con il componente Vue del
     * campo, che da li' riconosce quali feature portare sul layer delle
     * etichette e quali escludere dall'inquadratura iniziale.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function neighbourFeatures(string $novaPath): array
    {
        $rows = $this->neighbourCodes(
            $this->region, $this->province, $this->area, $this->sector, (int) $this->id,
        );

        $features = [];

        foreach ($rows as $row) {
            $geometry = json_decode($row->geojson, true);

            if (! $geometry) {
                continue;
            }

            $label = sprintf(
                '%02d%s',
                (int) $row->number,
                $row->variant === '0' ? '' : $row->variant,
            );

            $features[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'neighbour' => true,
                    'label' => $label,
                    'tooltip' => __('Sentiero').' '.$label,
                    'strokeColor' => 'rgba(100, 116, 139, 0.9)',
                    'strokeWidth' => 2,
                    'link' => url($novaPath.'/resources/'.static::novaUriKey('trail_registry_code').'/'.$row->id),
                ],
            ];
        }

        return $features;
    }
```

L'etichetta qui si compone con lo stesso `sprintf` dell'accessor `label` perché le righe arrivano
da `DB::select` e non sono modelli: la regola sulla variante `'0'` resta una sola perché è scritta
una volta in `label()` e ripetuta qui **identica**. Se preferisci una sola scrittura fisica,
sostituisci il blocco con `TrailRegistryCode::make((array) $row)->label` — costa l'istanza di un
modello per riga e va misurato prima di sceglierlo.

Aggiungi la chiave Nova per la Resource dei codici in `novaUriKey()` del trait
(`src/TrailRegistry/Models/Concerns/ComposesTrailRegistryMap.php`, riga 147):

```php
        $defaults = [
            'ec_track' => 'ec-tracks',
            'taxonomy_where' => 'taxonomy-wheres',
            'trail_application' => 'trail-applications',
            'trail_registry_code' => 'trail-registry-codes',
        ];
```

Poi inserisci i vicini in `getFeatureCollectionMap()`, **prima** di sentiero e istanza:

```php
    public function getFeatureCollectionMap(): array
    {
        $novaPath = $this->novaPath();

        $features = array_values(array_filter(array_merge(
            $this->sectorFeatures($novaPath),
            $this->neighbourFeatures($novaPath),
            [
                $this->trackFeature($novaPath),
                $this->applicationFeature($novaPath),
            ],
        )));

        return ['type' => 'FeatureCollection', 'features' => $features];
    }
```

- [ ] **Step 4: lancia i test e verifica che passino**

```bash
docker exec -it php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Feature/TrailRegistry"
```

Atteso: PASS. **Attenzione ai test già esistenti che contano le feature**: quello alla riga 13 si
aspetta `toHaveCount(3)`. Se un vicino entra in quello scenario, l'aspettativa va aggiornata con il
numero giusto — non allentata a `toBeGreaterThan()`, che smetterebbe di verificare qualcosa.

- [ ] **Step 5: misura il peso su dati veri**

```bash
docker exec -it php-forestas php artisan tinker --execute="
\$id = DB::table('trail_registry_codes')->where('region','Z')->where('province','NU')->where('area','B')->where('sector','4')->value('id');
\$c = \Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode::find(\$id);
\$t0 = microtime(true);
\$fc = \$c->getFeatureCollectionMap();
printf(\"features: %d — %.0f ms — %d KB\n\", count(\$fc['features']), (microtime(true)-\$t0)*1000, strlen(json_encode(\$fc))/1024);
"
```

Riporta il risultato in `notes.md`. È la misura su ZNUB4 che l'overview chiede: se il tempo o il
peso risultassero fuori scala, va discusso col dev prima di proseguire.

- [ ] **Step 6: commit** *(istruzione per il dev)*

```bash
git add src/TrailRegistry/Models/TrailRegistryCode.php src/TrailRegistry/Models/Concerns/ComposesTrailRegistryMap.php tests/Feature/TrailRegistry/TrailRegistryCodeMapTest.php
git commit -m "feat(oc:8568): la mappa disegna i sentieri vicini del settore"
```

---

### Task 4: la voce di legenda

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-nessuna-traduzione-aggiunta)


**Files:**
- Modify: `src/TrailRegistry/Nova/MapLegendRenderer.php` (costante `ENTRIES` riga 30, `isPresent()` riga 86)
- Test: `tests/Feature/TrailRegistry/MapLegendRendererTest.php` (se non esiste, crearlo)

**Interfaces:**
- Consumes: le feature con `properties.neighbour` dal Task 3.
- Produces: niente per i task successivi.

**Condizionale come le altre:** la voce compare solo quando almeno un vicino è disegnato — «una
legenda che nomina un elemento assente fa cercare all'operatore qualcosa che non c'è», come dice il
docblock di `isPresent()`.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Nova\MapLegendRenderer;

beforeEach(function () {
    runTrailRegistryStubs();
    Bus::fake();
});

it('mostra la voce dei vicini solo quando ce n e almeno uno', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $solo = makeCode(['status' => TrailCodeStatus::Assigned, 'taxonomy_where_id' => $sectorId, 'number' => 62]);
    $codeSolo = TrailRegistryCode::findOrFail($solo);
    DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', ['MULTILINESTRING Z((1 1 0, 2 2 0))', $codeSolo->ec_track_id]);

    expect(MapLegendRenderer::render($codeSolo->fresh()))->not->toContain('Altri sentieri');

    $vicino = makeCode(['status' => TrailCodeStatus::Assigned, 'taxonomy_where_id' => $sectorId, 'number' => 63]);
    DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', ['MULTILINESTRING Z((5 5 0, 6 6 0))', TrailRegistryCode::findOrFail($vicino)->ec_track_id]);

    expect(MapLegendRenderer::render($codeSolo->fresh()))->toContain('Altri sentieri');
});
```

- [ ] **Step 2: lancia il test e verifica che fallisca**

```bash
docker exec -it php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Feature/TrailRegistry/MapLegendRendererTest.php"
```

Atteso: FAIL sulla seconda aspettativa — la voce non esiste.

- [ ] **Step 3: aggiungi la voce**

In `ENTRIES`, dopo `other_sectors`:

```php
        'neighbours' => ['rgba(100, 116, 139, 0.9)', '', 'Altri sentieri del settore, con numero e variante'],
```

In `render()`, accanto a `$sectorCount`, conta i vicini sulla stessa collection già composta:

```php
        $features = $code->getFeatureCollectionMap()['features'];

        $sectorCount = count(array_filter(
            $features,
            fn (array $f) => isset($f['properties']['taxonomy_where_id']),
        ));

        $neighbourCount = count(array_filter(
            $features,
            fn (array $f) => ($f['properties']['neighbour'] ?? false) === true,
        ));
```

Passa il conteggio a `isPresent()` e gestisci la nuova chiave:

```php
    protected static function isPresent(
        TrailRegistryCodeModel $code,
        string $key,
        int $sectorCount,
        int $neighbourCount,
    ): bool {
        return match ($key) {
            'sector' => $code->taxonomy_where_id !== null,
            'other_sectors' => $sectorCount > 1,
            'neighbours' => $neighbourCount > 0,
            'track' => $code->ec_track_id !== null,
            'application' => $code->trail_application_id !== null,
            default => false,
        };
    }
```

Aggiorna la chiamata dentro il `foreach` passando `$neighbourCount`, e aggiorna il docblock della
classe: i colori ora sono quattro e restano legati a quelli del modello.

- [ ] **Step 4: lancia i test e verifica che passino**

```bash
docker exec -it php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Feature/TrailRegistry"
```

Atteso: PASS.

- [ ] **Step 5: traduzione**

Aggiungi la stringa nei file `resources/lang/*.json` del package, in tutte le lingue già presenti:

```bash
ls /Users/bongiu/Documents/geobox2/forestas/wm-package/resources/lang/
```

Per ogni file, aggiungi la chiave `"Altri sentieri del settore, con numero e variante"` con la
traduzione corrispondente. Nessun file di lingua esistente deve restare senza la chiave.

- [ ] **Step 6: commit** *(istruzione per il dev)*

```bash
git add src/TrailRegistry/Nova/MapLegendRenderer.php tests/Feature/TrailRegistry/MapLegendRendererTest.php resources/lang/
git commit -m "feat(oc:8568): la legenda spiega i sentieri vicini"
```

---

### Task 5: il campo Nova con il componente proprio

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-il-provider-del-campo-non-sta-in-una-sottocartella-src)


**Files:**
- Create: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/package.json`
- Create: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/webpack.mix.js`
- Create: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/nova.mix.js`
- Create: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/postcss.config.js`
- Create: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/resources/js/field.js`
- Create: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/resources/js/components/DetailField.vue`
- Create: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/resources/sass/field.scss`
- Create: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/src/FieldServiceProvider.php`
- Modify: `src/TrailRegistry/Nova/Fields/TrailRegistryMap.php` (tutto il docblock, più `$component`)
- Modify: `src/WmPackageServiceProvider.php` (riga 78, registrazione del provider)

**Interfaces:**
- Consumes: le feature con `properties.neighbour` e `properties.label` dal Task 3.
- Produces: il componente Nova `trail-registry-map`.

**Come funziona il wrapper.** Il componente condiviso emette `map-ready` con
`{ map, features, geojson, featuresMap }` (`FeatureCollectionMap.vue:400`). Il wrapper aspetta
quell'evento e fa tre cose:

1. toglie le feature dei vicini dal layer principale e le mette su un `VectorLayer` proprio con
   `declutter: true` — `declutter` è un'opzione **del layer**, non dello stile, e non si può
   accendere a runtime su un layer già costruito;
2. dà a quel layer una style function che disegna il tratto sempre e il `Text` solo oltre la soglia;
3. ricalcola l'inquadratura sulle sole feature non-vicine, perché il fit del componente condiviso
   include tutte le LineString (`FeatureCollectionMap.vue:349-358`) e con 62 vicini aprirebbe la
   mappa sull'intero settore, sotto la soglia delle etichette.

**Il tooltip continua a funzionare da solo.** Il componente condiviso lo cerca con
`map.forEachFeatureAtPixel` (`FeatureCollectionMap.vue:514`), che scansiona **tutti** i layer della
mappa: le feature spostate sul layer dei vicini restano raggiungibili col mouse senza alcun
intervento. È ciò che rende accettabile la soglia — sotto di essa il numero non sparisce, si legge
passandoci sopra.

- [ ] **Step 1: crea la toolchain del campo**

`package.json` — copia quello di `FeatureCollectionMap` cambiando il nome:

```json
{
  "name": "wm/trail-registry-map",
  "description": "Mappa del registro dei codici: FeatureCollectionMap con i sentieri vicini etichettati",
  "license": "MIT",
  "scripts": {
    "dev": "mix",
    "watch": "mix watch",
    "prod": "mix --production"
  },
  "devDependencies": {
    "@vue/compiler-sfc": "^3.4.0",
    "form-backend-validation": "^2.3.3",
    "laravel-mix": "^6.0.49",
    "resolve-url-loader": "^5.0.0",
    "sass": "^1.69.0",
    "sass-loader": "^13.3.0",
    "vue": "^3.4.0",
    "vue-loader": "^17.4.0"
  },
  "dependencies": {
    "axios": "^1.7.4",
    "lodash": "^4.17.21",
    "ol": "^9.2.4",
    "uid": "^2.0.2",
    "vuex": "^4.1.0"
  }
}
```

`postcss.config.js` — obbligatorio, altrimenti `npm run prod` risale alla root del consumer e
fallisce con `ERR_REQUIRE_ESM`:

```js
module.exports = {}
```

`nova.mix.js` — copia **identica** di `src/Nova/Fields/FeatureCollectionMap/nova.mix.js`, ma
attenzione al numero di `..` nell'alias di `laravel-nova`: quel percorso risale a `vendor/`, e questa
cartella è a una profondità diversa. Verifica con:

```bash
ls /Users/bongiu/Documents/geobox2/forestas/wm-package/src/TrailRegistry/Nova/Fields/TrailRegistryMapField/../../../../../vendor/laravel/nova/resources/js/mixins/packages.js
```

Se il file non c'è, aggiungi o togli un livello finché il percorso risolve, poi scrivi quel valore
nel file.

`webpack.mix.js`:

```js
const mix = require('laravel-mix')

require('./nova.mix')

mix
  .setPublicPath('dist')
  .js('resources/js/field.js', 'js')
  .vue({ version: 3 })
  .sass('resources/sass/field.scss', 'css')
  .version()
  .nova('wm/trail-registry-map')
```

`resources/sass/field.scss` — vuoto va bene, ma il file deve esistere:

```scss
// Nessuno stile proprio: la mappa usa quelli del componente condiviso.
```

`resources/js/field.js`:

```js
import DetailField from './components/DetailField.vue';

Nova.booting((app) => {
    app.component('detail-trail-registry-map', DetailField);
});
```

- [ ] **Step 2: scrivi il wrapper Vue**

`resources/js/components/DetailField.vue`:

```vue
<template>
    <PanelItem :index="index" :field="field">
        <template #value>
            <FeatureCollectionMap
                :geojson-url="geojsonUrl"
                :height="field.height || 500"
                :enable-slope-chart="false"
                :resource-name="resourceName"
                :resource-id="resourceId || (resource && resource.id && resource.id.value)"
                @map-ready="handleMapReady" />
        </template>
    </PanelItem>
</template>

<script>
import FeatureCollectionMap from '../../../../../Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue';
import VectorLayer from 'ol/layer/Vector';
import VectorSource from 'ol/source/Vector';
import { Style, Stroke, Text, Fill } from 'ol/style';

export default {
    name: 'DetailTrailRegistryMap',

    components: { FeatureCollectionMap },

    props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],

    data() {
        return {
            neighbourLayer: null,
        };
    },

    computed: {
        /** Soglia oltre la quale le etichette compaiono, espressa in livello di zoom. */
        labelMinZoom() {
            return this.field.labelMinZoom ?? 12;
        },

        geojsonUrl() {
            if (this.field.geojsonUrl) {
                return this.field.geojsonUrl;
            }

            const id = this.resourceId || (this.resource && this.resource.id && this.resource.id.value);

            return `/nova-vendor/feature-collection-map/${this.resourceName}/${id}`;
        },
    },

    methods: {
        handleMapReady({ map, features }) {
            const neighbours = features.filter(f => f.get('neighbour') === true);
            const others = features.filter(f => f.get('neighbour') !== true);

            if (neighbours.length === 0) {
                return;
            }

            this.moveNeighboursToOwnLayer(map, neighbours);
            this.refitOn(map, others);
        },

        /**
         * I vicini vanno su un layer proprio perche' `declutter` e' un'opzione
         * del layer e non dello stile: senza, in un settore denso le etichette
         * si sovrappongono e non si legge piu' nessuna.
         */
        moveNeighboursToOwnLayer(map, neighbours) {
            const mainLayer = map.getLayers().getArray()
                .find(l => l.getSource && l.getSource() && l.getSource().getFeatures
                    && l.getSource().getFeatures().includes(neighbours[0]));

            if (mainLayer) {
                neighbours.forEach(f => mainLayer.getSource().removeFeature(f));
            }

            if (this.neighbourLayer) {
                map.removeLayer(this.neighbourLayer);
            }

            const minZoom = this.labelMinZoom;

            this.neighbourLayer = new VectorLayer({
                source: new VectorSource({ features: neighbours }),
                declutter: true,
                // Sotto la traccia in esame: e' contesto, non protagonista.
                zIndex: 0,
                style: (feature, resolution) => {
                    const style = new Style({
                        stroke: new Stroke({
                            color: feature.get('strokeColor') || 'rgba(100, 116, 139, 0.9)',
                            width: feature.get('strokeWidth') || 2,
                        }),
                    });

                    // La style function riceve la risoluzione, non lo zoom: la
                    // soglia si scrive in zoom perche' e' il numero che si legge
                    // sulla mappa, e si converte qui.
                    const zoom = map.getView().getZoomForResolution(resolution);

                    if (zoom >= minZoom && feature.get('label')) {
                        style.setText(new Text({
                            text: feature.get('label'),
                            // Lungo il tracciato: al centro della geometria
                            // un sentiero a U porterebbe l'etichetta fuori dal
                            // sentiero.
                            placement: 'line',
                            overflow: false,
                            font: 'bold 12px sans-serif',
                            fill: new Fill({ color: 'rgba(30, 41, 59, 1)' }),
                            stroke: new Stroke({ color: 'rgba(255, 255, 255, 0.9)', width: 3 }),
                        }));
                    }

                    return style;
                },
            });

            map.addLayer(this.neighbourLayer);
        },

        /**
         * L'inquadratura del componente condiviso include tutte le LineString:
         * con i vicini dentro, la mappa si aprirebbe sull'intero settore e lo
         * zoom iniziale finirebbe sotto la soglia delle etichette.
         */
        refitOn(map, features) {
            if (features.length === 0) {
                return;
            }

            const source = new VectorSource({ features: features.map(f => f.clone()) });
            const extent = source.getExtent();

            if (!extent || extent[0] === Infinity) {
                return;
            }

            map.getView().fit(extent, { size: map.getSize(), padding: [50, 50, 50, 50], maxZoom: 17 });
        },
    },
};
</script>
```

- [ ] **Step 3: registra il campo lato PHP**

`src/TrailRegistry/Nova/Fields/TrailRegistryMapField/src/FieldServiceProvider.php`:

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Fields\TrailRegistryMapField;

use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;

class FieldServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Nova::serving(function (ServingNova $event) {
            Nova::script('trail-registry-map', __DIR__.'/../dist/js/field.js');
            Nova::style('trail-registry-map', __DIR__.'/../dist/css/field.css');
        });
    }

    public function register(): void
    {
        //
    }
}
```

Registralo in `src/WmPackageServiceProvider.php`, accanto alla riga 78:

```php
        $this->app->register(\Wm\WmPackage\TrailRegistry\Nova\Fields\TrailRegistryMapField\FieldServiceProvider::class);
```

> Se il dominio Catasto Sentieri è dietro l'interruttore dei domini opzionali, registra il provider
> nello stesso punto in cui vengono registrate le altre classi del dominio — vedi
> `docs/knowledge/domini-opzionali.md`. Non registrarlo incondizionatamente se gli altri non lo sono.

In `src/TrailRegistry/Nova/Fields/TrailRegistryMap.php` dichiara il componente e aggiungi la prop
della soglia:

```php
    public $component = 'trail-registry-map';

    /**
     * Il livello di zoom oltre il quale le etichette dei vicini compaiono
     * sulla mappa. Sotto la soglia le tracce restano disegnate e il numero si
     * legge dal tooltip.
     */
    public function labelMinZoom(int $zoom): static
    {
        return $this->withMeta(['labelMinZoom' => $zoom]);
    }
```

E **riscrivi il docblock della classe**, che oggi spiega il contrario:

```php
/**
 * La mappa della scheda di un codice del registro: il settore da cui il
 * prefisso e' stato ricavato, il sentiero a cui il codice e' assegnato,
 * l'istanza da cui e' nato e gli altri sentieri dello stesso settore,
 * etichettati con numero e variante.
 *
 * Stesso schema di `Osm2cai\SignageMap\SignageMap` in osm2cai2, **componente
 * proprio compreso**: fino a oc:8568 questa classe ereditava il componente di
 * `FeatureCollectionMap` perche' il disegno era quello di sempre. Con i vicini
 * non lo e' piu' — servono etichette sulle linee, una soglia di zoom e un
 * fit che ignori i vicini — e quei tre comportamenti non si esprimono con le
 * prop del componente condiviso.
 *
 * Il wrapper non duplica il componente: lo usa come figlio e interviene
 * sull'evento `map-ready`. Il prezzo e' un secondo bundle da tenere allineato;
 * in cambio le mappe di TaxonomyWhere, Layer, FeatureCollection e
 * TrailRegistryAnomaly restano intatte.
 *
 * Cosa disegna lo decide `TrailRegistryCode::getFeatureCollectionMap()`.
 */
```

- [ ] **Step 4: verifica che PHP non si rompa**

```bash
docker exec -it php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Feature/TrailRegistry && composer analyse"
```

Atteso: test PASS, PHPStan senza errori nuovi sui file toccati (il repo ha errori preesistenti:
conta solo che non ne compaiano di nuovi nei file di questa feature).

- [ ] **Step 5: commit** *(istruzione per il dev)*

```bash
git add src/TrailRegistry/Nova/Fields/ src/WmPackageServiceProvider.php
git commit -m "feat(oc:8568): il campo della mappa del registro ha un componente proprio"
```

---

### Task 6: compilazione del bundle e verifica sulla mappa

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-webpack-va-bloccato-a-51030) e [notes.md](notes.md#task-6-le-etichette-su-un-layer-proprio-sopra-tutto)


**Files:**
- Create: `src/TrailRegistry/Nova/Fields/TrailRegistryMapField/dist/` (generato)

**Interfaces:**
- Consumes: tutto quanto sopra.
- Produces: il bundle che Nova serve.

- [ ] **Step 1: installa le dipendenze e compila**

Dentro la cartella del campo, **mai dalla root**:

```bash
cd /Users/bongiu/Documents/geobox2/forestas/wm-package/src/TrailRegistry/Nova/Fields/TrailRegistryMapField
npm install
npm run prod
```

Atteso: `dist/js/field.js`, `dist/css/field.css` e `dist/mix-manifest.json`.

Se fallisce con `ERR_REQUIRE_ESM`, manca `postcss.config.js` o lo stai lanciando dalla root. Se
fallisce sull'alias `laravel-nova`, il numero di `..` in `nova.mix.js` è sbagliato: ricontrollalo
con il comando dello Step 1 del Task 5.

- [ ] **Step 2: controlla il diff del bundle**

```bash
git status --short src/TrailRegistry/Nova/Fields/TrailRegistryMapField/dist/
```

Atteso: solo file nuovi sotto `dist/`. `node_modules/` **non** va committata: verifica che il
`.gitignore` la escluda, come per l'altro campo.

- [ ] **Step 3: verifica la mappa a mano**

Apri la scheda di un codice in un settore con vicini — `ZORT2` numero 62 ha due varianti nello
stesso settore:

```
http://localhost:8000/nova/resources/trail-registry-codes/249
```

Controlla, uno per uno:

1. le tracce vicine si vedono, tenui e sottili, sotto quella in esame;
2. all'apertura la mappa è inquadrata **sul sentiero in esame**, non sull'intero settore;
3. avvicinandosi oltre la soglia compaiono le etichette (`62A`, `62B`), scritte lungo il tracciato;
4. allontanandosi le etichette spariscono e il numero resta leggibile passando il mouse;
5. le etichette non si sovrappongono fra loro;
6. la legenda ha la voce «Altri sentieri del settore».

Poi ripeti su ZNUB4, il settore più popolato, e su un codice **senza** vicini: lì la voce di legenda
non deve comparire e la mappa deve restare identica a prima.

- [ ] **Step 4: verifica che le altre mappe non siano cambiate**

Apri una scheda di `TaxonomyWhere`, una di `Layer` e una di `TrailRegistryAnomaly`: usano il
componente condiviso, che non abbiamo toccato. Devono essere identiche a prima.

- [ ] **Step 5: commit** *(istruzione per il dev)*

```bash
git add src/TrailRegistry/Nova/Fields/TrailRegistryMapField/dist/
git commit -m "feat(oc:8568): bundle del campo della mappa del registro"
```

---

## Dopo l'ultimo task

- [ ] Riempi in `notes.md` la tabella «preventivo contro consuntivo» con i tempi misurati.
- [ ] Riporta in `notes.md` la misura di peso dello Step 5 del Task 3.
- [ ] Nessun commit automatico: i blocchi `git` di questo piano li esegue il dev.

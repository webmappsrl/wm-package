> Ticket: oc:8570

# Ordinamento dei numeri per vicinanza geografica — Piano implementativo

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** far sì che il numero proposto per un sentiero appena accatastato continui la numerazione
già usata dai sentieri lì accanto, invece di essere il primo libero in assoluto del settore.

**Architecture:** l'ordinamento nasce da due pezzi separati e testabili da soli. Il primo è una
query PostGIS che, dato un settore e una geometria, restituisce i numeri già usati con la loro
distanza dalla traccia. Il secondo è una funzione pura che da quei numeri ricava i cluster per
contiguità numerica, sceglie il più vicino e ordina i numeri liberi per distanza numerica dai suoi.
Separarli tiene l'algoritmo verificabile senza database e la query misurabile senza algoritmo.

**Tech Stack:** PHP 8.4 (minimo del package `>8.1`), Laravel 12, PostgreSQL con PostGIS, Nova 5,
test con Pest. Tutto gira nel container `php-forestas`.

**Spec:** [overview.md](overview.md)

## Global Constraints

- **Repo:** tutto in `wm-package`. In `forestas` cambia solo il puntatore del submodule, a lavoro
  finito. Branch di partenza: `develop`.
- **PHP minimo `>8.1`: mai `const` dentro un trait.** Le costanti nei trait esistono solo da 8.2 e
  su un consumer a 8.1 sono un Fatal Error al primo autoload. Vanno sulla classe.
- **Le geometrie PostGIS passano sempre da SQL puro**, mai attraverso l'ORM.
- **Nessuna firma pubblica esistente cambia.** `availableNumbers()` non si tocca e non si rimuove,
  benché non abbia call site: il package è montato da consumer con branch diversi.
- **Il criterio di ordinamento vive in un solo metodo**, chiamato da entrambi i percorsi.
- **Documentazione e commenti in italiano**, termini tecnici in inglese.
- **`composer format` senza scope riformatta l'intero repo:** se serve, va invocato sui soli file
  toccati, e `git status` va controllato subito dopo.
- **I test girano solo dentro il container**, da `wm-package/`:
  `docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest ..."`
- **Nessun commit e nessun branch automatico.** I blocchi `git` di questo piano sono istruzioni per
  il dev, non comandi da eseguire.

---

### Task 1: helper di test per codici con geometria data

Senza questo, nessun test sulla distanza è scrivibile: `makeCode()` crea sempre l'`ec_track` con la
stessa geometria fissa, `LINESTRING Z (9 40 0, 9.01 40.01 0)`, quindi tutti i codici risultano
equidistanti da qualunque traccia.

**Files:**
- Modify: `tests/Pest.php:53-103` (funzione `makeCode`)

**Interfaces:**
- Consumes: niente
- Produces: `makeCode(array $attributes = []): int` accetta la chiave aggiuntiva `geometry_wkt`
  (string), usata come geometria dell'`ec_track` o della `trail_application` creata. In assenza,
  resta la geometria fissa di oggi, così i test esistenti non cambiano comportamento.

- [ ] **Step 1: scrivi il test che fallisce**

In `tests/Feature/TrailRegistry/ProximityOrderTest.php` (file nuovo):

```php
<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

beforeEach(function () {
    runTrailRegistryStubs();
});

it('crea un codice con la geometria richiesta', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $id = makeCode([
        'number' => 11,
        'status' => TrailCodeStatus::Assigned,
        'geometry_wkt' => 'LINESTRING Z (1 1 0, 1.001 1.001 0)',
    ]);

    $wkt = DB::selectOne(<<<'SQL'
        SELECT ST_AsText(t.geometry) AS wkt
        FROM trail_registry_codes c
        JOIN ec_tracks t ON t.id = c.ec_track_id
        WHERE c.id = ?
    SQL, [$id])->wkt;

    expect($wkt)->toContain('1 1');
});
```

- [ ] **Step 2: lancia il test e verifica che fallisca**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/ProximityOrderTest.php"
```

Atteso: FAIL — la geometria salvata è quella fissa `9 40`, non `1 1`.

- [ ] **Step 3: estendi `makeCode()`**

In `tests/Pest.php`, dentro `makeCode()`, prima dei due blocchi che creano detentore e traccia:

```php
    // Geometria del detentore, per i test che misurano distanze: senza questa
    // via ogni codice nasce con la stessa geometria fissa e ogni distanza
    // risulta uguale, rendendo non verificabile qualunque ordinamento per
    // vicinanza (oc:8570).
    $geometryWkt = $attributes['geometry_wkt'] ?? 'LINESTRING Z (9 40 0, 9.01 40.01 0)';
    unset($attributes['geometry_wkt']);
```

poi, nel blocco `Reserved`, sostituisci l'`insertGetId` dell'istanza con un INSERT che valorizza la
geometria:

```php
        $attributes['trail_application_id'] = DB::selectOne(<<<'SQL'
            INSERT INTO trail_applications (user_id, source, status, geometry, created_at, updated_at)
            VALUES (:user_id, 'office', 'under_review', ST_GeomFromText(:wkt, 4326)::geography, now(), now())
            RETURNING id
        SQL, [
            'user_id' => makeTrailRegistryTestUser(),
            'wkt' => 'MULTILINESTRING Z (('.trim(str_replace(['LINESTRING Z (', ')'], '', $geometryWkt)).'))',
        ])->id;
```

e nel blocco `Assigned` passa `$geometryWkt` al posto della costante:

```php
            'wkt' => $geometryWkt,
```

- [ ] **Step 4: lancia il test e verifica che passi**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/ProximityOrderTest.php"
```

Atteso: PASS.

- [ ] **Step 5: verifica di non aver rotto i test esistenti del dominio**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/"
```

Atteso: tutti verdi. `makeCode()` senza `geometry_wkt` si comporta come prima.

- [ ] **Step 6: commit** *(istruzione per il dev, non da eseguire)*

```bash
git add tests/Pest.php tests/Feature/TrailRegistry/ProximityOrderTest.php
git commit -m "test(oc:8570): makeCode accetta la geometria del detentore"
```

---

### Task 2: l'algoritmo di ordinamento, senza database

Il cuore del ticket. È una funzione pura: riceve numeri e distanze già pronti, non tocca il
database, e si testa in millisecondi su tutti i casi limite.

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php` (metodo nuovo, dopo `numbersWithAvailableVariants()`)
- Test: `tests/Feature/TrailRegistry/ProximityOrderTest.php`

**Interfaces:**
- Consumes: niente
- Produces:
  ```php
  public function orderByProximity(array $available, array $usedWithDistance): array
  ```
  `$available`: `list<int>` dei numeri da ordinare.
  `$usedWithDistance`: `array<int, float>` che mappa numero già usato → distanza in metri dalla
  traccia in esame.
  Ritorna: `list<int>`, gli stessi elementi di `$available`, riordinati. Mai un sottoinsieme.

- [ ] **Step 1: scrivi i test che falliscono**

Aggiungi a `tests/Feature/TrailRegistry/ProximityOrderTest.php`:

```php
it('ordina i liberi per distanza numerica dal cluster piu vicino', function () {
    // Cluster A = {11,12,13} a 10 m; cluster B = {40} a 500 m.
    // Governa A: dal suo bordo escono prima 10 e 14, poi 9 e 15.
    $ordered = app(TrailRegistryService::class)->orderByProximity(
        [9, 10, 14, 15, 39, 41],
        [11 => 10.0, 12 => 10.0, 13 => 10.0, 40 => 500.0],
    );

    expect($ordered)->toBe([10, 14, 9, 15, 39, 41]);
});

it('a parita di distanza numerica sceglie il precedente', function () {
    $ordered = app(TrailRegistryService::class)->orderByProximity(
        [12, 14],
        [13 => 5.0],
    );

    expect($ordered)->toBe([12, 14]);
});

it('senza codici nel settore lascia l ordine numerico', function () {
    $ordered = app(TrailRegistryService::class)->orderByProximity([3, 1, 2], []);

    expect($ordered)->toBe([1, 2, 3]);
});

it('non perde nessun numero: ordinare non e filtrare', function () {
    $available = range(0, 99);
    unset($available[20]);

    $ordered = app(TrailRegistryService::class)
        ->orderByProximity(array_values($available), [20 => 1.0]);

    expect($ordered)->toHaveCount(99)
        ->and(array_diff(array_values($available), $ordered))->toBe([]);
});

it('e deterministico: due chiamate danno lo stesso ordine', function () {
    // Due cluster equidistanti: senza un criterio di parita l ordine
    // oscillerebbe fra una chiamata e l altra.
    $service = app(TrailRegistryService::class);
    $args = [[5, 6, 50, 51], [7 => 42.0, 49 => 42.0]];

    expect($service->orderByProximity(...$args))
        ->toBe($service->orderByProximity(...$args));
});

it('a parita di distanza geografica governa il cluster col numero piu basso', function () {
    $ordered = app(TrailRegistryService::class)->orderByProximity(
        [5, 6, 50, 51],
        [7 => 42.0, 49 => 42.0],
    );

    // Vince il cluster {7}: 6 dista 1, 5 dista 2.
    expect($ordered[0])->toBe(6)
        ->and($ordered[1])->toBe(5);
});

it('un solo codice nel settore fa cluster da solo', function () {
    // Cluster {50}: 98 dista 48, 1 e 99 distano 49 (a parita' vince il
    // precedente), 0 dista 50.
    $ordered = app(TrailRegistryService::class)->orderByProximity(
        [0, 1, 98, 99],
        [50 => 3.0],
    );

    expect($ordered)->toBe([98, 1, 99, 0]);
});
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/ProximityOrderTest.php"
```

Atteso: FAIL con `Call to undefined method ... ::orderByProximity()`.

- [ ] **Step 3: implementa `orderByProximity()`**

In `src/TrailRegistry/TrailRegistryService.php`:

```php
    /**
     * I numeri disponibili, ordinati per vicinanza al cluster piu' prossimo.
     *
     * La numerazione dei sentieri segue la geografia dentro i numeri liberi:
     * la disponibilita' e' il vincolo, la vicinanza e' il criterio. Prendere
     * il primo libero in assoluto — quello che il servizio faceva prima di
     * oc:8570 — produce un numero che il gestore deve quasi sempre correggere
     * a mano.
     *
     * I numeri gia' usati si raggruppano per contiguita' numerica: 11, 12, 13
     * sono un cluster, 16, 17 un altro. Governa l'ordine **il cluster piu'
     * vicino alla traccia**, e basta quello: chi vuole aprire una numerazione
     * altrove non passa dalla proposta automatica, usa
     * {@see ReplaceTrailCodeNumber}. Far competere piu' cluster non
     * servirebbe a nessuno e renderebbe l'ordine difficile da spiegare.
     *
     * L'ordine e' deterministico: a parita' di distanza geografica governa il
     * cluster col numero piu' basso, e a parita' di distanza numerica vince il
     * numero precedente. Senza, due chiamate consecutive potrebbero
     * restituire ordini diversi — e in un settore di sentieristica gli
     * incroci sono la norma, quindi le parita' a distanza zero pure.
     *
     * Ordinare non e' filtrare: l'array restituito contiene sempre tutti gli
     * elementi ricevuti (oc:8570).
     *
     * @param  list<int>  $available  i numeri da ordinare
     * @param  array<int, float>  $usedWithDistance  numero gia' usato => distanza in metri
     * @return list<int>
     */
    public function orderByProximity(array $available, array $usedWithDistance): array
    {
        sort($available);

        if ($usedWithDistance === []) {
            return $available;
        }

        $cluster = $this->nearestCluster($usedWithDistance);

        usort($available, function (int $a, int $b) use ($cluster) {
            $da = $this->numericDistanceFrom($cluster, $a);
            $db = $this->numericDistanceFrom($cluster, $b);

            // A parita' di distanza numerica vince il precedente, cioe' il
            // numero piu' basso: «continua quella numerazione» non dice di
            // saltare in avanti lasciando un buco dietro.
            return $da <=> $db ?: $a <=> $b;
        });

        return $available;
    }

    /**
     * I numeri del cluster piu' vicino alla traccia.
     *
     * Un cluster e' un gruppo di numeri consecutivi fra quelli gia' usati; la
     * sua distanza e' quella del suo codice piu' prossimo. A parita' vince il
     * cluster col numero piu' basso, cosi' l'esito non dipende dall'ordine in
     * cui il database ha restituito le righe (oc:8570).
     *
     * @param  array<int, float>  $usedWithDistance
     * @return list<int>
     */
    private function nearestCluster(array $usedWithDistance): array
    {
        $numbers = array_keys($usedWithDistance);
        sort($numbers);

        $clusters = [];
        $current = [];

        foreach ($numbers as $number) {
            if ($current !== [] && $number !== end($current) + 1) {
                $clusters[] = $current;
                $current = [];
            }

            $current[] = $number;
        }

        $clusters[] = $current;

        usort($clusters, function (array $a, array $b) use ($usedWithDistance) {
            $da = min(array_map(fn (int $n) => $usedWithDistance[$n], $a));
            $db = min(array_map(fn (int $n) => $usedWithDistance[$n], $b));

            return $da <=> $db ?: $a[0] <=> $b[0];
        });

        return $clusters[0];
    }

    /**
     * Quanto dista un numero dal cluster: la distanza dal suo elemento piu'
     * vicino, nelle due direzioni.
     *
     * @param  list<int>  $cluster
     */
    private function numericDistanceFrom(array $cluster, int $number): int
    {
        return min(array_map(fn (int $n) => abs($n - $number), $cluster));
    }
```

- [ ] **Step 4: lancia i test e verifica che passino**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/ProximityOrderTest.php"
```

Atteso: PASS, tutti e otto i test del file.

- [ ] **Step 5: commit** *(istruzione per il dev)*

```bash
git add src/TrailRegistry/TrailRegistryService.php tests/Feature/TrailRegistry/ProximityOrderTest.php
git commit -m "feat(oc:8570): ordinamento dei numeri per vicinanza al cluster piu' prossimo"
```

---

### Task 3: la query che misura le distanze

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php` (metodo nuovo)
- Test: `tests/Feature/TrailRegistry/ProximityOrderTest.php`

**Interfaces:**
- Consumes: `orderByProximity()` dal Task 2
- Produces:
  ```php
  protected function usedNumbersWithDistance(string $fullCode, string $geometryWkt): array
  ```
  Ritorna `array<int, float>`: numero già usato → distanza minima in metri fra la geometria del suo
  detentore e `$geometryWkt`. Un numero con più codici (varianti diverse) compare una volta sola,
  con la distanza minima. I codici senza geometria sono esclusi.

- [ ] **Step 1: scrivi il test che fallisce**

```php
it('misura la distanza dei numeri usati dalla traccia in esame', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    makeCode([
        'number' => 11,
        'status' => TrailCodeStatus::Assigned,
        'geometry_wkt' => 'LINESTRING Z (1 1 0, 1.001 1.001 0)',
    ]);
    makeCode([
        'number' => 40,
        'status' => TrailCodeStatus::Assigned,
        'geometry_wkt' => 'LINESTRING Z (9 9 0, 9.001 9.001 0)',
    ]);

    $service = app(TrailRegistryService::class);
    $method = new ReflectionMethod($service, 'usedNumbersWithDistance');
    $distances = $method->invoke($service, 'ZNUB5', 'MULTILINESTRING Z ((1 1 0, 1.002 1.002 0))');

    expect($distances)->toHaveKeys([11, 40])
        ->and($distances[11])->toBeLessThan($distances[40]);
});
```

- [ ] **Step 2: lancia il test e verifica che fallisca**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/ProximityOrderTest.php --filter='misura la distanza'"
```

Atteso: FAIL — il metodo non esiste.

- [ ] **Step 3: implementa la query**

```php
    /**
     * I numeri gia' usati del settore con la loro distanza dalla traccia.
     *
     * La geometria di un codice non sta nel registro: sta nel sentiero, o
     * nell'istanza se il codice e' solo riservato — da qui il `COALESCE`,
     * lo stesso di {@see ComposesTrailRegistryMap::neighbourCodes()}. I
     * codici che non hanno ne' l'uno ne' l'altra non partecipano: non
     * sapremmo dove metterli.
     *
     * `ST_Distance` su `geography` restituisce metri. Un numero con piu'
     * varianti compare una volta sola, con la distanza minima: e' il numero a
     * essere ordinato, non la singola riga.
     *
     * @return array<int, float>
     */
    protected function usedNumbersWithDistance(string $fullCode, string $geometryWkt): array
    {
        $ecTracks = (string) config('wm-package.ec_track_table', 'ec_tracks');

        $active = array_map(
            fn (TrailCodeStatus $status) => $status->value,
            TrailCodeStatus::active(),
        );

        $placeholders = implode(',', array_fill(0, count($active), '?'));

        $rows = DB::select(
            <<<SQL
            SELECT
                c.number,
                MIN(ST_Distance(
                    COALESCE(t.geometry, a.geometry),
                    ST_GeomFromText(?, 4326)::geography
                )) AS distance
            FROM trail_registry_codes c
            LEFT JOIN {$ecTracks} t ON t.id = c.ec_track_id
            LEFT JOIN trail_applications a ON a.id = c.trail_application_id
            WHERE c.region = ?
              AND c.province = ?
              AND c.area = ?
              AND c.sector = ?
              AND c.status IN ({$placeholders})
              AND COALESCE(t.geometry, a.geometry) IS NOT NULL
            GROUP BY c.number
            SQL,
            array_merge(
                [$geometryWkt],
                [
                    substr($fullCode, 0, 1),
                    substr($fullCode, 1, 2),
                    substr($fullCode, 3, 1),
                    substr($fullCode, 4, 1),
                ],
                $active,
            ),
        );

        $distances = [];

        foreach ($rows as $row) {
            $distances[(int) $row->number] = (float) $row->distance;
        }

        return $distances;
    }
```

- [ ] **Step 4: lancia il test e verifica che passi**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/ProximityOrderTest.php"
```

Atteso: PASS.

- [ ] **Step 5: commit** *(istruzione per il dev)*

```bash
git add src/TrailRegistry/TrailRegistryService.php tests/Feature/TrailRegistry/ProximityOrderTest.php
git commit -m "feat(oc:8570): distanza dei numeri usati dalla traccia in esame"
```

---

### Task 4: `propose()` adotta il nuovo criterio

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php:119-151` (`propose()`)
- Test: `tests/Feature/TrailRegistry/ProposeTest.php`

**Interfaces:**
- Consumes: `orderByProximity()` e `usedNumbersWithDistance()` dai Task 2 e 3
- Produces: `propose()` mantiene la firma e la forma del valore di ritorno di oggi
  (`array{taxonomy_where_id, region, province, area, sector, number, variant}`)

- [ ] **Step 1: scrivi il test che fallisce**

Aggiungi a `tests/Feature/TrailRegistry/ProposeTest.php`:

```php
it('propone il numero che continua la numerazione dei sentieri vicini', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    // Vicino alla traccia in esame: 11, 12, 13. Lontano: 40.
    foreach ([11, 12, 13] as $number) {
        makeCode([
            'number' => $number,
            'status' => TrailCodeStatus::Assigned,
            'geometry_wkt' => 'LINESTRING Z (1 1 0, 1.001 1.001 0)',
        ]);
    }
    makeCode([
        'number' => 40,
        'status' => TrailCodeStatus::Assigned,
        'geometry_wkt' => 'LINESTRING Z (9 9 0, 9.001 9.001 0)',
    ]);

    $proposal = app(TrailRegistryService::class)
        ->propose('MULTILINESTRING Z ((1 1 0, 1.002 1.002 0))');

    // Prima di oc:8570 sarebbe uscito 0, il primo libero in assoluto.
    expect($proposal['number'])->toBe(10)
        ->and($proposal['variant'])->toBe('0');
});
```

- [ ] **Step 2: lancia il test e verifica che fallisca**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/ProposeTest.php --filter='continua la numerazione'"
```

Atteso: FAIL — arriva `0` invece di `10`.

- [ ] **Step 3: riscrivi il corpo di `propose()`**

Sostituisci i due cicli annidati. Il ciclo esterno sulle varianti **resta**, perché è il fallback
quando i cento numeri puri sono esauriti; cambia il ciclo interno, che non scorre più `range(0, 99)`
ma l'ordine per vicinanza:

```php
        $distances = $this->usedNumbersWithDistance($fullCode, $geometryWkt);

        foreach ($this->variantSearchOrder() as $variant) {
            $free = array_values(array_filter(
                range(0, 99),
                fn (int $number) => ! in_array($number.':'.$variant, $taken, true),
            ));

            foreach ($this->orderByProximity($free, $distances) as $number) {
                return [
                    'taxonomy_where_id' => $sector->id,
                    'region' => substr($fullCode, 0, 1),
                    'province' => substr($fullCode, 1, 2),
                    'area' => substr($fullCode, 3, 1),
                    'sector' => substr($fullCode, 4, 1),
                    'number' => $number,
                    'variant' => $variant,
                ];
            }
        }

        throw SectorExhaustedException::forFullCode($fullCode);
```

Aggiorna il docblock di `propose()`: dove diceva che si prova «per prima la posizione senza variante
su TUTTI i numeri del settore (0-99)», ora l'ordine interno è quello per vicinanza. **Il commento
«ATTENZIONE, cicli NON invertibili» resta valido e va conservato**: il ciclo esterno è ancora la
variante, e invertirli proporrebbe `ZNUB500A` al posto di `ZNUB501`.

- [ ] **Step 4: lancia i test di `propose()`**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/ProposeTest.php"
```

Atteso: PASS. I test esistenti continuano a valere perché usano settori **senza codici con
geometria vicina**: senza cluster l'ordine resta numerico.

- [ ] **Step 5: lancia tutto il dominio, per le regressioni**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/"
```

Atteso: tutti verdi. Guarda con attenzione `ReserveConfirmReleaseTest`, `ConcurrentReservationTest`
e `TrailRegistryNormalizeCommandTest`, che chiamano `propose()` per via indiretta.

- [ ] **Step 6: commit** *(istruzione per il dev)*

```bash
git add src/TrailRegistry/TrailRegistryService.php tests/Feature/TrailRegistry/ProposeTest.php
git commit -m "feat(oc:8570): propose() continua la numerazione dei sentieri vicini"
```

---

### Task 5: lo stesso ordine nel Field `Select` della sostituzione manuale

Senza questo il sistema proporrebbe un numero ragionando sui vicini, ma l'operatore che apre
l'Action per cambiarlo si troverebbe i numeri in ordine 0, 1, 2 — cioè il criterio che stiamo
dichiarando sbagliato.

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php:213-234` (`numbersWithAvailableVariants()`)
- Modify: `src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php:94-107` (`numberOptions()`)
- Test: `tests/Feature/TrailRegistry/NumbersWithAvailableVariantsTest.php`

**Interfaces:**
- Consumes: `orderByProximity()` e `usedNumbersWithDistance()` dai Task 2 e 3
- Produces: `numbersWithAvailableVariants(string $fullCode, ?string $geometryWkt = null): array`.
  Il secondo parametro è **opzionale**: senza, il comportamento è quello di oggi. Nessun consumer
  esistente si rompe.

- [ ] **Step 1: scrivi il test che fallisce**

```php
it('ordina per vicinanza i numeri offerti per la sostituzione', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    foreach ([11, 12, 13] as $number) {
        makeCode([
            'number' => $number,
            'status' => TrailCodeStatus::Assigned,
            'geometry_wkt' => 'LINESTRING Z (1 1 0, 1.001 1.001 0)',
        ]);
    }

    $numbers = app(TrailRegistryService::class)->numbersWithAvailableVariants(
        'ZNUB5',
        'MULTILINESTRING Z ((1 1 0, 1.002 1.002 0))',
    );

    expect($numbers[0])->toBe(10)
        ->and($numbers[1])->toBe(14)
        // i numeri gia' usati che hanno ancora una lettera libera restano
        // nell'elenco: e' cosi' che si assegna ZNUB513A accanto a ZNUB513
        ->and($numbers)->toContain(11)
        ->and($numbers)->toHaveCount(100);
});
```

- [ ] **Step 2: lancia il test e verifica che fallisca**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/NumbersWithAvailableVariantsTest.php --filter='ordina per vicinanza'"
```

Atteso: FAIL — `numbersWithAvailableVariants()` accetta un solo argomento.

- [ ] **Step 3: aggiungi il parametro opzionale**

In `numbersWithAvailableVariants()`, cambia la firma e l'ultima riga:

```php
    public function numbersWithAvailableVariants(string $fullCode, ?string $geometryWkt = null): array
    {
```

e al posto di `return array_values(array_diff(range(0, 99), $saturated));`:

```php
        $available = array_values(array_diff(range(0, 99), $saturated));

        // Senza geometria non c'e' nulla rispetto a cui misurare: resta
        // l'ordine numerico, che e' anche il comportamento dei consumer che
        // chiamano questo metodo con il solo fullCode (oc:8570).
        if ($geometryWkt === null) {
            return $available;
        }

        return $this->orderByProximity(
            $available,
            $this->usedNumbersWithDistance($fullCode, $geometryWkt),
        );
```

- [ ] **Step 4: lancia il test e verifica che passi**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/NumbersWithAvailableVariantsTest.php"
```

Atteso: PASS, compresi i test esistenti che chiamano il metodo con il solo `fullCode`.

- [ ] **Step 5: scrivi il test dell'Action**

In `tests/Feature/TrailRegistry/ReplaceTrailCodeNumberActionTest.php`:

```php
it('offre per primi i numeri vicini al sentiero in esame', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    foreach ([11, 12, 13] as $number) {
        makeCode([
            'number' => $number,
            'status' => TrailCodeStatus::Assigned,
            'geometry_wkt' => 'LINESTRING Z (1 1 0, 1.001 1.001 0)',
        ]);
    }

    // Il codice su cui si apre l'Action: sta accanto al cluster 11-13.
    $codeId = makeCode([
        'number' => 70,
        'status' => TrailCodeStatus::Assigned,
        'geometry_wkt' => 'LINESTRING Z (1 1 0, 1.0015 1.0015 0)',
    ]);

    $code = TrailRegistryCode::query()->findOrFail($codeId);

    $wkt = DB::selectOne(<<<'SQL'
        SELECT ST_AsText(COALESCE(t.geometry, a.geometry)) AS wkt
        FROM trail_registry_codes c
        LEFT JOIN ec_tracks t ON t.id = c.ec_track_id
        LEFT JOIN trail_applications a ON a.id = c.trail_application_id
        WHERE c.id = ?
    SQL, [$code->id])->wkt;

    $numbers = app(TrailRegistryService::class)
        ->numbersWithAvailableVariants($code->fullCode, $wkt);

    // Il cluster piu' vicino e' 11-13 (il 70 e' il codice stesso, che pero'
    // occupa una posizione e fa cluster a se'): in testa esce un suo
    // adiacente, non lo 0.
    expect($numbers[0])->not->toBe(0);
});
```

**Attenzione a un punto che questo test mette in luce:** `usedNumbersWithDistance()` include anche
il codice su cui l'Action è aperta, che dista zero da sé stesso e farebbe cluster da solo,
governando l'ordine. Se il test mostra che accade, escludi quel codice passando il suo id al
metodo — `neighbourCodes()` fa esattamente così con `$excludeId` — e annota la scelta in
`notes.md`.

- [ ] **Step 6: passa la geometria dall'Action**

In `ReplaceTrailCodeNumber::numberOptions()`, prima della chiamata al service:

```php
        // La geometria del codice in esame non sta nel registro: sta nel
        // sentiero, o nell'istanza se il codice e' solo riservato. Stesso
        // COALESCE di neighbourCodes() (oc:8570).
        $wkt = DB::selectOne(<<<'SQL'
            SELECT ST_AsText(COALESCE(t.geometry, a.geometry)) AS wkt
            FROM trail_registry_codes c
            LEFT JOIN ec_tracks t ON t.id = c.ec_track_id
            LEFT JOIN trail_applications a ON a.id = c.trail_application_id
            WHERE c.id = ?
        SQL, [$code->id])?->wkt;

        return collect(app(TrailRegistryService::class)->numbersWithAvailableVariants($code->fullCode, $wkt))
```

Il nome della tabella dei sentieri va letto da `config('wm-package.ec_track_table', 'ec_tracks')`
come fa `neighbourCodes()`, non scritto a mano.

- [ ] **Step 7: lancia i test dell'Action e poi tutto il dominio**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry/"
```

Atteso: tutti verdi.

- [ ] **Step 8: commit** *(istruzione per il dev)*

```bash
git add src/TrailRegistry/TrailRegistryService.php src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php tests/Feature/TrailRegistry/
git commit -m "feat(oc:8570): stesso ordine per vicinanza nella sostituzione manuale"
```

---

### Task 6: misura delle prestazioni sul settore più popolato

`propose()` sta nel percorso di creazione di ogni istanza, e `reserve()` lo richiama fino a cinque
volte in contesa. Un `ORDER BY` per distanza su `geography` non usa l'indice KNN: il costo va visto,
non supposto.

**Files:**
- Modify: `docs/features/8570-ordinare-i-numeri-disponibili-per-vicinanza-geografica/notes.md`

**Interfaces:**
- Consumes: tutto il lavoro dei Task 2-5
- Produces: le misure scritte in `notes.md`

- [ ] **Step 1: misura la query sul DB locale di forestas**

ZNUB4 è il settore più popolato: 61 codici con geometria.

```bash
docker exec postgres-forestas psql -U forestas -d forestas -c "
EXPLAIN ANALYZE
SELECT c.number, MIN(ST_Distance(
    COALESCE(t.geometry, a.geometry),
    ST_GeomFromText('MULTILINESTRING Z ((9 40 0, 9.01 40.01 0))', 4326)::geography
)) AS distance
FROM trail_registry_codes c
LEFT JOIN ec_tracks t ON t.id = c.ec_track_id
LEFT JOIN trail_applications a ON a.id = c.trail_application_id
WHERE c.region='Z' AND c.province='NU' AND c.area='B' AND c.sector='4'
  AND c.status IN ('reserved','assigned')
  AND COALESCE(t.geometry, a.geometry) IS NOT NULL
GROUP BY c.number;
"
```

- [ ] **Step 2: confronta con il costo di oggi**

La stessa misura sulla lettura che `propose()` fa adesso:

```bash
docker exec postgres-forestas psql -U forestas -d forestas -c "
EXPLAIN ANALYZE
SELECT number, variant FROM trail_registry_codes
WHERE region='Z' AND province='NU' AND area='B' AND sector='4'
  AND status IN ('reserved','assigned');
"
```

- [ ] **Step 3: scrivi le due misure in `notes.md`**

Nella sezione «Decisioni», con i tempi reali e il tipo di piano scelto da Postgres (Seq Scan o
Index Scan). Se il tempo supera i 200 ms su 61 codici, **fermati e segnalalo al dev** prima di
proseguire: significa che il costo cresce male e va affrontato adesso, non dopo il rilascio.

- [ ] **Step 4: commit** *(istruzione per il dev)*

```bash
git add docs/features/8570-ordinare-i-numeri-disponibili-per-vicinanza-geografica/notes.md
git commit -m "docs(oc:8570): misure di prestazione dell'ordinamento per vicinanza"
```

---

### Task 7: verifica finale e documentazione

**Files:**
- Modify: `docs/resources/TrailRegistry.md`
- Modify: `docs/features/8570-ordinare-i-numeri-disponibili-per-vicinanza-geografica/notes.md`

- [ ] **Step 1: suite completa del package**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest"
```

Atteso: tutti verdi.

- [ ] **Step 2: PHPStan**

```bash
docker exec php-forestas bash -c "cd wm-package && composer analyse"
```

Atteso: nessun errore sui file toccati.

- [ ] **Step 3: formattazione dei soli file toccati**

```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pint src/TrailRegistry/TrailRegistryService.php src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php tests/Pest.php tests/Feature/TrailRegistry/ProximityOrderTest.php"
```

Poi `git status`: **non devono comparire file fuori dal lavoro**. `composer format` senza scope
riformatta l'intero repo.

- [ ] **Step 4: aggiorna `docs/resources/TrailRegistry.md`**

Nella sezione che descrive la proposta del numero, sostituisci «primo libero del settore» con il
criterio nuovo: cluster per contiguità numerica, il più vicino governa l'ordine, il gestore cambia
con `ReplaceTrailCodeNumber`. Cita il ticket.

- [ ] **Step 5: commit** *(istruzione per il dev)*

```bash
git add docs/ src/ tests/
git commit -m "docs(oc:8570): il criterio di proposta del numero nella documentazione del dominio"
```

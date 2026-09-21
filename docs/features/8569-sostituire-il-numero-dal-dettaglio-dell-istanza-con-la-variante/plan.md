> Ticket: oc:8569

# Sostituire il numero dal dettaglio dell'istanza, con la variante — Piano di implementazione

> **Per gli agent:** REQUIRED SUB-SKILL: usa `superpowers:subagent-driven-development` (consigliata) o `superpowers:executing-plans` per eseguire questo piano task per task. Gli step usano checkbox (`- [ ]`) per il tracciamento.

**Goal:** portare la sostituzione del numero di un codice riservato dentro il dettaglio dell'istanza di accatastamento, estendendola alle varianti.

**Architettura:** due nuovi metodi di lettura sul `TrailRegistryService` rispondono alle due domande che popolano le tendine («quali varianti sono libere per questo numero», «quali numeri hanno almeno una variante libera»); `replaceNumber()` accetta la variante e sposta il controllo di stato dentro la transazione su riga bloccata; l'Action Nova passa da un campo a due, dipendenti fra loro, e si sposta dalla Resource del codice a quella dell'istanza.

**Tech Stack:** Laravel 12, PHP 8.4, Nova 5.7.6, PostgreSQL + PostGIS, Pest.

**Spec:** [overview.md](overview.md) — va letta insieme a questo piano.

## Vincoli globali

- **Repo: solo `wm-package`.** Nessun file del repo principale `forestas` viene toccato. Il submodule è montato in `/Users/bongiu/Documents/geobox2/forestas/wm-package`.
- **Nessuna migration.** La colonna `variant char(1) default '0'` esiste già, e il `CHECK` ammette `[0-9A-Z]`.
- **Traduzioni in `resources/lang/*.json`**, mai in `lang/`: scritte altrove vengono ignorate in silenzio.
- **Documentazione, commenti e messaggi di commit in italiano.** I termini tecnici restano in inglese.
- **Nessun commit automatico.** Gli step «Commit» sono istruzioni per il dev: chi esegue il piano scrive i file e si ferma.
- **Test:** Pest, in `tests/Feature/TrailRegistry/`. Ogni file di test del dominio apre con `beforeEach(fn () => runTrailRegistryStubs())`, perché le migration del dominio sono `.stub` e non vengono raccolte dal `migrate` normale. Per creare righe si usa `makeCode([...])` da `tests/Pest.php`, che restituisce l'**id** della riga (non il model) e crea da sé l'istanza quando lo stato è `Reserved`.
- **Comando dei test:** `docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=<nome>"`.
- **PHPStan livello 5** deve restare pulito sui file toccati.

## Vocabolario

Il dominio distingue tre cose che in italiano si direbbero tutte «numero»:

| Termine | Significato | Esempio |
|---|---|---|
| `fullCode` | le quattro lettere del prefisso: region, province, area, sector | `ZNUB5` |
| numero | l'intero 0-99 dentro il settore | `13` |
| variante | la colonna `variant`; `'0'` significa «nessuna variante» | `A`, oppure `'0'` |
| codice | come lo legge l'operatore: `fullCode` + numero a due cifre + variante, con `'0'` omessa | `ZNUB513A` |

## Struttura dei file

| File | Responsabilità | Cosa cambia |
|---|---|---|
| `src/TrailRegistry/TrailRegistryService.php` | tutte le regole del registro | due metodi di lettura nuovi; `replaceNumber()` accetta la variante e blocca la riga |
| `src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php` | il gesto dell'operatore | due campi dipendenti; parte dall'istanza invece che dal codice |
| `src/TrailRegistry/Nova/TrailApplication.php` | Resource dell'istanza | registra l'azione |
| `src/TrailRegistry/Nova/TrailRegistryCode.php` | Resource del registro | smette di registrarla |
| `resources/lang/it.json`, `resources/lang/en.json` | traduzioni | chiavi nuove |
| `tests/Feature/TrailRegistry/ReplaceTrailCodeNumberTest.php` | copertura della sostituzione | nuovo |
| `tests/Feature/TrailRegistry/TrailRegistryNovaResourcesTest.php` | dove vivono le action | asserzione invertita |

`availableNumbers()` **non si tocca**, e i suoi due chiamanti (`ProposeTest.php:58`, `ApproveTrailApplicationTest.php:152`) nemmeno: risponde a un'altra domanda — quali numeri *puri* sono liberi — che serve a `propose()` e a quei test. I metodi nuovi non la sostituiscono.

---

### Task 1: `availableVariants()` — le varianti libere di un numero

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php` (dopo `availableNumbers()`, riga ~172)
- Test: `tests/Feature/TrailRegistry/AvailableVariantsTest.php` (create)

**Interfaces:**
- Produces: `TrailRegistryService::availableVariants(string $fullCode, int $number): array` — `array<int, string>`, i valori di `variant` liberi per quel numero in quel settore, nell'ordine di `variantSearchOrder()` (`'0'` prima, poi `A`…`Z`). Una variante è libera se non esiste nessuna riga con quel `fullCode`+numero+variante in stato attivo (`Reserved` o `Assigned`).

Il conteggio dell'occupato interroga **tutte** le righe esistenti di quel numero, non solo quelle con variante lettera: se in archivio esistesse un `variant = '3'`, quella riga risulterebbe occupata come le altre. Sono le varianti *offerte* a essere solo `'0'` più A-Z, e questo è ciò che fa `variantSearchOrder()`.

- [ ] **Step 1: scrivi il test che fallisce**

Crea `tests/Feature/TrailRegistry/AvailableVariantsTest.php`:

```php
<?php

use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
});

it('offre tutte le varianti quando il numero e libero', function () {
    $variants = app(TrailRegistryService::class)->availableVariants('ZNUB5', 13);

    expect($variants)->toHaveCount(27)
        ->and($variants[0])->toBe('0')
        ->and($variants)->toContain('A')->toContain('Z');
});

it('toglie la variante zero quando il numero puro e occupato', function () {
    makeCode(['number' => 13, 'variant' => '0', 'status' => TrailCodeStatus::Reserved]);

    $variants = app(TrailRegistryService::class)->availableVariants('ZNUB5', 13);

    expect($variants)->not->toContain('0')
        ->and($variants[0])->toBe('A')
        ->and($variants)->toHaveCount(26);
});

it('offre la variante zero se il numero e libero ma una lettera e presa', function () {
    makeCode(['number' => 13, 'variant' => 'A', 'status' => TrailCodeStatus::Assigned]);

    $variants = app(TrailRegistryService::class)->availableVariants('ZNUB5', 13);

    expect($variants)->toContain('0')->not->toContain('A')
        ->and($variants)->toHaveCount(26);
});

it('non offre nulla quando ogni variante e occupata', function () {
    foreach (array_merge(['0'], range('A', 'Z')) as $variant) {
        makeCode(['number' => 13, 'variant' => $variant, 'status' => TrailCodeStatus::Assigned]);
    }

    expect(app(TrailRegistryService::class)->availableVariants('ZNUB5', 13))->toBe([]);
});

it('considera occupata anche una variante numerica di archivio', function () {
    makeCode(['number' => 13, 'variant' => '3', 'status' => TrailCodeStatus::Assigned]);

    $variants = app(TrailRegistryService::class)->availableVariants('ZNUB5', 13);

    // La variante numerica non e' fra quelle offerte, quindi il conteggio
    // resta 27: quel che conta e' che non venga scambiata per libera.
    expect($variants)->toHaveCount(27)->not->toContain('3');
});

it('ignora le righe liberate', function () {
    makeCode(['number' => 13, 'variant' => 'A', 'status' => TrailCodeStatus::Released]);

    expect(app(TrailRegistryService::class)->availableVariants('ZNUB5', 13))->toContain('A');
});

it('non guarda i numeri vicini', function () {
    makeCode(['number' => 14, 'variant' => 'A', 'status' => TrailCodeStatus::Assigned]);

    expect(app(TrailRegistryService::class)->availableVariants('ZNUB5', 13))->toContain('A');
});
```

- [ ] **Step 2: lancia il test e verifica che fallisca**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=AvailableVariantsTest"
```

Atteso: FAIL con `Call to undefined method ... ::availableVariants()`.

- [ ] **Step 3: implementa il metodo**

In `src/TrailRegistry/TrailRegistryService.php`, subito dopo `availableNumbers()`:

```php
    /**
     * Le varianti ancora libere per un numero, nell'ordine in cui vanno
     * offerte: '0' («nessuna variante») per prima, poi A-Z.
     *
     * Le righe occupate si contano tutte, comprese eventuali varianti
     * numeriche entrate dall'import: sono le varianti *offerte* a essere
     * limitate alle lettere, non quelle che occupano una posizione.
     *
     * @return array<int, string>
     */
    public function availableVariants(string $fullCode, int $number): array
    {
        $taken = TrailRegistryCode::query()
            ->where('region', substr($fullCode, 0, 1))
            ->where('province', substr($fullCode, 1, 2))
            ->where('area', substr($fullCode, 3, 1))
            ->where('sector', substr($fullCode, 4, 1))
            ->where('number', $number)
            ->whereIn('status', $this->activeStatusValues())
            ->pluck('variant')
            ->all();

        return array_values(array_diff($this->variantSearchOrder(), $taken));
    }
```

- [ ] **Step 4: lancia il test e verifica che passi**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=AvailableVariantsTest"
```

Atteso: 7 test verdi.

- [ ] **Step 5: commit (lo esegue il dev)**

```bash
git add src/TrailRegistry/TrailRegistryService.php tests/Feature/TrailRegistry/AvailableVariantsTest.php
git commit -m "feat(oc:8569): availableVariants() elenca le varianti libere di un numero"
```

---

### Task 2: `numbersWithAvailableVariants()` — i numeri che hanno ancora posto

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php` (dopo `availableVariants()`)
- Test: `tests/Feature/TrailRegistry/NumbersWithAvailableVariantsTest.php` (create)

**Interfaces:**
- Consumes: `availableVariants()` dal Task 1.
- Produces: `TrailRegistryService::numbersWithAvailableVariants(string $fullCode): array` — `array<int, int>`, i numeri da 0 a 99 del settore che hanno almeno una variante libera, in ordine crescente.

È la prima tendina. Esclude solo i numeri **saturi**: quelli per cui né il numero puro né una delle ventisei lettere è libera.

Va risolto con **una sola query** sul settore e la differenza in PHP: cento chiamate a `availableVariants()` sarebbero cento query a ogni apertura del modale.

- [ ] **Step 1: scrivi il test che fallisce**

Crea `tests/Feature/TrailRegistry/NumbersWithAvailableVariantsTest.php`:

```php
<?php

use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
});

it('elenca tutti i cento numeri quando il settore e vuoto', function () {
    $numbers = app(TrailRegistryService::class)->numbersWithAvailableVariants('ZNUB5');

    expect($numbers)->toHaveCount(100)
        ->and($numbers[0])->toBe(0)
        ->and($numbers[99])->toBe(99);
});

it('tiene il numero occupato perche le sue varianti sono libere', function () {
    makeCode(['number' => 13, 'variant' => '0', 'status' => TrailCodeStatus::Assigned]);

    $numbers = app(TrailRegistryService::class)->numbersWithAvailableVariants('ZNUB5');

    // E' il caso di Saba: il 213 e' occupato, ma 213A si puo' ancora fare.
    expect($numbers)->toContain(13)->toHaveCount(100);
});

it('toglie il numero saturo', function () {
    foreach (array_merge(['0'], range('A', 'Z')) as $variant) {
        makeCode(['number' => 13, 'variant' => $variant, 'status' => TrailCodeStatus::Assigned]);
    }

    $numbers = app(TrailRegistryService::class)->numbersWithAvailableVariants('ZNUB5');

    expect($numbers)->not->toContain(13)->toHaveCount(99);
});

it('non guarda gli altri settori', function () {
    foreach (array_merge(['0'], range('A', 'Z')) as $variant) {
        makeCode(['number' => 13, 'variant' => $variant, 'sector' => '6', 'status' => TrailCodeStatus::Assigned]);
    }

    expect(app(TrailRegistryService::class)->numbersWithAvailableVariants('ZNUB5'))->toContain(13);
});

it('non fa una query per numero', function () {
    makeCode(['number' => 13, 'variant' => '0', 'status' => TrailCodeStatus::Assigned]);

    \Illuminate\Support\Facades\DB::enableQueryLog();
    app(TrailRegistryService::class)->numbersWithAvailableVariants('ZNUB5');
    $queries = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    expect(count($queries))->toBeLessThanOrEqual(2);
});
```

- [ ] **Step 2: lancia il test e verifica che fallisca**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=NumbersWithAvailableVariantsTest"
```

Atteso: FAIL con `Call to undefined method ... ::numbersWithAvailableVariants()`.

- [ ] **Step 3: implementa il metodo**

In `src/TrailRegistry/TrailRegistryService.php`, dopo `availableVariants()`:

```php
    /**
     * I numeri del settore che hanno almeno una variante libera: e' l'elenco
     * da cui il gestore sceglie quando sostituisce a mano.
     *
     * Non coincide con availableNumbers(), che risponde a un'altra domanda —
     * quali numeri *puri* sono liberi — e serve a propose(). Qui un numero
     * gia' occupato resta in elenco finche' gli avanza una lettera: e' cio'
     * che rende raggiungibile la variante di un sentiero esistente.
     *
     * Una query sola sul settore: cento chiamate a availableVariants()
     * sarebbero cento query a ogni apertura del modale.
     *
     * @return array<int, int>
     */
    public function numbersWithAvailableVariants(string $fullCode): array
    {
        $rows = TrailRegistryCode::query()
            ->where('region', substr($fullCode, 0, 1))
            ->where('province', substr($fullCode, 1, 2))
            ->where('area', substr($fullCode, 3, 1))
            ->where('sector', substr($fullCode, 4, 1))
            ->whereIn('status', $this->activeStatusValues())
            ->get(['number', 'variant']);

        $offered = count($this->variantSearchOrder());

        $saturated = $rows
            ->groupBy('number')
            ->filter(fn ($group) => count(
                array_intersect($this->variantSearchOrder(), $group->pluck('variant')->all())
            ) === $offered)
            ->keys()
            ->map(fn ($number) => (int) $number)
            ->all();

        return array_values(array_diff(range(0, 99), $saturated));
    }
```

- [ ] **Step 4: lancia il test e verifica che passi**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=NumbersWithAvailableVariantsTest"
```

Atteso: 5 test verdi.

- [ ] **Step 5: commit (lo esegue il dev)**

```bash
git add src/TrailRegistry/TrailRegistryService.php tests/Feature/TrailRegistry/NumbersWithAvailableVariantsTest.php
git commit -m "feat(oc:8569): numbersWithAvailableVariants() elenca i numeri non saturi"
```

---

### Task 3: `replaceNumber()` accetta la variante e blocca la riga

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-replacenumber-accetta-la-variante-e-blocca-la-riga)

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php:330-368`
- Test: `tests/Feature/TrailRegistry/ReplaceTrailCodeNumberTest.php` (create)

**Interfaces:**
- Consumes: niente dai task precedenti.
- Produces: `TrailRegistryService::replaceNumber(TrailRegistryCode $code, int $number, string $variant = '0', ?int $userId = null): TrailRegistryCode`.

**Due cambiamenti in un task solo**, perché toccano lo stesso corpo e un reviewer non potrebbe approvarne uno rifiutando l'altro:

1. la variante diventa un parametro invece di essere `'0'` fissa;
2. il controllo sullo stato si sposta **dentro** la transazione, su riga bloccata con `lockForUpdate()`.

Sul secondo punto: oggi `guardTransition()` è chiamato alla riga 332, prima di `DB::transaction()`, su `$code` così com'è in memoria. Se fra il render del modale e il salvataggio un altro operatore approva l'istanza — `confirm()` porta il codice ad `Assigned` — la sostituzione passa lo stesso e libera un codice già comunicato al richiedente.

`$variant` è il terzo parametro e `$userId` scala al quarto: la firma cambia, ma l'unico chiamante è l'Action, riscritta nel Task 4.

- [ ] **Step 1: scrivi il test che fallisce**

Crea `tests/Feature/TrailRegistry/ReplaceTrailCodeNumberTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailCodeTransitionException;
use Wm\WmPackage\TrailRegistry\Exceptions\NumberOccupiedException;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
});

it('sostituisce con un numero libero, senza variante', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));

    $new = app(TrailRegistryService::class)->replaceNumber($code, 13);

    expect($new->number)->toBe(13)
        ->and($new->variant)->toBe('0')
        ->and($new->code)->toBe('ZNUB513')
        ->and($new->status)->toBe(TrailCodeStatus::Reserved)
        ->and($code->fresh()->status)->toBe(TrailCodeStatus::Released);
});

it('sostituisce con una variante di un numero gia occupato', function () {
    makeCode(['number' => 13, 'variant' => '0', 'status' => TrailCodeStatus::Assigned]);
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));

    $new = app(TrailRegistryService::class)->replaceNumber($code, 13, 'A');

    expect($new->variant)->toBe('A')
        ->and($new->code)->toBe('ZNUB513A');
});

it('rifiuta una combinazione gia presente', function () {
    makeCode(['number' => 13, 'variant' => 'A', 'status' => TrailCodeStatus::Assigned]);
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));

    expect(fn () => app(TrailRegistryService::class)->replaceNumber($code, 13, 'A'))
        ->toThrow(NumberOccupiedException::class);

    // Il rollback ha rimesso a posto il codice di partenza.
    expect($code->fresh()->status)->toBe(TrailCodeStatus::Reserved);
});

it('non sostituisce un codice gia assegnato', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83, 'status' => TrailCodeStatus::Assigned]));

    expect(fn () => app(TrailRegistryService::class)->replaceNumber($code, 13))
        ->toThrow(InvalidTrailCodeTransitionException::class);
});

it('non sostituisce un codice diventato assegnato dopo il caricamento', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));

    // La riga in memoria dice ancora Reserved; il database no. E' quel che
    // succede se un altro operatore approva mentre il modale e' aperto.
    DB::table('trail_registry_codes')
        ->where('id', $code->id)
        ->update(['status' => TrailCodeStatus::Assigned->value]);

    expect(fn () => app(TrailRegistryService::class)->replaceNumber($code, 13))
        ->toThrow(InvalidTrailCodeTransitionException::class);

    expect(DB::table('trail_registry_codes')->where('number', 13)->count())->toBe(0);
});

it('registra chi ha eseguito la sostituzione', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $userId = makeTrailRegistryTestUser();

    $new = app(TrailRegistryService::class)->replaceNumber($code, 13, '0', $userId);

    expect($code->fresh()->events->pluck('reason')->all())->toContain('number_replaced')
        ->and($new->events->pluck('reason')->all())->toContain('number_replaced');
});
```

- [ ] **Step 2: lancia il test e verifica che fallisca**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=ReplaceTrailCodeNumberTest"
```

Atteso: falliscono almeno «sostituisce con una variante di un numero gia occupato» (la variante viene ignorata, esce `'0'`) e «non sostituisce un codice diventato assegnato dopo il caricamento» (il guard legge lo stato stale e lascia passare).

- [ ] **Step 3: riscrivi il metodo**

Sostituisci il corpo di `replaceNumber()` in `src/TrailRegistry/TrailRegistryService.php` (righe 330-368) con:

```php
    public function replaceNumber(
        TrailRegistryCode $code,
        int $number,
        string $variant = '0',
        ?int $userId = null,
    ): TrailRegistryCode {
        $fullCode = $code->fullCode;

        return DB::transaction(function () use ($code, $number, $variant, $userId, $fullCode) {
            // Il guard va qui, non prima della transazione: fra il render del
            // modale e il salvataggio un'altra approvazione puo' aver portato
            // il codice ad Assigned, e sostituirlo significherebbe liberare un
            // numero gia' comunicato al richiedente.
            $code = TrailRegistryCode::query()
                ->whereKey($code->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardTransition($code, [TrailCodeStatus::Reserved], 'sostituire');

            $application = $code->application;

            $this->release($code, 'number_replaced', $userId);

            try {
                // insertReservation(), non reserve(): un numero scelto a mano
                // dal gestore e' deliberato, se e' occupato la sostituzione
                // deve fallire subito, non essere silenziosamente dirottata
                // su un numero diverso.
                return $this->insertReservation([
                    'taxonomy_where_id' => $code->taxonomy_where_id,
                    'region' => $code->region,
                    'province' => $code->province,
                    'area' => $code->area,
                    'sector' => $code->sector,
                    'number' => $number,
                    'variant' => $variant,
                ], $application, 'number_replaced');
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    throw NumberOccupiedException::forNumber($fullCode, $number, $variant);
                }

                throw $e;
            }
        });
    }
```

Aggiorna anche il docblock sopra il metodo: la frase «Variante sempre `'0'`… non esiste un percorso per sostituire con un numero in variante lettera» non è più vera e va rimossa.

- [ ] **Step 4: lancia il test e verifica che passi**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=ReplaceTrailCodeNumberTest"
```

Atteso: 6 test verdi.

- [ ] **Step 5: verifica di non aver rotto il resto del dominio**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=TrailRegistry"
```

Atteso: tutto verde. `availableNumbers()` non è stata toccata, quindi `ProposeTest` e `ApproveTrailApplicationTest` devono restare invariati: se falliscono, è un errore di questo task.

- [ ] **Step 6: commit (lo esegue il dev)**

```bash
git add src/TrailRegistry/TrailRegistryService.php tests/Feature/TrailRegistry/ReplaceTrailCodeNumberTest.php
git commit -m "feat(oc:8569): replaceNumber() accetta la variante e blocca la riga in transazione"
```

---

### Task 4: l'Action passa a due campi e parte dall'istanza

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-laction-passa-a-due-campi-e-parte-dallistanza)

**Files:**
- Modify: `src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php` (riscrittura)
- Test: `tests/Feature/TrailRegistry/ReplaceTrailCodeNumberActionTest.php` (create)

**Interfaces:**
- Consumes: `availableVariants()` (Task 1), `numbersWithAvailableVariants()` (Task 2), `replaceNumber()` con la nuova firma (Task 3), `TrailApplication::activeCode()` (già esistente, `src/TrailRegistry/Models/TrailApplication.php:102`).
- Produces: un'Action che opera su `TrailApplication` invece che su `TrailRegistryCode`.

Tre trappole da evitare, tutte emerse dalla Challenge:

1. **`$request->resourceId` ora è l'id dell'istanza.** L'implementazione attuale fa `TrailRegistryCode::find($request->resourceId)`: lasciata così restituirebbe *un altro codice*, quello che per caso ha lo stesso id, senza sollevare nessun errore. Si passa da `TrailApplicationModel::find()` e poi `->activeCode`.
2. **`activeCode` può essere `null`** — istanza respinta, o in uno stato anomalo. Va gestito sia nel popolamento delle tendine (elenco vuoto) sia in `handle()` (diniego leggibile).
3. **`handle()` cicla su `$models`.** Applicare lo stesso numero a più istanze farebbe fallire la seconda a metà lotto: l'azione dichiara di lavorare su una sola istanza.

L'etichetta del primo campo mostra **tre cifre**, settore più numero (`513`, non `13`): è il codice come lo legge l'operatore, e la differenza fra ciò che vede e ciò che il collega gli dice al telefono è una fonte di errori silenziosi.

- [ ] **Step 1: scrivi il test che fallisce**

Crea `tests/Feature/TrailRegistry/ReplaceTrailCodeNumberActionTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ReplaceTrailCodeNumber;

beforeEach(function () {
    runTrailRegistryStubs();
});

// Non chiamarla emptyActionFields(): quel nome e' gia' definito come
// funzione globale in ApproveTrailApplicationTest.php:47, e Pest carica
// tutti i file della suite nello stesso processo.
function actionFieldsFor(array $values): ActionFields
{
    return new ActionFields(collect($values), collect([]));
}

it('popola il primo campo con i numeri non saturi del settore', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $request = NovaRequest::create('/', 'GET', ['resourceId' => $code->trail_application_id]);

    $options = (new ReplaceTrailCodeNumber)->numberOptions($request);

    expect($options)->toHaveCount(100)
        ->and($options[13])->toBe('513');
});

it('non popola nulla se l istanza non ha un codice attivo', function () {
    $applicationId = DB::table('trail_applications')->insertGetId([
        'user_id' => makeTrailRegistryTestUser(),
        'source' => 'office',
        'status' => 'under_review',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $request = NovaRequest::create('/', 'GET', ['resourceId' => $applicationId]);

    expect((new ReplaceTrailCodeNumber)->numberOptions($request))->toBe([]);
});

it('non scambia l id dell istanza per l id del codice', function () {
    // Istanza 1 con codice 7: se l'action facesse find() sull'id grezzo,
    // leggerebbe il codice 1 — un settore che non c'entra niente.
    for ($i = 0; $i < 6; $i++) {
        makeCode(['number' => 90 + $i, 'sector' => '6', 'status' => TrailCodeStatus::Assigned]);
    }
    $code = TrailRegistryCode::find(makeCode(['number' => 83, 'sector' => '5']));

    expect($code->id)->not->toBe($code->trail_application_id);

    $request = NovaRequest::create('/', 'GET', ['resourceId' => $code->trail_application_id]);
    $options = (new ReplaceTrailCodeNumber)->numberOptions($request);

    // Le etichette portano il settore 5, quello dell'istanza giusta.
    expect($options[13])->toBe('513');
});

it('offre la variante zero e le lettere per un numero libero', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $request = NovaRequest::create('/', 'GET', ['resourceId' => $code->trail_application_id]);

    $options = (new ReplaceTrailCodeNumber)->variantOptions($request, 13);

    expect($options['0'])->toBe('nessuna variante')
        ->and($options)->toHaveKey('A');
});

it('sostituisce il numero dell istanza', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $application = TrailApplication::find($code->trail_application_id);

    (new ReplaceTrailCodeNumber)->handle(
        actionFieldsFor(['number' => '13', 'variant' => 'A']),
        collect([$application]),
    );

    expect($application->fresh()->activeCode->code)->toBe('ZNUB513A')
        ->and($code->fresh()->status)->toBe(TrailCodeStatus::Released);
});

it('nega quando l istanza non ha un codice attivo', function () {
    $applicationId = DB::table('trail_applications')->insertGetId([
        'user_id' => makeTrailRegistryTestUser(),
        'source' => 'office',
        'status' => 'rejected',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = (new ReplaceTrailCodeNumber)->handle(
        actionFieldsFor(['number' => '13', 'variant' => '0']),
        collect([TrailApplication::find($applicationId)]),
    );

    expect($result['danger'] ?? null)->toBeString();
});

it('nega quando la combinazione e gia occupata', function () {
    makeCode(['number' => 13, 'variant' => 'A', 'status' => TrailCodeStatus::Assigned]);
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $application = TrailApplication::find($code->trail_application_id);

    $result = (new ReplaceTrailCodeNumber)->handle(
        actionFieldsFor(['number' => '13', 'variant' => 'A']),
        collect([$application]),
    );

    expect($result['danger'] ?? null)->toBeString()
        ->and($code->fresh()->status)->toBe(TrailCodeStatus::Reserved);
});
```

- [ ] **Step 2: lancia il test e verifica che fallisca**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=ReplaceTrailCodeNumberActionTest"
```

Atteso: FAIL, `numberOptions()` e `variantOptions()` non esistono.

- [ ] **Step 3: riscrivi l'Action**

Sostituisci interamente `src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php`:

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailCodeTransitionException;
use Wm\WmPackage\TrailRegistry\Exceptions\NumberOccupiedException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Sostituisce a mano il numero prenotato da un'istanza, scegliendo un numero
 * del settore e, se serve, una variante.
 *
 * Vive sul dettaglio dell'istanza perche' e' li' che il gestore guarda la
 * mappa e si accorge che il numero proposto non e' quello giusto: mandarlo
 * nel registro dei codici gli farebbe perdere il contesto su cui decide.
 *
 * Due campi e non una lista sola: trattando «nessuna variante» come una delle
 * opzioni del secondo, la categoria «numero occupato» sparisce e resta una
 * regola sola — mostra cio' che e' libero.
 */
class ReplaceTrailCodeNumber extends Action
{
    use InteractsWithQueue, Queueable;

    public $onlyOnDetail = true;

    /**
     * Una sola istanza per volta: lo stesso numero applicato a piu' istanze
     * farebbe fallire la seconda a meta' lotto, sull'indice unico.
     */
    public $sole = true;

    public function name(): string
    {
        return __('Sostituisci numero');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(TrailRegistryService::class);

        /** @var TrailApplication|null $application */
        $application = $models->first();
        $code = $application?->activeCode;

        if ($code === null) {
            return Action::danger(__('Questa istanza non ha un codice attivo da sostituire.'));
        }

        try {
            $service->replaceNumber(
                $code,
                (int) $fields->get('number'),
                (string) ($fields->get('variant') ?: '0'),
                auth()->id(),
            );
        } catch (NumberOccupiedException|InvalidTrailCodeTransitionException $e) {
            return Action::danger($e->getMessage());
        }

        return Action::message(__('Numero sostituito.'));
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Select::make(__('Numero'), 'number')
                ->options($this->numberOptions($request))
                ->rules('required'),

            Select::make(__('Variante'), 'variant')
                ->dependsOn('number', function (Select $field, NovaRequest $request, $formData) {
                    $field->options($this->variantOptions($request, (int) $formData->number));
                })
                ->rules('required'),
        ];
    }

    /**
     * I numeri del settore con almeno una variante libera, etichettati a tre
     * cifre — settore piu' numero, il codice come lo legge il gestore.
     *
     * @return array<int, string>
     */
    public function numberOptions(NovaRequest $request): array
    {
        $code = $this->activeCodeOf($request);

        if ($code === null) {
            return [];
        }

        return collect(app(TrailRegistryService::class)->numbersWithAvailableVariants($code->fullCode))
            ->mapWithKeys(fn (int $number) => [
                $number => sprintf('%s%02d', $code->sector, $number),
            ])
            ->all();
    }

    /**
     * Le varianti libere del numero scelto. '0' e' «nessuna variante»: una
     * opzione come le altre, che semplicemente non compare se il numero puro
     * e' gia' preso.
     *
     * @return array<string, string>
     */
    public function variantOptions(NovaRequest $request, int $number): array
    {
        $code = $this->activeCodeOf($request);

        if ($code === null) {
            return [];
        }

        return collect(app(TrailRegistryService::class)->availableVariants($code->fullCode, $number))
            ->mapWithKeys(fn (string $variant) => [
                $variant => $variant === '0' ? __('nessuna variante') : $variant,
            ])
            ->all();
    }

    /**
     * Il codice su cui si opera si raggiunge dall'istanza, mai con un find()
     * sull'id della richiesta: da quando l'action vive sul dettaglio
     * dell'istanza, quell'id e' l'id dell'istanza, e find() restituirebbe un
     * codice qualunque senza sollevare nulla.
     */
    protected function activeCodeOf(NovaRequest $request): ?TrailRegistryCode
    {
        // Nel listing delle action non c'e' un resourceId: i campi sono
        // comunque richiesti da Nova, ma restano senza opzioni.
        if (! $request->resourceId) {
            return null;
        }

        return TrailApplication::find($request->resourceId)?->activeCode;
    }
}
```

- [ ] **Step 4: lancia il test e verifica che passi**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=ReplaceTrailCodeNumberActionTest"
```

Atteso: 7 test verdi. `$sole` esiste in questa versione di Nova (`vendor/laravel/nova/src/Actions/Action.php:188`), ma nessun test lo copre: la verifica è a mano, nel Task 6.

- [ ] **Step 5: commit (lo esegue il dev)**

```bash
git add src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php tests/Feature/TrailRegistry/ReplaceTrailCodeNumberActionTest.php
git commit -m "feat(oc:8569): l'action sostituisce numero e variante partendo dall'istanza"
```

---

### Task 5: lo spostamento fra le due Resource

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-lo-spostamento-fra-le-due-resource)

**Files:**
- Modify: `src/TrailRegistry/Nova/TrailApplication.php` (metodo `actions()`)
- Modify: `src/TrailRegistry/Nova/TrailRegistryCode.php:244-251` (metodo `actions()`)
- Modify: `tests/Feature/TrailRegistry/TrailRegistryNovaResourcesTest.php:36-43`

**Interfaces:**
- Consumes: l'Action del Task 4.

Il vincolo su chi può eseguire l'azione resta **uno solo**, quello del service: `Reserved`. Il `canRun` sulla Resource è ergonomia — fa comparire il bottone — e replica la stessa condizione risalendo all'istanza. Se le due condizioni divergessero comanderebbe comunque il service, che è il punto in cui l'invariante regge.

- [ ] **Step 1: aggiorna il test esistente**

In `tests/Feature/TrailRegistry/TrailRegistryNovaResourcesTest.php`, sostituisci il test «non espone una action che libera un numero dal registro» con:

```php
it('non espone piu dal registro la action che sostituisce il numero', function () {
    $actions = collect((new TrailRegistryCodeResource($this->code))
        ->actions(NovaRequest::create('/')))
        ->map(fn ($a) => class_basename($a))
        ->all();

    expect($actions)->not->toContain('ReleaseTrailCode');
    // Si sostituisce dal dettaglio dell'istanza (oc:8569): il registro resta
    // il posto dove si guarda lo stato dei codici, non dove si cambiano.
    expect($actions)->not->toContain('ReplaceTrailCodeNumber');
});
```

E aggiungi, nello stesso file:

```php
it('espone dall istanza la action che sostituisce il numero', function () {
    $code = TrailRegistryCodeModel::find(makeCode(['number' => 83]));
    $application = TrailApplicationModel::find($code->trail_application_id);

    $actions = collect((new TrailApplicationResource($application))
        ->actions(NovaRequest::create('/')))
        ->map(fn ($a) => class_basename($a))
        ->all();

    expect($actions)->toContain('ReplaceTrailCodeNumber');
});
```

Gli alias servono già tutti in testa al file e non vanno aggiunti: `TrailApplicationResource`, `TrailRegistryCodeResource`, `TrailApplicationModel`, `TrailRegistryCodeModel`. Nel test nuovo usa quindi `TrailRegistryCodeModel::find(...)` e `TrailApplicationModel::find(...)`, non i nomi di classe non aliasati.

- [ ] **Step 2: lancia il test e verifica che fallisca**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=TrailRegistryNovaResourcesTest"
```

Atteso: entrambi i test rossi — l'azione è ancora sul registro e non ancora sull'istanza.

- [ ] **Step 3: togli l'azione dal registro**

In `src/TrailRegistry/Nova/TrailRegistryCode.php`, sostituisci il corpo di `actions()` (righe 246-250) con:

```php
    public function actions(NovaRequest $request): array
    {
        // La sostituzione del numero si fa dal dettaglio dell'istanza
        // (oc:8569): il registro mostra lo stato dei codici, non li cambia.
        return [];
    }
```

Togli anche l'`use` di `ReplaceTrailCodeNumber` (riga 15) e quello di `TrailCodeStatus` **solo se** non è più usato altrove nel file — verificalo con un grep prima di rimuoverlo.

- [ ] **Step 4: registra l'azione sull'istanza**

In `src/TrailRegistry/Nova/TrailApplication.php`, nel metodo `actions()`:

```php
    public function actions(NovaRequest $request): array
    {
        $onlyUnderReview = fn ($request, $application) => $application->status === TrailApplicationStatus::UnderReview;

        return [
            (new ApproveTrailApplication)->canRun($onlyUnderReview),
            (new RejectTrailApplication)->canRun($onlyUnderReview),
            // Il vincolo che conta e' quello del service (solo Reserved):
            // qui lo replichiamo perche' il bottone non compaia quando non
            // porterebbe da nessuna parte.
            (new ReplaceTrailCodeNumber)->canRun(
                fn ($request, $application) => $application->activeCode?->status === TrailCodeStatus::Reserved,
            ),
        ];
    }
```

Aggiungi in testa al file:

```php
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ReplaceTrailCodeNumber;
```

- [ ] **Step 5: lancia i test e verifica che passino**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=TrailRegistryNovaResourcesTest"
```

Atteso: verdi.

- [ ] **Step 6: commit (lo esegue il dev)**

```bash
git add src/TrailRegistry/Nova/TrailApplication.php src/TrailRegistry/Nova/TrailRegistryCode.php tests/Feature/TrailRegistry/TrailRegistryNovaResourcesTest.php
git commit -m "refactor(oc:8569): sposta la sostituzione del numero dal registro all'istanza"
```

---

### Task 6: traduzioni e verifica d'insieme

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-traduzioni)

**Files:**
- Modify: `resources/lang/it.json`
- Modify: `resources/lang/en.json`

**Interfaces:**
- Consumes: le stringhe passate a `__()` nel Task 4.

Le chiavi sono le stringhe italiane: una chiave mancante in `it.json` non si nota, perché viene restituita la chiave stessa; una mancante in `en.json` mostra italiano a un utente inglese, e nulla lo segnala. Vanno quindi aggiunte a mano e verificate a occhio.

Le stringhe introdotte dal Task 4:

| Chiave | Dove |
|---|---|
| `Sostituisci numero` | nome dell'azione (esisteva già) |
| `Numero` | primo campo (esisteva già) |
| `Variante` | secondo campo |
| `nessuna variante` | opzione del secondo campo |
| `Numero sostituito.` | esito positivo (esisteva già) |
| `Questa istanza non ha un codice attivo da sostituire.` | diniego |

- [ ] **Step 1: verifica quali chiavi mancano davvero**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && for k in 'Sostituisci numero' 'Numero' 'Variante' 'nessuna variante' 'Numero sostituito.' 'Questa istanza non ha un codice attivo da sostituire.'; do printf '%s => it:%s en:%s\n' \"\$k\" \"\$(grep -c \"\\\"\$k\\\"\" resources/lang/it.json)\" \"\$(grep -c \"\\\"\$k\\\"\" resources/lang/en.json)\"; done"
```

- [ ] **Step 2: aggiungi le chiavi mancanti**

In `resources/lang/it.json` la traduzione coincide con la chiave (`"Variante": "Variante"`). In `resources/lang/en.json`:

```json
"Sostituisci numero": "Replace number",
"Numero": "Number",
"Variante": "Variant",
"nessuna variante": "no variant",
"Numero sostituito.": "Number replaced.",
"Questa istanza non ha un codice attivo da sostituire.": "This application has no active code to replace."
```

Inserisci le voci mantenendo l'ordine del file, e verifica che il JSON resti valido:

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && php -r 'json_decode(file_get_contents(\"resources/lang/it.json\"), true, 512, JSON_THROW_ON_ERROR); json_decode(file_get_contents(\"resources/lang/en.json\"), true, 512, JSON_THROW_ON_ERROR); echo \"json valido\".PHP_EOL;'"
```

- [ ] **Step 3: suite completa del dominio**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter=TrailRegistry"
```

Atteso: tutto verde, `ProposeTest` e `ApproveTrailApplicationTest` compresi — non sono stati toccati e devono restare tali.

- [ ] **Step 4: PHPStan sui file modificati**

```
docker exec -i php-forestas bash -lc "cd /var/www/html/forestas/wm-package && vendor/bin/phpstan analyse src/TrailRegistry --level=5"
```

Atteso: nessun errore nuovo rispetto alla baseline.

- [ ] **Step 5: prova a mano in Nova**

Non è automatizzabile e va fatta prima di considerare finito il lavoro — `dependsOn()` è l'unico pezzo che i test non coprono davvero, perché la dipendenza fra campi vive nel frontend di Nova.

1. apri il dettaglio di un'istanza in istruttoria con un codice riservato;
2. lancia «Sostituisci numero»: la prima tendina mostra i numeri a tre cifre;
3. scegli un numero **libero**: la seconda deve offrire «nessuna variante» più le lettere;
4. cambia il numero scegliendone uno **occupato**: la seconda deve aggiornarsi da sola e non offrire più «nessuna variante»;
5. conferma e verifica nel registro che il vecchio codice sia `released` e il nuovo `reserved`;
6. verifica che sul dettaglio di un codice, nel registro, il bottone non ci sia più;
7. verifica che l'azione non si possa lanciare su più istanze selezionate dall'indice.

- [ ] **Step 6: commit (lo esegue il dev)**

```bash
git add resources/lang/it.json resources/lang/en.json
git commit -m "feat(oc:8569): traduzioni delle etichette della sostituzione"
```

---

## Ordine e dipendenze

I task 1 e 2 sono indipendenti dal resto e si possono fare in parallelo fra loro; il 2 consuma concettualmente il primo ma non lo chiama. Il 3 è indipendente da 1 e 2. Il 4 li richiede tutti e tre. Il 5 richiede il 4. Il 6 chiude.

```
Task 1 ─┐
Task 2 ─┼─→ Task 4 ─→ Task 5 ─→ Task 6
Task 3 ─┘
```

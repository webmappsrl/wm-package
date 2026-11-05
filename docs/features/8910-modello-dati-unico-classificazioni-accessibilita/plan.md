> Ticket: oc:8910

# Modello unico delle classificazioni — piano per il `wm-package`

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** dare al package due tabelle — `taxonomy_terms` e `taxonomy_termables` — che accolgono
qualunque classificazione nuova senza una migration per ognuna, con il modello, la relazione
polimorfo, la Resource Nova e l'indicizzazione.

**Architecture:** vocabolari e termini nella stessa tabella, distinti da `parent_id` nullo; il
relazione è un `MorphPivot` con observer, che copia il vocabolario sulla riga e fa reindicizzare il
contenuto su Scout. Niente vincoli di unicità nel database: la scelta singola è una regola di
business che vive in Nova e nell'import. Le cinque tassonomie esistenti restano dove sono e non
vengono toccate.

**Tech Stack:** Laravel 10/11/12/13, PHP >8.1, PostgreSQL + PostGIS, Nova 5, Laravel Scout +
Elasticsearch, Pest.

**Spec:** [overview.md](overview.md)

## Global Constraints

- PHP minimo `>8.1`: **mai `const` dentro un trait** — vanno sulla classe, che il trait
  referenzia con `self::`.
- Documentazione, commenti e messaggi di commit **in italiano**; identificatori, nomi di colonna,
  di metodo e di chiave **in inglese**.
- Gli stub delle migration nuove stanno nella **root** di `database/migrations/`, con prefisso
  `zz_<AAAA_MM_GG_HHMMSS>_`, **non** in `trail_registry/`.
- Le traduzioni del package stanno in `resources/lang/*.json`, non in `lang/`.
- Per le select multiple si usa il **`Multiselect` di Outl1ne**, mai il `MultiSelect` nativo di
  Nova, che è la `<select multiple>` del browser (oc:8588). `Multiselect::options()` accetta **un
  solo argomento**: il secondo è ignorato in silenzio (oc:7953).
- Le righe di tassonomia si creano con `insertOrIgnore` più rilettura, **mai** `firstOrCreate` né
  catturando l'eccezione: sotto transazione PostgreSQL una violazione di unicità aborta la
  transazione (oc:8607).
- Prima di lanciare la suite va verificato l'isolamento del database: `.claude/rules/test.md`.
- **Nessun commit, push o branch viene eseguito da chi implementa.** Gli step «Commit» sono
  istruzioni testuali per il dev.
- Nel package **non si rimuove nulla**: `getAccessibilityTabFields()`, le diciotto chiavi
  `access_*` e `properties->difficulty` restano intatte.

## Review Focus

- **Un termine figlio di un termine** (gerarchia a tre livelli creata da Nova): `parent_id` sul
  relazione smette di essere il vocabolario. Test nel Task 2.
- **Una relazione scritta con `DB::table()->insert()`**, che scavalca il modello e lascia `parent_id`
  nullo: il raggruppamento per vocabolario perde quella riga. Test nel Task 2.
- **Un termine salvato senza `identifier`**: la colonna è `not null`, ma il modello deve fallire
  con un errore comprensibile e non con una violazione di vincolo grezza. Test nel Task 1.
- **Il solo `properties` della relazione che cambia**, senza che il contenuto venga salvato: Scout non
  se ne accorge da solo. Test nel Task 3.
- **Un contenuto con otto vocabolari in una lista**: `terms()` chiamato per vocabolario produce una
  query per vocabolario. Test del numero di query nel Task 4.

---

### Task 1: le due tabelle e il modello `TaxonomyTerm`

**Files:**
- Create: `database/migrations/zz_<AAAA_MM_GG_HHMMSS>_create_taxonomy_terms_table.php.stub`
- Create: `database/migrations/zz_<AAAA_MM_GG_HHMMSS>_create_taxonomy_termables_table.php.stub`
- Create: `src/Models/TaxonomyTerm.php`
- Test: `tests/Feature/Models/TaxonomyTermTest.php`

**Interfaces:**
- Produces: `TaxonomyTerm` con `parent(): BelongsTo`, `children(): HasMany`,
  `scopeVocabularies(Builder $q): Builder` (righe con `parent_id` nullo),
  `isVocabulary(): bool`, e le relazioni `ecTracks()`, `ecPois()` come `morphedByMany`.

- [ ] **Step 1: Scrivere i test che falliscono**

```php
it('distingue un vocabolario da un termine', function () {
    $vocabulary = TaxonomyTerm::create(['identifier' => 'hiking-difficulty', 'name' => ['it' => 'Difficoltà escursionistica']]);
    $term = TaxonomyTerm::create(['identifier' => 'hiking-difficulty-e', 'parent_id' => $vocabulary->id, 'name' => ['it' => 'E']]);

    expect($vocabulary->isVocabulary())->toBeTrue()
        ->and($term->isVocabulary())->toBeFalse()
        ->and($term->parent->id)->toBe($vocabulary->id)
        ->and($vocabulary->children)->toHaveCount(1)
        ->and(TaxonomyTerm::vocabularies()->pluck('identifier')->all())->toBe(['hiking-difficulty']);
});

it('traduce name, description ed excerpt', function () {
    $term = TaxonomyTerm::create([
        'identifier' => 'accepted-disabilities',
        'name' => ['it' => 'Disabilità accolte', 'en' => 'Accepted disabilities'],
    ]);

    app()->setLocale('en');
    expect($term->refresh()->name)->toBe('Accepted disabilities');
});

it('rifiuta un termine senza identifier', function () {
    expect(fn () => TaxonomyTerm::create(['name' => ['it' => 'Senza']]))
        ->toThrow(QueryException::class);
});

it('ordina i termini per sort_order e non per id', function () {
    $vocabulary = TaxonomyTerm::create(['identifier' => 'mtb-difficulty', 'name' => ['it' => 'MTB']]);
    TaxonomyTerm::create(['identifier' => 'mtb-ec', 'parent_id' => $vocabulary->id, 'sort_order' => 4, 'name' => ['it' => 'EC']]);
    TaxonomyTerm::create(['identifier' => 'mtb-tc', 'parent_id' => $vocabulary->id, 'sort_order' => 0, 'name' => ['it' => 'TC']]);

    expect($vocabulary->children->pluck('identifier')->all())->toBe(['mtb-tc', 'mtb-ec']);
});
```

- [ ] **Step 2: Lanciare i test e verificare che falliscano**

Dopo aver verificato l'isolamento del database secondo `.claude/rules/test.md`:

Run: `docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/Models/TaxonomyTermTest.php"`
Esito atteso: FAIL, `Class "Wm\WmPackage\Models\TaxonomyTerm" not found`.

- [ ] **Step 3: Scrivere lo stub `create_taxonomy_terms_table`**

Colonne, nell'ordine dell'overview: `id` bigserial; `parent_id` bigint nullable con FK su
`taxonomy_terms.id` e `nullOnDelete()`; `type` string nullable; `identifier` string **not null**
unique; `name`, `description`, `excerpt` jsonb nullable; `icon` string nullable; `sort_order`
smallInteger not null default 0; `single_choice` boolean not null default false; `properties`
jsonb nullable; `timestamps()`. Indice su `parent_id`.

- [ ] **Step 4: Scrivere lo stub `create_taxonomy_termables_table`**

`id` bigserial; `taxonomy_term_id` bigint con FK su `taxonomy_terms.id` e `cascadeOnDelete()`;
`parent_id` bigint nullable, **senza** FK (è una copia denormalizzata); `morphs('termable')`;
`properties` jsonb nullable. **Niente timestamps**, come gli altri pivot. Indice composto su
`(termable_type, termable_id, parent_id)`, **non unique**: serve a raggruppare per vocabolario, non
a vincolare.

- [ ] **Step 5: Scrivere `src/Models/TaxonomyTerm.php`**

Estende `Model`, usa `HasFactory` e `HasTranslations` con
`public array $translatable = ['name', 'description', 'excerpt'];` — lo stesso trait di
`src/Models/Abstracts/Taxonomy.php:17`. Cast `properties` ad `array`. `$fillable` con tutte le
colonne tranne `id` e i timestamps. Le relazioni dell'Interfaces block.

- [ ] **Step 6: Pubblicare le migration e lanciarle**

Run: `docker exec -it php-forestas php artisan wm-package:publish-migration <nome-stub>` per
entrambe, poi `php artisan migrate`.
Esito atteso: le due tabelle esistono; `\d taxonomy_terms` mostra `identifier` NOT NULL e unique.

- [ ] **Step 7: Lanciare i test e verificare che passino**

Run: come allo Step 2.
Esito atteso: PASS, 4 test.

- [ ] **Step 8: Commit** *(istruzione per il dev, non eseguire)*

```bash
git add database/migrations src/Models/TaxonomyTerm.php tests/Feature/Models/TaxonomyTermTest.php
git commit -m "feat(oc:8910): tabelle e modello dei termini di tassonomia"
```

---

### Task 2: la relazione polimorfa e la copia del vocabolario

**Files:**
- Create: `src/Models/TaxonomyTermable.php`
- Test: `tests/Feature/Models/TaxonomyTermableTest.php`

**Interfaces:**
- Consumes: `TaxonomyTerm` dal Task 1.
- Produces: `TaxonomyTermable extends MorphPivot`, con `$table = 'taxonomy_termables'`,
  `$timestamps = false`, e un hook `saving` che valorizza `parent_id` dal termine; più
  `taxonomyTerms(): MorphToMany` su `src/Traits/TaxonomyAbleModel.php`, con
  `->using(TaxonomyTermable::class)` e `->withPivot(['parent_id', 'properties'])`. La relazione
  nasce qui perché i test di questo task la usano; i metodi di lettura arrivano nel Task 4.

- [ ] **Step 1: Scrivere i test che falliscono**

```php
it('copia il vocabolario sulla relazione al salvataggio', function () {
    [$vocabulary, $term, $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);

    expect(DB::table('taxonomy_termables')->where('taxonomy_term_id', $term->id)->value('parent_id'))
        ->toBe($vocabulary->id);
});

it('lascia parent_id nullo quando si associa un vocabolario direttamente', function () {
    [$vocabulary, , $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($vocabulary->id);

    expect(DB::table('taxonomy_termables')->where('taxonomy_term_id', $vocabulary->id)->value('parent_id'))
        ->toBeNull();
});

it('rifiuta un termine il cui padre non è un vocabolario', function () {
    [$vocabulary, $term] = seedVocabularyTermAndTrack();
    expect(fn () => TaxonomyTerm::create([
        'identifier' => 'nipote', 'parent_id' => $term->id, 'name' => ['it' => 'Nipote'],
    ]))->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Lanciare i test e verificare che falliscano**

Run: `docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/Models/TaxonomyTermableTest.php"`
Esito atteso: FAIL.

- [ ] **Step 3: Scrivere `src/Models/TaxonomyTermable.php`**

`MorphPivot` sul modello di `src/Models/TaxonomyActivityable.php`. Nel `booted()`, un hook
`saving` che legge il `parent_id` del termine e lo scrive sulla riga, ignorando qualunque valore
arrivato da fuori: la colonna non è scrivibile a mano.

- [ ] **Step 4: Aggiungere a `TaxonomyTerm` il controllo sul padre**

Nel `booted()` del modello, un hook `saving`: se `parent_id` è valorizzato e il termine puntato
ha a sua volta un `parent_id`, lancia `InvalidArgumentException`. La gerarchia è a due livelli, e
senza questo controllo `parent_id` sulla relazione smette di significare «il vocabolario».

- [ ] **Step 5: Lanciare i test e verificare che passino**

Esito atteso: PASS, 3 test.

- [ ] **Step 6: Commit** *(istruzione per il dev)*

```bash
git add src/Models/TaxonomyTermable.php src/Models/TaxonomyTerm.php tests/Feature/Models/TaxonomyTermableTest.php
git commit -m "feat(oc:8910): relazione polimorfa dei termini con il vocabolario sulla riga"
```

---

### Task 3: l'observer che fa reindicizzare

**Files:**
- Create: `src/Observers/TaxonomyTermableObserver.php`
- Modify: `src/Providers/WmPackageServiceProvider.php` (registrazione dell'observer)
- Test: `tests/Feature/Models/TaxonomyTermableObserverTest.php`

**Interfaces:**
- Consumes: `TaxonomyTermable` dal Task 2.
- Produces: observer registrato su `saved` e `deleted`, che chiama `searchable()` sul contenuto
  collegato.

- [ ] **Step 1: Scrivere i test che falliscono**

```php
it('reindicizza il contenuto quando nasce una relazione', function () {
    Queue::fake();
    [, $term, $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);

    Queue::assertPushed(\Laravel\Scout\Jobs\MakeSearchable::class);
});

it('reindicizza anche quando cambia solo il properties della relazione', function () {
    Queue::fake();
    [, $term, $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);
    Queue::fake();

    $track->taxonomyTerms()->updateExistingPivot($term->id, ['properties' => ['duration_forward' => 7200]]);

    Queue::assertPushed(\Laravel\Scout\Jobs\MakeSearchable::class);
});

it('reindicizza quando una relazione viene tolto', function () { /* detach + assertPushed */ });
```

- [ ] **Step 2: Lanciare i test e verificare che falliscano**

Esito atteso: FAIL sul secondo e terzo test.

- [ ] **Step 3: Scrivere l'observer e registrarlo**

Sul modello di come `TaxonomyActivityable` è osservato oggi. Scout reagisce al salvataggio del
modello, e una riga di pivot non tocca il contenuto: l'observer risolve il `termable` e chiama
`searchable()`.

- [ ] **Step 4: Lanciare i test e verificare che passino**

Esito atteso: PASS, 3 test.

- [ ] **Step 5: Commit** *(istruzione per il dev)*

```bash
git add src/Observers src/Providers tests/Feature/Models/TaxonomyTermableObserverTest.php
git commit -m "feat(oc:8910): reindicizzazione al salvataggio di una relazione"
```

---

### Task 4: `terms()` e `term()` sul trait, senza N+1

**Files:**
- Modify: `src/Traits/TaxonomyAbleModel.php`
- Test: `tests/Feature/Models/TaxonomyTermsAccessorTest.php`

**Interfaces:**
- Consumes: Task 1 e 2.
- Produces: sui modelli che usano il trait —
  `terms(string $vocabularyIdentifier): Collection`,
  `term(string $vocabularyIdentifier): ?TaxonomyTerm`.

- [ ] **Step 1: Scrivere i test che falliscono**

```php
it('restituisce i termini di un vocabolario', function () {
    // due termini di accepted-disabilities e uno di hiking-difficulty sullo stesso sentiero
    expect($track->terms('accepted-disabilities')->pluck('identifier')->all())
        ->toEqualCanonicalizing(['disability-physical', 'disability-sensory'])
        ->and($track->term('hiking-difficulty')->identifier)->toBe('hiking-difficulty-e')
        ->and($track->term('route-type'))->toBeNull();
});

it('non fa una query per vocabolario', function () {
    $track->load('taxonomyTerms');
    DB::enableQueryLog();
    $track->terms('accepted-disabilities');
    $track->terms('hiking-difficulty');
    $track->term('accessibility-difficulty');
    expect(DB::getQueryLog())->toBeEmpty();
});
```

- [ ] **Step 2: Lanciare i test e verificare che falliscano**

Esito atteso: FAIL.

- [ ] **Step 3: Implementare i tre metodi**

`taxonomyTerms()` è una `morphToMany` verso `TaxonomyTerm` con `->using(TaxonomyTermable::class)`
e `->withPivot(['parent_id', 'properties'])`. `terms()` e `term()` **non interrogano il
database**: filtrano la collection della relazione per `pivot->parent_id`, risolvendo
l'identifier del vocabolario una volta sola. È questo che evita la query per vocabolario su una
lista.

- [ ] **Step 4: Lanciare i test e verificare che passino**

Esito atteso: PASS, 2 test, e il secondo con query log vuoto.

- [ ] **Step 5: Commit** *(istruzione per il dev)*

```bash
git add src/Traits/TaxonomyAbleModel.php tests/Feature/Models/TaxonomyTermsAccessorTest.php
git commit -m "feat(oc:8910): lettura dei termini per vocabolario senza query aggiuntive"
```

---

### Task 5: indicizzazione dei termini su Elasticsearch

**Files:**
- Modify: `src/Models/EcTrack.php` (`toSearchableArray()`, righe 727-775)
- Test: `tests/Feature/Search/EcTrackTermsIndexTest.php`

**Interfaces:**
- Consumes: Task 4.
- Produces: nell'array indicizzato, una chiave `terms` con un sotto-array per `identifier` di
  vocabolario, ciascuno con `identifier`, `name` e il `properties` della relazione.

- [ ] **Step 1: Scrivere il test che fallisce**

```php
it('indicizza i termini raggruppati per vocabolario con il properties della relazione', function () {
    $array = $track->toSearchableArray();

    expect($array['terms']['accepted-disabilities'])->toHaveCount(2)
        ->and($array['terms']['hiking-difficulty'][0]['identifier'])->toBe('hiking-difficulty-e')
        ->and($array['terms']['hiking-difficulty'][0]['pivot_properties'])->toBe(['duration_forward' => 7200]);
});
```

- [ ] **Step 2: Lanciare il test e verificare che fallisca**

Esito atteso: FAIL, chiave `terms` assente.

- [ ] **Step 3: Aggiungere il raggruppamento in `toSearchableArray()`**

Accanto a `taxonomyActivities` e `taxonomyWheres` che ci sono già. I dati di questo modello si
filtrano da Elasticsearch e non da SQL: il `properties` della relazione deve arrivare nell'indice o è
irraggiungibile.

- [ ] **Step 4: Dichiarare il mapping**

In `config/wm-elasticsearch.php`. Un mapping cambiato **non** si applica con il reindex normale:
serve `scout:import` (oc:8681). Scriverlo nella documentazione del Task 7.

- [ ] **Step 5: Lanciare il test e verificare che passi**

Esito atteso: PASS.

- [ ] **Step 6: Commit** *(istruzione per il dev)*

```bash
git add src/Models/EcTrack.php config/wm-elasticsearch.php tests/Feature/Search/EcTrackTermsIndexTest.php
git commit -m "feat(oc:8910): termini raggruppati per vocabolario nell'indice delle tracce"
```

---

### Task 6: Resource Nova e select per vocabolario

**Files:**
- Create: `src/Nova/TaxonomyTerm.php`
- Modify: `src/Nova/AbstractEcResource.php`
- Test: `tests/Feature/Nova/TaxonomyTermResourceTest.php`

**Interfaces:**
- Consumes: Task 1 e 4.
- Produces: `AbstractEcResource::makeTermFieldsForVocabularies(array $vocabularyIdentifiers): array`, che
  genera un campo per vocabolario.

- [ ] **Step 1: Scrivere i test che falliscono**

```php
it('genera una select multipla per un vocabolario normale e singola per una a scelta singola', function () {
    $fields = (new EcTrackResource)->makeTermFieldsForVocabularies(['accepted-disabilities', 'accessibility-difficulty']);

    expect($fields[0])->toBeInstanceOf(\Outl1ne\MultiselectField\Multiselect::class)
        ->and($fields[1])->toBeInstanceOf(\Laravel\Nova\Fields\Select::class);
});

it('non tocca i campi del tab Accessibility', function () {
    expect((new EcTrackResource)->getAccessibilityTabFields())->toHaveCount(18);
});
```

- [ ] **Step 2: Lanciare i test e verificare che falliscano**

Esito atteso: FAIL sul primo; il secondo deve **già** passare e resta come guardia.

- [ ] **Step 3: Scrivere la Resource `TaxonomyTerm`**

Campi: `parent` come `BelongsTo` verso sé stessa limitata ali vocabolari, `identifier`, `name`,
`description`, `excerpt` traducibili, `icon`, `sort_order`, `single_choice`, `properties`. Da qui
si crea un vocabolario nuova senza passare dal codice.

- [ ] **Step 4: Scrivere `makeTermFieldsForVocabularies()`**

Per ogni identifier di vocabolario legge la riga e genera: `Multiselect` di Outl1ne se
`single_choice` è falso, `Select` se è vero. `Multiselect::options()` accetta **un solo
argomento**. Mai il `MultiSelect` nativo di Nova.

- [ ] **Step 5: Lanciare i test e verificare che passino**

Esito atteso: PASS, 2 test.

- [ ] **Step 6: Commit** *(istruzione per il dev)*

```bash
git add src/Nova tests/Feature/Nova/TaxonomyTermResourceTest.php
git commit -m "feat(oc:8910): Resource Nova dei termini e select per vocabolario"
```

---

### Task 7: documentazione e gate di CI

**Files:**
- Create: `docs/resources/TaxonomyTerms.md`
- Modify: `CLAUDE.md` (una riga sotto `## Conoscenza`)

- [ ] **Step 1: Scrivere `docs/resources/TaxonomyTerms.md`**

Come nasce un vocabolario, come si lega a un contenuto, come si leggono i termini con `terms()` e
`term()`, cosa significa `type`, e la nota su `scout:import` dopo un cambio di mapping.

- [ ] **Step 2: Verificare il gate delle migration**

Run: `docker exec -it php-forestas php artisan wm-package:publish-missing-migrations --dry-run`
Esito atteso: «Tutti gli stub wm-package obbligatori risultano allineati al database.»

- [ ] **Step 3: PHPStan e formattazione**

Run: `docker exec -it php-forestas bash -c "cd wm-package && composer analyse"`
Esito atteso: nessun errore sui file di questo lavoro. Poi `composer format` **solo** sui file
toccati: senza scope Pint riformatta l'intero repo.

- [ ] **Step 4: Suite completa**

Run: `docker exec -it php-forestas bash -c "cd wm-package && composer test"`, dopo aver
verificato l'isolamento del database.
Esito atteso: verde.

- [ ] **Step 5: Commit** *(istruzione per il dev)*

```bash
git add docs/resources/TaxonomyTerms.md CLAUDE.md
git commit -m "docs(oc:8910): documentazione d'uso del modello dei termini"
```

---

### Task 8: una vista Nova per vocabolario

Nasce dalla convergenza delle avvertenze di Forestas, ma il pezzo è del package: ogni shard che
voglia dare a un vocabolario una voce di menu propria — e non mandare l'utente nell'elenco unico
dei termini con un filtro — ha bisogno della stessa classe.

**Files:**
- Create: `src/Nova/AbstractTermVocabularyResource.php`
- Test: `tests/Feature/Nova/TermVocabularyResourceTest.php`

- [ ] **Step 1: Scrivere i test che falliscono**

```php
it('elenca solo i termini della propria vocabolario', function () {
    // Due vocabolari con un termine ciascuna: la Resource di prova ne dichiara una sola.
    expect(identifiersInIndex(new ProvaWarningsResource))->toBe(['sardegnasentieri:warning:1']);
});

it('crea il termine dentro la propria vocabolario senza chiederla', function () {
    expect(fieldAttributes(new ProvaWarningsResource, 'create'))->not->toContain('parent');
});
```

- [ ] **Step 2: Lanciare i test e verificare che falliscano**

Esito atteso: FAIL.

- [ ] **Step 3: Implementare `AbstractTermVocabularyResource`**

Estende `TaxonomyTerm` e dichiara un solo punto di variazione, `vocabularyIdentifier(): string`.
Da quello ricava: `indexQuery()` ristretto ai figli di quelil vocabolario, il campo «Vocabolario»
nascosto e preimpostato in creazione, `label()`/`singularLabel()` dal nome del vocabolario e
`uriKey()` lasciato alla sottoclasse, così uno shard può conservare l'indirizzo che aveva.
Il vocabolario si risolve per `identifier` e si memorizza per richiesta: la Resource la interroga
più volte.

- [ ] **Step 4: Lanciare i test e verificare che passino**

Esito atteso: PASS, 2 test.

- [ ] **Step 5: Documentare**

Una sezione in `docs/resources/TaxonomyTerms.md` sotto «Nova»: a cosa serve, le dieci righe di
una sottoclasse, e quando **non** usarla (un vocabolario che si gestisce bene dall'elenco unico,
che ora ha i filtri Tipo e Vocabolario).

- [ ] **Step 6: Commit** *(istruzione per il dev)*

```bash
git add src/Nova/AbstractTermVocabularyResource.php tests/Feature/Nova docs/resources/TaxonomyTerms.md
git commit -m "feat(oc:8910): vista Nova dedicata ai termini di un vocabolario"
```

---

## Dopo questo piano

Il package si merge **prima**. Solo dopo, Forestas bumpa il puntatore del submodule e applica la
sua parte: [il piano di Forestas](../../../../docs/features/8910-modello-dati-unico-classificazioni-accessibilita/plan.md).

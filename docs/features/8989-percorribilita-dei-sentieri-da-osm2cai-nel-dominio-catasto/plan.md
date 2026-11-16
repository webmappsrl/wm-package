> Ticket: oc:8989
> Stato: approvata il 2026-11-16

# Percorribilità nel dominio Catasto — piano (wm-package)

> **Per chi esegue:** usa `superpowers:subagent-driven-development` o `superpowers:executing-plans`.
> I passi hanno le caselle (`- [ ]`) per il tracciamento. **I commit scritti qui sono istruzioni,
> non azioni da eseguire durante il lavoro**: il primo commit lo fa il dev alla chiusura.

**Goal:** dare al dominio Catasto la percorribilità di un sentiero — stato, data, autore, nota e
storico — con una sola via di scrittura, e una tabella di eventi che domani serva anche la
validazione.

**Architettura:** lo stato corrente resta un termine di tassonomia, così filtri, Elasticsearch e
uscita in API arrivano già fatti; la storia è una tabella append-only nel dominio, con chiavi
esterne vere. Un service unico scrive evento e legame nella stessa transazione, ed è l'unico punto
da cui si passa — Action Nova e import compresi.

**Stack:** Laravel 12, PHP ≥8.1, PostgreSQL + PostGIS, Nova 5.7.6, Pest.

**Spec:** [overview.md](overview.md) — i requisiti sono citati per identificativo in ogni task.

## Vincoli globali

- Identificatori in inglese, commenti e documentazione in italiano.
- PHP minimo `>8.1`: mai `const` dentro un trait (vanno sulla classe, il trait usa `self::`).
- Traduzioni del package in `resources/lang/*.json`, mai in `lang/`.
- Le migration del dominio sono **stub** in `database/migrations/trail_registry/`, pubblicate dagli
  shard: nome `zz_<data>_<n>_<nome>.php.stub`.
- Le classi del dominio sono sostituibili dallo shard: si istanziano via `TrailRegistryClasses`,
  mai con `new` diretto.
- Prima di lanciare la suite, verifica l'isolamento del database come da `.claude/rules/test.md`.
- Comando dei test: `docker exec -it php-forestas bash -lc 'cd wm-package && vendor/bin/pest'`.

## Dove guardare in review

Cinque comportamenti che la spec implica e che nessun task esercita per intero; ogni riga ha il suo
test nel task che possiede il codice.

1. Due salvataggi ravvicinati sullo stesso sentiero non devono lasciare due stati attaccati (Task 3).
2. Un evento che parte da uno stato che nel frattempo è cambiato deve essere rifiutato, non scritto
   su una base vecchia (Task 3).
3. Una famiglia non accesa per l'app fa sparire il dato dalle API senza errori: il test deve
   guardare il JSON, non la tabella (Task 2 e Task 7).
4. Un termine che ha storia non deve essere cancellabile (Task 1).
5. A dominio spento niente del lavoro si monta sulle Resource generali (Task 10).

---

### Task 1: la tabella degli eventi e il suo modello

**File**
- Crea: `database/migrations/trail_registry/zz_2026_11_17_000001_create_trail_status_events_table.php.stub`
- Crea: `src/TrailRegistry/Models/TrailStatusEvent.php`
- Modifica: `src/TrailRegistry/TrailRegistryClasses.php` (chiave `models.status_event`)
- Test: `tests/Unit/TrailRegistry/TrailStatusEventTest.php`

**Interfacce**
- Produce: `TrailStatusEvent` con `ecTrack()`, `taxonomy()`, `fromTerm()`, `toTerm()`, `user()`;
  colonne `ec_track_id`, `taxonomy_id`, `from_term_id`, `to_term_id`, `description`, `user_id`,
  `source`, `created_at`. `public $timestamps = false`, `created_at` scritto a mano.
- Produce: `TrailRegistryClasses::statusEvent()`.

- [ ] **Passo 1 — il test che fallisce**

```php
it('non lascia cancellare un termine che ha storia', function () {
    // crea famiglia + termine + traccia + evento, poi:
    expect(fn () => $term->delete())->toThrow(QueryException::class);
});

it('conserva l_evento quando l_utente viene cancellato', function () {
    $user->delete();
    expect($event->fresh()->user_id)->toBeNull();
    expect($event->fresh()->to_term_id)->not->toBeNull();
});
```

- [ ] **Passo 2 — lancialo e verifica che fallisca** (`--filter=TrailStatusEvent`): errore di
      classe o tabella inesistente.

- [ ] **Passo 3 — scrivi lo stub della migration.** `ec_track_id` e `taxonomy_id` NOT NULL con
      `constrained()`; `from_term_id` nullable e `to_term_id` NOT NULL, entrambi `constrained(
      'taxonomy_terms')` **senza** `cascadeOnDelete` — è il vincolo che impedisce di cancellare un
      termine con storia; `description` text nullable; `user_id` nullable con `nullOnDelete()`;
      `source` string(16); `timestamp('created_at')`. Indice su `(ec_track_id, taxonomy_id,
      created_at)`, che è la query della cronologia. Nel docblock della migration: perché niente
      cascata, e perché `single_choice` non basta.

- [ ] **Passo 4 — scrivi il modello**, con il docblock che dice che **il nome della famiglia è
      configurabile e questo nome di tabella no**.

- [ ] **Passo 5 — lancia i test**: passano.

- [ ] **Passo 6 — commit** (istruzione, non azione): `feat(oc:8989): tabella degli eventi di stato del sentiero`

---

### Task 2: la famiglia nasce con il dominio — [R4](overview.md#r4), [R11](overview.md#r11)

**File**
- Modifica: `config/wm-package.php` (sotto `features.trail_registry`, chiave `walkability_taxonomy`
  con default `walkability-status` e il suo `env()`)
- Crea: `src/TrailRegistry/Services/WalkabilityTaxonomyInstaller.php`
- Crea: `database/migrations/trail_registry/zz_2026_11_17_000002_install_walkability_taxonomy.php.stub`
- Test: `tests/Feature/TrailRegistry/WalkabilityTaxonomyInstallTest.php`

**Interfacce**
- Consuma: `TaxonomyTerm`, `App`.
- Produce: `WalkabilityTaxonomyInstaller::install(): void`, idempotente (cerca per `identifier`).

- [ ] **Passo 1 — il test che fallisce**

```php
it('crea la famiglia e i quattro termini, una volta sola', function () {
    app(WalkabilityTaxonomyInstaller::class)->install();
    app(WalkabilityTaxonomyInstaller::class)->install();
    expect(TaxonomyTerm::where('identifier', 'walkability-status')->count())->toBe(1);
    expect($family->children()->pluck('identifier')->all())->toBe([
        'walkability-status-unknown', 'walkability-status-not-walkable',
        'walkability-status-partially-walkable', 'walkability-status-walkable',
    ]);
});

it('accende la famiglia sulle app esistenti, solo per le tracce', function () {
    expect($app->taxonomies()->where('taxonomy_terms.id', $family->id)->exists())->toBeTrue();
    expect($pivotModels)->toBe(['EcTrack']);
});

it('marca la famiglia is_system, cosi_ le app nuove la ricevono', function () {
    expect($family->is_system)->toBeTrue();
    $nuova = App::factory()->create();
    expect($nuova->taxonomies()->where('taxonomy_terms.id', $family->id)->exists())->toBeTrue();
});
```

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — scrivi l'installer.** `single_choice` vero sulla famiglia, `sort_order` 0..3 nei
      termini nell'ordine sopra, `name` tradotto, `icon` per ciascuno, `properties` null. L'ordine
      conta: `unknown` per primo, poi dal peggiore al migliore, come `validation-status`.

- [ ] **Passo 4 — scrivi lo stub della migration** che chiama l'installer: è il passo 2 della
      procedura di attivazione, quindi accendere il dominio e migrare basta.

- [ ] **Passo 5 — lancia i test**: passano.

- [ ] **Passo 6 — aggiorna** `docs/howto/attivare-catasto-sentieri.md`: la sezione delle migration
      dice che da lì nascono anche famiglia e termini, e che uno shard che vuole una scala diversa
      cambia la chiave di configurazione **prima** di migrare.

- [ ] **Passo 7 — commit:** `feat(oc:8989): la famiglia della percorribilità nasce con il dominio`

---

### Task 3: il service unico di scrittura — [R2](overview.md#r2), [R3](overview.md#r3), [R14](overview.md#r14), [R15](overview.md#r15)

**File**
- Crea: `src/TrailRegistry/Services/WalkabilityService.php`
- Crea: `src/TrailRegistry/Enums/TrailStatusEventSource.php` (`Manual`, `Import`, `Migration`)
- Test: `tests/Feature/TrailRegistry/WalkabilityServiceTest.php`

**Interfacce**
- Consuma: `TrailStatusEvent` (Task 1), `WalkabilityTaxonomyInstaller` (Task 2).
- Produce:
  `WalkabilityService::set(EcTrack $track, string $toIdentifier, ?string $description, ?int $userId, TrailStatusEventSource $source, ?string $expectedFromIdentifier = null): TrailStatusEvent`
  — unica via di scrittura. Solleva `WalkabilityConflictException` se `$expectedFromIdentifier` non
  è lo stato corrente.
- Produce: `WalkabilityService::current(EcTrack $track): ?TaxonomyTerm`.

- [ ] **Passo 1 — i test che falliscono**

```php
it('scrive evento e legame nella stessa transazione', function () {
    $event = $service->set($track, 'walkability-status-not-walkable', 'albero caduto al km 3', $user->id, Manual);
    expect($service->current($track)->identifier)->toBe('walkability-status-not-walkable');
    expect($event->from_term_id)->toBe($prima->id);
    expect($track->taxonomyTerms()->where('parent_id', $family->id)->count())->toBe(1);
});

it('lascia un solo stato quando due scritture si incrociano', function () {
    // due set() consecutivi con lo stesso stato di partenza
    expect($track->taxonomyTerms()->where('parent_id', $family->id)->count())->toBe(1);
});

it('rifiuta la scrittura se lo stato di partenza non è più quello', function () {
    expect(fn () => $service->set($track, '…-walkable', null, $user->id, Manual, '…-unknown'))
        ->toThrow(WalkabilityConflictException::class);
});

it('non scrive niente se lo stato non cambia', function () {
    expect(TrailStatusEvent::count())->toBe(1);
});
```

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — scrivi il service.** Dentro `DB::transaction`: `lockForUpdate()` sulla riga del
      sentiero, rilettura dello stato corrente, confronto con `$expectedFromIdentifier` se dato,
      `detach` dei termini della famiglia e `attach` del nuovo, poi l'evento. Nessuna scrittura se
      lo stato coincide già. Il docblock dice perché il lock serve: il pivot impedisce il duplicato
      dello stesso termine, non due termini diversi della stessa famiglia.

- [ ] **Passo 4 — lancia i test**: passano.

- [ ] **Passo 5 — commit:** `feat(oc:8989): service unico di scrittura della percorribilità`

---

### Task 4: l'azione in Nova — [R1](overview.md#r1), [R10](overview.md#r10)

**File**
- Crea: `src/TrailRegistry/Nova/Actions/UpdateWalkability.php`
- Modifica: la Resource `EcTrack` del package, per registrare l'azione a dominio acceso
- Test: `tests/Feature/TrailRegistry/UpdateWalkabilityActionTest.php`

**Interfacce**
- Consuma: `WalkabilityService::set(...)` (Task 3), `TrailRegistryPolicy::allows()`.

- [ ] **Passo 1 — i test che falliscono:** un Editor la lancia e lo stato cambia con l'evento
      intestato a lui; un Contributor non la vede e, invocandola direttamente, non la esegue; i
      campi sono due, stato obbligatorio fra i termini della famiglia e nota facoltativa.

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — scrivi l'Action.** `showInline()`; `canSee` e **`canRun`** entrambi su
      `TrailRegistryPolicy::allows()` — il `canRun()` è obbligatorio perché salta l'autorizzazione
      della Resource; `handle()` chiama il service con `TrailStatusEventSource::Manual` e l'utente
      loggato, passando come stato atteso quello letto all'apertura del form.

- [ ] **Passo 4 — togli il campo generico della famiglia dal form** delle tassonomie, come fa
      `EcTrack` di forestas per `validation-status`: lasciarlo sarebbe una seconda via di scrittura
      e violerebbe [R3](overview.md#r3). Test: il form di modifica non contiene un campo che scriva
      quella famiglia.

- [ ] **Passo 5 — lancia i test**: passano.

- [ ] **Passo 6 — commit:** `feat(oc:8989): azione Nova per aggiornare la percorribilità`

---

### Task 5: la cronologia leggibile — [R6](overview.md#r6)

**File**
- Crea: `src/TrailRegistry/Nova/WalkabilityHistoryRenderer.php`
- Test: `tests/Unit/TrailRegistry/WalkabilityHistoryRendererTest.php`

**Interfacce**
- Consuma: `TrailStatusEvent` (Task 1). Stessa forma di `CodeHistoryRenderer`.

- [ ] **Passo 1 — il test che fallisce:** per tre eventi il renderer produce tre righe in ordine di
      data decrescente, ciascuna con data, nome dell'utente **oppure la provenienza** quando
      l'utente manca, i due stati con il loro nome tradotto, e la nota.

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — scrivi il renderer.** Dove `CodeHistoryRenderer` scrive un trattino per l'utente
      assente, qui si scrive la provenienza: gli eventi senza persona sono la maggioranza.

- [ ] **Passo 4 — lancia i test**: passano.

- [ ] **Passo 5 — commit:** `feat(oc:8989): cronologia della percorribilità nella detail view`

---

### Task 6: la storia del sentiero, tutta insieme — [R13](overview.md#r13)

**File**
- Crea: `src/TrailRegistry/Nova/TrailHistoryRenderer.php`
- Test: `tests/Feature/TrailRegistry/TrailHistoryRendererTest.php`

**Interfacce**
- Consuma: `TrailStatusEvent` (Task 1), `TrailRegistryCodeEvent`.
- Produce: `TrailHistoryRenderer::rowsFor(EcTrack $track): array` — righe ordinate per data.

- [ ] **Passo 1 — il test che fallisce:** un sentiero con un evento di codice e due di stato
      produce tre righe in un'unica sequenza ordinata; un sentiero che ha avuto **due codici**
      diversi mostra gli eventi di entrambi, non solo di quello attivo.

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — scrivi il renderer.** Due query: gli eventi di stato del sentiero, e gli eventi
      dei codici che hanno quel `ec_track_id`. Nel docblock: perché non una tabella sola, e che la
      riga dell'assegnazione manca finché l'import non registra l'evento — il rischio è scritto
      nell'overview.

- [ ] **Passo 4 — lancia i test**: passano.

- [ ] **Passo 5 — commit:** `feat(oc:8989): storia unita del sentiero nella detail view`

---

### Task 7: l'uscita in API — [R5](overview.md#r5)

**File**
- Modifica: il punto che costruisce `properties` del dettaglio traccia nel package
- Test: `tests/Feature/TrailRegistry/WalkabilityApiTest.php`

- [ ] **Passo 1 — i test che falliscono**

```php
it('espone lo stato fra le tassonomie e i suoi dati accanto', function () {
    $json = /* dettaglio della traccia */;
    expect($json['properties']['taxonomies']['walkability-status'][0]['identifier'])
        ->toBe('walkability-status-not-walkable');
    expect($json['properties']['walkability'])->toMatchArray([
        'last_update' => '…', 'user' => $user->id, 'description' => 'albero caduto al km 3',
    ]);
});

it('non espone il blocco se il sentiero non ha eventi', function () {
    expect($json['properties'])->not->toHaveKey('walkability');
});

it('non espone la cronologia completa', function () {
    expect($json['properties']['walkability'])->not->toHaveKey('history');
});
```

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — scrivi il blocco.** `user` è l'**id**, non il nome: il JSON è pubblico.
      `last_update` è il `created_at` dell'ultimo evento — il nome viene da osm2cai.

- [ ] **Passo 4 — lancia i test**: passano.

- [ ] **Passo 5 — commit:** `feat(oc:8989): percorribilità nel dettaglio della traccia in API`

---

### Task 8: le incongruenze catastali — [R7](overview.md#r7)

**File**
- Crea: `src/TrailRegistry/Enums/TrailRegistryInconsistencyType.php`
- Crea: `src/TrailRegistry/Inconsistencies/TrailRegistryInconsistencyTypes.php` e
  `InconsistencyTypeDefinition.php`
- Modifica: `config/wm-package.php` (`features.trail_registry.inconsistency_types`, default `[]`)
- Test: `tests/Unit/TrailRegistry/TrailRegistryInconsistencyTypesTest.php`

**Interfacce**
- Produce: `TrailRegistryInconsistencyTypes::values()`, `::label(string $type)`,
  `::definition(string $type)` — stessa forma di `TrailRegistryAnomalyTypes`.

- [ ] **Passo 1 — il test che fallisce:** uno shard dichiara un tipo in configurazione e quel tipo
      compare in `values()` con la sua etichetta tradotta; un tipo sconosciuto non è `isKnown()`.

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — scrivi le tre classi.** Nel docblock dell'enum, la distinzione che il codice non
      può esprimere: **un'anomalia si corregge alla fonte e sparisce al giro dopo; un'incongruenza
      si risolve qui dentro, inserendo il dato che manca.** È il rischio scritto nell'overview.

- [ ] **Passo 4 — lancia i test**: passano.

- [ ] **Passo 5 — commit:** `feat(oc:8989): incongruenze catastali come famiglia del dominio`

---

### Task 9: le due card — [R8](overview.md#r8)

**File**
- Crea: `src/TrailRegistry/Nova/Cards/WalkabilityDistributionCard.php`
- Crea: `src/TrailRegistry/Nova/Cards/TrailRegistryInconsistenciesCard.php`
- Test: `tests/Feature/TrailRegistry/WalkabilityCardsTest.php`

**Interfacce**
- Consuma: `WalkabilityTaxonomyInstaller` (Task 2), `TrailRegistryInconsistencyTypes` (Task 8).

- [ ] **Passo 1 — i test che falliscono:** con 4 sentieri percorribili, 1 non percorribile e 10
      sconosciuti, la distribuzione somma 5 e riporta 10 come numero a parte, fuori dal grafico; i
      conteggi si leggono dai termini presenti nel database, non da un elenco nel codice; la card
      delle incongruenze porta alla index filtrata.

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — scrivi le card.** Niente `switch` su stringhe di valore: si itera sui termini
      della famiglia letti dal database. È il difetto di osm2cai che non si replica.

- [ ] **Passo 4 — lancia i test**: passano.

- [ ] **Passo 5 — commit:** `feat(oc:8989): card della percorribilità e delle incongruenze`

---

### Task 10: additività — [R9](overview.md#r9)

**File**
- Modifica: i punti di registrazione di Action, renderer e card
- Test: `tests/Feature/TrailRegistry/WalkabilityDomainOffTest.php`

- [ ] **Passo 1 — i test che falliscono:** a dominio spento la Resource `EcTrack` non espone
      l'azione né il renderer, le card non si istanziano, e il dettaglio in API non ha il blocco;
      nessuna eccezione da nessuna parte.

- [ ] **Passo 2 — lancia e verifica il fallimento.**

- [ ] **Passo 3 — condiziona le registrazioni** all'interruttore del dominio. Il precedente da non
      ripetere è nel `CLAUDE.md` del package: un `canSee()` che chiamava un metodo non ancora
      presente tolse il menu Nova a tutti gli utenti di un ruolo.

- [ ] **Passo 4 — lancia la suite intera** e `composer analyse`.

- [ ] **Passo 5 — aggiorna** `docs/resources/TrailRegistry.md` con la percorribilità, e la sezione
      «Trappole» con: il campo generico della famiglia va tolto dal form, e `--fresh` sul comando
      dei termini fallisce dove c'è storia.

- [ ] **Passo 6 — commit:** `feat(oc:8989): la percorribilità si monta solo a dominio acceso`

---

## Dopo il package

Questo lavoro si merge **prima**; lo shard bumpa il puntatore del submodule e applica la propria
parte nello stesso commit. Il piano dello shard è in
`forestas/docs/features/8989-percorribilita-dei-sentieri-da-osm2cai-nel-dominio-catasto/plan.md`.

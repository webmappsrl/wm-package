> Ticket: oc:8675

# Title tradotti dei box Flexible: piano di implementazione

> **Per chi esegue:** sotto-skill `superpowers:executing-plans` (o `subagent-driven-development`),
> un task alla volta, checkbox `- [ ]`. **Nessun `git add`/`git commit`/`git push`/branch**: i
> commit sotto sono istruzioni per il dev, da eseguire solo dopo la sua approvazione del diff.

**Obiettivo:** i campi `FlexibleTranslatable` dentro un layout Flexible arrivano al form con i
valori di ciascun gruppo, e un salvataggio senza modifiche lascia `config_home` e `overlays`
identici.

**Architettura:** `FlexibleTranslatable::jsonSerialize()` serializza subito i sotto-campi per
lingua che stanno nel meta `fields`, fissando i valori del gruppo nel momento in cui il gruppo viene
serializzato (`Layout::getResolvedValue`). Il `resolve(true)` del template (`Layout::jsonSerialize`)
arriva dopo e non tocca più ciò che è già serializzato. Nessun accesso a proprietà private di
kongulov, nessun cambiamento del formato salvato.

**Stack:** wm-package (Laravel 12, Nova 5), whitecube/nova-flexible-content, kongulov/nova-tab-translatable
2.1.7 (consumer) e 2.2.5 (CI del package), Pest.

**Spec:** [overview.md](overview.md)

## Vincoli globali

- Tutto il codice in `wm-package/`. In maphub nessun file, e **mai il gitlink del submodule**.
- I test del campo dichiarano `uses(Tests\TestCase::class)` e girano **dalla suite di maphub**,
  dentro `php-maphub`: `docker exec php-maphub vendor/bin/pest wm-package/tests/<file>`.
  Così usano il vendor di produzione (kongulov 2.1.7) e il DB di sviluppo (copia di dev.maphub del
  05/10/2026). Nessuna scrittura sul DB: le `App` dei test non si salvano (`new App([...])`).
  Se un test deve salvare, `DatabaseTransactions`, mai `RefreshDatabase`.
- Formato salvato invariato: title `{"it":"…","en":"…"}` con le sole lingue valorizzate; title vuoto
  in tutte le lingue = chiave assente nei box generici e negli overlays, `"title": []` negli
  `horizontal_scroll`; title stringa legacy convertito come dopo oc:8488.
- Lingue: `config('wm-tab-translatable.locales')` = `it, en, fr, es, de`.
- PHP `>8.1`: niente `const` nei trait. Commenti e docblock in italiano.
- `composer format` riformatta tutto il repo: dopo, `git status` e scarta i file fuori dal lavoro.

## Review focus

Casi che l'overview implica e che un dev si aspetta funzionino, ciascuno con il suo test nel task 3:

1. Box di tipo diverso nella stessa home (`title` + `slug` + `external_url`): ognuno tiene il suo title.
2. Un gruppo con title legacy stringa accanto a uno con title tradotto: entrambi arrivano al form.
3. Un gruppo con solo l'italiano: dopo il giro resta `{"it":"…"}`, senza chiavi vuote.
4. Un gruppo nuovo, aggiunto con title vuoto: si salva senza title e non prende quello di un vicino.
5. Due gruppi in ordine invertito rispetto al salvato: ciascuno tiene il proprio title.

---

### Task 1: test che riproduce la causa

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-test-che-riproduce-la-causa)

**File:**
- Create: `wm-package/tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php`

**Interfacce prodotte (helper nello stesso file, usati dai task 2-4):**
- `configHomeFlexible(): \Whitecube\NovaFlexibleContent\Flexible` — `Flexible::make('Config Home', 'config_home')->resolver(ConfigHomeResolver::class)` con gli stessi layout di `App::config_home` (`title`, `slug`, `external_url`, `horizontal_scroll_activities`, `horizontal_scroll_poi_types`), presi dai metodi di layout della risorsa `Wm\WmPackage\Nova\App` (via Reflection se `protected`), non ricopiati.
- `serializeForForm(Flexible $field, $resource): array` — imposta una `NovaRequest` di form (update-fields) nel container, chiama `$field->resolve($resource)` e restituisce `json_decode(json_encode($field), true)`: il `json_encode` dell'**intero** campo, meta `layouts` compreso.
- `translatableValuesByGroup(array $serialized, string $attribute): array<int, array<string,string>>` — per ogni gruppo di `$serialized['value']`, trova il campo `FlexibleTranslatable` e restituisce `[locale => value]` letto dai suoi `fields[*]` (`meta.locale` → `value`).

- [x] **Step 1: scrivi il test**

```php
it('espone al form il title di ciascun gruppo quando più gruppi usano FlexibleTranslatable', function () {
    $app = new App(['config_home' => json_encode(['HOME' => [
        ['box_type' => 'title', 'title' => ['it' => 'Benvenuti', 'en' => 'Welcome']],
        ['box_type' => 'title', 'title' => ['it' => 'Itinerari', 'en' => 'Routes']],
    ]])]);

    $titles = translatableValuesByGroup(serializeForForm(configHomeFlexible(), $app), 'title');

    expect($titles[0])->toMatchArray(['it' => 'Benvenuti', 'en' => 'Welcome']);
    expect($titles[1])->toMatchArray(['it' => 'Itinerari', 'en' => 'Routes']);
});
```

- [x] **Step 2: verifica che fallisca per il motivo giusto**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php`
Atteso: FAIL sulle `expect`, con valori `''` (oppure con il title dell'ultimo gruppo su entrambi).
Se fallisce per errori di setup (binding, request, resolver) si corregge il setup. **Se passa,
ci si ferma**: la causa dell'overview è sbagliata o incompleta, va annotato in `notes.md` e
riportato al dev prima di proseguire.

### Task 2: fix in `FlexibleTranslatable::jsonSerialize()`

**File:**
- Modify: `wm-package/src/Nova/Fields/FlexibleTranslatable.php`
- Test: `wm-package/tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php`

**Interfacce:** consuma gli helper del task 1.

- [x] **Step 1: aggiungi il test sulla variante `richText`**

`it('espone al form il content rich text di ciascun gruppo')`: un `Flexible` di prova con un layout
`info` che contiene direttamente `FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')])`,
due gruppi con `content_it` diversi (`<p>Uno</p>`, `<p>Due</p>`), risolto su un `Fluent`/modello
con quegli attributi; `expect` sul valore `it` di ciascun gruppo. Deve fallire come quello del task 1.

- [x] **Step 2: implementa `public function jsonSerialize(): array`**

Chiama `parent::jsonSerialize()` e, se la chiave `fields` è un array, sostituisce ogni elemento
`JsonSerializable` con il suo `jsonSerialize()`. Docblock in italiano che spiega il perché: i
sotto-campi sono condivisi fra i gruppi dal `clone` superficiale di `Layout::cloneField`, e
`Layout::jsonSerialize` li svuota con `resolve(true)` prima del `json_encode` finale; è lo stesso
rimedio di `Layout::getResolvedValue`, un livello più in basso (oc:8675). Aggiorna il docblock di
classe se cita il comportamento della serializzazione.

- [x] **Step 3: verifica**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php wm-package/tests/Feature/Nova/Fields/FlexibleTranslatableTest.php`
Atteso: tutto PASS (oggi `FlexibleTranslatableTest` ha 25 test verdi).

- [x] **Step 4: commit (istruzione per il dev)** — fatto nel commit unico `1749a745`

```bash
git add src/Nova/Fields/FlexibleTranslatable.php tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php
git commit -m "fix(oc:8675): FlexibleTranslatable serializza subito i sotto-campi per lingua"
```

### Task 3: giro completo del form su home e overlays

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-giro-completo-del-form-su-home-e-overlays)

**File:**
- Test: `wm-package/tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php`

**Interfacce prodotte:**
- `submitFromForm(Flexible $field, array $serialized, $resource, array $overrides = []): array` — costruisce la `NovaRequest` PUT come la invia il form (`[$attribute => [['layout' => …, 'key' => …, 'attributes' => ["{key}__{sub-attribute}" => value, …]], …]]`, prendendo layout, key, attributi e valori da `$serialized`; `$overrides` permette di cambiare un valore o aggiungere/riordinare gruppi), chiama `$field->fill($request, $resource)` (eseguendo la callback se ne restituisce una) e restituisce il JSON decodificato dell'attributo del resource. Il formato esatto delle chiavi va verificato in `Flexible::syncAndFillGroups` e `ScopedRequest::scopeFrom`.
- `overlaysFlexible(): Flexible` — come `configHomeFlexible()`, con il resolver e i layout di `App::overlays`.

- [x] **Step 1: scrivi i test** (tutti con `serializeForForm` → `submitFromForm` senza modifiche, poi `expect` sul JSON salvato)

- `it('lascia identici i title di title, slug ed external_url dopo un salvataggio senza modifiche')` — un box per tipo, title diversi in `it`/`en`; `expect` uguale all'input. *(review focus 1)*
- `it('lascia identici i title degli horizontal_scroll activities e poi_types')` — due box con title e almeno un item; `expect` su `HOME[i].title`.
- `it('converte il title legacy stringa e tiene intatto il gruppo accanto')` — `['box_type' => 'title', 'title' => 'Itinera Romanica Plus']` + un box con title tradotto; `expect` sul legacy convertito come in `ConfigHomeTitleBoxLegacyStringTest` e sull'altro invariato. *(review focus 2)*
- `it('salva solo le lingue valorizzate')` — title `['it' => 'Solo italiano']`; `expect(...)->toBe(['it' => 'Solo italiano'])`. *(review focus 3)*
- `it('salva senza title un gruppo nuovo lasciato vuoto, senza prendere quello di un vicino')` — `$overrides` aggiunge in testa un gruppo `title` con tutti i valori `''`; `expect` che `HOME[0]` non abbia la chiave `title` e che gli altri siano invariati. *(review focus 4)*
- `it('tiene il title di ciascun gruppo quando i gruppi vengono riordinati')` — `$overrides` inverte i due gruppi; `expect` sui title nel nuovo ordine. *(review focus 5)*
- `it('mantiene i due formati del title vuoto')` — svuotando tutte le lingue: chiave assente su `title`/`slug`, `"title" => []` su `horizontal_scroll`.
- `it('lascia identiche le label degli overlays title')` — due overlay `title` con label diverse, `overlaysFlexible()`.

- [x] **Step 2: verifica**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php`
Atteso: PASS. Controprova: con il task 2 annullato temporaneamente (`git stash` del solo file
`FlexibleTranslatable.php`, poi `git stash pop`) almeno i test con più gruppi falliscono.

- [x] **Step 3: commit (istruzione per il dev)** — fatto nel commit unico `1749a745`

```bash
git add tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php
git commit -m "test(oc:8675): giro completo del form per i title di home e overlays"
```

### Task 4: non regressione dei Repeater

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#primo-ciclo-di-review-sulla-pr-06102026)

**File:**
- Test: `wm-package/tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php`

- [x] **Step 1: scrivi i test**

- `it('tiene i title di due item horizontal scroll dopo il giro del form')` — box `horizontal_scroll` activities con due item e title item diversi; `expect` su `HOME[0].items[*].title`.
- `it('tiene title e content di due righe info box dopo il giro del form')` — `config_detail` di un `EcPoi` non salvato (`HasConfigDetailPanel`, `ConfigDetailResolver`), layout `info` con due righe; `expect` su title e content di ciascuna riga.

Passano già prima del fix (controprova come nel task 3): il loro scopo è proteggere i Repeater
dal fix nel campo.

- [x] **Step 2: verifica e commit** — commit nel commit unico `1749a745`

Run: come sopra. Atteso: PASS.

```bash
git add tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php
git commit -m "test(oc:8675): non regressione dei title nei Repeater"
```

### Task 5: le due versioni di kongulov, suite e analisi statica

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-le-due-versioni-di-kongulov-suite-e-analisi-statica)

- [x] **Step 1: suite con la 2.1.7** (quella di maphub)

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php wm-package/tests/Feature/Nova/Fields/FlexibleTranslatableTest.php wm-package/tests/Feature/Nova/AppConfigOverlaysTitleLayoutTest.php wm-package/tests/Feature/Nova/AppConfigHomeHorizontalScrollTest.php wm-package/tests/Unit/Nova/AppConfigHomePoiTrackLayoutTest.php`
Atteso: PASS. `ConfigHomeTitleBoxLegacyStringTest` dalla suite di maphub fallisce già oggi con
`Target class [config] does not exist` (non dichiara `Tests\TestCase`): è preesistente, si annota
in `notes.md` e non si corregge qui.

- [x] **Step 2: suite con la 2.2.5**, solo in locale

Run: `docker exec php-maphub composer require kongulov/nova-tab-translatable:2.2.5 --no-interaction --no-scripts`, poi lo stesso comando dello step 1.
Atteso: PASS. Poi ripristino: `git -C /Users/rubensgarofalo/Sites/Webmapp/Laravel/maphub checkout composer.json composer.lock` e `docker exec php-maphub composer install --no-interaction --no-scripts`; controllo che `vendor/kongulov/nova-tab-translatable` sia di nuovo alla 2.1.7 e che `git status` di maphub non mostri `composer.*`.

- [x] **Step 3: PHPStan e Pint**

Run: `docker exec php-maphub vendor/bin/phpstan analyse` (da maphub) e Pint solo sui file toccati:
`docker exec php-maphub sh -c 'cd wm-package && vendor/bin/pint src/Nova/Fields/FlexibleTranslatable.php tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php'`.
Atteso: nessun errore nuovo sui file del diff.

### Task 6: prova a mano in Nova locale (maphub)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-prova-a-mano-in-nova-locale)

- [x] **Step 1:** `http://localhost:8000/nova/resources/apps/3/edit`, tab Home: i title dei box si
  vedono in tutte le lingue in cui sono salvati (il box `title` dell'app 3 ha il title legacy
  stringa). Prima salva l'attuale valore: `docker exec postgres-maphub psql -U maphub -d maphub -Atc "select config_home from apps where id=3"`.
- [x] **Step 2:** salva senza toccare nulla, rilancia la query: `config_home` identico, salvo la
  conversione del legacy in oggetto. Stessa prova sulla tab degli overlays.
- [x] **Step 3:** esito (con eventuali differenze) in `notes.md`.

### Task 7: documentazione del cantiere

- [x] `notes.md` con deviazioni, il test legacy che fallisce dalla suite del consumer, l'esito delle due versioni di kongulov e della prova a mano.
- [x] Commit (istruzione per il dev): — fatto nel commit unico `1749a745`

```bash
git add docs/features/8675-config-app-title-tradotti-box-flexible-si-svuotano-in-edit/
git commit -m "docs(oc:8675): overview, piano e note"
```

La pagina `docs/knowledge/campi-flexible-e-translatable.md` e il `CLAUDE.md` si aggiornano nella
fase update-context di wm-plan, non in questo piano.

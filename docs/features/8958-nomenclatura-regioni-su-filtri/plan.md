> Ticket: oc:8958

# Nomenclatura regioni su "Filtri" — piano `wm-package`

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** il `name` della Resource Nova `TaxonomyWhere` si modifica per lingua.

**Architecture:** `TaxonomyWhere::fields()` sostituisce `Text::make('name')` con
`NovaTabTranslatable::make([Text::make('Name', 'name')])`, lo stesso pattern di
`AbstractTaxonomyResource::fields()`.

**Tech Stack:** Laravel, Nova 5, `kongulov/nova-tab-translatable`, Spatie Translatable, Pest.

**Spec:** [overview.md](overview.md)

## Global Constraints

- Commit con scope `fix(oc:8958): …`, messaggi in italiano; i commit li esegue il dev, dopo il review-gate.
- Branch `feature/oc-8958-nomenclatura-regioni-su-filtri` da `origin/develop` di `wm-package`.
  Il checkout locale (`camminiditalia/wm-package`) è oggi su `feature/oc-8982-…` con modifiche non
  committate di oc:8982: non vanno toccate né portate nel branch di questo ticket.
- Policy invariata: modifica solo `Administrator`.
- I test si lanciano dal container dello shard: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/pest <file>"`.

## Review Focus

- Salvataggio con una lingua lasciata vuota nel tab: non deve cancellare il valore esistente di quella lingua (verifica manuale in Nova sul dev, Task 1 step 5).
- Index: la colonna del nome deve restare visibile e ordinabile come oggi (`$title = 'name'`).

---

### Task 1: `name` per lingua nella Resource `TaxonomyWhere`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-name-per-lingua-nella-resource-taxonomywhere)

**Files:**
- Modify: `src/Nova/TaxonomyWhere.php` (`fields()`)
- Test: `tests/Unit/Nova/TaxonomyWhereResourceTest.php` (nuovo)

**Interfaces:**
- Produces: nessuna interfaccia nuova; la Resource resta `Wm\WmPackage\Nova\TaxonomyWhere`, estesa da `App\Nova\TaxonomyWhere` di camminiditalia.

- [ ] **Step 1: Scrivi il test che fallisce**

`test_name_is_editable_per_language`: istanzia la Resource su un `TaxonomyWhere` nuovo, prende
`fields(NovaRequest::create('/'))`, cerca il primo `NovaTabTranslatable` e verifica che
`originalFields[0]` sia un `Text` con `attribute === 'name'`. Stesso schema di
`tests/Unit/EcDescriptionTiptapTest.php:152-159`.

`test_other_fields_are_unchanged`: nei campi restano un `ID`, il `FeatureCollectionMap` `geometry`
e il `PropertiesPanel`; nessun `Text` semplice con attributo `name` fuori dal `NovaTabTranslatable`.

- [ ] **Step 2: Lancia il test e verifica che fallisca** — Expected: FAIL, nessun `NovaTabTranslatable` trovato.

- [ ] **Step 3: In `fields()` sostituisci `Text::make('name')` con `NovaTabTranslatable::make([Text::make('Name', 'name')])`**, lasciando gli altri campi nello stesso ordine.

- [ ] **Step 4: Lancia il test e verifica che passi**, poi l'intera suite `tests/Feature/TaxonomyWhere/` e `tests/Feature/RetryTaxonomyWhereGeometryFetchTest.php`. Expected: PASS.

- [ ] **Step 5: Verifica manuale sul dev**, dopo il bump del package in camminiditalia (piano di
camminiditalia, Task 3): apri una `TaxonomyWhere` regionale con i 5 nomi, cambia solo `it`, salva,
controlla che le altre 4 lingue siano rimaste.

- [ ] **Step 6: Commit (lo esegue il dev)**

```bash
git add src/Nova/TaxonomyWhere.php tests/Unit/Nova/TaxonomyWhereResourceTest.php docs/features/8958-nomenclatura-regioni-su-filtri/
git commit -m "fix(oc:8958): nome delle TaxonomyWhere modificabile per lingua in Nova"
```

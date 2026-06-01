> Ticket: oc:7953

# Plan — lista vuota in "POI Search In"

## Step 1 — Fix `searchable_tab()` in `App.php`

**File:** `wm-package/src/Nova/App.php`
**Metodo:** `searchable_tab()` (riga ~480)

Sostituire:

```php
Multiselect::make(__('POI Search In'), 'poi_searchables'),
```

Con:

```php
Multiselect::make(__('POI Search In'), 'poi_searchables')
    ->options([
        'name' => 'Name',
        'description' => 'Description',
        'excerpt' => 'Excerpt',
        'osmid' => 'OSMID',
        'taxonomyPoiTypes' => 'POI Types',
    ], $poi_selected)
    ->help(__('Select one or more criteria from "name", "description", "excerpt", "osmid", "poi types"')),
```

**Perché:** `$poi_selected` è già calcolato a riga 483 ma non veniva passato al campo — i valori salvati non erano pre-selezionati. Le opzioni rispecchiano esattamente i campi gestiti da `EcPoi::getSearchableString()`.

## Step 2 — Commit

```
fix(oc:7953): add missing options to POI Search In multiselect
```

Branch: `oc_7953` (nel submodule `wm-package`)

## Step 3 — Aggiorna pointer submodule nel repo principale

Nel repo principale (`camminiditalia`, branch `develop`):

```bash
git add wm-package
git commit -m "fix(oc:7953): update wm-package pointer"
```

> ⚠️ Nessun commit va eseguito automaticamente. I commit sono istruzioni per il developer, da eseguire dopo revisione del codice.

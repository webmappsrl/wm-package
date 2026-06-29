> Ticket: oc:8140

# Plan (wm-package) — Fix: properties.layers su EcPoi valorizzato erroneamente per layer senza taxonomy_where

Vedi il plan completo in `camminiditalia/docs/features/8140-.../plan.md`.

## Modifiche in questo repo

### Step 1 — Branch
```bash
git checkout -b feature/oc-8140-fix-properties-layers-ecpoi-layer-senza-taxonomy-where
```

### Step 2 — `LayerService.php`
- Aggiungi metodo privato `hasValidAutoModeFilter(Layer $layer): bool`
- Aggiungi guard all'inizio di `updateLayersPropertyOnLayeredFeature()`

### Step 3 — Test
Crea `tests/Feature/LayerServiceUpdateLayersPropertyGuardTest.php` (5 test case).

### Step 4 — Commit
```bash
git commit -m "fix(oc:8140): skip updateLayersProperty when layer has no filters or manual models"
```

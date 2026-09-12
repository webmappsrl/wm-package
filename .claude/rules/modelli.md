---
paths:
  - "src/Models/**"
  - "src/Traits/**"
  - "src/Observers/**"
  - "src/Enums/**"
---

# Trappole: modelli e persistenza

Si applica quando tocchi modelli, trait, observer o enum del package.

- `HasPackageFactory` risolve la factory con `get_called_class()`: un modello figlio in un altro
  namespace (es. `App\Models\UgcPoi`) cerca la factory nel **proprio** namespace e `::factory()`
  non funziona. Va sovrascritto `newFactory()` nel figlio.
- `identifier` **non è in `$fillable`** di `TaxonomyWhere`: passarlo nell'array del costruttore lo
  scarta in silenzio e l'observer lo rigenera. Va assegnato dopo il costruttore, prima di
  `save()` (oc:8469).
- `$model->name` su un modello con Spatie `HasTranslations` restituisce **sempre** una stringa per
  la locale corrente, mai l'array: per tutte le traduzioni serve `getTranslations('name')`
  (oc:8241).
- `getTranslation('name', $locale)` può restituire stringa **vuota**, non `null`: serve una cascade
  `it → en → locale` con `empty()`, non un `??` (oc:7648).
- Mai `env()` diretto in un modello, sempre `config()`: con il config caching in produzione
  `env()` torna `null` (oc:8251).
- `env('X', 22)` restituisce una **stringa** appena la chiave è valorizzata in `.env` — il default
  int vale solo a chiave assente. Dove serve un `int`, il cast è esplicito (oc:8251).
- `env()` è risolto una volta all'avvio: nessun fallback pre-calcolato in un file di config se i
  test sovrascrivono a runtime con `config([...])` (oc:8464).
- `EcPoi` non ha il trait `Searchable`, a differenza di `EcTrack`: per reindicizzarlo si dispatcha
  `BuildAppPoisGeojsonJob`, non `->searchable()` (oc:8043).
- `scopeByWhereProperty()` fa early return **senza condizioni** quando `taxonomy_where` è vuoto:
  chi lo usa da solo ottiene tutti i modelli dell'app, non zero (oc:8140).
- Un consumer non può registrare un observer **prima** di quello del package: serve
  `Event::listen('eloquent.deleting: ...')` (oc:8180).

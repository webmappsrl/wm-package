# API Links Nova Cards — Design Spec

**Date:** 2026-03-31
**Scope:** wm-package + forestas project
**Branch:** wm-package `update-forestas`, forestas `develop`

---

## Obiettivo

Aggiungere card Nova nelle detail view di Layer e EcTrack che mostrano link diretti alle API di debug/monitoraggio (Elasticsearch e MinIO). Nessuna card per EcPoi (i POI sono già tutti visibili sull'app).

---

## Card da implementare

### LayerApiLinksCard (Layer → Elasticsearch)

Mostra il link alla chiamata Elasticsearch per quel layer:

```
{APP_URL}/api/v2/elasticsearch?app=geohub_app_{layer->app_id}&layer={layer->id}
```

Costruzione URL: `url('/api/v2/elasticsearch') . '?app=geohub_app_' . $layer->app_id . '&layer=' . $layer->id`

> Nota: il prefisso `geohub_app_` è legacy e potrebbe cambiare in futuro.

### EcTrackApiLinksCard (EcTrack → MinIO)

Mostra il link al file JSON del track su MinIO:

```
{AWS_WMFE_URL}/{shard_name}/tracks/{track->id}.json
```

Costruzione URL: riusa `StorageService::getTrackJsonPath($id)` già presente in wm-package, prefissato con `config('wm-minio.url')` (che mappa su `AWS_WMFE_URL`).

---

## Architettura

### File in wm-package

```
wm-package/src/Nova/Cards/
  ├── ApiLinksCard.php          ← base class astratta
  ├── LayerApiLinksCard.php     ← estende ApiLinksCard
  └── EcTrackApiLinksCard.php   ← estende ApiLinksCard
```

**`ApiLinksCard.php`** — classe base che:
- Accetta un array di `['label' => '...', 'url' => '...']` via costruttore
- Implementa `jsonSerialize()` per passarli al componente Vue
- Definisce `component()` → `'api-links-card'`

**`LayerApiLinksCard.php`** — costruisce i link dal model Layer, chiama parent con l'array.

**`EcTrackApiLinksCard.php`** — costruisce i link dal model EcTrack via StorageService, chiama parent con l'array.

### Componente Vue

Un singolo componente `api-links-card` nel sistema di build esistente di wm-package. Renderizza una lista di bottoni `<a target="_blank">` con label e URL. Stile coerente con il design system Nova.

### Registrazione

- `wm-package/src/Nova/Layer.php` → metodo `cards()` → `new LayerApiLinksCard($this->resource)`
- `wm-package/src/Nova/EcTrack.php` → metodo `cards()` → `new EcTrackApiLinksCard($this->resource)`

---

## Workflow di sviluppo

- Nessun commit autonomo: il codice viene scritto e revisionato dall'utente prima del commit.
- Tutto il codice va in wm-package (modelli e risorse Nova base sono lì).

# Tassonomie nel dettaglio di tracce e POI

## Come funziona oggi

`properties.taxonomies` è l'unico punto da cui i frontend (webapp, app, WordPress degli shard)
leggono le tassonomie di un contenuto. C'è nel dettaglio di ogni EcTrack e in ogni feature di
`pois.geojson`, con la stessa forma: un oggetto indicizzato per chiave della tassonomia, ogni
valore un array di termini. Ogni termine ha sempre `identifier`, `name` (tutte le traduzioni) e
`tab`, più i campi della sua fonte che hanno un valore (niente `null`):

| Fonte | Campi in più |
|---|---|
| `where` | `admin_level` (solo zone OSM: 4 regione, 6 provincia, 8 comune), `source` (`osmfeatures`, `osm2cai`, `geohub_conf_32`…) |
| `activity`, `theme`, `poi-type` | `description`, `excerpt` (traduzioni), `icon` |
| `taxonomy_terms` | `description`, `excerpt`, `icon`, `sort_order`, `pivot_properties` (le `properties` del legame contenuto-termine) |

Restano fuori gli id delle righe, i campi di import e le date.

Esempio, dalla traccia 326 di forestas (DB di dev):

```json
"taxonomies": {
  "activity": [
    {"identifier": "mtb", "name": {"it": "Mountain Bike", "en": "Mountain Bike"}, "tab": "Activities"},
    {"identifier": "hiking", "name": {"it": "Alte vie", "en": "High routes"}, "tab": "Activities"}
  ],
  "where": [
    {"identifier": "R7361997", "name": {"en": "Sardegna"}, "tab": "General", "admin_level": 4, "source": "osmfeatures"},
    {"identifier": "R40176", "name": {"en": "Atzara"}, "tab": "General", "admin_level": 8, "source": "osmfeatures"}
  ],
  "route-type": [
    {"identifier": "route-type-trail", "name": {"it": "Sentiero"}, "tab": "General", "sort_order": 0}
  ],
  "hiking-difficulty": [
    {"identifier": "sardegnasentieri:tid:4066", "name": {"it": "T"}, "tab": "Activities", "sort_order": 0}
  ]
}
```

**Le chiavi**, nell'ordine in cui escono (`DetailTaxonomiesService::build()`):

| Chiave | Fonte | `tab` |
|---|---|---|
| `activity` | `taxonomy_activities` | il `tab` di `config('wm-package.legacy_taxonomies')` (`Activities`) |
| `theme` | `taxonomy_themes` | `General` |
| `poi-type` | `taxonomy_poi_types` | `General` |
| `where` | `properties.taxonomy_where`, filtrate da «Località mostrate» dell'app del contenuto | `General` |
| identifier della tassonomia | `taxonomy_terms` accese nell'app per quel tipo di contenuto (`forAppAndModel`, `EcTrack` o `EcPoi`) | `properties->tab` della tassonomia, `General` se manca |

Le storiche stanno in `DetailTaxonomiesService::LEGACY_TAXONOMIES`. Le loro chiavi sono definitive e
in kebab-case, lo stesso formato degli identifier di `taxonomy_terms`, e sono riservate
(`TaxonomyTerm::RESERVED_TAXONOMY_IDENTIFIERS`, un test verifica che coincidano). Quando una
storica passerà in `taxonomy_terms` prenderà la sua chiave come identifier, e la riserva cadrà
con la voce in `LEGACY_TAXONOMIES`: per i frontend non cambierà niente.

Il `tab` è quello in cui Nova mostra la tassonomia nella sezione delle classificazioni
(`BuildsTaxonomyFields`): è una chiave (`General`, `Activities`, `Inclusivity`), non un'etichetta.

**Ordine.** Termini delle storiche per id, di `taxonomy_terms` per `sort_order` e id, zone dalla più
ampia alla più piccola (`admin_level`, senza livello per prime), come `taxonomyWheres`. Una
tassonomia senza termini non compare. `taxonomies` vuota e `name` senza traduzioni sono oggetti
JSON (`{}`), mai `[]`.

**Le etichette stanno nel `config.json` dell'app**, una volta sola
(`AppConfigService::config_section_taxonomy_labels()`):

```json
"TAXONOMY_LABELS": {
  "activity": {"it": "Attività", "en": "Activities"},
  "poi-type": {"it": "Tipologie POI", "en": "Poi Types"},
  "where": {"it": "Zone", "en": "Zones"},
  "route-type": {"it": "Tipo percorso"}
},
"TAXONOMY_TAB_LABELS": {
  "General": {"it": "Generale", "en": "General"},
  "Activities": {"it": "Attività", "en": "Activities"},
  "Inclusivity": {"it": "Inclusività", "en": "Inclusivity"}
}
```

Il frontend prende il nome di una tassonomia da `TAXONOMY_LABELS[<chiave di taxonomies>]` e quello
di un tab da `TAXONOMY_TAB_LABELS[<tab del termine>]`. `TAXONOMY_LABELS` ha le quattro storiche
(nome = la stessa chiave di traduzione di Nova) e tutte le tassonomie di `taxonomy_terms` accese
nell'app, per tracce o per POI, per `sort_order`; `TAXONOMY_TAB_LABELS` i tab che usano, `General`
per primo. Le traduzioni dei tab e delle storiche vengono dai file di lingua del package, solo per
le lingue tradotte di `wm-tab-translatable`; l'inglese ripiega sulla chiave.

**Dove esce.**
- Tracce: `EcTrack::getDetailGeojson()`, usato dalle tre uscite del dettaglio — il file su AWS
  (`/{shard}/tracks/{id}.json`, `EcTrackResource`), `/api/ec/track/{id}` quando il file manca e
  `/api/ec/track/multiple`. `getGeojson()` non la ha: gira anche nei job interni (DEM, pendenze,
  grafico altimetrico, zone).
- POI: `App::getAllPoisGeojson()`, con le tassonomie accese per gli EcPoi nell'app del file, lette
  una volta per file.
- Layer e UGC non la hanno.

**Quando si rigenera.**

| Evento | Cosa si riscrive |
|---|---|
| Attaccare o staccare un termine di `taxonomy_terms` da una traccia | il suo file su AWS (`TaxonomyTermableObserver`, dopo il commit, una volta per traccia; non dentro `withoutSyncingToSearch()`, che un import usa) |
| Attaccare o staccare un termine da un POI | `pois.geojson` dell'app |
| Accendere o spegnere una tassonomia dalla scheda App | `config.json` (`UpdateAppConfigJob`) e, con `RegenerateTaxonomyWhereOutputsJob`, dettagli di tutte le tracce, indice e `pois.geojson` (`ReindexAppContentsJob`) |
| Rinominare una tassonomia, cambiarne `identifier`, `properties` (il tab) o `sort_order`, cancellarla | solo il `config.json` delle app in cui è accesa |
| Rinominare o cancellare un termine | niente |

Negli ultimi due casi dettagli e `pois.geojson` restano indietro finché non si rigenerano: con un
identifier cambiato il frontend non trova più l'etichetta della tassonomia nei file vecchi.

**Chiavi deprecate.** Restano nell'output, identiche, per le app che le leggono già:

| Chiave deprecata | Al suo posto |
|---|---|
| `taxonomy.activity` | `taxonomies.activity` |
| `taxonomy.poi_type`, `taxonomy.poi_types` | `taxonomies.poi-type` |
| `taxonomy.when` | nessuna: `when` non è più usata |
| `taxonomy_where` | `taxonomies.where` |
| `taxonomyWheres` | `taxonomies.where[].name` |
| `taxonomyIdentifiers` (POI) | le chiavi e gli `identifier` di `taxonomies` |

I commenti `@deprecated` stanno su codice che serve anche a layer, UGC e al documento
Elasticsearch delle tracce, che un sostituto non hanno: toglierle vuol dire prima dare
`taxonomies` anche a quelle uscite.

**Su uno shard senza le migration di oc:8910** `taxonomies` ha solo le storiche; migration e
riavvio di Horizon al bump sono in [8910-tassonomie-cosa-controllare-al-bump-dello-shard.md](8910-tassonomie-cosa-controllare-al-bump-dello-shard.md).
La presenza delle tabelle si controlla una volta per processo, quindi senza il riavvio il worker
continua a non vederle.

## Perché così

- **Una chiave nuova invece di estendere `taxonomy`** (oc:8959): le chiavi di `taxonomy` hanno
  forme diverse (`activity` array di oggetti, `poi_type` oggetto singolo, `when` array di id) e
  altri shard le popolano; ricostruirle avrebbe cambiato il tipo di ciò che le app di oggi leggono.
- **Array di termini con `tab` su ogni termine** (oc:8959): il frontend legge una lista piatta, il
  WordPress raggruppa per tab con una select.
- **Tutti i campi utili di ogni fonte** (oc:8959): la forma ridotta a `identifier`/`name`/`tab`
  perdeva dati che i frontend usano, come l'`admin_level` delle zone.
- **`tab` dalle stesse fonti di Nova** (oc:8959): il dettaglio ha la stessa struttura della
  detail view in Nova.
- **Etichette nel `config.json`** (oc:8959): sono dati della tassonomia, uguali per tutte le tracce;
  il WordPress il `config.json` lo legge già (ha la sua sezione `WORDPRESS`). Sole traduzioni,
  perché il tab è già su ogni termine. Nomi delle sezioni scelti col dev.
- **Chiavi in kebab-case** (oc:8959): con `poi_type` la storica non avrebbe potuto passare in
  `taxonomy_terms` con la stessa chiave, perché il formato degli identifier non ammette `_`.
- **`target` e `when` fuori** (oc:8959): `when` non è più usata; `target` è vuota su forestas, e le
  custom stanno in `taxonomy_terms`.
- **Un servizio condiviso** (oc:8959): tracce e POI escono da `DetailTaxonomiesService`; le
  costanti stanno su una classe perché in un trait non si possono mettere (PHP 8.1).
- **Il toggle passa da `RegenerateTaxonomyWhereOutputsJob`** (oc:8959): è già il job che rigenera
  le uscite pubbliche dell'app, unico finché non parte, quindi un salvataggio che cambia sia le
  tassonomie sia «Località mostrate» non raddoppia i job per traccia.
- **`UpdateAppConfigJob` unico fino all'avvio, con `WithoutOverlapping`** (oc:8959): con
  `ShouldBeUnique` una seconda rinomina arrivata mentre il job girava veniva scartata.

## Come ci siamo arrivati

- **`taxonomies` costruita in `GeoJsonService`** (oc:8959, superata): arrivava a tutte e tre le
  uscite del dettaglio, ma `getGeojson()` gira anche nei job interni, che pagavano le query delle
  tassonomie per niente. Ora la costruisce `getDetailGeojson()`.
- **`tab` fisso `General` per le storiche** (oc:8959, superata): separava `activity` dalle
  difficoltà per attività, che in Nova stanno in `Activities`.
- **Nome della tassonomia su ogni termine** (oc:8959, scartata prima di scriverla): si ripeteva per
  ogni termine della stessa tassonomia; scartata anche una chiave accanto a `taxonomies` nel
  dettaglio, che si sarebbe ripetuta su ogni traccia.
- **Catalogo `TAXONOMIES` nel `config.json`** (oc:8959, non adottato): versione, tab e tassonomie
  come liste ordinate, scelta singola, dipendenze, termini possibili. Al frontend di oggi non
  serve, perché i filtri li costruisce da Elasticsearch e da `MAP.filters`; si può aggiungere
  accanto senza rompere niente quando un frontend costruirà i filtri da qui.
- **`TAXONOMY_LABELS` filtrata per le EcTrack** (oc:8959, superata): le etichette servono anche ai
  POI.
- **Rigenerazione delle tracce dentro `ReindexAppContentsJob`** (oc:8959, superata): duplicava
  `RegenerateTaxonomyWhereOutputsJob` e, con un salvataggio che cambiava due cose, accodava due
  job per traccia.

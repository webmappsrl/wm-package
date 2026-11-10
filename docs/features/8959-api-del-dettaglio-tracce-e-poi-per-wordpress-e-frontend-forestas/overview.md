> Ticket: oc:8959

# API del dettaglio tracce e POI per WordPress e frontend Forestas — primo pezzo: `taxonomies` delle tracce

## Cosa cambia

Il dettaglio di una traccia, cioè il file `/{shard}/tracks/{id}.json` che `UpdateEcTrackAwsJob`
scrive su AWS serializzando `EcTrackResource`, ottiene in `properties` una chiave nuova,
`taxonomies`. È un oggetto indicizzato per identifier della tassonomia: ogni valore è un array di
termini, e ogni termine porta il proprio `tab`.

```json
"taxonomies": {
  "activity":          [ {"identifier": "hiking", "name": {"it": "…", "en": "…"}, "tab": "General"} ],
  "where":             [ {"identifier": "R276369", "name": {"it": "Cagliari"}, "tab": "General"} ],
  "route-type":        [ {"identifier": "route-type-trail", "name": {…}, "tab": "General"} ],
  "hiking-difficulty": [ {"identifier": "…", "name": {…}, "tab": "Activities"} ]
}
```

`properties.taxonomy` (`activity`, `when`, `poi_type`, `poi_types`), `properties.taxonomy_where` e
`properties.taxonomyWheres` restano identici a oggi, per le app che li leggono già.

È il primo dei rilasci brevi del ticket: le altre parti (dati del dettaglio dall'artifact di
analisi, POI in `pois.geojson`, roadbook) arrivano in pezzi successivi.

**Etichette nel `config.json` dell'app.** Il nome delle tassonomie e dei tab non sta nel dettaglio
della traccia, dove si ripeterebbe su ogni termine e su ogni traccia: sta una volta sola nel
`config.json` dell'app, in due sezioni nuove. Il dettaglio le richiama per chiave
(`taxonomies.<identifier>`, `tab`).

```json
"TAXONOMY_LABELS": {
  "activity":          {"it": "Attività", "en": "Activities"},
  "where":             {"it": "Zone", "en": "Zones"},
  "route-type":        {"it": "Tipo percorso", "en": "Route type"},
  "hiking-difficulty": {"it": "Difficoltà escursionistica"}
},
"TAXONOMY_TAB_LABELS": {
  "General":    {"it": "Generale", "en": "General"},
  "Activities": {"it": "Attività", "en": "Activities"}
}
```

## Perché

Il WordPress di Forestas (Rubens) e il frontend leggono il dettaglio dal file su AWS, e allo scrum
del 2026-11-10 si è deciso che non si creano rotte nuove: «si lavora sempre sulla stessa API,
quella del dettaglio, che poi scrive in AS» (Giuseppe Bonfanti). Le tassonomie sono il primo pezzo:
«tutte le tassonomie devono entrare in un oggetto con la chiave che è l'identifier [...] il come si
faceva prima rimane per retrocompatibilità», «ce le vorrei tutte, comprese le weare, compreso tutte
quelle custom», e «le tassonomie le deve fare il VM package, tutto il resto lo deve fare Forestas».

`taxonomies` diventa l'unico punto da cui tutti i frontend leggono le tassonomie di una traccia.

Una chiave nuova, invece di estendere `taxonomy`, perché le chiavi vecchie non hanno la stessa forma
(`activity` è un array di oggetti, `poi_type` un oggetto singolo, `when` un array di id) e altri
shard le popolano: ricostruirle cambierebbe il tipo di quello che le app di oggi leggono.

La forma «array di termini con `tab` su ogni termine» è voluta: il frontend la legge come lista
piatta, e il WordPress raggruppa per tab con una select.

## Requisiti

- [ ] `properties.taxonomies` è presente nel dettaglio della traccia in tutte le sue uscite: file
      su AWS (`EcTrackResource`), `/api/ec/track/{id}` e `multiple` (`EcTrack::getDetailGeojson()`), e
      nelle feature di `pois.geojson` (secondo pezzo). Non compare nei layer né in `getGeojson()`.
- [ ] Ogni termine ha sempre `identifier`, `name` (tutte le traduzioni, `getTranslations('name')`) e
      `tab`, più i campi utili della sua fonte quando hanno un valore: `admin_level` e `source` per
      le zone; `description`, `excerpt`, `icon` per le storiche; `description`, `excerpt`, `icon`,
      `sort_order` e `pivot_properties` per `taxonomy_terms` (aggiunto dopo la prova in locale, su
      richiesta del dev).
- [ ] Le tassonomie di `taxonomy_terms`: chiave = `identifier` della tassonomia (il padre), `tab` =
      `properties->tab` della tassonomia, `General` se manca (stesso default di Nova,
      `BuildsTaxonomyFields`). Escono solo le tassonomie accese nell'app della traccia, come in
      `EcTrack::termsForSearch()`.
- [ ] Il `tab` di ogni tassonomia è **lo stesso tab in cui Nova la mostra** nella sezione
      «Tassonomie» della detail view della EcTrack (`BuildsTaxonomyFields`), letto dalle stesse
      fonti di Nova e mai riscritto a mano: `properties->tab` per `taxonomy_terms` (default
      `General`), `config('wm-package.legacy_taxonomies')` per le tassonomie storiche (`activity` →
      `Activities`), il tab di default per le zone (`where` → `General`, come il campo «Zone»). Su
      dev oggi: `General` per `validation-status`, `route-type`, `itinerary-type`, `warnings` e le
      zone; `Activities` per le attività e le cinque difficoltà; `Inclusivity` per le due di
      inclusività.
- [ ] Le tassonomie vecchie entrano con chiavi fisse: `activity` (`taxonomy_activities`), `theme`
      (`taxonomy_themes`), `poi-type` (`taxonomy_poi_types`), `where`, in kebab-case come gli identifier
      di `taxonomy_terms` (`DetailTaxonomiesService::LEGACY_TAXONOMIES`). `theme` e `poi-type`, che in
      Nova non compaiono nella sezione, prendono il tab di default `General`.
- [ ] `where` si costruisce da `properties.taxonomy_where` **dopo** il filtro «Località mostrate»
      (`applyTaxonomyWhereDisplay`): `identifier` = la chiave della mappa (es. `R276369`), `name` =
      le traduzioni senza le chiavi tecniche (`_source`, `_admin_level`).
- [ ] `when` e `target` non entrano in `taxonomies`: `when` non è più usata, `target` è vuota su
      forestas (0 righe su locale, UAT e dev) e le custom stanno in `taxonomy_terms`.
- [ ] Una tassonomia che la traccia non ha non compare: niente array vuoti.
- [ ] `properties.taxonomy`, `taxonomy_where` e `taxonomyWheres` restano byte per byte uguali a oggi.
- [ ] Le chiavi che `taxonomies` sostituisce sono segnalate come **deprecate**, pur restando
      nell'output: `properties.taxonomy` (con `activity`, `when`, `poi_type`, `poi_types`),
      `properties.taxonomy_where` e `properties.taxonomyWheres`. La segnalazione sta in due posti:
      un commento `@deprecated` nel codice che le costruisce (`GeoJsonService`,
      `applyTaxonomyWhereDisplay`, `taxonomyIdentifiers` in `App::getAllPoisGeojson()`), con il rimando a `taxonomies`, e la pagina di conoscenza sul
      formato, con l'elenco delle chiavi deprecate e la chiave nuova da leggere al loro posto.
- [ ] Le chiavi delle storiche (`activity`, `theme`, `poi-type`, `where`) sono riservate: il `saving`
      di `TaxonomyTerm` rifiuta una tassonomia (senza `parent_id`) con una di queste, come già rifiuta
      un identifier fuori formato. `RESERVED_TAXONOMY_IDENTIFIERS` coincide con le chiavi di
      `LEGACY_TAXONOMIES` (test). Una storica che passa in `taxonomy_terms` prende la sua chiave.
- [ ] Le tassonomie di `taxonomy_terms` si filtrano con `forAppAndModel($appId, 'EcTrack')`, non con
      `forApp()` come `termsForSearch()`: conta anche l'ambito `app_taxonomy_term.models`, come nel
      form Nova.
- [ ] Su uno shard senza le migration di oc:8910 `taxonomies` contiene solo le tassonomie storiche
      e il job non fallisce: il controllo è `Schema::hasTable('taxonomy_terms')`, non un `catch` su
      `QueryException`, che dentro una transazione PostgreSQL la lascerebbe abortita.
- [ ] Un termine storico senza `identifier` (la colonna è nullable) non entra in `taxonomies`.
- [ ] Attaccare o staccare un termine di `taxonomy_terms` da una traccia rigenera il suo file su
      AWS: `TaxonomyTermableObserver`, per le tracce, oltre al reindex accoda `UpdateEcTrackAwsJob`
      (oggi lo fa solo per i POI, con `BuildAppPoisGeojsonJob`).
- [ ] `config.json` dell'app ha la sezione `TAXONOMY_LABELS`: una voce per ogni tassonomia che può
      comparire in `taxonomies` di una traccia dell'app — le quattro storiche (`activity`, `theme`,
      `poi-type`, `where`) e tutte le tassonomie di `taxonomy_terms` accese nell'app, per tracce o POI —
      con le sole traduzioni del nome (il tab è già su ogni termine del dettaglio), nello stesso
      ordine della sezione Nova (storiche prima, poi `sort_order`). I nomi delle storiche sono le stesse chiavi di traduzione di Nova
      (`label` di `legacy_taxonomies` per `activity`, `Themes`, `Poi Types`, `Zones`).
- [ ] `config.json` ha la sezione `TAXONOMY_TAB_LABELS`: per ogni tab che le tassonomie di `TAXONOMY_LABELS` usano, le sue
      traduzioni, prese dai file di lingua del package per le lingue di `wm-tab-translatable`
      (solo quelle tradotte; l'inglese ripiega sulla chiave). `General` per primo, come in Nova.
- [ ] Salvare o cancellare una tassonomia (`TaxonomyTerm` senza `parent_id`) in cui cambiano
      `name`, `properties` o `sort_order` rigenera il `config.json` di ogni app in cui è accesa
      (`UpdateAppConfigJob`, dopo il commit; il job è unico finché non parte e non gira in parallelo
      per la stessa app). Accendere o spegnere una tassonomia dalla scheda App fa accodare a
      `ReindexAppContentsJob` `UpdateAppConfigJob` e `RegenerateTaxonomyWhereOutputsJob` (dettagli,
      indice, `pois.geojson`). Un nome vuoto esce come `{}`.
- [ ] Test Pest nel package: forma di `taxonomies`, sezioni `TAXONOMY_LABELS`/`TAXONOMY_TAB_LABELS` e loro rigenerazione, rigenerazione del file dall'observer, tab di default, filtro per app, where filtrate,
      chiavi vecchie invariate, identifier riservati.

## Secondo pezzo: `taxonomies` nei POI di `pois.geojson`

Ogni feature di `pois.geojson` ottiene `properties.taxonomies`, con la stessa forma e le stesse
regole del dettaglio della traccia: storiche (`activity`, `theme`, `poi-type`, `where`) e tassonomie
di `taxonomy_terms` accese per l'app e per gli **EcPoi**, termini con `identifier`, `name`, `tab` e i
campi utili valorizzati. Le etichette sono già in `TAXONOMY_LABELS` / `TAXONOMY_TAB_LABELS`.

- [ ] La costruzione di `taxonomies` si sposta da `EcTrack` in una classe condivisa
      (`src/Services/DetailTaxonomiesService.php`), parametrizzata dal modello (`EcTrack`, `EcPoi`)
      e dall'app; `EcTrack::getDetailGeojson()` e `App::getAllPoisGeojson()` la usano.
      Costanti e helper delle storiche passano lì (niente `const` nei trait: PHP 8.1).
- [ ] In `App::getAllPoisGeojson()` ogni POI ha `properties.taxonomies` (`{}` se vuoto). Le
      tassonomie accese si filtrano con l'app che costruisce il file (in pratica quella del POI:
      `globalEcPois()` parte da `ecPois()`); le zone con lo stesso filtro di `taxonomy_where`.
      Le tassonomie accese si leggono una volta per file, non una per POI.
- [ ] `taxonomyIdentifiers` resta identico, segnato `@deprecated`.
- [ ] Accendere o spegnere una tassonomia rigenera anche `pois.geojson` (con
      `RegenerateTaxonomyWhereOutputsJob`). Un legame POI-termine lo rigenera già.
- [ ] Test: forma nei POI, filtro per `EcPoi`, `{}`, `taxonomyIdentifiers` invariato, rigenerazione
      al toggle. (Il caso «POI globale di un'altra app» non esiste: vedi sopra.)

## Rischi

- **Dove si costruisce `taxonomies`** (challenge, poi review): il dettaglio della traccia esce da tre
  strade — file su AWS (`EcTrackResource`), `/api/ec/track/{id}` quando il file manca, `multiple`.
  Prima decisione: `GeoJsonService` per i modelli con il metodo dedicato; superata perché
  `getGeojson()` gira anche nei job interni (DEM, pendenze, zone), che pagavano le query. Ora la
  costruisce `EcTrack::getDetailGeojson()`, usata dalle tre uscite. Il filtro «Località mostrate» si
  applica dopo, quindi `DetailTaxonomiesService` filtra da sé le zone con lo stesso servizio.
- **Peso del file**: le where su altri shard arrivano a centinaia (maphub ~490 righe in tabella),
  ma per traccia sono poche (4 nell'esempio di forestas); il `tab` ripetuto su ogni termine costa
  pochi byte.
- **Valori del `tab`**: sono chiavi di traduzione Nova (`Activities`, `Inclusivity`) o assenti;
  l'etichetta tradotta sta in `TAXONOMY_TAB_LABELS` del `config.json`. Il tab segue quello di Nova (challenge:
  con `activity` fissata a `General` attività e difficoltà sarebbero finite in tab diversi): un
  cambio di tab in Nova cambia anche il dettaglio, ed è voluto.
- **Shard che non hanno ancora le migration di oc:8910**: senza `taxonomy_terms` la query fallisce.
  La costruzione deve tollerare la relazione assente o la tabella mancante.
- **Il file su AWS si aggiorna solo quando gira `UpdateEcTrackAwsJob`**: per vedere `taxonomies`
  sulle tracce esistenti serve rigenerare i file (lancio a cura del team, in dev).
- **File su AWS vecchio dopo una modifica delle tassonomie** (challenge): oggi
  `TaxonomyTermableObserver` per le tracce fa solo `searchable()`
  (`src/Observers/TaxonomyTermableObserver.php:29-61`), quindi la lista del WordPress (Elastic)
  mostra il termine nuovo e il dettaglio (AWS) no. Mitigato accodando `UpdateEcTrackAwsJob`
  dall'observer. Restano scoperti, consapevolmente: rinomina o cancellazione di un termine, cambio di
  `tab` o di `identifier` di una tassonomia (si riscrive solo il `config.json`); lì dettagli e
  `pois.geojson` si rigenerano a mano.
- **`UpdateAppConfigJob` è condiviso da tutti gli shard** (review): passa a
  `ShouldBeUniqueUntilProcessing` con `WithoutOverlapping` per app; un'esecuzione che trova il lock
  si riaccoda dopo 10 s e consuma un tentativo.

## Out of scope

- Le etichette dentro il dettaglio della traccia (scartato: si ripeterebbero).

- Gli altri dati del dettaglio dell'artifact di analisi (credits delle foto, enti, allegati, video,
  dati tecnici, link GPX/KML).
- Roadbook spostato come in Cammini d'Italia, versioning dei file, mappa dei risultati (endpoint
  `multiple`), fix del GPX (oc:8953).
- Togliere `taxonomy`, `taxonomy_where` e `taxonomyWheres`: in questo pezzo diventano deprecate ma
  restano nell'output per retrocompatibilità. La rimozione avverrà quando tutti i frontend leggeranno
  `taxonomies`.
- Aggiornare il WordPress di produzione: si lavora in dev.

## Moduli toccati

Tutto in **wm-package**:

- `src/Services/DetailTaxonomiesService.php` (nuovo) — costruzione di `taxonomies`, storiche,
  tab, controllo delle tabelle di oc:8910.
- `src/Models/EcTrack.php` — `getDetailGeojson()`.
- `src/Http/Resources/EcTrackResource.php`, `src/Http/Controllers/Api/EcTrackController.php` —
  usano `getDetailGeojson()`.
- `src/Models/App.php` — `taxonomies` in `getAllPoisGeojson()`, `taxonomyIdentifiers` deprecato.
- `src/Services/GeoJsonService.php`, `src/Models/Abstracts/GeometryModel.php` — solo `@deprecated`.
- `src/Services/Models/App/AppConfigService.php` — `TAXONOMY_LABELS` e `TAXONOMY_TAB_LABELS`.
- `src/Models/TaxonomyTerm.php` — identifier riservati; rigenera il `config.json` quando cambia una
  tassonomia.
- `src/Observers/TaxonomyTermableObserver.php` — accoda `UpdateEcTrackAwsJob` per le tracce.
- `src/Jobs/App/ReindexAppContentsJob.php` — al toggle accoda `UpdateAppConfigJob` e
  `RegenerateTaxonomyWhereOutputsJob`.
- `src/Jobs/UpdateAppConfigJob.php` — unico fino all'avvio, `WithoutOverlapping`.
- `tests/` — test Pest; `tests/Pest.php` — `UpdateEcTrackAwsJob` nel fake globale.
- `docs/knowledge/tassonomie-nel-dettaglio-di-tracce-e-poi.md`, `CLAUDE.md` — pagina e riga
  d'indice.

**forestas**: nessuna modifica di codice in questo pezzo; al bump del submodule, i test che
attaccano termini o accendono tassonomie vanno protetti con `Bus::fake` (vedi notes.md).

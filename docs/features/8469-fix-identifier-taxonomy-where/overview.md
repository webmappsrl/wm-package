> Ticket: oc:8469

# Import TaxonomyWhere fallisce con "column identifier does not exist"

## Cosa cambia

Oggi qualsiasi import di aree da Nova (`Import TaxonomyWhere`, sia OSMFeatures che
OSM2CAI) si interrompe immediatamente con `SQLSTATE[42703]: column "identifier"
does not exist`. Dopo questa feature l'import funziona, e ogni `TaxonomyWhere`
possiede un `identifier` stabile e leggibile, derivato dalla sorgente del dato.

Nel dettaglio:

1. La tabella `taxonomy_wheres` acquisisce la colonna `identifier`
   (`text nullable unique`), allineandosi alle altre taxonomy.
2. La derivazione dell'identifier diventa **sovrascrivibile dal modello**:
   `TaxonomyObserver` chiama un metodo del modello invece di applicare sempre
   `Str::slug(nome)`. Il default resta invariato per tutte le altre taxonomy.
3. `TaxonomyWhere` sovrascrive la derivazione con la regola basata su `properties`:

   ```
   source === 'osmfeatures' → "{source}-{osmfeatures_id}"   es. osmfeatures-r276369
   source === 'osm2cai'     → "{source}-{osm2cai_id}"       es. osm2cai-142
   altrimenti (legacy)      → "{source}-{slug(name)}"       es. geohub-conf-32-area-f-nord
   creato a mano (Nova)     → "{source}-{slug(name)}"       es. forestas-area-nuova
                              dove source viene valorizzato alla creazione con
                              slug(config('app.name')) -> 'forestas'.
                              In caso di omonimia si aggiunge un contatore
                              progressivo: forestas-area-nuova-2, -3, ...

   L'identifier NON dipende dal nome quando esiste un id di sorgente: e' univoco
   per costruzione e immune alle correzioni upstream dei nomi.
   ```

4. L'identifier e' **stabile per costruzione**: derivando da `source` e dall'id
   della sorgente, ricalcolarlo produce sempre lo stesso valore. Il guard
   `if (empty($identifier))` di `TaxonomyObserver` resta quindi **invariato** —
   rimuoverlo avrebbe fatto ricalcolare l'identifier di `TaxonomyActivity` e
   `TaxonomyPoiType` a ogni rename, rompendo i riferimenti gia' persistiti nella
   config App (`src/Nova/App.php:923-941`).
5. Una collisione di identifier **non interrompe più l'import**: il record viene
   saltato e conteggiato, e il totale compare nel messaggio finale dell'action.
   Con la regola sopra la collisione e' impossibile sui record da import: resta
   come rete di sicurezza per i record legacy o creati a mano.
6. Corretto il confronto loose `if ($taxonomy->identifier != null)`
   (`TaxonomyObserver.php:23`): con identifier stringa vuota il confronto e'
   `false` (verificato), quindi il check di unicita' viene **saltato** e si
   scrive `''` in una colonna unique, producendo un 23505 grezzo al secondo
   record. Va usato un confronto strict.

## Perché

`Wm\WmPackage\Models\Abstracts\Taxonomy::boot()` registra `TaxonomyObserver` per
tutte le taxonomy, `TaxonomyWhere` inclusa. L'observer, in `creating()` e
`updating()`, genera lo slug `identifier` dal nome e ne verifica l'unicità con
`where('identifier', ...)`. La tabella `taxonomy_wheres` però non ha quella
colonna: lo stub crea solo `id`, `name`, `properties`, `geometry`, `timestamps`,
e il modello la esclude perfino da `$fillable`.

Il risultato è che l'import di aree è **completamente bloccato** — entrambe le
sorgenti, e anche il semplice update di un `TaxonomyWhere` esistente.

La derivazione dal solo nome, usata dalle altre taxonomy, non è applicabile qui:
i nomi arrivano da OSMFeatures e sono inaffidabili. Sui 15 record attualmente in
DB: due hanno il nome mancante e valorizzato con l'id della relation
(`R19622159`, `R19622158`) e uno arriva in coreano (`올리아스트라` = Ogliastra).
`Str::slug('올리아스트라')` restituisce stringa vuota — verificato in tinker —
quindi due nomi non latini produrrebbero lo stesso identifier vuoto e l'observer
abortirebbe l'import con `validationError()`.

## Requisiti

- [ ] L'action `Import TaxonomyWhere` completa senza errori SQL per tutte le
      sorgenti della tendina (L4, L6, L8, L9, L10, OSM2CAI)
- [ ] `taxonomy_wheres` ha la colonna `identifier`, `text nullable unique`,
      aggiunta da un nuovo stub in `wm-package` (non una migration locale)
- [ ] I 15 record già presenti ricevono l'identifier tramite backfill nella
      stessa migration, secondo la regola di derivazione
- [ ] La derivazione dell'identifier è un metodo sovrascrivibile sul modello;
      `TaxonomyObserver` non contiene rami condizionali per modello specifico
- [ ] Il comportamento delle altre taxonomy (activity, poi_type, theme, when,
      target) resta identico: derivazione da `Str::slug(nome)`
- [ ] `TaxonomyWhere` deriva l'identifier da `source` + id della sorgente, mai
      dal nome, quando un id di sorgente è disponibile
- [ ] I record creati a mano da Nova ricevono `properties['source']` valorizzato
      con `Str::slug(config('app.name'))` (es. `forestas`), così ogni record ha
      sempre una sorgente ed è filtrabile da `TaxonomyWhereSourceFilter`
- [ ] Tali record ricevono identifier `{source}-{slug(name)}`, con contatore
      progressivo (`-2`, `-3`, …) solo in caso di omonimia: l'identifier resta
      pulito quando non c'è conflitto, e tutto si risolve in `creating()` senza
      un secondo salvataggio
- [ ] Il guard `if (empty($identifier))` di `TaxonomyObserver` resta invariato:
      nessun ricalcolo forzato su update per le altre taxonomy
- [ ] Il confronto `if ($taxonomy->identifier != null)` diventa strict: con
      stringa vuota il loose compare è `false` e salta il check di unicità
- [ ] `identifier` NON viene aggiunto a `$fillable` di `TaxonomyWhere`: lo
      scrive l'observer, non il mass-assignment
- [ ] Il backfill dei record esistenti avviene in SQL puro, mai via Eloquent
      (`TaxonomyWhere` estende `Polygon`: risalvare farebbe transitare la
      geometria PostGIS attraverso l'ORM)
- [ ] Una collisione di identifier salta il singolo record e lo conteggia, senza
      interrompere l'import; il conteggio compare nel messaggio finale dell'action
- [ ] `AbstractObserver::saving()` continua ad applicarsi a `TaxonomyWhere`
      (sync di `properties['name']` dalle traduzioni)
- [ ] La nuova stringa utente passa da `__()` con chiavi in `en.json` e `it.json`
      di `wm-package` (default locale del repo: `en`)

## Rischi

Esito della Fase: challenge (subagente adversariale + verifiche sul codice).

**Rilievi accolti, che hanno cambiato il design:**

- **Collisioni = perdita di dato permanente.** Con la regola iniziale
  (`{source}-{admin_level}-{slug(name)}`) due quartieri omonimi a L10 collidono;
  lo skip li avrebbe esclusi dal DB per sempre, e i layer in auto mode
  (`ST_Intersects` su `taxonomy_wheres`) avrebbero prodotto mappe incomplete
  senza segnale. Mitigazione: l'identifier deriva dall'id di sorgente, univoco
  per costruzione — la collisione non è più possibile sui record da import.
- **Ricalcolo su update.** Rimuovere il guard `if (empty($identifier))`
  nell'observer condiviso avrebbe fatto ricalcolare l'identifier di
  `TaxonomyActivity` e `TaxonomyPoiType` a ogni rename, rompendo i riferimenti
  persistiti nella config App (`src/Nova/App.php:923-941`). Mitigazione: guard
  invariato; con la nuova regola il ricalcolo è comunque idempotente.
- **`Str::slug` sull'identifier composto.** Verificato:
  `Str::slug('geohub_conf_32-area-f-nord')` → `geohub-conf-32-area-f-nord`.
  Deterministico e accettato, non aggirato.
- **Confronto loose.** Verificato: `'' != null` è `false`, quindi un identifier
  vuoto salta il check di unicità e produce un 23505 grezzo. Corretto.
- **Backfill via Eloquent.** Rischio di corruzione delle geometrie PostGIS.
  Mitigazione: backfill in SQL puro.
- **Record senza `source`.** Il path non-import (form Nova) non era coperto.
  Mitigazione a livello di dato: alla creazione `properties['source']` viene
  valorizzato con il nome della piattaforma (`slug(config('app.name'))`), così
  nessun record resta senza sorgente; identifier `{source}-{slug(name)}` con
  contatore progressivo sulle omonimie.

**Rilievi verificati e respinti:**

- **Reindex Elasticsearch.** `getOrderedTaxonomyWheres()`
  (`src/Models/Abstracts/GeometryModel.php:212`) legge
  `properties['taxonomy_where']` e restituisce **solo i nomi**: l'identifier non
  entra né nel documento ES né nel GeoJSON. Nessun reindex necessario.
- **Mass-assignment.** Decade non aggiungendo `identifier` a `$fillable`.
- **Migration in due passi.** Il rischio di deploy bloccata su un altro consumer
  è teorico: `TaxonomyWhere` è usata solo in forestas e non esiste produzione;
  sugli altri progetti la tabella è vuota e il backfill è un no-op. Migration
  unica.

**Rischi residui accettati:**

- **Impatto multi-progetto.** La modifica vive in `wm-package`, condiviso con
  camminiditalia e altri. Mitigazione: il default della derivazione resta
  invariato per tutte le taxonomy diverse da `TaxonomyWhere`.
- **Migration non pubblicata.** I progetti che aggiornano il package senza
  eseguire `vendor:publish --tag=wm-package-migrations --force` + `migrate`
  ricadrebbero nello stesso `SQLSTATE[42703]`. Mitigazione: derivazione
  difensiva (skip se la colonna non esiste) + nota nel changelog.
- **TOCTOU.** `where('identifier',...)->first()` + insert non è atomico: due
  import concorrenti possono collidere a livello DB. Accettato: l'action è
  manuale e non concorrente.
- **Submodule pointer.** La fix vive su `wm-package@develop`; forestas resta
  rotto finché il pointer non viene bumpato. Step esplicito del piano.
- **Gate CI.** `WmPackagePublishMissingMigrationsCommand` segnalerà la nuova
  migration come non pubblicata sui consumer. Step esplicito del piano.
- **Verifica empirica.** Import di tutti i livelli (L4, L6, L8, L9, L10) in
  locale per confermare che nessun record venga saltato.

## Ampliamento di scope accolto in corso d'opera

Durante la verifica empirica e' emerso un secondo bug, distinto dall'identifier
ma sulla stessa rotta di import, e il dev ha scelto di includerlo qui:
`OsmfeaturesClient::getAdminAreasIds()` ripiegava su `reset($nameObj)`, cioe'
sulla prima lingua disponibile, salvando ad esempio `올리아스트라` (Ogliastra in
coreano) sotto la chiave `it`. Il tag `name` base di OSM non e' esposto
dall'endpoint `list`, ma e' presente nel dettaglio che
`FetchTaxonomyWhereGeometryJob` scarica gia' per la geometria.

- [x] Senza `it`/`en` il client restituisce `null` invece della prima lingua
- [x] L'action salva l'id come segnaposto quando il nome e' `null`
- [x] Il job sostituisce il segnaposto con `properties.name` del dettaglio, senza
      chiamate HTTP aggiuntive, e non sovrascrive mai un `it`/`en` affidabile

## Esito della verifica empirica (Task 5)

Import reale eseguito su tutte le sorgenti della tendina:

| Verifica | Risultato |
|---|---|
| Record importati (L4, L6, L8, L9, L10) | 427 |
| Identifier distinti | 427 — **zero collisioni** |
| Identifier mancanti | 0 |
| Nomi segnaposto (`R…`) | 0 (erano 40) |
| Nomi in lingua errata | 0 (erano 18) |

Il rischio "collisioni ai livelli bassi" dichiarato in Fase: challenge non si e'
materializzato: la derivazione dall'id di sorgente lo elimina per costruzione.

## Out of scope

- Traduzione dei messaggi già esistenti di `ImportTaxonomyWhere`, oggi hardcoded
  in italiano e non passanti da `__()`
- Correzione dei nomi sporchi provenienti da OSMFeatures (`R19622159`,
  `올리아스트라`): l'identifier li gestisce, il nome resta quello della sorgente
- Introduzione di un consumer dell'identifier di `TaxonomyWhere` in API, config
  o export: oggi nessuno lo legge, la colonna nasce per coerenza e per il futuro
- Estensione della stessa regola a `taxonomy_when` e `taxonomy_target`

## Moduli toccati

Tutti in **`wm-package`** (submodule condiviso), branch di lavoro **`develop`**.
Nessuna modifica al repo `forestas`, che è solo il progetto in cui il bug si
manifesta. Analisi rivalidata su `develop` (32 commit avanti rispetto al commit
agganciato da forestas): bug e file coinvolti identici.

| File | Tipo |
|---|---|
| `database/migrations/zz_..._add_identifier_to_taxonomy_wheres_table.php.stub` | nuovo |
| `src/Observers/TaxonomyObserver.php` | modificato |
| `src/Models/Abstracts/Taxonomy.php` | modificato (derivazione default sovrascrivibile) |
| `src/Models/TaxonomyWhere.php` | modificato (override derivazione + `$fillable`) |
| `src/Nova/Actions/ImportTaxonomyWhere.php` | modificato (skip + contatore collisioni) |
| `src/Http/Clients/OsmfeaturesClient.php` | modificato (nome: niente fallback sulla prima lingua) |
| `src/Jobs/TaxonomyWhere/FetchTaxonomyWhereGeometryJob.php` | modificato (nome dal tag base del dettaglio) |
| `resources/lang/en.json`, `resources/lang/it.json` | modificati |
| `tests/` | nuovi test |

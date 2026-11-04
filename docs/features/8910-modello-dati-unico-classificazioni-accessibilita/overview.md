> Ticket: oc:8910

# Modello dati unico per classificazioni e accessibilità — parte nel `wm-package`

> La parte di Forestas — tassonomie, termini, import da Drupal, colonne custom — è in
> `docs/features/8910-modello-dati-unico-classificazioni-accessibilita/overview.md` del repo
> `forestas`.

## Cosa cambia

Oggi ogni classificazione ha la sua tabella e il suo pivot: `taxonomy_activities`,
`taxonomy_themes`, `taxonomy_wheres`, `taxonomy_targets`, `taxonomy_poi_types`, più
`taxonomy_warnings` su Forestas. Le tabelle hanno le stesse colonne e i pivot sono identici a meno
di `duration_forward`/`duration_backward` su `taxonomy_activityables`. Una classificazione nuova
costa due tabelle e una migration.

Dopo questo lavoro il package ha **due tabelle in più** che accolgono qualunque classificazione
nuova senza altre migration:

- `taxonomy_terms` — tassonomie e termini nella stessa tabella, distinti da `parent_id`: nullo vuol
  dire che la riga è una tassonomia, valorizzato che è un termine dentro quella tassonomia;
- `taxonomy_termables` — la relazione polimorfa verso `EcTrack`, `EcPoi` e gli altri.

Le cinque tabelle esistenti **restano dove sono** e continuano a funzionare: il modello nuovo
nasce accanto e accoglie solo classificazioni nuove.

Nel package **non si rimuove nulla** e non entra nulla dell'inclusività: il modello nuovo nasce
accanto a quello che c'è, e i dati del Catasto sardo vivono nel repo di Forestas.

## Perché

Due ragioni, una di oggi e una di domani.

Quella di oggi: la call con Forestas del 29/10/2026 apre in un colpo solo otto tassonomie di
classificazione — cinque scale di difficoltà (escursionistica, mountain bike, ippovia, Nordic
Walking, mobilità dolce), il grado di accessibilità, le disabilità accolte, gli itinerari e
cammini — più tipo percorso e tematismi da definire. Con il modello attuale sono venti tabelle.

Quella di domani: alcune di queste classificazioni non sono attività e non hanno dove stare.
«Itinerari e cammini» oggi è infilato dentro `taxonomy_activities` come
`sardegnasentieri:type:sentiero` e `sardegnasentieri:type:itinerario`, due delle nove righe di una
tabella che dovrebbe contenere attività. E la difficoltà, che Forestas ha diversa per ogni modo di
fruizione, oggi è `properties->difficulty`: un campo di testo unico, che una difficoltà per
attività non la sa rappresentare.

Che il bisogno sia reale lo dicono i dati della sorgente: su 755 nodi Drupal con una difficoltà,
90 ne hanno una in due tassonomie diverse e 12 in tre.

## Requisiti

- [ ] Esiste la tabella `taxonomy_terms`:

      | colonna | tipo | note |
      |---|---|---|
      | `id` | bigserial | |
      | `parent_id` | bigint, nullable, FK su sé stessa | nullo = è una tassonomia |
      | `type` | string, nullable | dice **chi interpreta il `properties` della relazione**: una tassonomia `activity` sa che lì dentro stanno le durate, una tassonomia di difficoltà non ha `type` e non ha nulla da interpretare |
      | `identifier` | string, **not null**, unique | il codice ci si aggancia (`terms('hiking-difficulty')`, l'import, la config): una tassonomia senza identifier sarebbe invisibile |
      | `name`, `description`, `excerpt` | jsonb | traducibili con lo stesso trait delle tassonomie esistenti (`HasTranslations`, `src/Models/Abstracts/Taxonomy.php:17`) |
      | `icon` | string, nullable | le cinque tassonomie esistenti l'hanno già (`zz_2025_11_05_134829_add_icon_column_to_taxonomy_tables.php.stub`) |
      | `sort_order` | smallint, not null, default 0 | le scale sono ordinali (T < E < EE < EEA): senza, l'ordine dipende dall'`id`, cioè da come è girato il seeder. `sort_order` e non `order`, riservata in SQL |
      | `single_choice` | boolean, not null, default false | solo sulle radici: di questo tassonomia se ne sceglie una sola. Lo legge Nova per mostrare una select singola invece che multipla, e l'import per scrivere una relazione solo |
      | `properties` | jsonb, nullable | |
      | timestamps | | |

- [ ] Esiste la tabella `taxonomy_termables`:

      | colonna | tipo | note |
      |---|---|---|
      | `id` | bigserial | |
      | `taxonomy_term_id` | bigint, FK | |
      | `parent_id` | bigint, nullable | la tassonomia, copiata dal termine al salvataggio e non scrivibile a mano: serve a raggruppare per tassonomia senza una join |
      | `termable_type`, `termable_id` | morphs | |
      | `properties` | jsonb, nullable | ciò che quella tassonomia tiene sulla relazione, interpretato secondo il `type` |

      Niente timestamps, come gli altri pivot. **Niente `duration_forward` / `duration_backward`
      come colonne**: sono l'unico esempio esistente di campo specifico sulla relazione e non sono
      usate — su Forestas `taxonomy_activityables` ha 1805 righe e zero durate valorizzate, e il
      codice che le leggeva dal pivot è commentato (`src/Models/EcTrack.php:390`). Le durate che
      il sistema usa stanno in `manual_data` e `dem_data` del sentiero. Se serviranno sulla relazione,
      staranno in `properties`.

- [ ] **Nessun vincolo di unicità nel database.** «Di questo tassonomia se ne sceglie una sola» è
      una regola di business, non un invariante dello schema: il cliente l'aveva scritta al
      contrario e l'ha cambiata in call il 29/10. La fanno rispettare Nova, che per una tassonomia
      `single_choice` mostra una select singola, e l'import, che scrive una relazione solo. Un indice
      unique parziale non sarebbe nemmeno esprimibile: il predicato non può leggere la tabella
      delle tassonomie (PostgreSQL risponde `cannot use subquery in index predicate`, verificato).
- [ ] Il modello `TaxonomyTerm` espone `children()`, `parent()` e la relazione polimorfa verso i
      contenuti.
- [ ] I modelli che usano le tassonomie espongono **un solo metodo generico** per leggere i
      termini di una tassonomia: `terms('<family-identifier>')` per la lista,
      `term('<family-identifier>')` per la voce sola o `null`. Nessun metodo dedicato per
      tassonomia.
- [ ] **Identificatori in inglese**, come vuole la convenzione del repo: i nomi visibili sono in
      italiano e traducibili, ma `identifier`, nomi di colonna, di metodo e di chiave restano in
      inglese.
- [ ] Il pivot è un `MorphPivot` con observer, sul modello di `TaxonomyActivityable`, così che il
      salvataggio di una relazione faccia reindicizzare il contenuto su Scout.
- [ ] `toSearchableArray()` di `EcTrack` indicizza i termini raggruppati per tassonomia, **con il
      `properties` della relazione**: è da Elasticsearch che si leggono questi dati, non da SQL, quindi
      ciò che sta nel jsonb della relazione deve arrivare nell'indice o è irraggiungibile.
      Il `properties` della relazione arriva nel documento ma **non è indicizzato**: una dynamic
      template lo dichiara `enabled: false`, quindi si legge da `_source` e non si filtra. Il
      motivo è che le sue chiavi sono libere per costruzione, e due tipi diversi sulla stessa
      chiave farebbero **rifiutare il documento** da Elasticsearch — con `scout.queue` a false
      l'eccezione la cattura l'observer e finisce solo nel log, lasciando il contenuto con
      l'indice vecchio senza che nessuno se ne accorga. Per filtrare su una di quelle chiavi la
      si dichiara nel mapping (per esempio `long` per una durata) e si rilancia `scout:import`.
- [ ] L'observer sul pivot fa reindicizzare anche quando cambia il solo `properties` della relazione,
      senza che il contenuto venga salvato: Scout reagisce al salvataggio del modello, e una
      riga di pivot non lo tocca.
- [ ] Esiste una Resource Nova `TaxonomyTerm` da cui si creano tassonomie e termini.
- [ ] Sul dettaglio di un contenuto i termini si scelgono con **una select per tassonomia**, non
      con i pannelli `MorphToMany` usati oggi: `Multiselect` di Outl1ne per le tassonomie normali,
      `Select` a scelta singola per quelle con `single_choice`. Mai il `MultiSelect` nativo di
      Nova, che è la `<select multiple>` del browser (oc:8588).
- [ ] **L'inclusività non entra nel package.** I tre interi dell'Allegato H, i due testi liberi,
      il punteggio derivato e le soglie delle classi A1-A6 nascono dal regolamento tecnico della
      Regione Sardegna: sono dati di Forestas e vivono nel suo repo, non qui. Il package mette a
      disposizione il modello generale — tassonomie, termini, relazione polimorfa — e nient'altro.
- [ ] Il tab «Accessibility» condiviso **non si tocca**: `getAccessibilityTabFields()` resta
      com'è, con le diciotto chiavi `access_*`. È montato anche dagli `EcPoi` di Forestas
      (`app/Nova/EcPoi.php:62`), e nel package nessuno lo monta: toglierlo da lì colpirebbe i POI,
      che questo lavoro non copre. L'accessibilità generica del package verrà rifatta per conto
      suo, ed è il motivo per cui tutti i nomi di questo lavoro portano `inclusivity` e lasciano
      libero il namespace `access*`.
- [ ] `properties->difficulty` **resta dov'è** e non esce dal modello. Su Forestas è vuoto su
      tutte e 780 le tracce, ma il package è montato da shard che importano da GeoHub, dove
      `difficulty` e le diciotto `access_*` sono mappate e scritte
      (`config/wm-geohub-import.php:388` e `480-493`): toglierle dal modello le farebbe scartare
      in silenzio dal mass assignment, senza nessun errore. La tassonomia
      `hiking-difficulty` nasce accanto, e spegnere la chiave vecchia è un lavoro suo, da fare
      quando si deciderà di migrare anche le tassonomie esistenti.
- [ ] Gli stub delle migration nuove stanno nella root di `database/migrations/`, non in
      `trail_registry/`: le classificazioni sono materia del package, non del dominio opzionale
      Catasto Sentieri.
- [ ] Il gate `publish-missing-migrations` in CI passa.

## Rischi

- **La gerarchia è a due livelli per accordo, non per vincolo.** Niente impedisce da Nova di
  creare un termine figlio di un termine: in quel caso `parent_id` sulla relazione non è più «la
  tassonomia», il raggruppamento in Elasticsearch si spacca e la regola di scelta singola si
  applica al livello sbagliato. Si chiude con un controllo in validazione: il padre di un termine
  può essere solo una riga con `parent_id` nullo.
- **`terms('<family>')` non è eager-loadable come una relazione dichiarata.** `with('taxonomyActivities')`
  oggi funziona; un metodo parametrico no, e su una lista Nova o un export di 780 tracce con otto
  tassonomie diventano migliaia di query. Si mitiga caricando una sola relazione polimorfa con i
  termini e raggruppando in PHP, invece di una query per tassonomia.
- **Un termine senza `identifier` sarebbe invisibile al codice**, che si aggancia proprio a
  quello. Per questo la colonna è `not null` e unique, non nullable come nelle tassonomie
  esistenti.
- **Una tassonomia nuova creata da Nova genera una chiave nuova nell'indice Elasticsearch.** Con
  undicle tassonomie non è un problema (`mapping.total_fields.limit` è 2000), ma il mapping va
  dichiarato e un mapping cambiato non si applica con un reindex normale: serve `scout:import`.

## Out of scope

- **Migrare le cinque tabelle di tassonomia esistenti.** Il modello nuovo nasce accanto; assorbire
  le vecchie è una decisione successiva, da prendere quando avrà dimostrato di reggere. Il package
  è montato da osm2cai2, ersaf, maphub e dagli altri: migrare significherebbe toccare dati e
  codice su tutti insieme.
- **`taxonomy_wheres`.** Ha una colonna geografica e il Catasto la usa per lavoro geografico vero,
  con `ST_Intersects`; è il meccanismo da cui dipende la numerazione dei sentieri. Non è una
  classificazione.
- **Togliere qualunque cosa dal package.** Le diciotto `access_*` e `properties->difficulty`
  restano: sono mappate dall'import GeoHub e vivono sugli shard che importano da lì. Far
  convergere la vecchia accessibilità nel modello nuovo è un ticket a parte.
- **L'inclusività.** Tre interi, due testi, punteggio, classi A1-A6 e relative soglie sono dati
  di Forestas e stanno nel suo repo.
- **La check-list dell'Allegato H.** I circa trenta parametri con i loro punteggi parziali non
  entrano nel sistema: li compila Forestas a mano in fase di accatastamento e arrivano già
  sommati nei tre interi. Non c'è nessun algoritmo di calcolo da scrivere.
- **I wireframe del blocco «Sentieri per tutti»** (quattro barre a sei tacche, classe scritta per
  esteso): sono oc:8908.

## Moduli toccati

| File | Cosa |
|---|---|
| `database/migrations/zz_<data>_create_taxonomy_terms_table.php.stub` | nuovo |
| `database/migrations/zz_<data>_create_taxonomy_termables_table.php.stub` | nuovo |
| `src/Models/TaxonomyTerm.php` | nuovo |
| `src/Models/TaxonomyTermable.php` | nuovo, `MorphPivot` con observer |
| `src/Observers/TaxonomyTermableObserver.php` | nuovo |
| `src/Traits/TaxonomyAbleModel.php` | aggiunge `terms()` e `term()` |
| `src/Models/EcTrack.php` | solo `toSearchableArray()`: i termini raggruppati per tassonomia |
| `src/Nova/TaxonomyTerm.php` | nuova Resource |
| `src/Nova/AbstractEcResource.php` | solo le select per tassonomia; `getAccessibilityTabFields()` non si tocca |
| `config/wm-package.php` | nulla di inclusività: solo ciò che serve al modello dei termini |
| `docs/resources/` | documentazione d'uso del modello |

---

## Aggiornamento del 3 novembre — cosa è cambiato rispetto a sopra

Quanto sta sopra descrive il modello dati; questo lavoro è poi cresciuto in tre direzioni decise
in corsa con il dev e nella call con Alessio Piccioli. Le righe qui sotto **prevalgono** su quelle
sopra dove le contraddicono.

### Si chiama tassonomia

Durante il lavoro il concetto è passato da «famiglia» a «vocabolario» a **«tassonomia»**: è il
termine che in Webmapp si è sempre usato, in italiano e nel codice. Il testo sopra è stato
riscritto di conseguenza. Nel codice: `TaxonomyTerm::scopeTaxonomies()`, `App::taxonomies()`,
`AbstractTaxonomyTermsResource`, `TermsByTaxonomyFilter`. «Vocabolario» resta solo dove indica il
vocabolario di Drupal, che è un'altra cosa.

### Vincoli nel database

- [x] `taxonomy_termables` ha un unique su `(taxonomy_term_id, termable_type, termable_id)`: la
      garanzia stava nel codice dell'import, ora la dà il database a chiunque scriva. **Annulla il
      requisito «nessun vincolo di unicità nel database»** scritto sopra, che riguardava un'altra
      cosa — la scelta singola dentro una tassonomia, che resta senza vincolo perché non è
      esprimibile come indice.
- [x] Un trigger (`taxonomy_terms_two_levels`) impedisce che un termine diventi padre di un altro
      termine. **Chiude il rischio «la gerarchia è a due livelli per accordo, non per vincolo»**:
      ora è un vincolo. Un `CHECK` non bastava, non può leggere un'altra riga.
- [x] `is_system` (boolean) sostituisce la colonna `type`: distingue le tassonomie che vivono nel
      package da quelle create su uno shard.

Entrambi i vincoli sono nella `create` delle tabelle, non in una migration aggiuntiva: il modello
non è ancora su UAT.

### Le tassonomie si legano alle App

- [x] Nuova tabella `app_taxonomy_term` e relazione `App::taxonomies()`: ogni App dichiara quali
      tassonomie usa. Alla creazione di un'App, `AppObserver` **accende tutte le tassonomie di
      sistema**; le custom restano spente. Un'app nuova è utilizzabile senza configurazione, e
      l'elenco serve a togliere, non ad aggiungere.
- [x] Si governa dalla scheda App in Nova, non da quella della tassonomia.

### Un solo modo di inserire una classificazione

Il requisito di sopra diceva «una select per tassonomia, `Multiselect` di Outl1ne». È diventato
qualcosa di più:

- [x] Il campo è `Wm\WmPackage\Nova\Fields\Choice` (componente `icon-select`), che mostra l'icona
      accanto a ogni voce. `IconSelect` ora lo estende.
- [x] `Choice::visibleWhen()` applica insieme `dependsOn` di Nova **e** la regola di validazione
      lato server: il campo si nasconde e un POST diretto viene comunque rifiutato.
- [x] Le **dipendenze fra tassonomie stanno nei dati**, in `properties->depends_on` della riga
      della tassonomia, non nel codice di uno shard: «la difficoltà mountain bike si vede solo se
      fra le attività c'è mountain bike» vale per qualunque coppia e qualunque shard.
- [x] Ogni tassonomia dichiara **in quale tab va**, in `properties->tab`: non è più il codice
      della Resource a deciderlo.
- [x] Il trait `src/Nova/Concerns/BuildsTaxonomyFields.php` costruisce la sezione a tab a partire
      da questi dati. Lo usano sia le Resource EC sia il Layer.
- [x] **Le tassonomie storiche si presentano come le altre.** `config/wm-package.php` ha
      `legacy_taxonomies`, che per ognuna dichiara relazione, modello, etichetta, tab, scelta
      singola e se è nascosta: le Attività compaiono nella stessa sezione, con la stessa icona,
      pur essendo ancora `taxonomy_activities`. La convergenza dei modelli è un lavoro successivo:
      prima l'interfaccia unica, poi i dati.

### Creare una vista per tassonomia non costa passaggi manuali

- [x] `AbstractTaxonomyTermsResource` è la classe base di una Resource che mostra i soli termini
      di una tassonomia.
- [x] `wm:make-taxonomy-resource <identifier>` genera quella classe.
- [x] `TaxonomyTermsMenu` mette da sé la voce nel menu: non si tocca il `NovaServiceProvider`.

### I Layer si filtrano per tassonomia

- [x] La scheda di un Layer ha la sezione «Filtri» con **le stesse tab** della scheda EC track, al
      posto del pannello `MorphToMany` delle attività. `Layer::taxonomyTerms()` è la relazione.
- [x] La regola è **OR fra i termini della stessa tassonomia, AND fra tassonomie diverse** —
      «(sentiero o cammino) e (difficoltà BC)» — ed è la stessa che già valeva fra attività e
      zone. In `LayerService` è una sottoquery per tassonomia, non un `whereIn` unico.
- [x] Al cambio dei filtri, `SyncAutoLayerAfterTrackTaxonomyChangeJob` reindicizza le tracce che
      **entrano e quelle che escono**: `properties.layers` delle uscite va corretto, altrimenti la
      mappa e l'elenco restano con il contenuto vecchio.
- [x] Le zone geografiche restano fuori dalla sezione: su maphub sono circa 490 righe e un campo a
      scelta multipla con quei numeri non è usabile.

### PostHog non configurato: la feature non si vede

Trovato mentre la mappa di un layer restava vuota. `AnalyticsService::__construct` faceva
`rtrim(null)` e andava in TypeError, che buttava giù l'intero GeoJSON della mappa.

- [x] Il costruttore fa il cast della config, e `isConfigured()` dice se PostHog c'è.
- [x] La legenda «Posizione utente» compare **solo se** è configurato (`showLiveUserPositions`),
      e `getRecentUserPositions()` esce subito altrimenti.

### Cosa resta da fare prima del merge

- **Build di due componenti Vue**, non eseguibile sulla macchina di sviluppo senza
  `laravel-nova-devtool` dal repo privato Nova: `FeatureCollectionMap` (legenda condizionata).
  Quella di `Choice` è stata fatta il 4 novembre — istruzioni in
  `src/Nova/Fields/Choice/README.md`.
- **Cosa controllare su camminiditalia e maphub** prima del bump:
  [docs/knowledge/8910-tassonomie-cosa-controllare-al-bump-dello-shard.md](../../knowledge/8910-tassonomie-cosa-controllare-al-bump-dello-shard.md).

### Moduli toccati, in aggiunta a quelli sopra

| File | Cosa |
|---|---|
| `database/migrations/zz_2026_11_03_000003_create_app_taxonomy_term_table.php.stub` | nuovo |
| `src/Nova/Concerns/BuildsTaxonomyFields.php` | nuovo: la sezione a tab, usata da EC e Layer |
| `src/Nova/Fields/Choice/` | nuovo campo con icona: classe, provider, sorgenti Vue e `dist/`, tutto nella stessa cartella |
| `src/Nova/AbstractTaxonomyTermsResource.php` | nuovo: vista per tassonomia |
| `src/Nova/Menu/TaxonomyTermsMenu.php` | nuovo: voci di menu automatiche |
| `src/Commands/WmMakeTaxonomyResourceCommand.php` | nuovo: `wm:make-taxonomy-resource` |
| `src/Nova/Filters/TermsByTaxonomyFilter.php`, `TaxonomyTermKindFilter.php` | nuovi |
| `src/Nova/Layer.php`, `src/Services/Models/LayerService.php`, `src/Observers/LayerObserver.php` | filtri per termine e loro effetti |
| `src/Jobs/Layer/SyncAutoLayerAfterTrackTaxonomyChangeJob.php` | reindicizza entrate e uscite |
| `src/Observers/AppObserver.php` | accende le tassonomie di sistema su un'App nuova |
| `src/Services/PostHog/AnalyticsService.php` | `isConfigured()` e cast della config |
| `config/wm-package.php` | `legacy_taxonomies` |

---

## Aggiornamento del 4 novembre — cosa è cambiato rispetto a sopra

Le righe qui sotto **prevalgono** su quelle sopra dove le contraddicono.

### Le zone entrano nella sezione, dopo tutto

Sopra c'è scritto che «le zone geografiche restano fuori dalla sezione: su maphub sono circa 490
righe e un campo a scelta multipla con quei numeri non è usabile». La motivazione è caduta: il
campo `icon-select` filtra man mano che si scrive, quindi il numero di opzioni non è un problema
d'uso. Resta solo il costo di serializzarle tutte nella pagina.

- [x] Su un **Layer** le zone sono un filtro scrivibile a scelta multipla, dichiarato in
      `legacyTaxonomies()` accanto alle attività: entrambe vivono in una tabella propria e il
      meccanismo delle legacy serve esattamente a farle comparire come le altre tassonomie.
      `Layer::legacyTaxonomies()` aggiunge la voce `wheres` a quelle del trait, con l'alias
      `packageLegacyTaxonomies`.
- [x] Su un **contenuto** sono in **sola lettura**, perché non si scelgono: le calcola la geometria.
      `makeComputedWheresField()` le mostra come testo, leggendole da `properties.taxonomy_where`
      — dove l'Action le scrive con `jsonb_set`, già tradotte, con `_admin_level` e `_source` e
      senza id. Il pivot `taxonomy_whereables` per le EC e gli UGC oggi è di fatto vuoto.
- [x] Il vecchio pannello `MorphToMany::make('Taxonomy Where', …)`, che stava fuori dalla sezione,
      è stato tolto.
- [x] Che il dato di un contenuto viva in un JSON denormalizzato invece che nel pivot è un debito
      a sé: **oc:8920**, «TaxonomyWhere per UGC ed EC su `taxonomy_whereables` invece che in
      `properties.taxonomy_where`».

### L'etichetta della sezione

- [x] Il default era «Classifications». Ora è «Tassonomie» (`Taxonomies`), con la voce nei JSON
      del package.

### Correzioni trovate collaudando

- [x] `LayerService::assignPoisByTaxonomy()`: il blocco dei termini usava `$trackTable` e
      `$trackMorphTypes`, le variabili del ramo delle tracce. Ogni salvataggio delle zone di un
      Layer moriva con «Undefined variable $trackTable», perché ci passa
      `TaxonomyWhereablesObserver`.
- [x] I quattro job unici sul percorso del salvataggio Nova — `UpdateLayerGeometryJob`,
      `SyncAutoLayerAfterTrackTaxonomyChangeJob`, `SyncAutoLayerAfterPoiTaxonomyChangeJob`,
      `ReindexEcTrackSearchableJob` — prendevano il lock di unicità su `cache_locks`, cioè sul
      database, **dentro** la transazione del salvataggio: la seconda volta la transazione era già
      abortita e usciva `SQLSTATE[25P02]`. Il `catch` che c'era in `dispatchDebounced()` non
      bastava. Ora `uniqueVia()` restituisce `Cache::store('redis')`.
- [x] `LayerableObserver` non reggeva una riga di pivot il cui contenuto o layer era stato
      cancellato — la relazione sopravvive a ciò che collega — e moriva con un `TypeError`
      lasciando il ricalcolo a metà. Ora esce prima.
- [x] `Layer::taxonomyTerms()` era un override nudo, senza `using(TaxonomyTermable::class)`: il
      `saving` del pivot non scattava e `parent_id` restava nullo, quindi il filtro per famiglia
      non vedeva quelle righe. L'override è stato tolto e vale quello di `TaxonomyAbleModel`.

### Test del package: come si lanciano, e due cose che mancavano

- [x] Vanno lanciati **dal package**, non da uno shard, anche se girano nel container dello shard:
      usano `Wm\WmPackage\Tests\TestCase`.
- [x] `phpunit.xml.dist` dichiara `EC_TRACK_MODEL`, senza la quale la suite non risolve
      `App\Models\EcTrack` e i test dei layer falliscono con `Class not found`.
- [x] In `tests/Pest.php`, `class_alias` anche per `App\Nova\User`, e
      `seedVocabularyTermAndTrack()` accende la tassonomia sull'app della traccia: l'indice porta
      solo le tassonomie accese, quindi senza quello `termsForSearch()` esce vuoto.

---

## Aggiornamento del 4 novembre, sera — cosa è cambiato rispetto a sopra

Le righe qui sotto **prevalgono** su quelle sopra dove le contraddicono.

### Una tassonomia dichiara a quali contenuti si applica

Ribalta il modo in cui le tassonomie arrivavano sulle schede. Prima la sezione la componeva lo
shard, e quali tassonomie comparissero dipendeva solo dall'accensione per app.

- [x] **La sezione la compone il package**, in `EcTrack::fields()` e `EcPoi::fields()`, sopra i
      Blocchi Dettaglio: è codice che vale per ogni shard, e lo shard la eredita invece di
      inserirla. `taxonomySectionFields()` la restituisce come array, vuoto se non c'è niente da
      mostrare; `keepOnlyTaxonomySection()` serve a chi sovrascrive `fields()` e scarta i
      `TabsGroup` ereditati, che altrimenti porterebbe via anche questa.
- [x] **L'accensione per app dice *dove* una tassonomia esiste, non *a cosa* si applica.** Senza
      la seconda domanda, portando la sezione sui POI, «Difficoltà di accessibilità» sarebbe
      comparsa sulla scheda di un POI. La risposta sta sul pivot, `app_taxonomy_term.models`, e
      non sulla tassonomia: la stessa tassonomia, condivisa fra app, può valere sulle EC Track di
      una e sugli EC Poi di un'altra. `null` vuol dire tutti i contenuti.
- [x] Si sceglie dalla scheda dell'App, tab «Tassonomie», con due elenchi: «Tassonomie EC Track»
      e «Tassonomie EC Poi». Scrivono la stessa tabella, quindi passano da
      `App::syncTaxonomiesForModel()` invece di fare un `sync()` per campo, che si
      cancellerebbero a vicenda.
- [x] Sui **Layer** l'ambito non si applica: lì le tassonomie sono filtri che scelgono i
      contenuti, e un layer può raccogliere sia tracce sia POI.
- [x] Su un contenuto **in creazione**, che non ha ancora un'app, non si guarda quale app ma
      l'ambito vale lo stesso. Una tassonomia che nessuna app accende resta visibile, così il
      form di un'app nuova non nasce vuoto.
- [x] Su Forestas tutte e undici sono accese sulle sole EC Track.

### Cambiare le accensioni fa reindicizzare

- [x] `terms`, nel documento di una traccia, porta solo le tassonomie accese su quell'app:
      accenderne o spegnerne una cambia ogni documento senza che nessun contenuto venga salvato,
      e **nessuno reindicizzava**. L'indice restava indietro fino al prossimo salvataggio di
      ciascuna traccia. Ora c'è `ReindexAppContentsJob`, unico per app e `afterCommit`.
- [x] Il job porta con sé la firma delle accensioni di prima e la riconfronta quando gira: i due
      campi del tab scrivono uno dopo l'altro, e il primo lascia uno stato intermedio che sembra
      un cambiamento. Senza, un salvataggio qualunque della scheda App avrebbe reindicizzato
      tutto.
- [x] `enableTaxonomiesForModel()`, che usano i comandi di allineamento, non accoda: l'import
      reindicizza a fine batch.

### Il campo `Choice` è compilato

- [x] La build era data per non eseguibile: le credenziali di Nova sono in `auth.json`, e gli
      ostacoli erano il PHP 7.4 dell'host (si compila nel container) e `laravel/nova` 5.11.1, che
      la licenza non copre — il `composer.json` del campo vincola la 5.7.6.
- [x] Il campo sta tutto in `src/Nova/Fields/Choice/`, chiamata come le altre cartelle: il nome
      del campo, senza suffisso. Nessuna voce PSR-4, perché cartella e namespace combaciano. Il
      motivo per cui il provider stava fuori era sbagliato.
- [x] `IconSelect`, che estende `Choice` e non dichiara un componente suo, usa il bundle nuovo.

### Salvare una tassonomia da Nova andava in errore

- [x] `TaxonomyTerm::FAMILY_IDENTIFIER_FORMAT` non esiste — la costante si chiama
      `TAXONOMY_IDENTIFIER_FORMAT` — e il riferimento era rimasto da una rinomina. Scattava nella
      validazione dell'identifier, cioè a **ogni** salvataggio di una tassonomia dalla scheda.
- [x] I test non lo prendevano perché estraggono le `rules` e le danno a `Validator`: quella
      strada non costruisce la Resource come fa Nova. Ora ogni Resource toccata ha un test che
      fa la richiesta del browser, e quelle del package stanno qui, non nello shard.

### Elasticsearch

- [x] Le due dynamic template del campo `terms` erano già nel config: mancava applicarle, e un
      mapping cambiato vuole `scout:import`. Fatto su forestas, `terms.*.identifier` è passato da
      `text` a `keyword`. **Aggiungere una tassonomia non richiede `scout:import`**: il campo
      nasce da sé già con il tipo giusto, perché la template vale anche per i campi nuovi.

### Gergo

- [x] Tolto «vocabolario» dal modello nuovo — costante, metodi, chiavi di traduzione — dove
      restava dal nome vecchio. Resta dove nomina il vocabolario di Drupal, che è il nome giusto.
- [x] Identificatori in inglese: `ricalcoloLayer*()`, `importaConFruibilita()`, `campoDelTab()` e
      una cinquantina di variabili.

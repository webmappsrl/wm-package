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
      dele tassonomie (PostgreSQL risponde `cannot use subquery in index predicate`, verificato).
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
  `laravel-nova-devtool` dal repo privato Nova: `ChoiceField/` (annuncio del cambio valore, che è
  ciò che fa funzionare `visibleWhen` nel browser) e `FeatureCollectionMap` (legenda condizionata).
  Istruzioni in `src/Nova/Fields/ChoiceField/README.md`.
- **Cosa controllare su camminiditalia e maphub** prima del bump:
  [docs/knowledge/8910-tassonomie-cosa-controllare-al-bump-dello-shard.md](../../knowledge/8910-tassonomie-cosa-controllare-al-bump-dello-shard.md).

### Moduli toccati, in aggiunta a quelli sopra

| File | Cosa |
|---|---|
| `database/migrations/zz_2026_11_03_000003_create_app_taxonomy_term_table.php.stub` | nuovo |
| `src/Nova/Concerns/BuildsTaxonomyFields.php` | nuovo: la sezione a tab, usata da EC e Layer |
| `src/Nova/Fields/Choice.php` + `ChoiceField/` + `ChoiceFieldServiceProvider.php` | nuovo campo con icona; il provider sta fuori dalla cartella per non aggiungere voci PSR-4 |
| `src/Nova/AbstractTaxonomyTermsResource.php` | nuovo: vista per tassonomia |
| `src/Nova/Menu/TaxonomyTermsMenu.php` | nuovo: voci di menu automatiche |
| `src/Commands/WmMakeTaxonomyResourceCommand.php` | nuovo: `wm:make-taxonomy-resource` |
| `src/Nova/Filters/TermsByTaxonomyFilter.php`, `TaxonomyTermKindFilter.php` | nuovi |
| `src/Nova/Layer.php`, `src/Services/Models/LayerService.php`, `src/Observers/LayerObserver.php` | filtri per termine e loro effetti |
| `src/Jobs/Layer/SyncAutoLayerAfterTrackTaxonomyChangeJob.php` | reindicizza entrate e uscite |
| `src/Observers/AppObserver.php` | accende le tassonomie di sistema su un'App nuova |
| `src/Services/PostHog/AnalyticsService.php` | `isConfigured()` e cast della config |
| `config/wm-package.php` | `legacy_taxonomies` |

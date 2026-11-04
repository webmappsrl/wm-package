# Termini di classificazione (`TaxonomyTerm`)

> Ticket: oc:8910

Il modello unico per le classificazioni: due tabelle, `taxonomy_terms` e `taxonomy_termables`,
accolgono qualunque classificazione nuova senza altre migration. Le cinque tassonomie esistenti
(`taxonomy_activities`, `themes`, `wheres`, `targets`, `poi_types`) restano dove sono e non
vengono toccate: il modello nuovo nasce accanto e accoglie solo classificazioni nuove.

L'inclusività (Allegato H, classi A1-A6) non è nel package: sono dati di Forestas.

## Tassonomie e termini

Le due cose vivono nella stessa tabella e le distingue `parent_id`:

- **tassonomia**: `parent_id` nullo. È la classificazione (per esempio `hiking-difficulty`);
- **termine**: `parent_id` valorizzato, punta alla tassonomia. È una voce (per esempio `e`).

Si creano da Nova con la Resource `TaxonomyTerm` (prima la tassonomia, poi i suoi termini) oppure
da codice:

```php
$taxonomy = TaxonomyTerm::create([
    'identifier' => 'hiking-difficulty',
    'name' => ['it' => 'Difficoltà escursionistica'],
    'single_choice' => true,
]);

TaxonomyTerm::create([
    'parent_id' => $taxonomy->id,
    'identifier' => 'e',
    'name' => ['it' => 'Escursionistico'],
    'sort_order' => 20,
]);
```

### La gerarchia è a due livelli

Il model rifiuta un terzo livello (`InvalidArgumentException` al salvataggio): il padre di un
termine può essere solo una riga con `parent_id` nullo; un termine non può essere padre di sé
stesso; una tassonomia che ha già figli non può diventare figlia. Il motivo è che `parent_id` sul
relazione deve voler dire sempre «la tassonomia».

### L'identifier

`identifier` è `not null` e unique: il codice si aggancia a quello (`terms('hiking-difficulty')`,
l'import, la config).

L'identifier di una **tassonomia** deve essere in kebab-case senza punti (minuscole, cifre,
trattini; il model accetta in più i due punti usati dall'import, per esempio
`sardegnasentieri:tid:123`). Il vincolo c'è perché l'identifier di una tassonomia è la **chiave di
raggruppamento nell'indice Elasticsearch**, e un punto in una chiave Elasticsearch la espande in
oggetti annidati. Un identifier non conforme fa lanciare `InvalidArgumentException`.

Per i **termini** non c'è quel vincolo: lì l'identifier è un valore, non una chiave.

### Le colonne

| Colonna | Significato |
|---|---|
| `type` | Dice **chi interpreta il `properties` della relazione**. Una tassonomia con `type = activity` sa che lì dentro stanno le durate; una tassonomia di difficoltà non ha `type` e non ha nulla da interpretare. Nullable. |
| `single_choice` | Vale suli tassonomie: se vero, di quella tassonomia se ne sceglie un solo termine. Nova mostra una `Select` invece del `Multiselect`; l'import scrive una relazione solo. **Non è un vincolo del database**: la regola la fanno rispettare Nova e l'import, non lo schema. |
| `sort_order` | Ordine dei termini dentro la tassonomia (smallint, default 0). Serve alle scale ordinali (T < E < EE < EEA): senza, l'ordine dipende dall'`id`. `children()` ordina per `sort_order` e poi per `id`. |
| `icon` | Percorso o nome dell'icona, come nelle altre tassonomie. Nullable. |
| `name`, `description`, `excerpt` | Traducibili (`HasTranslations`). |
| `properties` | jsonb libero sul termine. |

## Legare un termine a un contenuto

I modelli con il trait `TaxonomyAbleModel` hanno la relazione `taxonomyTerms()` (`MorphToMany`
sul pivot `TaxonomyTermable`):

```php
$track->taxonomyTerms()->attach($term->id, ['properties' => ['duration' => 90]]);
```

Il pivot copia da solo la tassonomia su `parent_id` al salvataggio: qualunque valore passato a mano
viene ignorato. Il `properties` della relazione è il posto dove una tassonomia tiene i dati che
riguardano quella relazione (per esempio una durata), interpretati secondo il `type` della tassonomia.

Se un termine cambia tassonomia, `TaxonomyTerm` riscrive da solo il `parent_id` delle righe del
pivot che lo puntano (un `update` diretto sulla tabella, senza caricare i modelli). Poiché è una
query diretta **non scatta l'observer del pivot**: la copia è aggiornata, ma l'indice Elasticsearch
dei contenuti no, e serve un reindex. Lo stesso vale per chi cancella o riassegna relazioni con una
query diretta su `taxonomy_termables` (per esempio la delete per tassonomia, che è l'uso per cui
`parent_id` esiste): nessun observer, quindi il reindex va lanciato da sé. Gli indici del pivot
sono due: `(termable_type, termable_id, parent_id)` per leggere i termini di un contenuto,
`(parent_id, termable_type)` per filtrare per tassonomia.

Un observer sul pivot reindicizza il contenuto su Scout a ogni salvataggio o cancellazione del
relazione, anche quando cambia solo il `properties` e il contenuto non viene salvato. Se la
reindicizzazione fallisce l'errore finisce nel log e le relazioni restano scritti.

## Leggere i termini

```php
$track->terms('hiking-difficulty');  // Collection<TaxonomyTerm>, vuota se non ce ne sono
$track->term('hiking-difficulty');   // il primo termine, oppure null
```

`term()` serve ali tassonomie `single_choice`; se ce n'è più d'uno restituisce il primo. Il
`properties` della relazione è su `$term->pivot->properties`.

Due cose da sapere: `terms()` non interroga il database per tassonomia, filtra la relazione
`taxonomyTerms` già caricata (una query sola, con i padri); un identifier inesistente (un
refuso) restituisce una collection vuota, indistinguibile da una tassonomia senza termini. I termini
escono per `sort_order` e poi per `id`, quindi `term()` prende il primo di quell'ordine.

La relazione carica anche il **padre** di ogni termine (`with('parent')`): chi serializza i termini
di un contenuto (API, Nova) se lo ritrova annidato nell'output. Inoltre, se un termine viene
**spostato sotto un'altra tassonomia o cancellato**, i contenuti legati restano nell'indice con il
raggruppamento vecchio finché non vengono risalvati: dopo quelle operazioni serve un reindex (la
cancellazione passa dal `cascadeOnDelete` del database, che non fa scattare nessun observer).

## Cosa finisce nell'indice Elasticsearch

`EcTrack::toSearchableArray()` aggiunge la chiave `terms`, un sotto-array per tassonomia:

```json
"terms": {
  "hiking-difficulty": [
    { "identifier": "e", "name": { "it": "Escursionistico" }, "pivot_properties": { } }
  ]
}
```

La tassonomia si legge dal padre vivo del termine, non dalla copia `parent_id` del pivot.

### Il `properties` della relazione non è indicizzato

`pivot_properties` **arriva nel documento ma non è indicizzato**: una dynamic template del
mapping (`config/wm-elasticsearch.php`) lo dichiara `enabled: false`. Si legge da `_source`, **non
si filtra**.

Il motivo: le sue chiavi sono libere per costruzione, e due tipi diversi sulla stessa chiave
farebbero **rifiutare il documento** da Elasticsearch. Con `scout.queue` a false l'eccezione la
cattura l'observer e finisce solo nel log, e il contenuto resta con l'indice vecchio senza che
nessuno se ne accorga.

Per filtrare su una chiave la si dichiara nel mapping (per esempio `long` per una durata) e si
rilancia `scout:import`.

### Un mapping cambiato non si applica con il reindex normale

Un cambio di mapping vale solo per gli indici creati dopo: per applicarlo serve `scout:import`
(già noto nel repo come oc:8681). Vale per ogni modifica a `config/wm-elasticsearch.php`, e anche
per una tassonomia nuova che porta nel mapping una chiave che prima non c'era (il limite
`mapping.total_fields.limit` è 2000: con poche tassonomie non è un problema).

L'`identifier` di ogni termine è indicizzato come `keyword` (dynamic template
`terms.*.identifier`), quindi si filtra con una `term` query.

## Nova

La Resource `TaxonomyTerm` crea tassonomie e termini. Sul dettaglio di un contenuto,
`AbstractEcResource::makeTermFieldsForTaxonomies(array $taxonomyIdentifiers)` (nessuna Resource del
package lo chiama, lo usa lo shard) genera **una select per tassonomia**:
`Choice` multiplo per le tassonomie normali, `Choice` a scelta singola per quelle con
`single_choice`. Mai il `MultiSelect` nativo di Nova (oc:8588). Il pacchetto
`outl1ne/nova-multiselect-field` deve essere installato.

Nell'elenco dei termini, dove tassonomie e termini convivono nella stessa tabella:

- la colonna **Nome** mostra il nome tradotto più l'identifier
  (`Wm\WmPackage\Support\TaxonomyTermLabel`, con fallback sull'italiano quando la lingua
  corrente non è tradotta), ed è anche il titolo della riga: senza, la lista e la tendina
  «Tipo» mostrerebbero solo chiavi in inglese;
- due filtri: **Tipo** (`TaxonomyTermKindFilter`, tassonomie o termini) e **Tipo**
  (`TermsByTaxonomyFilter`, l'elenco delle tassonomie letto dal database, quindi una tassonomia
  nuova compare da sola).

Sul dettaglio di un **termine** ci sono le liste dei contenuti collegati, EC Tracks ed EC Pois.
Su una tassonomia sono nascoste, perché la relazione sta sul termine e lì sarebbero vuote per
costruzione. Le due liste puntano alle Resource che lo shard registra: si sostituiscono
ridefinendo `ecTrackResource()` ed `ecPoiResource()` nella sottoclasse (Forestas lo fa in
`app/Nova/TaxonomyTerm.php`).

### Una vista per tassonomia

Quando una tassonomia merita una voce di menu propria — perché prima era una tassonomia a sé, come
le avvertenze di Forestas — si estende `AbstractTaxonomyTermsResource` invece di mandare chi la usa
nell'elenco unico con un filtro addosso:

```php
class TaxonomyWarning extends AbstractTaxonomyTermsResource
{
    public static function taxonomyIdentifier(): string
    {
        return 'warnings';
    }
}
```

Da quell'unica riga arrivano: l'elenco ristretto ai termini di quella tassonomia, la creazione già
dentro la tassonomia (il campo «Tipo» diventa nascosto e precompilato), l'etichetta e il titolo
presi dal nome della tassonomia, e l'identifier libero dal formato stretto che vale solo per le
tassonomie. L'`uriKey` è quello che Nova deriva dal nome della classe: si ridefinisce per
conservare un indirizzo già in uso. Se la tassonomia non esiste ancora, l'elenco è vuoto — non è
l'elenco di tutti i termini.

La classe non si scrive nemmeno a mano:

```bash
php artisan wm:make-taxonomy-resource warnings --uri-key=taxonomy-warnings
```

scrive `app/Nova/Warnings.php` (il nome si cambia con `--class=`, una classe già esistente si
sovrascrive solo con `--force`). Se la tassonomia non è ancora in `taxonomy_terms` il comando
avvisa e genera lo stesso.

La voce di menu si aggiunge da sé, se la sezione delle tassonomie la chiede al package:

```php
MenuSection::make('Taxonomies', [
    MenuItem::resource(TaxonomyWhere::class),
    ...TaxonomyTermsMenu::items($request),   // ogni AbstractTaxonomyTermsResource registrata
    MenuItem::resource(TaxonomyTerm::class),
])
```

`TaxonomyTermsMenu::items()` guarda le Resource che l'utente può vedere, tiene quelle che estendono
`AbstractTaxonomyTermsResource` e le ordina per nome della tassonomia. Il nome è `jsonb` traducibile:
la voce segue la lingua attiva e ricade sull'italiano dove la traduzione manca, così non resta
mai senza titolo.

**Quando non serve:** una tassonomia che si gestisce bene dall'elenco unico, che ha i filtri Tipo
e Tipo. Una Resource per ciascuna delle dodici scale di difficoltà sarebbe dodici voci di
menu per niente.

## Una tassonomia che dipende da un'altra

Una tassonomia può avere senso solo quando un'altro tassonomia ha certi termini scelti. La regola si
dichiara nei dati, in `properties` della tassonomia dipendente, e la applica il package: nessuno
shard scrive codice.

```json
{"depends_on": {"family": "route-type", "terms": ["route-type-itinerary", "route-type-walk"]}}
```

Letto così: «Tipologia itinerario» vale solo se «Tipo percorso» è Itinerario o Cammino. Da quella
riga vengono due comportamenti:

- **nel form**, il campo si nasconde quando la condizione è falsa (Nova `dependsOn`);
- **al salvataggio**, la richiesta viene rifiutata con un messaggio. Senza questa metà, un POST
  diretto scriverebbe la relazione lo stesso: nascondere un campo non è validarlo.

Finché la tassonomia sorgente non ha nessun termine scelto la condizione è considerata vera, e il
campo resta visibile: in compilazione non deve sparire un dato che si sta inserendo. Se la
tassonomia sorgente non è fra quelle mostrate in quella scheda, la dipendenza non si applica —
non ci sarebbe un valore su cui decidere.

## A quali contenuti si applica una tassonomia

L'accensione per app dice **dove** una tassonomia esiste, non **a cosa** si applica: sono due
domande diverse, e con la sola prima «Difficoltà di accessibilità» comparirebbe anche sulla
scheda di un POI, dove non vuole dire niente.

Una tassonomia lo dichiara in `properties->models`, con i nomi brevi dei modelli:

```json
{"models": ["EcTrack"]}
```

Senza la chiave vale per tutti i contenuti, così le tassonomie già create non cambiano
comportamento. Il filtro è in `BuildsTaxonomyFields::taxonomyAppliesHere()` e riguarda solo le
schede dei contenuti: **su un Layer non si applica**, perché lì le tassonomie sono filtri che
scelgono i contenuti, e un layer può raccogliere sia tracce sia POI.

Riguarda ciò che si vede e si compila in Nova, non il dato: un termine già attaccato a un POI
resta attaccato e continua a finire nell'indice. La chiave non è un vincolo di integrità.

## Quali tassonomie vede un'app

Una tassonomia è accesa o spenta **per app**, nel pivot `app_taxonomy_term`: una riga vuol dire
acceso, non esiste uno stato «spento» da scrivere. Ci vanno solo le tassonomie — un termine segue
il suo, così la stessa informazione non può divergere in due posti.

Chi accende cosa dipende da `is_system`:

| Tipo | Alla nascita di un'app | Poi |
|---|---|---|
| Di sistema (`is_system = true`, portato dal package) | acceso (`AppObserver::created()`) | si può spegnere |
| Custom (creato in uno shard) | spento | si accende nelle app che lo vogliono |

Così una tassonomia creata per un'app non compare mai da sola in un'altra, che è il problema per
cui la relazione esiste. Lo stesso tassonomia acceso in tutte le app di uno shard è, di fatto, un
tassonomia di shard: la differenza sta in quante righe ha, non in com'è fatto.

**Dove si configura:** nel tab «Tassonomie» della scheda dell'app. Chi mette in piedi
un'app decide in un posto solo quali classificazioni vedranno i suoi contenuti. La scheda del
tassonomia mostra in sola lettura dove è acceso.

**Dove agisce il filtro:** nel tab delle classificazioni di un contenuto (solo le tassonomie
accesi nella sua app) e in `termsForSearch()`, cioè nell'indice Elasticsearch — una tassonomia
spento non deve finire nell'indice, altrimenti l'app ci filtra sopra. Un contenuto che non ha
ancora un'app, in creazione, non filtra niente: il form nascerebbe senza classificazioni.

**Negli shard:** le tassonomie create da un comando dello shard sono custom, quindi vanno accese.
Il comando di Forestas lo fa da sé (`syncWithoutDetaching` su tutte le app dello shard).

## Un campo solo per tutte le classificazioni

Nella scheda di un contenuto ogni classificazione usa lo stesso controllo, `IconSelect`: casella
con ricerca, opzioni con la loro icona, termini scelti come etichette removibili. La scelta
singola o multipla viene da `single_choice` della tassonomia, non da come è scritto il campo.

`Select` e `Multiselect` non vanno bene qui: riducono ogni opzione a etichetta e valore
(`Multiselect.php:56`), quindi l'icona non ci passa. Sul **dettaglio** invece `IconSelect`
mostrerebbe gli id, perciò lì va un campo di testo in sola lettura, con lo stesso attributo e
visibilità opposta, che rende icona e nome.

**Nota:** nel menu a tendina un termine senza icona non ha un segnaposto — il componente disegna
un elemento vuoto con il suo margine, e il nome si sposta di pochi pixel. Si corregge nel
componente di `IconSelect`, che va ricompilato (serve `laravel-nova-devtool`, dal repository
privato di Nova).

### Le tassonomie che vivono ancora altrove

Le Attività e le altre tassonomie storiche non sono tassonomie, ma nella scheda si presentano
come tali. Le descrive `config('wm-package.legacy_taxonomies')`:

```php
['key' => 'activities', 'relation' => 'taxonomyActivities', 'model' => TaxonomyActivity::class,
 'label' => 'Activities', 'tab' => 'Classifications', 'single_choice' => false],
```

Nome, tab e scelta singola si dichiarano qui perché quelle tabelle non hanno le colonne che un
tassonomia ha. Quando una di esse confluirà in `taxonomy_terms` si toglie la sua voce dalla
config e non cambia nient'altro.

Il salvataggio sincronizza **per differenza**: ciò che resta scelto non si stacca e non si
riattacca, così il pivot conserva i propri dati — per le attività le durate avanti e indietro.
E il campo cambia solo come si sceglie in Nova: GeoJSON (`properties.taxonomy.activity`) e
indice Scout continuano a rispondere identici, con un test che lo verifica.

## Cosa non fa

- non migra le cinque tassonomie esistenti;
- non tocca `properties->difficulty` né le quattordici chiavi `access_*`;
- nessun vincolo di unicità nel database sulla scelta singola.

Gli stub delle migration sono nella root di `database/migrations/`
(`zz_2026_11_02_000001_create_taxonomy_terms_table.php.stub`,
`zz_2026_11_02_000002_create_taxonomy_termables_table.php.stub`) e vanno pubblicati negli shard.
Lo spec del lavoro è in `docs/features/8910-modello-dati-unico-classificazioni-accessibilita/`.

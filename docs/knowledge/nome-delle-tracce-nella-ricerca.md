# Nome delle tracce nella ricerca

## Come funziona oggi

L'indice Elasticsearch delle tracce (`ec_tracks`, uno per shard, comune a tutte le app) riceve da
`EcTrack::toSearchableArray()` due campi per il nome:

- **`name`**: la stringa italiana (`getTranslation('name', 'it')`). Nel mapping
  (`config/wm-elasticsearch.php`) è testo con il sottocampo `keyword`, su cui l'endpoint di ricerca
  ordina (`orderBy('name.keyword')`). L'ordine è quindi alfabetico sul nome italiano per tutti gli
  utenti, come su Geohub.
- **`name_translations`** (costante `EcTrack::SEARCH_NAME_TRANSLATIONS_FIELD`): l'oggetto con le
  lingue valorizzate (`getTranslations('name')`, che scarta le lingue nulle o vuote). Nel mapping è
  `type: object` senza `properties` né `enabled: false`: le lingue nascono da Elastic al primo
  documento che le contiene, come testo con `keyword`.

L'endpoint di ricerca (`api/v2/elasticsearch`, `ElasticsearchController::index()`) prima di
rispondere chiama `localizeSearchResults()`: in ogni risultato che ha `name_translations` non vuoto
lo mette al posto di `name`, e in ogni caso lo toglie. L'app riceve `name` come oggetto per lingua,
nella stessa forma di Geohub, e la card del risultato (wm-core, `search-box` con il pipe `wmtrans`)
mostra la lingua dell'utente. **`name` nell'indice e `name` nella risposta sono diversi di proposito**:
chi guarda l'indice, per esempio da Kibana, vede la stringa italiana.

Un risultato senza `name_translations` mantiene il `name` di oggi: è il caso delle tracce non ancora
riscritte nell'indice dopo un aggiornamento del package. Si correggono con «Reindicizza Scout», che
lavora per app e va lanciato su ciascuna app dello shard, oppure con `scout:import`, che ricrea
l'indice di tutto lo shard.

La query (`QueryStringQuery` senza campi) cerca su tutti i campi dell'indice, quindi anche su
`name_translations`. Per trovare una traccia in qualsiasi lingua c'era già `searchable`, che contiene
il nome in tutte le lingue; `name_translations` aggiunge due casi: le app che hanno tolto `name` da
`track_searchables`, e le parole accentate presenti solo in un nome tradotto (in `searchable` gli
accenti sono codificati da `json_encode`).

## Perché così

- **Un campo nuovo accanto a `name`, non `name` come oggetto** (oc:8681): il mapping dichiara `name`
  come testo, e il tipo di un campo esistente non si cambia senza ricreare l'indice. Un campo nuovo
  invece viene accettato dagli indici esistenti, perché il mapping non ha `dynamic: strict`: dopo
  l'aggiornamento del package i salvataggi delle tracce continuano a funzionare con o senza reindex.
  Verificato su Elasticsearch 8.17.1 con il mapping precedente e con quello nuovo
  (`docs/features/8681-la-ricerca-salva-il-nome-delle-tracce-solo-in-italiano/notes.md`).
- **Sostituzione nell'API, non nel frontend** (oc:8681): wm-core e le app già pubblicate mostrano il
  nome con `wmtrans`, che gestisce già un oggetto per lingua (è la forma che arriva da Geohub). Così
  non serve nessun rilascio di wm-core, webmapp-app o wm-types.
- **`name_translations` senza `enabled: false`** (oc:8681): negli indici esistenti il campo nasce in
  modo dinamico, e quindi ricercabile; dichiarato senza `enabled: false` nasce uguale anche negli
  indici ricreati. Con `enabled: false` la stessa ricerca avrebbe dato risultati diversi da uno
  shard all'altro.

## Come ci siamo arrivati

- **`name` fisso in italiano** (oc:5220, superata da oc:8681): nell'aprile 2025 `name` è stato
  fissato in italiano insieme al mapping con i sottocampi `exact`, `phrase`, `edge` e `completion`,
  che servivano a query con boost sul nome. Quelle query sono state commentate pochi giorni dopo e
  sostituite dalla ricerca su tutti i campi; il nome in una sola lingua è rimasto, e su un'app con
  nomi tradotti diversi dall'italiano le card mostravano l'italiano.
- **`name` come oggetto con tutte le lingue** (oc:8681, scartata): con `scout.queue` a `false`, il
  default, un oggetto in un campo testo viene rifiutato da Elastic e il rifiuto diventa un'eccezione
  al salvataggio, che annulla il salvataggio della traccia in Nova. Avrebbe richiesto `scout:import`
  su ogni shard nel momento esatto dell'aggiornamento del package.
- **Leggere il nome da `searchable`** (oc:8681, scartata): `searchable` è una stringa con le
  virgolette tolte che contiene anche le descrizioni, quindi non si può separare per lingua.

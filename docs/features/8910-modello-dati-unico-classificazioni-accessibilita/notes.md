> Ticket: oc:8910

# Notes — Modello unico delle classificazioni, parte `wm-package`

## Deviazioni dal piano

Tutte decise durante l'esecuzione, con il motivo. Nessuna cambia il risultato atteso dallo spec.

- **`taxonomyTerms()` nasce nel Task 2, non nel Task 4.** I test del Task 2 la usano per fare
  `attach()`: una relazione definita due task dopo chi la usa non sta in piedi.
- **L'observer si registra nel `booted()` del modello**, non in un provider. Il
  `WmPackageServiceProvider` esiste (in `src/`, non in `src/Providers/`), ma il pattern del repo è
  quello di `TaxonomyActivityable.php:18`, che registra l'observer dal modello.
- **Lo Step 6 del Task 1 — pubblicare le migration e lanciare `migrate` — non è stato eseguito
  qui.** Pubblicare gli stub è il Task 1 del piano di Forestas, e un `migrate` sullo shard
  applicherebbe anche migration pendenti estranee a questo lavoro. Il gate
  `publish-missing-migrations` resta quindi rosso finché Forestas non li pubblica: è atteso.
- **`terms()` filtra per `parent?->identifier`, non per `pivot->parent_id`** come diceva il piano.
  La copia sul pivot non veniva aggiornata se un termine cambiava vocabolario, e il metodo avrebbe
  restituito i termini del vocabolario sbagliata in silenzio. Il requisito vero — zero query —
  resta soddisfatto.
- **La guardia sul formato dell'`identifier` vive nel model, non solo in Nova**, e vale solo per
  le **vocabolari**: il loro identifier è la chiave di raggruppamento nell'indice, e un punto lo
  spaccherebbe. Per i termini l'identifier è un valore e un punto è innocuo.

## Bug trovati

Tre difetti **preesistenti** del repo, incontrati lavorando e non risolti qui perché estranei al
ticket:

- **La suite intera del package non parte.** Il suo `TestCase` è `Wm\WmPackage\Tests\TestCase`, ma
  una decina di file in `tests/Unit/` dichiara `use Tests\TestCase`, che è quello del consumer e
  nel package non esiste. È la trappola già scritta nel `CLAUDE.md` del package, applicata a sé
  stesso. Si lanciano le directory sane.
- **`outl1ne/nova-multiselect-field` non è installato in `wm-package/vendor`** benché sia in
  `composer.json:41`: c'è solo in `forestas/vendor`. Fa fallire anche `AppImportedPropertiesFieldsTest`,
  estraneo a questo lavoro. I due test che richiedono davvero il `Multiselect` sono `skip` con il
  motivo scritto, così in un ambiente completo girano e in uno incompleto dicono perché no.
- **PHPStan riporta 969 errori nel package.** Nessuno è di questo lavoro: i nostri 13 sono stati
  risolti con le annotazioni di tipo, non silenziati.

Più una cosa sull'ambiente locale, non sul codice: **il disco del container è al 93%**, sopra il
watermark del 90% di Elasticsearch, che smette di allocare shard. Durante la prova del mapping il
nodo rispondeva 503.

## Decisioni

- **Nessun vincolo di unicità nel database.** «Di questo vocabolario se ne sceglie una sola» è una
  regola di business — il cliente l'aveva scritta al contrario e l'ha cambiata in call il 29/10 —
  non un invariante dello schema. Un indice unique parziale non sarebbe nemmeno esprimibile: il
  predicato non può leggere la tabella dei vocabolari (`cannot use subquery in index predicate`,
  provato). La regola vive in `single_choice` sul vocabolario, letta da Nova e dall'import.
- **Niente debounce nell'observer**: un reindex per relazione. L'observer però non può far fallire la
  scrittura della relazione — l'indicizzazione è in un try/catch con log — perché un Elasticsearch
  irraggiungibile lascerebbe un `attach` a metà. Il caso massivo è l'import di Forestas, che si
  silenzia con `withoutSyncingToSearch()`: l'observer ora lo rispetta, ed era la condizione che
  rendeva valida questa decisione.
- **`pivot_properties` non è indicizzato** (dynamic template `enabled: false`): le sue chiavi sono
  libere per costruzione, e due tipi diversi sulla stessa chiave farebbero rifiutare il documento
  da Elasticsearch — con l'eccezione catturata dall'observer e finita solo nel log, cioè un
  contenuto che sparisce dalle ricerche senza che nessuno se ne accorga. Il dato si legge da
  `_source`. Scartato `flattened`, che indicizza ogni foglia come keyword e renderebbe un
  `> 3600` sulle durate un confronto fra stringhe. **Provato contro Elasticsearch reale**, con
  controllo negativo: senza la template il secondo documento viene rifiutato con
  `document_parsing_exception`.
- **`taxonomy_termables.parent_id` resta**, perché permette di filtrare i contenuti per vocabolario
  guardando la sola tabella delle relazioni — ed è l'uso che ne farà l'import di Forestas. Era però una
  copia che nessuno aggiornava: ora un hook su `TaxonomyTerm` la riscrive quando il termine cambia
  vocabolario, c'è un indice che parte da `parent_id`, ed è documentato che le query dirette su quella
  tabella non fanno scattare l'observer, quindi dopo di esse serve un reindex.

- **La vista per vocabolario non tiene il vocabolario in memoria.** `AbstractTermVocabularyResource::family()`
  rilegge la riga a ogni chiamata, e sono poche per richiesta. Una memoria statica c'era, e l'ha
  smascherata la suite di Forestas: sopravvive alla richiesta, quindi in un worker di coda — o
  fra due test — tiene l'id di un vocabolario che non esiste più e l'elenco risponde vuoto senza
  dire perché. Una query su una tabella di una dozzina di righe non vale quel rischio.

- **Il formato stretto dell'identifier vale per i vocabolari, e ora c'è un gancio per dirlo.**
  `TaxonomyTerm::isCreatingVocabulary()` sostituisce il controllo diretto su `$request->input('parent')`:
  in una vista per vocabolario il campo «Vocabolario» non c'è, e senza il gancio ogni termine creato da
  lì sarebbe validato come se fosse un vocabolario.

## Follow-up

- **Verificare a mano in Nova** che il tentativo di dare un padre a un vocabolario che ha già figli
  produca il messaggio di validazione e non una pagina di errore: il test legge le regole
  dichiarate dal field, non fa girare il form.
- **`single_choice` non è imposto lato server**: una richiesta costruita a mano può salvare più
  termini in un vocabolario a scelta singola, e un contenuto che ne ha già due — per esempio da un
  import — ne perde uno al primo salvataggio da Nova. Merita un ticket suo.
- **`saveQuietly` su un termine salta l'hook** che riscrive la copia sul pivot.
- `TaxonomyTerm` espone `ecTracks()` ed `ecPois()` ma non `layers()`, mentre `Layer` usa il trait.
- Nessun indice su `taxonomy_termables.taxonomy_term_id`: in PostgreSQL una foreign key non
  indicizza. I pivot esistenti stanno allo stesso modo.

## 3 novembre — vincoli nel database e dipendenze nei dati

**Unicità sul pivot.** `taxonomy_termables` ha ora un unique su
`(taxonomy_term_id, termable_type, termable_id)`. Prima la garanzia viveva nel codice dell'import.
Il vincolo ha subito fatto cadere un test che creava un doppione di proposito per verificare che
l'import lo ripulisse: il caso non è più producibile, e il test ora verifica che sia il database a
rifiutarlo. Nota di scrittura dei test: dopo la violazione la transazione PostgreSQL è abortita,
quindi nessuna query può seguire nello stesso test.

**Due livelli e basta.** Un trigger (`taxonomy_terms_two_levels`) impedisce che un termine diventi
padre di un altro termine. Un `CHECK` non sarebbe bastato: non può leggere un'altra riga. La
regola viveva nel codice e nei test; ora la dice il database a chiunque scriva, import compresi.
Entrambi i vincoli sono nella `create` delle tabelle, non in una migration aggiuntiva: il modello
non è ancora su UAT.

**`depends_on` nelle properties del vocabolario.** La dipendenza «Tipologia itinerario solo su
itinerari e cammini» era codice in `app/Nova/EcTrack.php` di Forestas
(`withItineraryTypeDependency()`), valida per quella coppia soltanto. Ora è un dato sulla riga
del vocabolario e la applica `AbstractEcResource::applyTermDependencies()`, per qualunque coppia e
qualunque shard. Nell'occasione è stata aggiunta la **validazione lato server**, che mancava: il
campo si nascondeva, ma un POST diretto scriveva la relazione lo stesso.

## 3 novembre, pomeriggio — interfaccia unica, Layer, PostHog

Da qui in avanti il concetto si chiama **tassonomia**. Sopra si legge «famiglia» e «vocabolario»:
sono i due nomi provvisori che ha avuto in questa stessa giornata, lasciati lì perché queste note
sono un diario. Il dev: «anche in Webmapp li abbiamo sempre chiamati tassonomie… avrà pure un
peso», e «vocabolario è nato adesso, non è neanche codice committato».

### Decisioni

- **Il codice che costruisce la sezione è un trait, non una classe base.** `BuildsTaxonomyFields`
  nasce estraendolo da `AbstractEcResource` perché serviva anche al Layer, che non è una Resource
  EC e non può ereditarla. Il trait espone due ganci che il Layer ridefinisce:
  `taxonomyFieldsAreFilters()` e `taxonomyTermsRelation()`.

- **Prima l'interfaccia unica, poi la convergenza dei modelli.** Chiesto dal dev in questi
  termini. Le Attività restano `taxonomy_activities`, ma si vedono e si compilano come le
  tassonomie nuove: `config/wm-package.php → legacy_taxonomies` dichiara per ognuna relazione,
  modello, etichetta, tab, scelta singola e se è nascosta. Il costo di convertire i dati si paga
  quando serve, non per avere un'interfaccia coerente.

- **La dipendenza fra tassonomie è un dato, non codice.** Era
  `EcTrack::withItineraryTypeDependency()` su Forestas, valida per una coppia sola. Ora sta in
  `properties->depends_on` della riga della tassonomia e la applica il trait, per qualunque coppia
  e qualunque shard. Nell'occasione è stata aggiunta la **validazione lato server**, che mancava:
  il campo si nascondeva, ma un POST diretto scriveva la relazione lo stesso.

- **Il nome del campo non ha il prefisso `Wm`.** Il dev: «scegliamo assieme un nome… capiamo di
  base cosa fa in più il nostro select rispetto agli altri e da lì cerchiamo il nome». Ciò che fa
  in più è mostrare l'icona accanto alla voce e nascondersi in base a un altro campo: si chiama
  `Choice`, e `IconSelect` ora lo estende invece di duplicarlo.

- **Il provider stava fuori dalla cartella del campo, ed era sbagliato.** Il motivo scritto allora
  — che metterlo dentro avrebbe richiesto una voce PSR-4 nuova — non regge: `TrackColor` ha il suo
  dentro e nessuna voce, perché cartella e namespace combaciano e la regola di base del package
  basta. Il 4 novembre il campo è stato raccolto tutto in `src/Nova/Fields/Choice/`, cartella
  chiamata come le altre (il nome del campo, senza suffisso `Field`): `Choice.php`,
  `FieldServiceProvider.php`, i sorgenti Vue e il `dist/`.

- **Filtri del Layer: OR dentro una tassonomia, AND fra tassonomie diverse.** Proposto dal dev
  («tutti i valori di una tassonomia sono in OR, fra una tassonomia e l'altra in AND, corretto?»)
  ed è anche la regola che già valeva fra attività e zone. In `LayerService` è una sottoquery per
  tassonomia: con un `whereIn` unico sarebbe un OR globale, e due filtri di tassonomie diverse si
  comporterebbero al contrario di ogni altro criterio della stessa pagina.

- **Le zone geografiche restano fuori dalla sezione «Filtri».** Su maphub sono circa 490 righe:
  un campo a scelta multipla con quei numeri non è usabile. Restano nel loro pannello.

- **Il reindex dopo un cambio di filtri tocca anche le tracce che escono.** Reindicizzare solo le
  entrate lasciava `properties.layers` sbagliato sulle uscite, che restavano nella mappa e
  nell'elenco. Costa di più ed è il prezzo giusto.

### Bug trovati, tutti nostri

- **Un campo che non mostra tutte le righe le stacca.** Nascondendo in Nova le tre attività
  `sardegnasentieri:type:*`, al primo salvataggio quelle righe venivano deselezionate: il fill
  prendeva per buono l'elenco inviato dal form, che le righe nascoste non le contiene. Un layer
  ha perso la sua attività e con essa tutte le tracce, e una traccia pure. Corretto con
  `array_intersect($current, $allowed)` — si toccano solo le righe che l'utente poteva vedere — e
  con un test. **È il difetto da cercare per primo su uno shard che ha una Resource Layer
  propria**, perché due controlli sulla stessa relazione nella stessa pagina producono lo stesso
  effetto.

- **La mappa di un Layer restava vuota per colpa di PostHog.** `Layer::getFeatureCollectionMap()`
  costruisce `AnalyticsService`, il cui costruttore faceva `rtrim(null)` quando la config non
  c'è: un TypeError che buttava giù l'intero GeoJSON. L'errore era nei log e **l'avevo liquidato
  come preesistente e non collegato**: era la causa. Corretto con il cast e `isConfigured()`; la
  legenda «Posizione utente» ora compare solo se PostHog è configurato, come ha chiesto il dev
  («se non c'è conf non si deve vedere nulla di quella feature»).

- **Una Closure passata nuda a `rules()` la invoca Nova con la NovaRequest** («Too few arguments…
  1 passed and exactly 3 expected», pagina in 500). Va dentro un array.

- **`dependsOn` non esiste su un campo che non usa `SupportsDependentFields`**: il trait va
  aggiunto esplicitamente.

### Follow-up

- **Due componenti Vue da ricompilare**, non eseguibile senza `laravel-nova-devtool` dal repo
  privato Nova: `ChoiceField/` (annuncio del cambio valore, che è ciò che fa funzionare
  `visibleWhen` nel browser; più un segnaposto per le icone mancanti) e `FeatureCollectionMap`
  (legenda condizionata). Istruzioni in `src/Nova/Fields/ChoiceField/README.md`.
- **`App::taxonomies()` è un nome generico su un modello che tutti gli shard estendono**: da
  cercare nei loro repo prima del bump. Vedi
  [docs/knowledge/8910-tassonomie-cosa-controllare-al-bump-dello-shard.md](../../knowledge/8910-tassonomie-cosa-controllare-al-bump-dello-shard.md).

## 4 novembre — la build di `Choice` si poteva fare

Il follow-up qui sopra dava la build per non eseguibile «senza `laravel-nova-devtool` dal repo
privato Nova». Le credenziali però ci sono già: `auth.json` nella root dello shard ha l'`http-basic`
per `nova.laravel.com`, e Composer le legge da `COMPOSER_AUTH`.

- **Due ostacoli veri, nessuno dei due insormontabile.** Il PHP dell'host è 7.4 e `nova-devtool`
  vuole `^8.1`: si lancia nel container, che ha PHP 8.4, Node 20 e npm 9. E `composer install`
  moriva con un HTTP 402 — «Your license is not allowed to download this release» — perché
  risolveva `laravel/nova` 5.11.1: lo shard è fermo a 5.7.6 e la licenza arriva lì. Vincolata
  quella versione nel `composer.json` del campo, l'installazione passa.
- **`dist/` compilato**: 14.5 KiB, con Vue esterno come vuole la trappola oc:7546. `Choice` ora
  dichiara `$component = 'choice'`, il provider è registrato, e `IconSelect` — che estende
  `Choice` senza dichiarare un componente suo — passa anche lui al bundle nuovo. Il test
  `ActivitiesAsTaxonomyTest` asseriva `icon-select`: aggiornato.
- **Il campo è stato raccolto tutto in una cartella**, `src/Nova/Fields/Choice/`, chiamata come le
  altre: il nome del campo, senza il suffisso `Field` che nessun altro campo del package usa.
  Dentro ci sono classe, provider, sorgenti Vue e `dist/`. Nessuna voce PSR-4 in `composer.json`,
  perché cartella e namespace combaciano.

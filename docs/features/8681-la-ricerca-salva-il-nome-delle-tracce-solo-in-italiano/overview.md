> Ticket: oc:8681

# La ricerca salva il nome delle tracce solo in italiano

## Cosa cambia

Nei risultati della ricerca l'app mostra il nome della traccia nella lingua dell'utente, e non più
sempre in italiano.

L'indice Elastic delle tracce riceve un campo nuovo, `name_translations`, con il nome in tutte le
lingue valorizzate. L'endpoint di ricerca di wm-package (`api/v2/elasticsearch`), prima di
rispondere, mette quel campo al posto di `name` in ogni risultato che lo contiene. L'app riceve
così `name` come oggetto (`{"it": "V6 – Anello di Vecchiano", "en": "V6 - Loop of Vecchiano"}`),
nella stessa forma che le manda già Geohub, e lo traduce con il pipe `wmtrans` che usa già.

Il campo `name` nell'indice resta com'è oggi (stringa italiana): il mapping di `name` e
l'ordinamento non cambiano.

## Perché

Il collaudo dell'import di Itinera Romanica PLUS (Geohub 28 → maphub dev app 3, tag
[COLLAUDO][ITINERA-ROMANICA-PLUS][2026]1) ha mostrato che chi usa l'app in inglese vede nelle card
della ricerca il nome italiano, mentre nel dettaglio della traccia il nome è giusto. Riprodotto il
06/10/2026 su maphub dev, traccia 57: con l'app in inglese la ricerca «Loop» trova la traccia, ma
la card mostra «V6 – Anello di Vecchiano» invece di «V6 - Loop of Vecchiano».

La ricerca *trova* già in tutte le lingue, grazie a `searchable`, che contiene il nome in tutte le
lingue. Il difetto è nel nome *mostrato*: la card legge `name` (wm-core
`search-box.component.html:12`, `data.properties?.name ?? data.name | wmtrans`), che nell'indice è
la sola traduzione italiana (`src/Models/EcTrack.php:748`). Il nome in tutte le lingue è già nel
database: su maphub dev il `searchable` della traccia 57, costruito dal database, contiene
`{it:V6 – Anello di Vecchiano,en:V6 - Loop of Vecchiano}`. Quindi né rifare l'import né rilanciare
«Reindicizza Scout» con il codice attuale lo correggono.

Il difetto si vede solo quando una traccia ha un nome tradotto diverso dall'italiano: se le
traduzioni sono uguali all'italiano, mostrare l'italiano dà lo stesso testo.

**Perché un campo nuovo e non `name` come oggetto.** Il mapping (`config/wm-elasticsearch.php`)
definisce `name` come testo. Un oggetto in `name` verrebbe rifiutato dagli indici esistenti, e con
`scout.queue` a `false` (il default di Laravel Scout) il rifiuto lancia un'eccezione al
salvataggio (`vendor/matchish/laravel-scout-elasticsearch/src/Engines/ElasticSearchEngine.php:49-51`)
che annulla il salvataggio della traccia in Nova. Servirebbe ricreare l'indice con `scout:import`
su ogni shard nel momento stesso del bump. Un campo nuovo invece viene accettato dagli indici
esistenti, perché il mapping non dichiara `dynamic: strict`, e i salvataggi continuano a
funzionare prima e dopo qualsiasi reindex.

## Requisiti

- [x] `EcTrack::toSearchableArray()` aggiunge `name_translations` con le sole lingue valorizzate del
      nome (`getTranslations('name')`, che scarta già le lingue nulle o vuote).
- [x] `name` nell'indice resta `getTranslation('name', 'it')`: mapping di `name` e ordinamento
      `name.keyword` invariati.
- [x] `config/wm-elasticsearch.php` dichiara `name_translations` come `object`, senza
      `enabled: false`: le lingue vengono create da Elastic come negli indici esistenti, dove il
      campo nasce in modo dinamico al primo documento. Così il campo è uguale negli indici vecchi e
      in quelli ricreati con `scout:import`.
- [x] L'endpoint di ricerca, per ogni risultato che ha `name_translations` non vuoto, mette quel
      valore al posto di `name`; un risultato senza `name_translations` (indice non ancora
      reindicizzato) o con il campo vuoto mantiene il `name` di oggi. In entrambi i casi
      `name_translations` viene tolto dal risultato: la risposta contiene solo `name`, il campo che
      legge il frontend, con gli stessi campi di oggi.
- [x] La risposta resta compatibile con il frontend di oggi: nessuna modifica a wm-core,
      webmapp-app o wm-types.
- [x] Test automatici nel package: `toSearchableArray()` produce `name_translations` con le sole
      lingue valorizzate e lascia `name` in italiano; la trasformazione della risposta sostituisce
      `name` solo quando `name_translations` è presente e non vuoto, anche su un insieme misto di
      risultati, e nessun risultato restituito contiene `name_translations`.
- [x] Documentato che `name` nell'indice (stringa italiana) e `name` nella risposta dell'API
      (oggetto con tutte le lingue) sono diversi di proposito: un commento nel codice sul punto in
      cui l'API fa la sostituzione, e la pagina di conoscenza sulla ricerca delle tracce in
      `docs/knowledge/`, con il perché del campo separato e dell'opzione scartata (`name` come
      oggetto).
- [ ] Verifica manuale su maphub dev dopo merge e bump: «Reindicizza Scout» sull'app 3 (lanciato
      dal dev); con l'app in inglese, la ricerca «Loop» mostra nella card della traccia 57
      «V6 - Loop of Vecchiano».

## Rischi

- **Indice misto dopo il bump.** Finché su uno shard non si lancia «Reindicizza Scout», solo le
  tracce salvate dopo il bump hanno `name_translations`; le altre restano con il nome italiano,
  come oggi. Mitigazione: la sostituzione avviene risultato per risultato, con ricaduta sul `name`
  di oggi.
- **«Reindicizza Scout» lavora per app** (`ReindexAppScoutAction`): su uno shard con più app va
  lanciato su ciascuna. Le app saltate restano come oggi, senza errori.

## Out of scope

- Gli accenti dentro `searchable`: `json_encode` senza `JSON_UNESCAPED_UNICODE` li codifica
  (`Est\u00e9ron`) e resta così. Per i nomi non ha più effetto: `name_translations` è ricercabile
  (vedi sotto, «Moduli toccati» e `notes.md`, «Decisioni»), quindi una parola accentata presente solo
  in un nome tradotto viene trovata da lì. Resta per le descrizioni e gli altri testi di
  `searchable`.
- I nomi salvati sotto la lingua sbagliata su Geohub (per esempio la traccia 76 «Boucle 1 – Circuit
  de Santa Maria Assunta», testo francese sotto `it`): è un dato di origine, non dipende da questo
  codice.
- Rimuovere dal mapping i sottocampi di `name` non più usati dalle query (`exact`, `phrase`, `edge`,
  `completion`).
- Il lancio di «Reindicizza Scout» sugli shard diversi da maphub dev.

## Moduli toccati

Tutti in **wm-package**:

- `src/Models/EcTrack.php` — costante `SEARCH_NAME_TRANSLATIONS_FIELD` e nuovo campo
  `name_translations` in `toSearchableArray()`.
- `config/wm-elasticsearch.php` — dichiarazione di `name_translations` (`object`).
- `src/Http/Controllers/Api/ElasticsearchController.php` — `localizeSearchResults()`: sostituzione di
  `name` con `name_translations` nei risultati. `name_translations` è anche ricercabile, perché la
  query cerca su tutti i campi: per le app con la configurazione standard i risultati non cambiano.
- `tests/` — test su `toSearchableArray()` (`tests/Unit/Models/EcTrackSearchableArrayTest.php`),
  sulla trasformazione della risposta (`tests/Unit/ElasticsearchControllerLocalizeSearchResultsTest.php`)
  e sul mapping (`tests/Unit/Config/WmElasticsearchMappingTest.php`).
- `docs/knowledge/nome-delle-tracce-nella-ricerca.md` — pagina nuova, con il motivo del campo
  separato e le opzioni scartate; riga nell'indice `## Conoscenza` di `CLAUDE.md`.
- `.claude/rules/modelli.md` — due trappole: niente oggetti in un campo già dichiarato testo;
  «Reindicizza Scout» non applica un mapping cambiato.

In **maphub** nessun file: solo il bump del submodule, a cura dei dev.

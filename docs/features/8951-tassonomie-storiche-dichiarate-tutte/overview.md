> Ticket: oc:8951

# Le tassonomie storiche le dichiara il package, lo shard sottrae

## Cosa cambia

`config/wm-package.php` → `legacy_taxonomies` dichiara anche i **Tipi POI**
(`taxonomy_poi_types`, relazione `taxonomyPoiTypes`, tab «General»), accanto alle Attività che
c'erano già. La chiave di traduzione `"Poi Types"` è aggiunta nelle cinque lingue del package.

Nient'altro: nessun modello, nessuna migration, nessun campo Nova nuovo. Il meccanismo che
costruisce i campi da queste dichiarazioni (`BuildsTaxonomyFields`) è quello di oc:8910 e non
viene toccato.

## Perché

`legacy_taxonomies` è nato in oc:8910 per descrivere **ogni** tassonomia che vive ancora in una
tabella propria — lo dice la pagina di conoscenza di quel lavoro — ma ne dichiarava una sola, le
Attività. Un consumer che usa i Tipi POI doveva quindi riscrivere da sé relazione, modello,
etichetta e tab di una tassonomia che il package già conosce; e un consumer che non lo sa fare
non vede affatto una classificazione che esiste.

L'elenco completo è conoscenza del modello dati, e il modello dati sta qui. Quali tassonomie
servano è invece una scelta del progetto: ogni shard sottrae le proprie sovrascrivendo
`legacyTaxonomies()` nella sua Resource Nova. Il criterio opposto — il package dichiara il minimo,
lo shard aggiunge — sposta sul consumer una conoscenza che non è sua.

## Requisiti

- [x] `legacy_taxonomies` dichiara `activities` e `poi_types`.
- [x] La voce dei Tipi POI non compare sulle tracce: solo `EcPoi` ha `taxonomyPoiTypes`, e
      `BuildsTaxonomyFields` salta con `method_exists` le voci che il modello non ha.
- [x] `"Poi Types"` tradotta in `it`, `en`, `fr`, `es`, `de`.
- [x] Nessun consumer perde qualcosa senza fare niente: chi non sovrascrive `legacyTaxonomies()`
      si ritrova i Tipi POI dichiarati, che è il comportamento voluto.

## Rischi

**Un consumer si ritrova il campo dei Tipi POI accanto alla propria lista di attach.** Chi già
mostra `taxonomyPoiTypes` con un `MorphToMany` avrà due controlli sulla stessa relazione nella
stessa pagina, e due controlli su una relazione si sovrascrivono a vicenda: quello che non mostra
tutte le righe stacca le altre al primo salvataggio. È lo stesso difetto descritto in
`docs/knowledge/8910-tassonomie-cosa-controllare-al-bump-dello-shard.md` per il Layer di Forestas.
*Mitigazione:* il consumer toglie la lista di attach, come ha fatto camminiditalia, oppure
sottrae la voce da `legacyTaxonomies()`.

**Il costo di costruzione cresce con le righe della tabella.** Le opzioni si ricavano caricando
tutta la tabella della tassonomia: 164 righe per i Tipi POI di camminiditalia sono trascurabili,
ma il criterio non scala da sé — sul Layer le Zone sono 7.930 righe con le geometrie, ed è il
motivo per cui quel consumer non dichiara nulla sul cammino.

## Out of scope

- Convertire le tassonomie storiche in `taxonomy_terms`: non esiste un command di travaso, e i
  dati alimentano i filtri della mappa e `pois.geojson`.
- Dichiarare i **Temi**: non hanno una relazione sui contenuti EC, quindi questo meccanismo non
  li raggiunge.
- La rotta v1 `/{id}/pois.geojson`, che risponde 500 perché il parametro non combacia con
  `pois(App $app)`: difetto preesistente, trovato durante questo lavoro, da trattare a parte.

## Moduli toccati

| File | Cosa |
|---|---|
| `config/wm-package.php` | la voce `poi_types` in `legacy_taxonomies` e il suo import |
| `resources/lang/{it,en,fr,es,de}.json` | la chiave `"Poi Types"` |

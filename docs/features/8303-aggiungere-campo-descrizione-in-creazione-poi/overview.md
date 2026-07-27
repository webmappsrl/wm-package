> Ticket: oc:8303

# Aggiungere campo descrizione in creazione POI

## Cosa cambia

Il campo "Descrizione" (`properties->description`, traducibile, editor rich-text Tiptap) viene spostato dal pannello dinamico "Proprietà" (schema-driven, nascosto quando vuoto) a un campo statico dichiarato direttamente nel tab **Details > Info** di `EcPoi` e `EcTrack`, accanto agli altri campi già sempre visibili lì (es. `Contact email`, `Not Accessible`). In questo modo il campo compare sempre, sia in creazione che in modifica, con lo stesso editor rich-text traducibile già in uso oggi.

## Perché

Un gestore di cammino segnala che per aggiungere la descrizione a un POI è necessario prima crearlo, poi accedere in modifica e compilare il campo lì. Il workflow corretto dovrebbe permettere di inserirla già nella schermata di creazione, senza il doppio passaggio.

**Nota di percorso decisionale:** l'approccio iniziale valutato (rendere visibile l'intero pannello dinamico `PropertiesPanel` quando esiste uno schema statico, indipendentemente dai dati) è stato scartato dopo la Fase: challenge — ha rivelato un bug pre-esistente in `PropertiesPanel::jsonForm()`: la selezione dello schema statico per `EcPoi` dipende solo da `columnName` (sempre `properties`), non dall'`attribute` richiesto, quindi lo schema "Descrizione" verrebbe applicato per errore anche ai pannelli `properties->ugc` e `properties->form` (oggi mascherato perché quei pannelli restano vuoti e quindi nascosti). L'approccio a campo statico evita del tutto questo rischio: non tocca `PropertiesPanel` (metodo condiviso da 6+ resource in tutti i consumer del package) né richiede di discriminare per attributo.

## Requisiti

- [ ] Aggiungere un campo `description` traducibile (`NovaTabTranslatable::make([Tiptap::make(...)])`, stessi bottoni/config di `PropertiesPanel::tiptapButtons()`) in `getInfoTabFields()` di `EcPoi` (`wm-package/src/Nova/EcPoi.php:71-82`), bound a `properties->description` — visibile sempre, sia in creazione che in modifica
- [ ] Stesso campo aggiunto in `getInfoTabFields()` di `EcTrack` (`wm-package/src/Nova/EcTrack.php:119-129`), bound a `properties->description`
- [ ] Rimuovere la entry `description` da `wm-package/config/wm-ec-poi-schema.php` e da `wm-package/config/wm-ec-track-schema.php` — altrimenti in modifica comparirebbero **due** editor per lo stesso dato (uno nel tab Info, uno nel pannello dinamico "Proprietà")
- [ ] Nessuna modifica a `Layer` (fuori scope in questo approccio: `Layer` non passa più dal fix condiviso, resta con il comportamento attuale)
- [ ] Nessuna modifica agli altri campi dello schema EcTrack (`excerpt`, `ref`, `from`, `to`, `geohub_id`, `taxonomy_where`) — restano nel pannello dinamico, stesso comportamento di oggi (bug noto, non in scope)

## Rischi

- Duplicazione dato se la rimozione dallo schema config viene dimenticata: se `description` resta sia nel campo statico sia nello schema config, un utente vedrebbe due editor indipendenti che scrivono sullo stesso path JSON, con possibile sovrascrittura silenziosa dell'ultimo salvato — mitigato rendendo la rimozione dallo schema config un requisito esplicito, non opzionale
- Se `wm-ec-poi-schema.php` rimane con `fields: []` dopo la rimozione, il pannello dinamico "Proprietà POI" per EcPoi diventa orfano (nessun campo) — comportamento accettabile (il pannello semplicemente non compare mai più, essendo `description` l'unico campo previsto), ma da verificare che `PropertiesPanel::makeWithModel` non sollevi errori con schema a fields vuoto

## Out of scope

- Nessuna modifica al tipo di editor (Tiptap resta l'editor per `description`)
- Nessuna modifica alla gestione permessi di creazione EcPoi (rimane Administrator-only in camminiditalia, vedi oc:8120) — coperto dal ticket separato oc:8304 (gestione autonoma POI da parte dei gestori di cammino)
- Nessuna modifica a `PropertiesPanel::makeWithModel()`/`hasDataForPath()` — il bug di dispatch schema per `properties->ugc`/`properties->form` resta noto ma non toccato in questo ciclo
- Nessuna modifica a `Layer` né agli altri campi dello schema `EcTrack` (`excerpt`, `ref`, `from`, `to`, `geohub_id`, `taxonomy_where`)

## Moduli toccati

- `wm-package/src/Nova/EcPoi.php` — nuovo campo `description` in `getInfoTabFields()`
- `wm-package/src/Nova/EcTrack.php` — nuovo campo `description` in `getInfoTabFields()`
- `wm-package/config/wm-ec-poi-schema.php` — rimozione entry `description`
- `wm-package/config/wm-ec-track-schema.php` — rimozione entry `description`
- `wm-package/tests/Feature/` — nuovo test Feature (con `Wm\WmPackage\Tests\TestCase`) che verifica: il campo `description` è presente nei field di `EcPoi`/`EcTrack` sia in contesto creazione sia modifica, e non è più presente come field del pannello dinamico "Proprietà"

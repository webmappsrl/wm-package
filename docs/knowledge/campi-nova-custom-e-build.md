# Campi Nova custom: build, CSS e componenti condivisi

Come si costruisce un field Nova custom in questo package, senza `laravel-nova-devtool`.

## Stato attuale

### La toolchain

`Nova::mix()` e `laravel-nova-devtool` sono un pacchetto privato, non sempre disponibile.
L'alternativa in uso è `laravel-mix` + `vue` + `laravel-nova` (pubblici su npm), con un
`webpack.mix.js` che dichiara:

- `externals: { vue: 'Vue' }` — senza, la build include una **seconda copia di Vue** e il campo non
  renderizza: nessun errore, il tab appare vuoto.
- `webpack` pinnato **esatto**, mai con `^`: `laravel-mix@6` porta `webpack-cli@4`, che si rompe
  a ogni cambio di API interne di webpack. Il numero non è uno solo nel package — `TranslationsBuilder`
  sta a `5.75.0` (oc:7546, `ProgressPlugin`), `BboxField` e il campo del registro a `5.103.0` — e
  diversi campi non lo dichiarano affatto, affidandosi al proprio `package-lock.json`. Quello che
  vale per tutti è il limite superiore: **la 5.103 compila, la 5.111 no** — `laravel-mix` cerca
  `webpack/lib/SizeFormatHelpers`, che in mezzo è stato rimosso. Dove cada esattamente il confine
  fra le due non è stato cercato: 5.103 è il valore verificato che funziona, e non c'è motivo di
  salire. Un campo nuovo senza versione dichiarata e senza lock prende l'ultima e fallisce in
  compilazione (oc:8568).

**Ogni nuovo campo custom con build CSS deve avere il proprio `postcss.config.js`**
(`module.exports = {}`). Senza, `npm run prod` risale l'albero delle cartelle e trova il
`postcss.config.js` (ESM) della root del consumer, con un `ERR_REQUIRE_ESM` (oc:8241). Per lo
stesso motivo `LayerAnalyticsCard.vue` non ha `<style scoped>`: gli stati hover e focus sono
binding `:style` reattivi su una variabile Vue, non CSS `:hover` (oc:8182).

### Tailwind: le classi che non esistono

Nessun campo custom del package compila una build Tailwind propria: si riusano solo le classi già
presenti nel CSS compilato di Nova. **Una classe Tailwind che Nova non usa nella propria UI viene
eliminata dal suo purge e non ha alcun effetto** — scoperto con `bg-opacity-50`, `px-5`, `gap-3`,
`mb-1`/`mb-4`/`mb-5`.

Regola pratica: usare solo classi verificate con un grep su
`vendor/laravel/nova/resources/{js,ui}`; altrimenti CSS in `<style scoped>` (compilato nel nostro
bundle da vue-loader) o `style` inline (oc:7546).

### Modali

`<Teleport to="body">` è **obbligatorio** per un modale dentro i pannelli tab di Nova: senza, un
modale `position: fixed` risulta disallineato orizzontalmente, perché il containing block è
alterato da un `transform` in catena. Pattern già in `FeatureCollectionMap.vue`, identico al
`Modal.vue` nativo di Nova (oc:7546).

### Componenti condivisi

`src/Nova/Fields/_shared/resources/js/` ospita `components/{Button,SelectInput,TextInput}.vue` e
`utils/collectAllKeys.js`: prima non esisteva alcun componente riutilizzabile fra i campi custom
del package. Le classi di `Button.vue` replicano esattamente il `<Button>` nativo di Nova
(varianti `solid`/`ghost`/`danger`, size large), quindi sono garantite presenti nel suo CSS. Per
importarli da un nuovo field il path relativo è
`../../../../_shared/resources/js/components/...` (oc:7546).

### Il `dist` versionato

Il `dist` di ogni field è committato nel repo, quindi va sempre ricompilato dalla sorgente
**verificata**: il commit `fb3c0555` conteneva in `FeatureCollectionMap` una prop
`"inline-geojson":A.field.geojson||null` mai presente in `DetailField.vue`, perché compilata da una
working copy locale non committata.

Prima di ogni `npm run prod`, controllare che la sorgente non abbia prop spurie; dopo, fare un grep
sul dist per accertarsene (oc:8093, oc:8043).

### Altri vincoli sui field

- `dependsOn()` di Nova non raggiunge i campi annidati in un `Repeater` dentro un `Flexible`
  (verificato in `vendor/laravel/nova/src/Http/Controllers/UpdateFieldController.php`): serve un
  field custom (oc:8241).
- Nova propaga correttamente le `rules()` di un field dentro un `Repeater` tramite
  `Repeater::formatRules()` (oc:8241).
- `Multiselect::options()` accetta **un solo argomento**: il secondo — i valori pre-selezionati —
  viene ignorato silenziosamente da PHP. La pre-selezione la fa Nova dall'attributo del modello
  (oc:7953).
- Il titolo di una Resource Nova **non può essere una colonna enum**: `public static $title =
  'type'` fa convertire l'enum in stringa e produce un 500 in pagina — serve un metodo `title()`.
  E i modelli devono dichiarare **nullable** anche le colonne obbligatorie, perché Nova costruisce
  i campi su un'istanza vuota per ricavare le colonne dell'elenco: un `match` sull'enum senza ramo
  `null` esplode lì (oc:8489).
- `PropertiesPanel::makeWithModel()` nasconde l'intero pannello se `hasDataForPath()` non trova
  dati per il path richiesto — condizione sempre vera su un record nuovo. Un campo che deve essere
  visibile in creazione va dichiarato staticamente in `getInfoTabFields()`, fuori dal pannello
  dinamico (oc:8303).
- Nei test un `Panel` si ispeziona con `Panel::$data`, non `$fields`: `Laravel\Nova\Panel` estende
  `Illuminate\Http\Resources\MergeValue`, che espone `$data` come proprietà pubblica (oc:8303).
- Le traduzioni del package stanno in `resources/lang/*.json`, **non** in `lang/`: scrivere
  altrove produce chiavi silenziosamente non caricate (oc:8180).

### Dove stanno le classi PHP di un campo

Quasi tutti i campi tengono le classi in `<Campo>/src/`, ma in due modi diversi, e la differenza
conta:

- `TrackColor`, `OrderList`, `PoiTrackReferenceField` **non** hanno una voce PSR-4 propria: si
  fanno caricare dalla mappatura generale `Wm\WmPackage\ → src` mettendo `\src` dentro il
  namespace (`namespace Wm\WmPackage\Nova\Fields\TrackColor\src;`);
- `TranslationsBuilder`, `IconSelect`, `LayerFeatures`, `FeatureCollectionMap`,
  `FeatureCollectionGrid` hanno una voce dedicata in `composer.json`, che salta quel `src` nel
  namespace.

**Il secondo modo non va usato per un campo nuovo.** L'autoload del consumer non legge il
`composer.json` del package dal vivo, ma la copia registrata in `vendor/composer/installed.json`
quando il package è stato installato: una voce PSR-4 aggiunta oggi non esiste per nessun consumer
finché non esegue `composer update`, e fino ad allora il campo è una classe che non si trova —
errore a **ogni** pagina di Nova, perché il provider è registrato all'avvio, non solo dove il campo
è usato.

Un campo nuovo sceglie quindi fra le due strade che non richiedono nulla al consumer: classi
direttamente nella cartella del campo, oppure in `src/` con `\src` nel namespace. Il campo del
registro sentieri usa la prima (oc:8568).

### JavaScript di un dominio opzionale

`resources/js/domains/<dominio>.js` è caricato da `registerEnabledDomains()` solo a dominio acceso
e registra i componenti con una **render function**, non un template come stringa: il Vue di Nova è
la build runtime-only e non compilerebbe il template, mentre la globale `Vue` è garantita — le card
già compilate del package ci fanno `externals`. Un dominio non richiede quindi un bundle proprio
(oc:8489).

## Come ci siamo arrivati

- `PropertiesPanel::jsonForm()` seleziona lo schema statico per `EcPoi` solo in base a
  `columnName` (sempre `'properties'`), ignorando `$attribute`: il ramo che dovrebbe caricare lo
  schema UGC-specifico per `properties->ugc` è codice morto, intercettato sempre prima dal ramo
  generico. Innocuo finché lo schema POI è vuoto, riemergerebbe aggiungendo campi a
  `wm-ec-poi-schema.php`. Trovato ma non corretto (oc:8303).
- `AbstractEcResource::tiptapButtons()` duplica intenzionalmente la configurazione toolbar di
  `PropertiesPanel::tiptapButtons()` e `Nova\App::tiptapButtons()`: non c'è base class condivisa
  fra Nova Fields e Nova Resources, e un trait comune richiederebbe di toccare `PropertiesPanel`
  (oc:8303).
- `deleteTranslation(key)` nel builder traduzioni App era "out of scope" nella prima stesura:
  rimessa dopo aver verificato che senza, ogni chiave inserita per errore resta cruft permanente
  rimovibile solo dal DB — non praticabile per l'utente non-developer a cui la feature è
  destinata (oc:7546).
- Estendere il builder traduzioni oltre it/en richiede una migration per le colonne
  `translations_<lang>`, il cast nel modello `App` e la generalizzazione di
  `AppConfigService::config_section_translations()` (oggi hardcoded su it/en). Il campo Vue e la
  classe PHP sono già generici su `langs`. Probabile fonte lingue:
  `config('wm-tab-translatable.locales')` (oc:7546).
- La validazione client-side degli upload (prima del submit) è fuori scope: richiederebbe di
  estendere il componente Vue del field Ebess o un field custom (oc:8247).

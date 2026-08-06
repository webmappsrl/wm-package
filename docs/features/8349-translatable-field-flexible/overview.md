> Ticket: oc:8349

# Analisi: supporto nativo ai campi Translatable nel componente Flexible

## Cosa cambia

Si introduce in `wm-package` un nuovo Nova Field, sottoclasse di `kongulov/nova-tab-translatable` (`NovaTabTranslatable`), che overrida `createTranslatedField()` per rimuovere la dipendenza dal vero Eloquent Model (type-hint `Model $model`, chiamata a `$model->setTranslation()` del trait Spatie `HasTranslations`) e la sostituisce con logica che legge/scrive direttamente sull'attributo esposto dal "finto modello" `Layout` di `whitecube/nova-flexible-content`. La UI a tab-per-lingua di `NovaTabTranslatable` viene quindi riusata as-is dentro i `Layout`/`Repeatable` di Flexible, dove oggi non può funzionare.

Il nuovo field sostituisce il metodo `translatableFields()` di `HasFlexibleTranslatableFields` (campo `KeyValue` fisso, una riga per lingua), usato in **4 punti** (correzione finale emersa leggendo per intero `App.php` e i resolver Flexible in fase di scrittura del piano):

1. `App::config_home_title_layout()` → `translatableFields('Title', 'title', required: true)`, riusato da 5 layout del Flexible `config_home`: Titolo, Horizontal Scroll Activities, Horizontal Scroll POI Types, Slug, External URL
2. `App::overlays_title_layout()` → `translatableFields('Label', 'label')`, layout "Titolo" di un secondo campo Flexible su App (`ConfigOverlaysResolver`, non ancora citato nella prima versione dell'overview)
3. `HorizontalScrollItemRepeatable::fields()` → `title` a livello di riga del repeater `items` (dentro i layout Horizontal Scroll Activities/POI Types)
4. `InfoBoxItemRepeatable::fields()` → `title` a livello di riga del repeater `items` (config_detail, Box Informativi)

...più il meccanismo hand-coded con N campi `Trix` (uno per locale) e `fillUsing` che sanifica via `HTMLPurifier` — usato per il `content` in `InfoBoxItemRepeatable` (unico punto).

`decodeTranslatableValue()` **(secondo metodo dello stesso trait) resta invariato, non viene rimosso**: è un helper di sola lettura usato da tre resolver Flexible (`ConfigHomeResolver`, `ConfigOverlaysResolver`, `ConfigDetailResolver`) per decodificare i valori tradotti già salvati — è già difensivo (accetta sia una stringa JSON, formato storico prodotto dal vecchio KeyValue, sia un array già decodificato, formato prodotto dal nuovo field), quindi resta compatibile con entrambi i formati senza modifiche. Solo `translatableFields()` viene rimosso dal trait; il file del trait resta, ridotto a questo solo metodo.

**Nota tecnica sui "modelli" attraversati (rilevante per l'implementazione):** i 4 punti di consumo passano per due meccanismi Nova diversi con contratti diversi per le closure `resolveUsing`/`fillUsing` del nuovo field:

- `App::config_home_title_layout()`/`overlays_title_layout()`: campi diretti di un `Whitecube\NovaFlexibleContent\Layouts\Layout` — sia `fill` che `resolve` ricevono l'istanza `Layout` (accesso `->` sempre valido).
- `HorizontalScrollItemRepeatable`/`InfoBoxItemRepeatable`: campi di riga di un `Laravel\Nova\Fields\Repeater\Repeatable` nativo Nova (diverso da `Layout`) — in **fill** il "modello" è un'istanza fresca di `Laravel\Nova\Support\Fluent` (accesso `->` valido), in **resolve** è invece un **array PHP grezzo** (accesso `->` non valido, serve `data_get()`/accesso ad array). Il nuovo field deve usare accessor agnostici (`data_get()` in lettura) per funzionare in entrambi i contesti.

`whitecube/nova-flexible-content` non viene toccato, forkato né sostituito: non è la causa del problema. La classe `Layout` del package è già agnostica rispetto al tipo di campo — delega a `Field::resolve()`/`Field::fill()` nativi di Nova per qualsiasi campo standard. Il blocco reale è isolato in `NovaTabTranslatable::createTranslatedField()`, che assume un vero modello Eloquent con trait Spatie.

Il formato dati persistito in `properties` JSON resta identico a quello attuale (stesso schema chiave→lingua per il `title`, stesso schema `content_{locale}` sanificato per il `content`): nessuna migrazione dati necessaria, nessuna riscrittura dei record già esistenti.

## Perché

Emerso durante l'implementazione dei Box Informativi (oc:8181, ticket padre), ma il problema è trasversale a ogni uso di Flexible nella codebase. Oggi ogni punto che ha bisogno di campi traducibili dentro un layout Flexible reinventa una propria soluzione ad-hoc, invece di riusare `NovaTabTranslatable` — il meccanismo standard di traduzione già adottato ovunque nel resto del progetto (`App`, `Layer`, `EcTrack`, `Tile`, `FeatureCollection`, ecc.) — perché quel field presuppone un vero Eloquent Model con `HasTranslations` di Spatie, incompatibile con il "finto modello" `Layout` di Flexible (niente cast array/json, niente `setTranslation()`).

Consolidare su un unico field riduce la duplicazione di pattern (UI diversa da quella standard, comportamento da ricordare caso per caso) e rende disponibile per il futuro un meccanismo drop-in per qualunque nuovo uso di Flexible che richieda campi traducibili.

## Requisiti

- [ ] **Spike di validazione (gate, da fare prima di tutto il resto):** prototipo minimale del nuovo field su un solo campo semplice (`title`), con test che usa un'istanza **reale** di `Whitecube\NovaFlexibleContent\Layouts\Layout` (non uno stub `stdClass`) e verifica che il JSON persistito sia byte-per-byte identico a quello prodotto oggi da `HasFlexibleTranslatableFields`. `NovaTabTranslatable::createTranslatedField()` rinomina l'attributo del campo tradotto in `translations_{attr}_{locale}` (usato solo per l'unicità del campo nel form Vue) mentre la persistenza reale sul modello avviene tramite `$originalAttribute` catturato via closure — l'override deve scrivere sul `Layout` usando `$originalAttribute`, non il parametro `$attribute` rinominato passato alla closure, altrimenti il dato finisce silenziosamente sotto una chiave diversa da quella storica. Solo dopo che questo spike conferma la parità di formato si procede con gli altri requisiti sotto.
- [ ] Nuovo Nova Field in `wm-package`, sottoclasse di `NovaTabTranslatable`, che overrida `createTranslatedField()` per non dipendere da un vero Eloquent Model / `setTranslation()`, operando invece sull'attributo esposto dal `Layout` Flexible (fake-model)
- [ ] Il nuovo field supporta sia campi semplici (equivalente all'attuale `title`/KeyValue) sia rich text, con la sanificazione HTMLPurifier preservata (stessa whitelist di tag oggi in `InfoBoxItemRepeatable`)
- [ ] `whitecube/nova-flexible-content` non viene modificato, forkato né sostituito
- [ ] Il nuovo field sostituisce `HasFlexibleTranslatableFields`/KeyValue in tutti i 4 punti di consumo attuali: `App::config_home_title_layout()`, `App::overlays_title_layout()`, `HorizontalScrollItemRepeatable` (config_home), `InfoBoxItemRepeatable` (config_detail, sia `title` che `content`)
- [ ] Formato dati persistito in `properties` JSON identico a quello attuale — round-trip compatibile con i record già esistenti in produzione/db locale, nessun comportamento visibile può regredire
- [ ] Test automatico di regressione che verifica che il round-trip (salvataggio → lettura) prodotto dal nuovo field sia identico a quello prodotto dalla vecchia implementazione, per entrambi i punti di consumo — incluso almeno un caso con traduzioni parziali (non tutte le lingue compilate), il caso più comune nei dati reali
- [ ] `HTMLPurifier` istanziato una sola volta e riusato tra le lingue nella nuova closure, non ricreato per ogni locale (evita una regressione di performance rispetto al pattern attuale)
- [ ] Verifica manuale una-tantum sui record reali già esistenti nel db locale (Box Informativi e Horizontal Scroll già configurati) prima di considerare la feature conclusa
- [ ] Se durante l'implementazione la sottoclasse si rivela non pulita/fattibile come previsto dall'analisi, documentare i limiti trovati in `notes.md` e tornare dal dev prima di adottare un approccio più invasivo (fork/patch del vendor)
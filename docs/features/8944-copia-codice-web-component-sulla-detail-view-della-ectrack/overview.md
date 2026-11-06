> Ticket: oc:8944

# Copia codice web component sulla detail view della EcTrack

## Cosa cambia

La detail view della Resource EcTrack mostra un Field «Web Component» con il bottone «Copia codice web component», nello stesso punto e con lo stesso aspetto di quello della Resource Layer (`src/Nova/Layer.php:150`). Il codice copiato è lo snippet `wm-layer-map` del layer con in più l'attributo `track-id` della traccia: il widget apre il cammino con la tappa già selezionata (parametro introdotto da oc:8937 in wm-elements).

Una EcTrack può stare in più layer: se i layer proponibili sono più di uno, sopra il bottone compare una select per scegliere quale; se è uno solo la select non compare e il bottone copia direttamente lo snippet di quel layer.

## Perché

Il cliente oggi copia lo snippet dal cammino e aggiunge a mano la riga `track-id` (procedura di oc:8937). Il bottone sulla traccia elimina il passaggio manuale e gli errori che porta (id sbagliato, `layer-id` di un cammino diverso da quello della tappa). Decisione presa allo scrum del 06/11/2026, che supera quella del 05/11 riportata in oc:8937.

## Requisiti

- [ ] Field `Text` «Web Component» `asHtml()->onlyOnDetail()` nell'array principale restituito da `fields()` della Resource EcTrack, come sul Layer (Panel principale, non dentro il `Tab::group` Details)
- [ ] Lo snippet della traccia è quello del Layer più `track-id="<id EcTrack>"`; `shard`, `app-id` (del layer scelto), `layer-id`, tag, script URL e stile vengono dallo stesso generatore usato dal Layer, condiviso fra le due Resource senza duplicare il codice
- [ ] I layer proponibili sono quelli della relazione `layers()` della traccia la cui App proprietaria (`appOwner`) ha il flag `properties->layer_web_component_enabled` acceso
- [ ] L'insieme dei layer proponibili passa da un metodo `protected` della Resource EcTrack, sovrascrivibile dai consumer per restringerla (in camminiditalia: ai layer di cui l'utente è owner)
- [ ] Ogni option della select mostra nome e id del layer (`getStringName()` + `(#<id>)`), perché esistono layer diversi con lo stesso nome
- [ ] Option ordinate per id del layer crescente, con la prima preselezionata: lo stesso risultato a ogni caricamento
- [ ] Ogni option porta il proprio snippet in un attributo `data-snippet`; il bottone copia quello dell'option selezionata al momento del click, senza stato JS da sincronizzare
- [ ] Escaping: il nome del layer in output solo via `htmlspecialchars`; gli snippet in attributi `data-*` escapati o con il doppio passaggio `json_encode(JSON_HEX_*)` + `htmlspecialchars` già usato dal Layer; nessuna stringa JS costruita concatenando testo utente
- [ ] Con un solo layer proponibile la select non compare; con nessuno il Field non compare (`canSee` false)
- [ ] Il cambio di option nella select cambia lo snippet copiato, senza ricaricare la pagina
- [ ] Il flag resta uno solo, `layer_web_component_enabled` (chiave invariata): cambiano solo label e help del Boolean nella Resource App, in termini generici validi per ogni shard: en «Enable Web Component copy (layers and tracks)» / help «Shows a button in layer and track details to copy the wm-layer-map web component snippet»; it «Abilita copia Web Component (layer e tracce)» / help «Mostra nel dettaglio di layer e tracce un bottone per copiare il codice del web component wm-layer-map»
- [ ] Tutte le chiavi di traduzione nuove o modificate presenti in `resources/lang/{de,en,es,fr,it}.json` (lingua base: inglese); le chiavi del bottone oggi solo in `en`/`it` vengono completate in `de`/`es`/`fr`, perché ora le usa anche la EcTrack
- [ ] Test in `tests/Feature/` accanto a `LayerWebComponentCopyButtonTest.php`: snippet della traccia con `track-id`, filtro dei layer per flag dell'App, select assente con un solo layer, Field assente senza layer, ordine delle option, `data-snippet` corretto per ogni option, nome di layer con apostrofo e `<` escapato; i test esistenti del Layer restano verdi

## Rischi

- **Rilascio prima del widget**: finché `wm-elements@dist` non legge `track-id`, lo snippet apre il cammino intero. Mitigazione: il rilascio di oc:8944 segue la pubblicazione di oc:8937 (deciso col dev); il flag non può fare da interruttore perché è condiviso coi cammini.
- **Altri shard**: il flag è unico, quindi ogni shard che ha già acceso il bottone sui layer se lo trova anche sulle tracce al primo deploy di wm-package con questa modifica. Accettato: stesso widget, stesso interruttore.

- **JS non coperto dai test**: PHPUnit verifica l'HTML, non l'esecuzione del cambio di option. Mitigazione: JS minimo basato su `data-snippet` e verifica manuale nel browser (EcTrack 522 come Administrator, scelta #63, snippet incollato con `layer-id="63"`).
- **Testo utente in HTML/JS inline**: i nomi dei layer contengono apostrofi (10 sul DB locale di camminiditalia). Mitigazione: requisito di escaping e test dedicato.

## Out of scope

- Modifiche al widget `wm-layer-map` (oc:8937, repo wm-elements)
- Rinomina della chiave `layer_web_component_enabled`
- Bottone su altre Resource (EcPoi)
- Comportamento del widget con un `track-id` orfano (tappa cancellata dopo l'embed): lo decide il widget, oc:8937
- Versionamento dello script URL (`wm-elements@dist`): già così per lo snippet del Layer

## Moduli toccati

- `src/Nova/EcTrack.php` — Field e metodo dei layer proponibili
- `src/Services/LayerMapWebComponentService.php` (nuovo) — snippet con `track-id` opzionale, controllo del flag e bottone di copia, condivisi
- `src/Nova/Layer.php` — il Field usa il service; tolti costante, JS e metodi privati del bottone
- `src/Models/App.php` — docblock di `renderCopyLinkButton()` riallineato
- `src/Nova/App.php` — label e help del Boolean
- `resources/lang/{de,en,es,fr,it}.json`
- `tests/Feature/Nova/` — `LayerMapWebComponentServiceTest`, `EcTrackWebComponentCopyButtonTest`
- `tests/Feature/LayerWebComponentCopyButtonTest.php` — riscritto col `TestCase` del package

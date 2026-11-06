# Bottone «Copia codice web component» su Layer ed EcTrack

## Come funziona oggi

Sulla detail view della Resource Layer e della Resource EcTrack un Field `Text` «Web Component»
(`asHtml()->onlyOnDetail()`) copia negli appunti lo snippet del web component `wm-layer-map`
(repo `webmappsrl/wm-elements`), da incollare in un sito esterno.

- **Un solo punto per snippet e bottone**: `src/Services/LayerMapWebComponentService.php`.
  `snippet($layer, ?$trackId)` produce il tag con `shard`, `app-id` e `layer-id` del layer, più
  `track-id` subito dopo `layer-id` quando c'è una traccia. `renderCopyButton($options)` disegna il
  bottone. Con una sola option lo snippet sta in `data-snippet` sul bottone; con più option compare
  una `<select>` e ogni `<option>` ha il suo `data-snippet`, con la prima preselezionata. Al click il
  JS legge lo snippet dell'option selezionata in quel momento.
- **Sulla Layer** lo snippet è quello del cammino, senza `track-id`, e non c'è mai la select.
- **Sulla EcTrack** lo snippet ha anche `track-id`: il widget apre il cammino con la tappa già
  selezionata (parametro introdotto in wm-elements da oc:8937). Le option sono i layer della traccia
  restituiti da `EcTrack::webComponentLayers()`, ordinati per id; la label è
  `getStringName() (#id)`, perché esistono layer diversi con lo stesso nome.
- **Un solo flag per entrambi**: `properties->layer_web_component_enabled` dell'App proprietaria del
  layer (`isEnabledFor()`). La chiave dice ancora «layer» ma vale anche per le tracce; label e help
  del Boolean nella Resource App parlano di «layers and tracks».
- **Punto di estensione per gli shard**: `protected webComponentLayers(NovaRequest): Collection` su
  `Nova\EcTrack`. Uno shard lo sovrascrive per restringere i layer proposti, **partendo da
  `parent::`**, che applica il filtro sul flag (esempio: camminiditalia, che ai non-Administrator
  propone solo i layer di cui sono owner).
- **Niente query fuori dalla detail view**: su edit e update Nova risolve e autorizza i Field prima
  di scartare quelli `onlyOnDetail`. Per questo i layer si calcolano in modo pigro e il `canSee`
  vale solo su `isResourceDetailRequest()`.
- **Escaping**: il nome del layer va in output solo con `htmlspecialchars`, gli snippet solo in
  attributi `data-*` escapati. Il JS non contiene mai testo dell'utente.
- Uno shard cambia script URL, tag o stile da `config('wm-package.web_components.layer_map.fallback')`.

## Perché così

- **Snippet e bottone nel service, non nelle Resource** (oc:8944): Layer ed EcTrack devono produrre
  lo stesso snippet; prima il codice viveva in metodi privati di `Nova\Layer` e la EcTrack l'avrebbe
  dovuto copiare.
- **Snippet in `data-snippet`, non dentro il JS** (oc:8944): con la select gli snippet diventano più
  d'uno, e il nome del layer (testo dell'utente) entra nella pagina. Tenere il JS fisso ed escapare
  solo attributi elimina la stringa JS costruita a mano, il punto in cui un apostrofo rompeva la
  pagina.
- **Un flag solo** (oc:8944): i due bottoni generano lo stesso widget; un interruttore in più per le
  tracce aggiungeva un passo di configurazione senza un caso d'uso. Cambiare la chiave avrebbe voluto
  dire migrare `properties` delle App su ogni shard.
- **Option ordinate per id con la prima preselezionata** (oc:8944): senza `orderBy` Postgres non
  garantisce l'ordine della pivot, e chi copia senza guardare la select avrebbe ottenuto un cammino
  diverso a ogni caricamento.

## Come ci siamo arrivati

- **Bottone solo sui Layer, `track-id` aggiunto a mano dal cliente** (oc:8937, superata): allo
  scrum del 05/11/2026 il bottone sulle tracce era escluso, perché una traccia può stare in più layer.
  Allo scrum del 06/11/2026 si è deciso di farlo, con la select del layer (oc:8944).

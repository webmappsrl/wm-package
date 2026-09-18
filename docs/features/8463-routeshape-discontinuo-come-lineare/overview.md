> Ticket: oc:8463

# RouteShape: esporre Discontinuo come Lineare al frontend, tenere l'alert solo in Nova

## Cosa cambia
Un nuovo helper **globale e generico** del pacchetto, `withoutInternalConfigKeys(array $data): array` (`src/helpers.php`, già autoloaded via composer `"files"`), rimuove da un array le chiavi elencate in `config('wm-package.internal_attribute_keys')` — una lista che il pacchetto non popola mai da solo (vuota di default), riservata ai consumer. Non è legato al concetto di `Layer` né a `shape_discontinuous`: qualunque consumer, per qualunque motivo, può dichiarare chiavi da nascondere senza che wm-package ne sappia nulla.

Va applicato in **due punti**, entrambi oggi fanno passthrough integrale di `properties` senza whitelist:
- `AppConfigService::config_section_map()` (metodo reale — non `buildAppConfig()`, che non esiste — righe 372-379: `foreach ($item['properties'] as $key => $value) { $item[$key] = $value; }`, seguito da `unset($item['properties'])`), che alimenta `MAP.layers[]` in `config.json`.
- `AppController::layer()` (riga 435, route `GET /{app}/layer/{layer}`), che fa `$json = $layer->toArray();` e lo restituisce as-is — bypassa completamente `AppConfigService`, canale di esposizione distinto trovato in Fase: challenge.

**Punto di attenzione sul design (trovato in challenge):** il loop di flattening sopra itera sulle chiavi di *primo livello* di `properties` — `attributes` è una di queste chiavi, le chiavi da nascondere stanno un livello più sotto, dentro `attributes`. L'esclusione non va quindi messa dentro quel loop (dove `$key` vale `'attributes'`, mai il nome della chiave interna), ma come chiamata a `withoutInternalConfigKeys()` sull'array annidato, **dopo** che `attributes` è stato copiato in `$item`.

## Perché
Il repo principale (camminiditalia) introduce un flag booleano interno `properties->attributes->shape_discontinuous`, usato solo da Nova per un alert di discontinuità sul cammino (vedi `camminiditalia/docs/features/8463-routeshape-discontinuo-come-lineare/overview.md` per il contesto completo del ticket). Senza un'esclusione, il flag interno finirebbe comunque esposto pubblicamente — verificato scaricando un `config.json` reale in produzione, che oggi espone `attributes.shape` as-is proprio tramite questo passthrough — vanificando il requisito che la discontinuità resti solo un'informazione backend.

**Decisione presa durante l'esecuzione (non nella stesura originale):** la prima versione di questo fix esponeva `shape_discontinuous` per nome dentro wm-package (`Layer::withoutInternalAttributes()`, con la stringa scritta a lettere nel codice del pacchetto). Il dev ha chiesto di generalizzare: wm-package non deve conoscere un concetto specifico di un solo consumer. Da qui il meccanismo config-driven sopra, stesso pattern già in uso nel progetto per `default_layer_mode`/`DEFAULT_LAYER_MODE` (oc:8314).

## Requisiti
- [ ] Un helper globale del pacchetto (`withoutInternalConfigKeys()`) rimuove da un array le chiavi elencate in `config('wm-package.internal_attribute_keys')`, popolata da env comma-separated (`WM_INTERNAL_ATTRIBUTE_KEYS`), vuota di default.
- [ ] Applicato sia in `AppConfigService::config_section_map()` (blocco `MAP.layers[]` di `config.json`) sia in `AppController::layer()` (endpoint `GET /{app}/layer/{layer}`), sull'array `attributes` estratto in ciascun punto.
- [ ] Nessun impatto sulle altre chiavi già esposte in `attributes` (`shape`, `season`, `distance`, `stage_count`, `taxonomy_where`) — passthrough invariato per queste, in entrambi i punti.
- [ ] Test che verifica, sul nesting reale (`properties->attributes->shape_discontinuous`, non una struttura sintetica semplificata) e impostando esplicitamente `config(['wm-package.internal_attribute_keys' => ['shape_discontinuous']])` (Testbench non carica `.env`/`.env.testing`), che un layer con quel flag `true` non lo esponga né nel `config.json` generato né nella risposta di `AppController::layer()`.

## Rischi
- **Punto di esclusione ambiguo** (critico, vedi nota di design sopra): un filtro posizionato ingenuamente nel loop di primo livello non intercetterebbe mai `shape_discontinuous`. Mitigato dal design esplicito sopra e dal test sul nesting reale.
- **Secondo canale di esposizione** (`AppController::layer()`): non coperto nella prima stesura di questa overview, trovato in Fase: challenge. Mitigato includendolo esplicitamente nei requisiti, con helper condiviso per non duplicare la logica.
- **Accoppiamento cross-repo su stringa duplicata**: `shape_discontinuous` è scritta in camminiditalia e letta/esclusa qui come stringa letterale duplicata, senza costante condivisa tra i due repo — un typo in un solo lato riapre il leak silenziosamente. Mitigato lato camminiditalia con un test di integrazione end-to-end sul `config.json` finale (vedi overview del repo principale); qui non si introduce un meccanismo di sincronia automatica (fuori scope per un fix mirato).
- **Ordine di deploy obbligato**: questo fix va mergiato e il submodule bumpato nel consumer **prima** che camminiditalia inizi a scrivere `shape_discontinuous` — altrimenti il flag verrebbe scritto senza che l'esclusione sia ancora attiva (stesso pattern di un incidente già occorso in team, episodio maphub, `wm-package/CLAUDE.md`).

## Out of scope
- Qualunque logica di calcolo della discontinuità: resta interamente nel repo camminiditalia (confermato in call 2026-09-15, Giuseppe Bonfanti: "la logica rimane comunque sempre dentro Cammini Italia").
- Modifiche al blocco `MAP.filters.layers` (il filtro select "tipologia", righe 590-621): non espone `shape`/`attributes` oggi, non coinvolto da questo fix.
- Introduzione di un meccanismo di whitelist/denylist generico per `properties->attributes`: si esclude solo la chiave puntuale richiesta da questo ticket, non si generalizza il pattern.

## Moduli toccati
- `src/helpers.php` (nuovo helper globale `withoutInternalConfigKeys()`)
- `config/wm-package.php` (nuova chiave `internal_attribute_keys`, env `WM_INTERNAL_ATTRIBUTE_KEYS`)
- `src/Services/Models/App/AppConfigService.php` (`config_section_map()`)
- `src/Http/Controllers/Api/AppController.php` (`layer()`)
- `tests/Feature/AppConfigLayerShapeDiscontinuousTest.php`, `tests/Feature/Api/AppLayerEndpointShapeDiscontinuousTest.php`

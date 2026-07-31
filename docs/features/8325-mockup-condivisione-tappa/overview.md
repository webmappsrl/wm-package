> Ticket: oc:8325

# Estensibilità story-share per redesign camminiditalia (logo layer + tappa)

## Cosa cambia

Nessun cambiamento di comportamento visibile per nessun consumer di `wm-package`. Questo repo riceve solo le estensioni di base necessarie affinché camminiditalia possa implementare, **nel proprio repo principale**, un redesign dell'immagine di condivisione tappa (oc:8325) senza duplicare la logica di compositing esistente (oc:8183):

1. `StoryShareImageService`: i metodi privati di disegno (header/logo, in particolare) diventano `protected`, per permettere a una sottoclasse consumer di sovrascrivere solo la parte che le serve, ereditando invariati mappa/statistiche/gradienti.
2. `StoryShareImageService::compose()` accetta un nuovo parametro opzionale genrico (`?array $extraContext = null`), propagato a `composeWithFrame()`/`composeFallback()`/`drawFallbackHeader()` — ignorato dall'implementazione base del package (comportamento identico a oggi per chi non lo valorizza), disponibile per una sottoclasse consumer che voglia disegnare elementi aggiuntivi (es. logo layer, tappa) senza duplicare mappa/statistiche.
3. `ShareStoryImageController`: reso estendibile (non `final`, dipendenze via constructor injection già presenti) con un nuovo punto di estensione protetto per calcolare `$extraContext` a partire da `$ugcTrack`/`$app` risolti — default: restituisce `null`/array vuoto, nessun cambio di comportamento. Un consumer può sovrascrivere solo questo metodo (via container binding, stesso pattern già usato per `LayerFeatureController` in oc:8089) senza duplicare risoluzione uuid, ownership check, persistenza `share_image`, snapshot `properties` — che restano un'unica fonte di verità nel package.
4. `GeometryComputationService`: nuovo metodo generico per trovare l'`EcTrack` geometricamente più vicino a una geometria data, filtrato per una lista di ID candidati e con soglia di distanza esplicita come parametro (mirror di `getNearestToLonLat()`, già esistente) — riusabile da qualunque consumer che debba risolvere "quale tappa ufficiale corrisponde a questa traccia registrata".

## Perché

Camminiditalia vuole un'immagine di condivisione social branded per-cammino (logo del layer, nome tappa, arrivo/partenza), diversa dal fallback generico attuale — ma questa personalizzazione **non deve essere il comportamento di default del package**: gli altri consumer (maphub, osm2cai2, ...) devono continuare a vedere esattamente il comportamento odierno.

Deciso esplicitamente in fase di challenge di **non duplicare** `ShareStoryImageController` nel repo consumer: quel controller è l'unica fonte di verità per risoluzione uuid, ownership check (403/404) e persistenza (`share_image`, snapshot `properties`) — duplicarlo avrebbe fatto sì che un futuro fix di sicurezza nel package non si propagasse automaticamente al duplicato locale. La strada scelta è invece rendere il controller e il servizio di compositing **estendibili tramite un punto di estensione esplicito** (contesto opzionale generico), lasciando tutta la logica specifica (branding, tappa, disegno del nuovo header) nel repo camminiditalia. Dettaglio completo del design in `docs/features/8325-mockup-condivisione-tappa/overview.md` del repo principale.

## Requisiti

- [ ] I metodi di `StoryShareImageService` coinvolti nel disegno dell'header/logo passano da `private` a `protected`, senza alcun cambio di comportamento per il codice esistente (stesso identico risultato per tutti i path già in uso).
- [ ] `StoryShareImageService::compose()` accetta un parametro opzionale generico (es. `?array $extraContext = null`), propagato ai metodi di disegno interni ma **ignorato dall'implementazione base** — nessun cambio di comportamento per chi non lo valorizza.
- [ ] `ShareStoryImageController` espone un punto di estensione protetto per calcolare `$extraContext` dopo aver risolto `$ugcTrack`/`$app` — default: nessun contesto extra, comportamento identico a oggi. Risoluzione uuid, ownership check, persistenza `share_image` e snapshot `properties` restano invariati e non duplicabili (unica fonte di verità nel package).
- [ ] Nuovo metodo pubblico/statico in `GeometryComputationService` per la ricerca del `EcTrack` più vicino a una geometria, con filtro opzionale su una lista di ID (es. gli `EcTrack` di un dato Layer) e soglia di distanza esplicita come parametro obbligatorio (nessun default implicito che un consumer possa dimenticare di impostare) — generico, non riferisce concetti "cammino"/"tappa" specifici di camminiditalia.
- [ ] Nessuna nuova media collection, nessun nuovo campo Nova, nessuna nuova migration in questo repo per questa feature.

## Rischi

- **Cambio di visibilità (`private` → `protected`) su una classe già in produzione**: rischio molto basso (non altera comportamento, solo estendibilità), ma va verificato che nessun test/analisi statica (PHPStan) assuma quella visibilità come contratto implicito.
- **Nuovo helper geometrico** duplica in parte la logica già presente in `getNearestToLonLat()` (stesso tipo di query `ST_Distance`/`ST_Transform`) — da valutare in fase di implementazione se generalizzare un helper comune invece di scrivere una query parallela quasi identica.
- **`ShareStoryImageController` reso estendibile** (rimozione di `final` se presente, nuovo metodo protetto) aumenta la superficie di estensione pubblica di una classe di sicurezza-sensibile (ownership check). Il nuovo metodo di estensione va progettato per non poter accidentalmente bypassare o alterare l'ownership check esistente — il consumer deve poter aggiungere contesto, non poter cambiare la logica di autorizzazione.

## Out of scope

- Qualunque logica di branding, logo, tappa, disegno header specifico — resta interamente nel repo camminiditalia.
- Nuova media collection `share_frame`/logo a livello Layer — non necessaria: `Layer` ha già una media collection `logo` (`Wm\WmPackage\Models\Layer::registerMediaCollections()`), riusata così com'è.

## Moduli toccati

- `src/Services/Models/StoryShare/StoryShareImageService.php` — refactor visibilità metodi di disegno + nuovo parametro `$extraContext` opzionale su `compose()`.
- `src/Http/Controllers/Api/ShareStoryImageController.php` — reso estendibile, nuovo punto di estensione protetto per calcolare `$extraContext` (default: nessuno).
- `src/Services/GeometryComputationService.php` — nuovo metodo "nearest EcTrack by geometry, filtered by IDs, con soglia esplicita".

Vedi anche `docs/features/8325-mockup-condivisione-tappa/overview.md` nel repo principale (camminiditalia) per il design completo della feature.

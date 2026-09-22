> Ticket: oc:8590

# Notes — Bug: embed/online viewer non mostra logo (e forse POI) per Cammini d'Italia

## Deviazioni dal piano

Nessuna deviazione di codice dal piano: i 3 task sono stati eseguiti esattamente come pianificato
(costante condivisa `Layer::DEFAULT_LAYER_MAP_SCRIPT_URL`, rimozione del fetch live, config
allineato, test aggiornati con un quarto test aggiuntivo previsto dal piano stesso per il caso
"config mancante").

## Verifica manuale end-to-end (Task 3)

Snippet generato per il layer 130 ("Anello di Teodelapio", app-id 1) e incollato su
https://html.onlineviewer.net/. Risultato:

- ✅ **Logo generico dell'app** ("Cammini di Italia", icona rotonda con silhouette escursionisti) —
  compare correttamente nel badge CTA in alto a sinistra. Nel vecchio widget (screenshot fornito
  dal dev in apertura ticket) questo badge era solo testo, senza alcuna icona: miglioramento
  visibile rispetto a prima anche solo su questo punto.
- ✅ **POI** — marker numerati visibili sulla mappa lungo il tracciato.
- ⚠️ **Logo specifico del layer/cammino** (immagine dedicata nel badge "Anello di Teodelapio",
  distinta dall'icona generica dell'app) — non compare per questo layer specifico. Coerente con
  il rischio già documentato in `overview.md`: `getLogoImageAttribute()` (wm-package) dipende da
  un file media "logo" caricato su Nova per quel layer, che con ogni probabilità non è presente
  per il layer 130. Non è un problema di codice risolvibile in questo ciclo — verificare in Nova
  se il layer ha un file "logo" caricato, e se manca è un'azione di contenuto (caricamento media),
  non un fix. Il dev aveva riferito di aver verificato il logo funzionare correttamente su
  wm-elements — probabile che il test fosse su un layer diverso con il media effettivamente
  caricato.
- ❌ **Badge store Android/iOS** — non compaiono nel test, nonostante l'App "Cammini di Italia"
  abbia sia `ios_store_link` che `android_store_link` popolati (verificato via tinker in questa
  sessione) e il codice sorgente di `wm-elements` (branch `main`, in sync con `dist` alla data di
  questo test — stesso commit, verificato via `git log`) supporti esplicitamente questa
  funzionalità leggendo `app?.iosStore`/`app?.androidStore` dallo stesso config.json già
  verificato corretto lato backend. **Non determinato se sia un bug reale in wm-elements o un
  artefatto dell'ambiente sandboxed di html.onlineviewer.net** (possibile restrizione di rete/
  CORS sull'iframe di preview che non intacca il caricamento della config principale ma potrebbe
  intaccare un secondo fetch, o un problema genuino nel widget). Non indagato oltre: il codice
  sorgente di `wm-elements` è fuori scope per questo ticket (repo esterno, non submodule). Da
  verificare con un test su un sito reale (non sandboxed) prima di considerare chiuso questo
  aspetto specifico del ticket originale.

## Follow-up

- ✅ **Chiuso dal dev**: verifica manuale ripetuta sul cammino "Grande di Celestino" — quello
  citato testualmente dal cliente nella richiesta originale (la verifica di questa sessione era
  stata fatta sul layer 130 "Anello di Teodelapio"). Il dev conferma che funziona correttamente.
  Colma il gap segnalato in fase di review (Finder 1: la verifica automatica non copriva il
  cammino esatto citato dal cliente).
- Verificare la comparsa dei badge store Android/iOS su un embed reale (sito esterno, non
  sandbox), fuori da questo ciclo. Se confermato assente anche lì, serve un ticket dedicato per
  indagare in `wm-elements` (repo esterno, fuori scope qui).
- Verificare in Nova se i layer "principali" di Cammini di Italia (a partire dal layer 130) hanno
  un file "logo" caricato; se mancante, è un'azione di contenuto per il team/cliente, non di
  codice.
- Comunicare al cliente/ai siti che hanno già incollato il vecchio snippet che serve ri-copiare e
  incollare quello aggiornato (il fix vale solo per le nuove copie da Nova) — segnalato anche in
  `overview.md`, non eseguito in questo ciclo.
- **Gap di discovery test pre-esistente** (non introdotto da questo ticket): il file
  `wm-package/tests/Feature/LayerWebComponentCopyButtonTest.php` non è incluso nel testsuite di
  default di camminiditalia (`phpunit.xml` scansiona solo `tests/Feature`/`tests/Unit` nella root
  del repo principale). Verificato in questa sessione: `php artisan test
  --filter=LayerWebComponentCopyButtonTest` → "No tests found"; il test gira solo se invocato con
  il path esplicito. Nessuna azione in questo ciclo (toccare `phpunit.xml` per includere
  `wm-package/tests/Feature` rischia di far girare anche altri test del package incompatibili con
  l'ambiente camminiditalia) — da valutare in un ticket separato se si vuole chiudere questo gap
  di CI in modo più ampio.

## Decisioni

- Scelta A (Challenge, asse "Rischi architetturali"): rimosso interamente il meccanismo di
  risoluzione live (`example_url`/fetch/cache) invece di ripuntarlo a una nuova pagina "live" in
  wm-elements — decisione del dev, motivata dal fatto che l'URL del bootstrap è ormai una
  convenzione fissa (`@dist/<widget>/<widget>.js`), non più soggetta a rinominazioni.
- Estratta una costante **privata** di classe (`Layer::DEFAULT_LAYER_MAP_SCRIPT_URL`) come unica
  fonte di verità per l'URL corretto — decisione emersa in Challenge per eliminare il
  "fallback-del-fallback" silente. **Superata dalla review** (vedi sotto): la prima
  implementazione la referenziava anche da `config/wm-package.php`, reintroducendo un accoppiamento
  peggiore di quello che doveva risolvere. La versione finale non la condivide più con nessuno:
  il config non definisce affatto `script_url` nel proprio `fallback`, e la costante fa da unico
  default lato codice quando quella chiave manca.
- Rischi accettati senza azione di codice (CDN/branch `dist` mutabile senza SRI, nessun rollout
  graduale, nessun test HTTP di smoke in CI) — vedi `overview.md`, sezione Rischi.

## Review (wm-review-ticket, prima del commit)

Eseguita sul working tree non committato (nessuna PR aperta), diff contro `develop`. Nessun bug
funzionale o regressione trovato. Due punti corretti prima del commit:

- **Dipendenza superflua config → Nova Resource**: la prima versione referenziava
  `Layer::DEFAULT_LAYER_MAP_SCRIPT_URL` da `config/wm-package.php` (import `use
  Wm\WmPackage\Nova\Layer;`). Misurato che questo forzava l'autoload dell'intera gerarchia Nova
  Resource (+15 classi, +19 trait, +1MB) ad ogni boot senza config cache — costo e accoppiamento
  evitabili, dato che `resolveLayerMapComponentConfig()` aveva già un fallback PHP che copre
  esattamente lo stesso caso quando `script_url` manca dal config. Fix: rimossa la chiave
  `script_url` dal `fallback` del config (resta solo come default PHP, ora costante `private`);
  verificato con un `require` a freddo del file di config che `Wm\WmPackage\Nova\Layer` non viene
  più autoloadata.
- **Test tautologici sul requisito principale del ticket**: i test iniziali sovrascrivevano il
  config con lo stesso URL che poi verificavano (o confrontavano lo snippet con la costante
  invece che con un letterale) — sarebbero rimasti verdi anche se l'URL corretto fosse tornato
  quello del vecchio repo deprecato. Fix: `test_default_snippet_uses_wm_elements_script_url` ora
  non sovrascrive affatto `web_components.layer_map` e confronta con un letterale indipendente
  dalla costante. **Verificato empiricamente**: rimesso temporaneamente l'URL vecchio nella
  costante, il test è fallito come atteso; ripristinato l'URL corretto, tutta la suite torna
  verde (6 test, 16 assertion).
- Aggiunti anche due test per casi prima non coperti (segnalati come "Review Focus" già nel
  piano): override parziale del `fallback` (solo `script_url`, senza `tag_name`/`default_style`)
  e override esplicito completo con URL diverso da quello di produzione.
- Collassati `resolveLayerMapComponentConfig()`/`getFallbackLayerMapComponentConfig()` in un solo
  metodo (il secondo era ridotto a un passthrough dopo la rimozione del fetch live).
- Non modificato: il nesting `fallback` nel config (osservato come "vestigiale" ora che non
  esiste più un ramo primario, ma cambiarlo sarebbe un rename dello schema di config, non un
  cleanup a costo zero — lasciato invariato). Non modificata la duplicazione tra
  `test_default_snippet_uses_wm_elements_script_url` e `test_rendered_button_contains_helper_link`
  oltre a quanto già tolto (quest'ultimo ora verifica solo il testo helper, non più anche lo
  script url) — nessun'altra azione necessaria.
- **Verifica di accettazione ancora aperta, non chiusa da questa review**: la verifica manuale
  end-to-end (Task 3) resta fatta sul layer 130, non su "Celestino" citato dal cliente; i badge
  store restano non confermati. Vedi Follow-up sopra — invariato.

> Ticket: oc:8590

# Bug: embed/online viewer non mostra logo (e forse POI) per Cammini d'Italia

## Cosa cambia

Lo snippet HTML/script generato da `Wm\WmPackage\Nova\Layer` (bottone "copia" del web component
embed) punterà al pacchetto corretto e mantenuto `webmappsrl/wm-elements` (branch `dist`, widget
`wm-layer-map`) invece del repository deprecato `webmappsrl/wm-layer-map`. Viene inoltre rimosso
il meccanismo di risoluzione "live" (fetch + scraping di una pagina di esempio, con cache di 30
minuti) che non ha più un equivalente funzionante nel nuovo repository: la configurazione userà
solo un valore statico versionato nel config del package.

## Perché

L'embed pubblico generato per i clienti (es. Cammini d'Italia) carica ancora il vecchio widget
vanilla-JS deprecato, che non mostra più correttamente logo del cammino, POI e link store
Android/iOS — funzionalità già presenti e verificate dal dev nel nuovo componente Angular
`wm-elements`. Il meccanismo di fetch live che avrebbe dovuto tenere l'URL sempre aggiornato punta
a sua volta al repo sbagliato, e non avrebbe comunque un bersaglio valido nel nuovo repo: la sua
pagina di test (`test/wm-layer-map/index.html`) carica i bundle Angular hashati direttamente, non
espone più un singolo tag `<script>` copiabile come faceva la vecchia pagina di esempio.

Il ticket era stato aperto con una diagnosi diversa (ipotesi: il problema stava nel template del
componente esterno, non verificabile da questa sessione). Il dev ha corretto la diagnosi
confrontando lo snippet generato oggi da camminiditalia con quello corretto copiato dal repository
`wm-elements`.

## Requisiti

- [ ] Il fallback statico in `wm-package/config/wm-package.php`
      (`web_components.layer_map.fallback.script_url`) punta a
      `https://cdn.jsdelivr.net/gh/webmappsrl/wm-elements@dist/wm-layer-map/wm-layer-map.js`
- [ ] Rimosso il meccanismo di fetch live (`example_url`, `fetchLayerMapExampleConfig()`, cache)
      da `Wm\WmPackage\Nova\Layer`; la risoluzione della config usa sempre e solo il fallback
      statico
- [ ] Allineato anche il default hardcoded in `getFallbackLayerMapComponentConfig()`
      (`wm-package/src/Nova/Layer.php:383`), che oggi punta al vecchio repo indipendentemente dal
      config — emerso in Challenge come "fallback-del-fallback" silente: se la chiave
      `fallback.script_url` mancasse a runtime (config cache stale, override parziale in un
      consumer), il codice ripiomberebbe sul repo rotto senza errore visibile. L'URL corretto va
      scritto in un solo posto (il default in codice deve leggere dal config o essere eliminato,
      non duplicato)
- [ ] `tag_name` (`wm-layer-map`) e `default_style`
      (`display:block;width:100%;height:600px`) restano invariati
- [ ] `wm-package/tests/Feature/LayerWebComponentCopyButtonTest.php` aggiornato: rimosso il test
      sul fetch live, il comportamento "usa il valore statico di config" diventa l'unico caso
      testato
- [ ] Verifica manuale: incollare lo snippet generato (per il layer del ticket, es. layer-id 130)
      su https://html.onlineviewer.net/ e controllare che compaiano logo del cammino, POI e badge
      store Android/iOS
- [ ] Il fix è nel default del package, quindi vale per tutti i consumer di wm-package — nessun
      consumer disponibile in locale risulta avere un override che lo lega intenzionalmente al
      vecchio repo (vedi Rischi)

## Rischi

- Il fix cambia un comportamento condiviso da tutti i consumer di wm-package. **Verificato**
  (non solo confermato a voce): nessuno dei consumer disponibili in locale in questa sessione
  (`forestas`, `maphub`, `osm2cai2`) ha un `config/wm-package.php` con override della chiave
  `web_components.layer_map` — nessuno è quindi agganciato di proposito al vecchio repo. Non
  copre consumer non presenti in locale in questa sessione.
- Chi ha già copiato in passato lo snippet embed sul proprio sito esterno ha ancora il vecchio
  script incollato staticamente: il fix vale solo per le nuove copie fatte da Nova dopo il deploy.
  Serve eventualmente avvisare il cliente di ri-copiare/incollare lo snippet aggiornato
  (comunicazione, non codice — fuori scope in questo ciclo).
- Il layer-badge del nuovo componente mostra il logo del cammino solo se il Layer ha un file
  "logo" caricato su Nova (accessor `getLogoImageAttribute()`, già esposto in `config.json` via
  `AppConfigService::config_section_map()`); il dev ha verificato di persona che il logo compare
  correttamente col nuovo componente. Comportamento diverso dal vecchio widget: da comunicare ai
  gestori layer (Validator) che potrebbero non aver mai compilato quel campo, non essendo mai
  stato mostrato prima (comunicazione, fuori scope in questo ciclo).
- **Rischio accettato (CDN/branch mutabile):** il branch `dist` di `wm-elements` è un ref
  mutabile su CDN pubblico (jsDelivr), senza SRI (`integrity`/`crossorigin`) sul tag
  `<script type="module">` — non aggiungibile in pratica, dato che il bootstrap risolve bundle con
  hash diversi ad ogni build. Un rename, force-push con path diverso, o compromissione di quel
  branch romperebbe simultaneamente l'embed pubblico di **tutti** i consumer wm-package, con
  scoperta probabile solo via segnalazione cliente. Rischio strutturale preesistente del
  componente `wm-elements` (non introdotto da questo fix — il vecchio widget aveva lo stesso
  pattern sul proprio repo), qui solo consolidato come "quello giusto". Nessuna azione di codice
  in questo ciclo.
- **Rischio accettato (nessun rollout graduale):** il cambio di default è un cutover secco per
  tutti i consumer al prossimo bump del submodule — nessun feature flag o attivazione per singolo
  layer/consumer. Un feature flag sarebbe overengineering per un fix di configurazione; il
  rollback (revert + bump) è comunque semplice (nessuna migration/breaking change), ma riporta al
  comportamento *noto rotto*, non a un terzo stato neutro.
- **Non aggiunto un test HTTP di smoke sull'URL jsDelivr reale**: una chiamata di rete esterna in
  CI sarebbe flaky (anti-pattern). Il test esistente sullo snippet generato resta la copertura
  adeguata per la responsabilità del backend; un'eventuale regressione futura in `wm-elements`
  stesso non verrebbe rilevata da questo repo (accettato, stesso rischio strutturale del punto
  CDN sopra).

## Out of scope

- Qualsiasi modifica al codice sorgente di `wm-elements` o del vecchio `wm-layer-map`
  (repository esterni Webmapp, non submodule di questo progetto)
- Comunicazione al cliente per il re-embed sul proprio sito esterno (segnalata come rischio, non
  eseguita in questo ciclo)
- Creazione di una nuova pagina "live" in `wm-elements` per un eventuale ripristino futuro del
  meccanismo di fetch (scartato in favore del solo fallback statico)

## Moduli toccati

Tutti in `wm-package` (submodule) — nessuna modifica nel repo principale camminiditalia, a parte
il bump del puntatore del submodule a fine ciclo:

- `wm-package/config/wm-package.php` (chiave `web_components.layer_map`)
- `wm-package/src/Nova/Layer.php` (`resolveLayerMapComponentConfig`,
  `getFallbackLayerMapComponentConfig`, rimozione di `fetchLayerMapExampleConfig`)
- `wm-package/tests/Feature/LayerWebComponentCopyButtonTest.php`

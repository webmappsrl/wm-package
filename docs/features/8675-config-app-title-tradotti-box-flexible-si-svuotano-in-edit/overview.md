> Ticket: oc:8675

# Config app: i Title tradotti dei box Flexible si svuotano in edit e si perdono al salvataggio

## Cosa cambia

Aprendo l'edit della config di un'app in Nova, i campi tradotti costruiti con `FlexibleTranslatable`
mostrano i valori salvati in ogni lingua e in ogni gruppo del Flexible o del Repeater. Salvando
senza toccarli, i valori restano identici in `config_home` (e negli altri campi che usano lo
stesso meccanismo).

Il fix sta nel campo `FlexibleTranslatable` di wm-package, non nei singoli box: quando un gruppo
viene serializzato, il campo serializza subito anche i suoi sotto-campi per lingua, fissando i
valori di quel gruppo. Il `resolve(true)` del template, che arriva dopo, svuota gli oggetti
condivisi ma non tocca più ciò che è già stato serializzato. È lo stesso rimedio che whitecube
applica ai campi normali in `Layout::getResolvedValue`, portato un livello più in basso.

## Perché

Il campo `FlexibleTranslatable`, introdotto il 07/09/2026 con `798ead30` (#269), espone i
sotto-campi per lingua come oggetti condivisi fra tutti i gruppi:

- `Layout::cloneField` (vendor whitecube/nova-flexible-content) fa un `clone` superficiale
  (`Layout.php:305`), quindi i sotto-campi in `$this->data` restano gli stessi oggetti;
- `NovaTabTranslatable` li espone al frontend con `'fields' => $this->data`
  (`NovaTabTranslatable.php:72`);
- `Layout::jsonSerialize` chiama `resolve(true)` «per svuotare tutti i campi»
  (`Layout.php:688-690`), e svuota anche gli oggetti condivisi con i gruppi già risolti.

Il form arriva in edit con i campi vuoti, il componente Vue li invia vuoti, e
`ConfigHomeResolver::buildGenericElement` non scrive il title vuoto (`ConfigHomeResolver.php:181-186`):
al salvataggio il title sparisce, senza errori. La catena è dedotta dalla lettura del codice e va
confermata da un test.

Il fix di oc:8488 (`daaea545`) agisce in `getAttributesForItem()`, prima della costruzione del form,
e il suo test di andata e ritorno chiama i metodi privati con un `Layout` senza campi: non passa dal
form e non poteva vedere il bug.

Riprodotto in produzione sull'app 1 il 01/10/2026; le app salvate dall'edit dopo il 07/09 possono
aver già perso i title.

## Requisiti

- [ ] Un test che oggi fallisce dimostra la causa: un Flexible con almeno due gruppi che usano
      `FlexibleTranslatable`, serializzato come lo serializza Nova, deve esporre i valori salvati
      di ciascun gruppo in ogni lingua. Il test fa il `json_encode` dell'**intero campo Flexible**,
      meta `layouts` compreso, come la risposta di Nova: chiamare solo `jsonSerialize()` sul gruppo
      non basta, perché i sotto-campi per lingua restano oggetti fino al `json_encode` finale. I
      due gruppi hanno valori diversi, e il test controlla il valore di ciascun gruppo, non solo
      che non sia vuoto.
- [ ] Il fix sta in `FlexibleTranslatable` (wm-package), non nei resolver dei singoli box:
      `jsonSerialize()` sostituisce nel meta `fields` gli oggetti dei sotto-campi con la loro
      serializzazione. Nessun accesso a proprietà private del vendor, comportamento identico
      con la 2.1.7 e con la 2.2.5.
- [ ] Coperte entrambe le varianti del campo: `simple` (title) e `richText` (content dell'info box).
- [ ] Coperti i contenitori in cui il campo sta direttamente nel layout Flexible, e quindi è
      colpito, ciascuno con un test di andata e ritorno che passa dal form
      (`resolve → jsonSerialize → fill → set`) con almeno due gruppi:
  - [ ] box di `config_home`: `title`, `slug`, `external_url`, `horizontal_scroll` (activities e
        poi_types), tutti tramite `config_home_title_layout()`;
  - [ ] layout `title` degli overlays (campo `Label`).
- [ ] Test di non regressione per i Repeater, dove il campo sta nelle righe: due righe con valori
      diversi escono intatte dal giro del form, prima e dopo il fix. Nova costruisce i campi di
      ogni riga con una nuova chiamata a `fields()` (`Repeatable::resolveFields`), quindi dalla
      lettura del codice i Repeater non condividono gli oggetti per lingua e non sono colpiti:
  - [ ] `HorizontalScrollItemRepeatable` (title dell'item);
  - [ ] `InfoBoxItemRepeatable` (title e content), dentro `config_detail` di Layer, EcTrack ed EcPoi.
- [ ] Il formato salvato non cambia: il title resta un oggetto per lingua con le sole lingue
      valorizzate (`{"it":"…","en":"…"}`), il title stringa del vecchio formato continua a essere
      convertito come dopo oc:8488.
- [ ] Il comportamento di un title vuoto resta quello di oggi, che non è uguale in tutti i box:
      una lingua lasciata vuota non viene salvata; un title vuoto in tutte le lingue non scrive la
      chiave nei box generici (`ConfigHomeResolver.php:181-186`) e nella label degli overlays
      (`ConfigOverlaysResolver.php:74-78`), mentre negli `horizontal_scroll` scrive `"title": []`
      (`ConfigHomeResolver.php:586-605`). Questo ticket non li rende uguali.
- [ ] I test nuovi passano con entrambe le versioni di `kongulov/nova-tab-translatable`: la 2.1.7
      dei consumer (fissata dal `composer.lock` di maphub, cioè quella di produzione) e la 2.2.5
      che il package installa con `^2.1`. I test del campo dichiarano `Tests\TestCase` e girano
      dalla suite di maphub, che usa già la 2.1.7. La 2.2.5 si installa solo in locale in maphub
      per un giro di test, e poi `composer.json` e `composer.lock` di maphub si ripristinano senza
      committarli.
- [ ] I test esistenti (`FlexibleTranslatableTest`, `ConfigHomeTitleBoxLegacyStringTest`,
      `AppConfigOverlaysTitleLayoutTest`, `AppConfigHomeHorizontalScrollTest`,
      `AppConfigHomePoiTrackLayoutTest`) restano verdi.
- [ ] Prova a mano in Nova locale (maphub, DB copiato da dev.maphub il 05/10/2026): aperto l'edit
      della config di un'app, i title si vedono in tutte le lingue; salvando senza toccarli,
      `config_home` resta identico.

## Rischi

- **La causa è dedotta, non provata.** Se il test non riproduce lo svuotamento, la catena è
  sbagliata o incompleta: ci si ferma e si rivede la causa prima di scrivere il fix.
- **Il fix tocca un campo condiviso da tre prodotti.** Il campo arriva anche a camminiditalia e
  osm2cai2 quando aggiornano il submodule. Mitigazione: nessun cambiamento del formato salvato, e
  test sui contenitori di wm-package. La verifica sugli altri consumer non fa parte di questo ticket.
- **Il frontend riceve `config_home` così com'è** (`AppConfigService.php:248-252`) e legge i title
  dei box della home con la pipe `wmtrans` (wm-core `18c9f1ed`, `wmtrans.pipe.ts:31-41`), che
  gestisce oggetto per lingua, lingue mancanti, stringa e title assente. Il box `base` (Poi/Track),
  che in wm-core stampa il title senza pipe, non ha un title e non è toccato. Gli info box stanno in
  `properties->config_detail` di Layer, EcTrack ed EcPoi, che il frontend non legge ancora
  (oc:8181). Non è stato verificato come map-core traduce la label degli overlays: per questo il
  formato salvato non deve cambiare.

## Out of scope

- Recupero dei title già persi in produzione: si reinseriscono a mano. La tabella Nova
  `action_events` conserva in `original` alcune versioni precedenti di `config_home`.
- Verifica del fix su camminiditalia e osm2cai2.
- Allineamento del gitlink del submodule nei consumer: a carico dei dev.

## Moduli toccati

Tutto in **wm-package**:

- `src/Nova/Fields/FlexibleTranslatable.php` — il fix.
- `tests/Feature/Nova/FlexibleTranslatableInFlexibleLayoutTest.php` — test nuovi per home,
  overlays e Repeater (`tests/Feature/Nova/Fields/FlexibleTranslatableTest.php` resta invariato).
- `docs/knowledge/campi-flexible-e-translatable.md` e `.claude/rules/nova.md` — conoscenza e
  trappola nate da questo lavoro.
- `docs/features/8675-config-app-title-tradotti-box-flexible-si-svuotano-in-edit/`.

In **maphub**: nessun file. Solo la prova a mano in Nova locale.

## Aggiornamento dopo l'implementazione (05/10/2026)

La causa è confermata dai test e nel browser, con una precisazione rispetto al «Perché»: i
sotto-campi per lingua sono condivisi fra i gruppi **dello stesso layout** e il template di quel
layout, non fra tutti i gruppi. Lo stesso meccanismo produce due effetti:

- con più box dello stesso tipo, ogni box riceve i title di un altro;
- con un solo box per tipo, il caso del cliente, i title arrivano vuoti: la risposta di edit di
  Nova contiene il campo due volte (`fields` e `panels`), e la seconda copia viene serializzata
  dopo il `resolve(true)` del template.

Il dettaglio, con le prove, è in [notes.md](notes.md#task-1-test-che-riproduce-la-causa).

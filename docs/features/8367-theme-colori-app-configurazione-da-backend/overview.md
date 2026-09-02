> Ticket: oc:8367

# Theme colori app — configurazione da backend

## Cosa cambia

Il tab "Theme" della Nova resource `App` (`src/Nova/App.php::theme_tab()`) viene esteso con 5 nuovi color picker (`secondary`, `tertiary`, `success`, `warning`, `danger`), tutti validati con una regex hex (`^#[A-Fa-f0-9]{6}$`, `nullable`) — stessa regola applicata anche ai 2 color picker già esistenti (`primary_color`, `default_feature_color`) per coerenza.

`AppConfigService::config_section_theme()` viene riscritto per produrre `config.json.THEME` con una mappatura esplicita chiave-per-chiave da snake_case (storage `properties->theme->*`) a camelCase (contratto `ITHEME` del frontend wm-core), lo stesso pattern già in produzione su geohub (`app/Traits/ConfTrait.php::config_section_theme()`). Solo i valori effettivamente impostati (non null/non vuoti) vengono inclusi nell'output — il frontend applica già i propri default per ogni chiave assente.

Le nuove label dei campi Nova vengono aggiunte a `resources/lang/it.json` ed `en.json`.

**Bug collaterale trovato e corretto nello stesso ciclo**: `config_section_map()` (`AppConfigService.php:500`) usa `$this->app->primary_color` come colore di fallback per i box "feature_collection" della home — ma quella è una colonna DB reale della tabella `apps` (definita in `create_apps_table.php.stub`, default `#de1b0d`), mai scritta da Nova (che scrive solo su `properties->theme->primary_color` JSON). La colonna è quindi sempre al valore di default della migration, indipendentemente dal colore primario reale impostato dall'admin. Stessa causa radice del bug principale di questo ticket (storage reale disconnesso dal JSON `theme`) — corretto leggendo `$this->app->properties['theme']['primary_color'] ?? '#000000'`, stesso pattern già usato correttamente da `StoryShareImageService::resolveAccentColor()`.

## Perché

Il motore di theming runtime del frontend (wm-core, `ITHEME` + `getCSSVariables()`) è completo e già in produzione, ma il backend (`wm-package`) produce oggi `config.json.THEME` con chiavi snake_case che non corrispondono a quelle camelCase attese dal frontend — verificato che il campo "Primary color" impostato in Nova non ha mai avuto effetto reale sull'app (confermato sul DB camminiditalia: `properties->theme` popolato correttamente, ma `THEME.primary` risulta sempre assente in `config.json`, quindi il frontend applica sempre i colori di default hardcoded).

Il cliente vuole personalizzare i colori dell'app (primary, secondary, e altri ruoli) per allinearla al proprio brand, da pannello admin, senza ricompilare l'app per ogni istanza/shard.

## Requisiti

- [ ] `theme_tab()` espone color picker per `primary`, `secondary`, `tertiary`, `success`, `warning`, `danger`, `default_feature_color` (storage snake_case sotto `properties->theme->*`, coerente con i campi già esistenti)
- [ ] Tutti i color picker hanno validazione `nullable` + regex hex a 3 o 6 cifre (`^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$`) — niente notazione con alpha (vedi Rischi)
- [ ] Layout dei campi resta flat nel tab "Theme" (nessun Panel di raggruppamento)
- [ ] `AppConfigService::config_section_theme()` produce `config.json.THEME` con le chiavi camelCase esatte attese da `ITHEME`: `primary`, `secondary`, `tertiary`, `success`, `warning`, `danger`, `defaultFeatureColor`, `fontFamilyHeader`, `fontFamilyContent`
- [ ] Solo le chiavi con valore non vuoto vengono incluse in `THEME` — nessuna chiave con valore `null`/stringa vuota
- [ ] `config_section_theme()` accede a `properties->theme->*` solo tramite `??`/`isset()` (mai accesso diretto a sottochiave senza fallback) — un `properties->theme` assente/parziale/malformato non deve lanciare eccezioni, dato il salvataggio sincrono dell'intero `config()` in `AppObserver::saved()` (vedi Rischi)
- [ ] Nessuna modifica alle chiavi/dati già salvati sotto `properties->theme->*` (nessuna migrazione richiesta — la traduzione avviene solo nel layer di output, come già verificato in produzione su geohub e confermato contro la versione reale di wm-core pinnata da webmapp-app)
- [ ] Nuove label Nova aggiunte a `resources/lang/it.json` e `resources/lang/en.json`
- [ ] Test Pest per `config_section_theme()` che verificano: (a) con tutte le 9 chiavi popolate, l'intero array `THEME` prodotto è asserito con un singolo `toBe()`/`toEqual()` sull'array completo — non `toHaveKey()` sparse, per intercettare un typo su qualsiasi chiave; (b) esclusione dei valori vuoti; (c) `properties->theme` assente/parziale non lancia eccezioni; (d) valore reale osservato sul DB camminiditalia (`primary_color: #ef7821`) tradotto correttamente in `THEME.primary`
- [ ] Verifica manuale (tinker) su camminiditalia che `config()['THEME']` prodotto rifletta i valori realmente impostati in Nova
- [ ] `config_section_map()` (`AppConfigService.php:500`) legge `$this->app->properties['theme']['primary_color'] ?? '#000000'` invece della colonna DB morta `$this->app->primary_color` — test dedicato che verifica il fallback colore dei box "feature_collection" senza `fill_color`/`stroke_color` propri

## Rischi

- **Blast radius totale su un bug nel mapping — mitigato**: `AppObserver::saved()` chiama `writeAppConfigOnAws()` sincronamente dentro la request di salvataggio Nova, e `config()` concatena 12+ sezioni in un'unica catena — un'eccezione non guardata in `config_section_theme()` farebbe fallire con 500 **qualsiasi** salvataggio dell'App (non solo i colori). Mitigato per costruzione: accesso a `properties->theme->*` solo via `??`/`isset()` (vedi Requisiti), più un test dedicato che verifica l'assenza di eccezioni su input parziale/malformato.
- **Cambio visivo retroattivo di massa in produzione — accettato, non un bug**: qualsiasi App che ha già `primary_color`/`default_feature_color` impostati (mai avuto effetto finora, per definizione del bug che questo ticket corregge) cambierà colore visivamente **al primo save dopo il deploy** — comportamento corretto e voluto, ma non automatico né annunciato: da segnalare come nota operativa post-deploy (chi fa il deploy dovrebbe avvisare i clienti con colori già impostati in Nova, non lasciare che lo scoprano da soli). Stesso discorso si applica al fix di `config_section_map()`: qualsiasi box "feature_collection" della home senza `fill_color`/`stroke_color` propri passerà dal colore hardcoded `#de1b0d` al vero primary color dell'app al primo save/rigenerazione config dopo il deploy.
- **Nessun comando di backfill/resync per le app esistenti — accettato**: il fix non è retroattivo automaticamente; un'App non risalvata dopo il deploy resta con `config.json` nello schema vecchio finché un admin non la risalva (anche senza toccare i colori, un save qualsiasi rigenera `config.json`). Nessun comando batch in questo ciclo — basso volume (`il progetto ha una sola app` per camminiditalia), il passo manuale va solo documentato come nota operativa post-deploy.
- **Validazione regex — 3 cifre accettate per tolleranza input manuale, non per il picker**: verificato che il campo è `Laravel\Nova\Fields\Color` (`<input type="color">` HTML5 nativo), che **non produce mai** un valore a 3 cifre — il ramo "3 cifre" della regex non è quindi raggiungibile dalla UI Nova, ma resta utile per tolleranza verso valori scritti/importati via API o tinker senza passare dal picker. Notazione con alpha (`#RRGGBBAA`) resta esclusa deliberatamente: `theme.ts::getCSSVariables()` costruisce le CSS variable `--wm-color-*-rgb` con `Color(valore).array().toString()` assumendo sempre un array a 3 componenti — un valore con alpha romperebbe qualsiasi composizione `rgba(var(--wm-color-x-rgb), <alpha>)` a valle. Vincolo del contratto frontend esistente, fuori scope modificarlo qui.
- **Staleness cache/CDN — follow-up non bloccante**: la catena Nova save → `writeAppConfigOnAws()` → S3 → CDN/fetch client non ha invalidazione cache verificata in questo ciclo — comportamento preesistente condiviso da tutte le 12 sezioni di `config()`, non introdotto da questa feature. Annotato come follow-up in `notes.md`, non investigato qui.
- **Divergenza versione wm-core (geohub vs camminiditalia) — verificato, non è più un rischio aperto**: confermato direttamente contro il commit wm-core realmente pinnato da `webmapp-app` (`34b7e6aa...`, 2026-08-20) — `theme.ts` è identico byte-per-byte alla copia usata per il mapping, e `ITHEME` ha le stesse 21 chiavi camelCase. Il pattern geohub è stato solo una seconda conferma della strategia, non l'unica fonte.
- **Nessuna verifica visiva end-to-end in questa sessione**: la verifica si ferma al JSON prodotto da `config()['THEME']` (test automatizzati + tinker), non al rendering reale nell'app/webapp (richiederebbe build/run di wm-core, repo separato non incluso in questo ciclo) — verifica visiva demandata al dev su un ambiente con wm-core in esecuzione.

## Out of scope

- Ruoli `dark`/`medium`/`light` (colori "di sistema": sfondo, testo, elementi neutri) — restano ai default hardcoded del frontend, nessun campo Nova aggiunto.
- Ruolo `select` e le 7 chiavi di font-size (`fontXxxlg`...`fontXsm`) di `ITHEME` — non richiesti dal ticket, restano ai default frontend.
- Nessuna modifica al motore di calcolo shade/tint del frontend (`getCSSVariables()`, `wm-core`).
- Nessuna modifica al repo principale camminiditalia — feature interamente in `wm-package`.
- Nessun raggruppamento in Panel dei campi del tab "Theme" (resta flat, come oggi).
- Nessuna verifica visiva nell'app/webapp reale in questa sessione.
- Nessun comando batch di backfill/resync di `config.json` per le app esistenti — nota operativa manuale post-deploy sufficiente.
- Nessuna modifica/verifica dell'invalidazione cache/CDN sulla pipeline `writeAppConfigOnAws()`.
- Le colonne DB morte (`font_family_header`, `font_family_content`, `default_feature_color`, `primary_color` sulla tabella `apps`, mai scritte da Nova) non vengono rimosse — restano orfane ma inerti, nessuna migration di drop in questo ciclo.

## Moduli toccati

- `wm-package/src/Nova/App.php` (`theme_tab()`, nuovo helper privato `themeColorField()`)
- `wm-package/src/Services/Models/App/AppConfigService.php` (`config_section_theme()` — riscritto con `THEME_KEY_MAP`; `config_section_map()` — fix fallback colore + validazione hex su `primary_color`, `fill_color`, `stroke_color`)
- `wm-package/src/Services/Models/StoryShare/StoryShareImageService.php` (`resolveAccentColor()` — usa ora `sanitizeHexColor()` condivisa; docblock riga ~106, riferimento a `THEME.primary_color` aggiornato in `THEME.primary`)
- `wm-package/src/helpers.php` (nuova funzione globale `sanitizeHexColor()`, condivisa tra `AppConfigService` e `StoryShareImageService`)
- `wm-package/resources/lang/it.json`, `wm-package/resources/lang/en.json`
- `wm-package/tests/Feature/AppConfigServiceThemeTest.php` (nuovo)
- `wm-package/tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php` (nuovo)
- `wm-package/CLAUDE.md`, `wm-package/docs/features/8367-theme-colori-app-configurazione-da-backend/{overview,plan,notes}.md` (artefatti di processo)

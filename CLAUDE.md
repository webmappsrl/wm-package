# wm-package — Note per Claude

## Regola che precede tutte le altre: un test può scrivere fuori da qui

**Un test che crea un'App con `native_app_deep_link_enabled = true` deve fakare `Bus` e il disco
`well_known_registry`**, altrimenti scrive per davvero sul registro SFTP condiviso: è un effetto
verso l'esterno che nessuno può annullare, ed è già successo (oc:8251).

Il database invece è al sicuro da entrambi i lati, e non serve disciplina per quello:
`phpunit.xml.dist` del package punta a `DB_DATABASE=wm_package`, e `phpunit.xml` di `forestas` a
`forestas_testing`. Se un giorno crei un `phpunit.xml` locale nel package, ricordati che ha la
precedenza sul `.dist`: senza le righe `DB_*` la protezione sparisce. È già successo una volta su
`forestas`, quando quelle righe erano commentate, e il dev ha dovuto fare un restore del database
di sviluppo (oc:8182).

## Cos'è questo repo

Package Laravel/Nova (`wm/wm-package`) condiviso dai progetti Webmapp, montato dai consumer come
repository locale via Composer (forestas, maphub, camminiditalia, osm2cai2…). Fornisce modelli EC
e UGC, risorse e campi Nova, import da GeoHub/OSM, analytics PostHog e i domini opzionali.

`composer.json` dichiara PHP `>8.1`, `laravel/framework` `^10 || ^11 || ^12 || ^13`,
`laravel/nova` `^5.0`. Il DB è PostgreSQL con PostGIS. L'ambiente di sviluppo di riferimento è il
Docker di `forestas` (container `php-forestas`, PHP 8.4, Laravel 12).

**Il package è usato da più consumer con branch diversi.** Prima di dare per scontato che una
funzionalità esista ovunque, controlla: alcuni fix vivono solo su un branch cliente.

## Comandi

Tutto gira **dentro il container `php-forestas`**, da `wm-package/`.

| Cosa | Comando |
|---|---|
| Entrare nel container | `docker exec -it php-forestas bash` |
| Test (vedi la regola in cima) | `composer test` → `vendor/bin/pest` |
| Test con coverage | `composer test-coverage` |
| PHPStan | `composer analyse` |
| Formattazione (Pint) | `composer format` — riformatta **tutto** il repo, vedi regole |
| Pint + PHPStan insieme | `composer lint` |
| Allineare le migration di un consumer | `php artisan wm-package:publish-missing-migrations --dry-run` |
| Ricompilare un campo Nova custom | `npm run prod` **dentro la cartella del campo** (`src/Nova/Fields/<Nome>/`), mai dalla root |

## Regole del repo

- **PHP minimo `>8.1`: mai `const` dentro un trait.** Le costanti nei trait esistono solo da 8.2 —
  su un consumer a 8.1 è un Fatal Error al primo autoload, non un bug silenzioso. Vanno sulla
  classe, che il trait referenzia con `self::`.
- **Le geometrie PostGIS passano sempre da SQL puro**, mai attraverso l'ORM: risalvare un modello
  geometrico via Eloquent fa transitare la geometria e la corrompe.
- **`composer format` (Pint) senza scope riformatta l'intero repo.** Controlla sempre
  `git status` dopo, e scarta i file fuori dal lavoro in corso.
- **Le traduzioni del package stanno in `resources/lang/*.json`**, non in `lang/`: scritte altrove
  vengono silenziosamente ignorate.
- **Ogni nuovo campo Nova custom con build CSS porta il proprio `postcss.config.js`**
  (`module.exports = {}`), altrimenti `npm run prod` risale alla root del consumer e fallisce con
  `ERR_REQUIRE_ESM`. Il `dist` è versionato: ricompilalo dalla sorgente e verifica che il diff non
  contenga prop spurie.
- **Prima di ogni bump del package nei consumer esegui la procedura migration**:
  [docs/howto/migration-wm-package.md](docs/howto/migration-wm-package.md). Gli stub della root
  sono obbligatori per ogni consumer; `vendor:publish` non pubblica quelli dei domini opzionali.
- **Se manca un endpoint o un helper, si estende il package**, non si costruisce un aggiramento
  nel consumer: il codice è nato per loro.
- **Documentazione, commenti e messaggi di commit in italiano**; i termini tecnici restano in
  inglese. Nomi di file, slug e identificatori seguono la stessa regola.

## Trappole

Ogni gruppo si carica da solo quando tocchi i file che lo riguardano — non pesa sulle
sessioni che lavorano altrove. Le due trappole con effetti irreversibili stanno invece in cima a
questo file, perché vanno lette sempre.

| Soggetto | Quando si applica | Regola |
|---|---|---|
| Modelli e persistenza | Tocchi modelli, trait, observer o enum del package | [.claude/rules/modelli.md](.claude/rules/modelli.md) |
| Nova | Tocchi Resource, campi, action o card Nova del package | [.claude/rules/nova.md](.claude/rules/nova.md) |
| Job, code e import | Tocchi job, comandi artisan, import o servizi del package | [.claude/rules/job-e-import.md](.claude/rules/job-e-import.md) |
| Test | Scrivi o modifichi un test del package | [.claude/rules/test.md](.claude/rules/test.md) |
| Build e frontend | Tocchi la build di un campo o di una card Nova custom (ogni cartella ha il proprio `webpack.mix.js` e `package.json`) | [.claude/rules/build.md](.claude/rules/build.md) |

## Conoscenza

| Argomento | Cosa copre | Pagina |
|---|---|---|
| Analytics PostHog | filtro shard, coerenza KPI/classifica, limiti di HogQL | [docs/knowledge/analytics-posthog.md](docs/knowledge/analytics-posthog.md) |
| Import GeoHub, OSM e tassonomie | le tre sorgenti, identifier, pivot, transazioni | [docs/knowledge/import-geohub-e-taxonomy.md](docs/knowledge/import-geohub-e-taxonomy.md) |
| Campi Flexible e Translatable | `config_home`/`config_detail`, embed nel WYSIWYG | [docs/knowledge/campi-flexible-e-translatable.md](docs/knowledge/campi-flexible-e-translatable.md) |
| Autorizzazione e ruoli | policy, super-admin, impersonation, throttle login | [docs/knowledge/autorizzazione-e-ruoli.md](docs/knowledge/autorizzazione-e-ruoli.md) |
| App e `config.json` | theme, deep link, story share, preferiti, asset | [docs/knowledge/configurazione-app-e-config-json.md](docs/knowledge/configurazione-app-e-config-json.md) |
| Media, upload e avatar | validazione dei campi media, conversion avatar | [docs/knowledge/media-e-avatar.md](docs/knowledge/media-e-avatar.md) |
| Layer e associazione contenuti | modalità auto/manuale, `poi_mode`, pivot `layerables` | [docs/knowledge/layer-e-associazione-contenuti.md](docs/knowledge/layer-e-associazione-contenuti.md) |
| Campi Nova custom e build | toolchain, Tailwind, componenti condivisi, `dist` | [docs/knowledge/campi-nova-custom-e-build.md](docs/knowledge/campi-nova-custom-e-build.md) |
| Domini opzionali | interruttore, stub, risorse Nova, Catasto Sentieri | [docs/knowledge/domini-opzionali.md](docs/knowledge/domini-opzionali.md) |
| Testare il package | i due `TestCase`, factory, isolamento verso l'esterno | [docs/knowledge/testare-il-package.md](docs/knowledge/testare-il-package.md) |

## Procedure

Per allineare le migration in un consumer:
[docs/howto/migration-wm-package.md](docs/howto/migration-wm-package.md).

## Documentazione

- `docs/resources/` — documentazione d'uso delle feature: `Analytics.md`, `TaxonomyWhere.md`,
  `TrailRegistry.md`, `OptionalDomains.md`.
- `docs/knowledge/` — perché funziona così e cosa è già stato provato, per argomento.
- `docs/howto/` — le procedure operative.
- `docs/features/<slug>/` — il cantiere di ogni lavoro: `overview.md`, `plan.md`, `notes.md`. È un
  diario, non una fotografia del risultato: **un fatto preso da un cantiere non entra altrove
  senza essere verificato nel codice**. Non si riscrive, si annota in fondo.

I ticket vivono su Orchestrator, formato `oc:<numero>`. Ogni documento in `docs/features/` inizia
con `> Ticket: oc:<ID>`; lo scope dei commit è `feat(oc:<ID>): …` / `fix(oc:<ID>): …`.

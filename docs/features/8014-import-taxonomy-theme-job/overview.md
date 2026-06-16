> Ticket: oc:8014

# Feature: aggiungere ImportTaxonomyThemeJob e registrarlo nell'import order

## Cosa cambia

Il sistema acquisirà la capacità di importare i `TaxonomyTheme` da GeoHub durante l'import completo di un'app. Attualmente il job mancava e la taxonomy Theme non veniva mai importata, rendendo non funzionanti i filtri per tema nelle app.

**Aggiornamento (fix critico):** anche dopo la creazione di `ImportTaxonomyThemeJob`, l'import non funzionava perché `ImportAppJob::processDependencies()` usa blocchi `if` espliciti per ogni dipendenza — il blocco per `taxonomy_theme` era assente. Il job veniva ignorato anche quando presente nel config.

## Perché

Le app GeoHub che usano filtri per tema non vengono importate correttamente su Maphub perché il job `ImportTaxonomyThemeJob` non esiste. Il modello `TaxonomyTheme` e la configurazione in `wm-geohub-import.php` esistono già, mancano solo il job e la sua registrazione.

## Requisiti

- [ ] Creare `ImportTaxonomyThemeJob` sul pattern di `ImportTaxonomyActivityJob`
  - `getModelKey()` → `'taxonomy_theme'`
  - `getForeignKey()` → `'taxonomy_theme_id'`
  - `getRelationshipName()` → `'taxonomyThemes'`
  - Timeout: 300 secondi (5 minuti)
  - Gestione errori silenziosa: try/catch in `handle()` che non rilancia l'eccezione
- [ ] Registrare `taxonomy_theme` in `MODEL_IMPORT_ORDER` in `GeohubImportService`, dopo `taxonomy_activity` e prima di `ec_poi`
- [ ] Aggiungere `taxonomy_theme` a `default_dependencies['app']` in `wm-geohub-import.php`
- [ ] Popolare `'job'` per `taxonomy_theme` in `wm-geohub-import.php` con `ImportTaxonomyThemeJob::class`
- [ ] Popolare `'fields'` per `taxonomy_theme` in `wm-geohub-import.php` con: `name`, `description`, `excerpt`, `identifier`, `created_at`, `updated_at`, `icon` (allineato al pattern `taxonomy_activity`)
- [ ] Aggiungere blocco `if (in_array('taxonomy_theme', $allowedDependencies))` in `ImportAppJob::processDependencies()`, dopo `taxonomy_poi_types` e prima di `ec_poi` (ordine: tutti i taxonomy prima delle entità EC)
- [ ] Aggiungere `taxonomy_theme` a `$allDependencies` in `ImportAppJob::getAllowedDependencies()` per allineare il fallback hardcoded al config

## Rischi

- **Accesso GeoHub:** per eseguire e testare è necessario che il proprio IP sia abilitato dal firewall GeoHub — il test in locale non è possibile senza whitelist.
- **Campo `icon` da verificare con il capo (decisione pendente):** non è confermato che GeoHub abbia la colonna `icon` nella tabella `taxonomy_themes`. Se manca o ha formato diverso, il transformer `svgIconToNameIcon` genera un'eccezione inghiottita silenziosamente: i job appaiono completati in Horizon ma i theme non vengono importati. Chiedere conferma prima di includere `icon` nel mapping `fields`.
- **Eccezioni silenziose:** il try/catch non logga gli errori — aggiungere `Log::error()` nel catch per rendere i fallimenti visibili nei log senza far fallire il job.
- **Tabella `taxonomy_themes` locale:** verificare che le migrazioni del wm-package siano state pubblicate ed eseguite nel progetto target prima del test.
- **`ImportAppJob` non dinamico:** `getAllowedDependencies()` usa un array hardcoded come fallback — aggiungendo `taxonomy_theme` al fallback e al blocco `if`, si chiude il flusso. Se in futuro si aggiungono nuove taxonomy, lo stesso pattern va ripetuto manualmente.

## Out of scope

- `ImportTaxonomyWhenJob` e `ImportTaxonomyTargetJob` — hanno lo stesso problema (`'job' => ''`) ma sono esclusi da questo ciclo; ticket separati da aprire.
- Eventuali Nova actions o UI per la gestione dei TaxonomyTheme.

## Moduli toccati

Repo **`wm-package`**:

| File | Modifica |
|---|---|
| `src/Jobs/Import/ImportTaxonomyThemeJob.php` | Nuovo file |
| `src/Nova/TaxonomyTheme.php` | Nuovo file — Nova resource |
| `src/Services/Import/GeohubImportService.php` | Aggiunta di `taxonomy_theme` in `MODEL_IMPORT_ORDER` |
| `config/wm-geohub-import.php` | `job`, `fields`, `default_dependencies` per `taxonomy_theme` |
| `src/Jobs/Import/ImportAppJob.php` | Aggiunta `taxonomy_theme` in `processDependencies()` e `getAllowedDependencies()` |

Repo **`maphub`** (boilerplate):

| File | Modifica |
|---|---|
| `app/Nova/TaxonomyTheme.php` | Nuovo file — stub che estende wm-package |
| `app/Providers/NovaServiceProvider.php` | Aggiunta di `TaxonomyTheme` nel menu Taxonomies |

> Ticket: oc:8014

# Feature: aggiungere ImportTaxonomyThemeJob e registrarlo nell'import order

## Cosa cambia

Il sistema acquisirà la capacità di importare i `TaxonomyTheme` da GeoHub durante l'import completo di un'app. Attualmente il job mancava e la taxonomy Theme non veniva mai importata, rendendo non funzionanti i filtri per tema nelle app.

## Perché

Le app GeoHub che usano filtri per tema non vengono importate correttamente su Maphub perché il job `ImportTaxonomyThemeJob` non esiste. Il modello `TaxonomyTheme` e la configurazione in `wm-geohub-import.php` esistono già, mancano solo il job e la sua registrazione.

## Requisiti

- [ ] Creare `ImportTaxonomyThemeJob` sul pattern di `ImportTaxonomyActivityJob`
  - `getModelKey()` → `'taxonomy_theme'`
  - `getForeignKey()` → `'taxonomy_theme_id'`
  - `getRelationshipName()` → `'taxonomyThemes'`
  - Timeout: 300 secondi (5 minuti)
  - Gestione errori silenziosa: try/catch in `handle()` che non rilancia l'eccezione
- [ ] Registrare `taxonomy_theme` in `MODEL_IMPORT_ORDER` in `GeohubImportService`, dopo `taxonomy_activity` e prima di `ec_poi`
- [ ] Aggiungere `taxonomy_theme` a `default_dependencies['app']` in `wm-geohub-import.php`
- [ ] Popolare `'job'` per `taxonomy_theme` in `wm-geohub-import.php` con `ImportTaxonomyThemeJob::class`
- [ ] Popolare `'fields'` per `taxonomy_theme` in `wm-geohub-import.php` con: `name`, `description`, `excerpt`, `identifier`, `created_at`, `updated_at`, `icon` (allineato al pattern `taxonomy_activity`)

## Rischi

- **Accesso GeoHub:** per eseguire e testare è necessario che il proprio IP sia abilitato dal firewall GeoHub — il test in locale non è possibile senza whitelist.
- **Campo `icon` da verificare con il capo (decisione pendente):** non è confermato che GeoHub abbia la colonna `icon` nella tabella `taxonomy_themes`. Se manca o ha formato diverso, il transformer `svgIconToNameIcon` genera un'eccezione inghiottita silenziosamente: i job appaiono completati in Horizon ma i theme non vengono importati. Chiedere conferma prima di includere `icon` nel mapping `fields`.
- **Eccezioni silenziose:** il try/catch non logga gli errori — aggiungere `Log::error()` nel catch per rendere i fallimenti visibili nei log senza far fallire il job.
- **Tabella `taxonomy_themes` locale:** verificare che le migrazioni del wm-package siano state pubblicate ed eseguite nel progetto target prima del test.

## Out of scope

- `ImportTaxonomyWhenJob` e `ImportTaxonomyTargetJob` — hanno lo stesso problema (`'job' => ''`) ma sono esclusi da questo ciclo; ticket separati da aprire.
- Eventuali Nova actions o UI per la gestione dei TaxonomyTheme.

## Moduli toccati

Repo **`wm-package`**:

| File | Modifica |
|---|---|
| `src/Jobs/Import/ImportTaxonomyThemeJob.php` | Nuovo file |
| `src/Nova/TaxonomyTheme.php` | Nuovo file — Nova resource |
| `src/Services/Import/GeohubImportService.php` | Aggiunta di `taxonomy_theme` in `MODEL_IMPORT_ORDER` |
| `config/wm-geohub-import.php` | `job`, `fields`, `default_dependencies` per `taxonomy_theme` |

Repo **`maphub`** (boilerplate):

| File | Modifica |
|---|---|
| `app/Nova/TaxonomyTheme.php` | Nuovo file — stub che estende wm-package |
| `app/Providers/NovaServiceProvider.php` | Aggiunta di `TaxonomyTheme` nel menu Taxonomies |

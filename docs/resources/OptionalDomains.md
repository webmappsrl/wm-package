# Domini opzionali del package

> Introdotti con oc:8492. Fonte di verita' sulle decisioni:
> `docs/features/8492-stub-opzionali-feature-opt-in/overview.md`.

## Cos'e' un dominio opzionale

Un **dominio** e' un insieme di stub di migration, comandi e impostazioni che
vivono nel package ma che un consumer riceve solo se lo attiva esplicitamente.
A dominio spento il package si comporta come se il dominio non esistesse: i suoi
stub non sono considerati dai comandi, i suoi comandi e le sue risorse Nova non
vengono registrati.

Serve per il codice che e' utile ad alcuni progetti e inutile ad altri — il
Catasto Sentieri serve a un catasto regionale, non a camminiditalia — senza
imporlo a tutti e senza tirarne fuori un package separato.

Domini oggi dichiarati:

| Dominio | Cosa contiene |
|---|---|
| `trail_registry` | Catasto Sentieri: codice REI (oc:8489), prevalidazione (oc:8490), validazione istanze (oc:8491) |

Ogni dominio ha una sezione in `config/wm-package.php`:

```php
'features' => [
    'trail_registry' => [
        'enabled' => env('WM_TRAIL_REGISTRY_ENABLED', false),
        // le impostazioni del dominio stanno qui, non in un file proprio
    ],
],
```

L'interruttore e le impostazioni stanno insieme: chi cerca "com'e' configurato
il catasto" trova tutto in un posto solo.

## Attivare un dominio su un consumer

1. **Vedere cosa comporta, senza toccare nulla:**

   ```bash
   php artisan wm-package:publish-missing-migrations --with=<dominio> --dry-run
   ```

   `--with` aggiunge il dominio alla verifica anche se e' spento, quindi si puo'
   valutare l'adesione senza modificare la propria configurazione.

2. **Accendere l'interruttore** — `WM_<DOMINIO>_ENABLED=true` in:
   - `.env` (locale, non versionato)
   - `.env-deploy`, se il repo ha una CI che lo copia in `.env`
   - `.env-example`, per chi installa il progetto da zero
   - `.env.testing`, se il repo ce l'ha versionato: e' il file che leggono i
     comandi artisan lanciati con `--env=testing`, dove `phpunit.xml` non arriva
   - il `.env` di **ogni server** (UAT, produzione). E' l'unico posto fuori dal
     repository, ed e' quello che si dimentica.

3. **Farlo vedere anche ai test** — in `phpunit.xml`, accanto a `DB_DATABASE`:

   ```xml
   <env name="WM_<DOMINIO>_ENABLED" value="true"/>
   ```

   `php artisan test` forza `APP_ENV=testing`, quindi Laravel carica
   `.env.testing` e non vede `.env-deploy`. Senza questa riga la suite gira con
   il dominio spento e fallisce in modo poco leggibile ("route non trovata"
   invece di "dominio non attivo"). `phpunit.xml` e' preferito a `.env.testing`
   perche' e' il file che dichiara le condizioni della suite e ha la precedenza
   sui file `.env`.

4. **Pubblicare gli stub del dominio**, uno per uno:

   ```bash
   php artisan wm-package:publish-migration <dominio>/<nome-stub>
   php artisan migrate
   git add database/migrations/ && git commit
   ```

5. **Se il repo ha il gate in CI**, aggiungere il dominio allo step esistente:

   ```yaml
   run: php artisan wm-package:publish-missing-migrations --with=<dominio> --dry-run
   ```

   Attenzione alla via d'uscita: `--with` e' **indipendente** dall'interruttore.
   Se un giorno il dominio viene spento, va tolto anche di qui, altrimenti la CI
   continua a pretendere gli stub di un dominio disattivato.

## `vendor:publish` non pubblica gli stub dei domini

`->discoversMigrations()` di `spatie/laravel-package-tools` legge la cartella
`database/migrations` con `Filesystem::files()`, che **non e' ricorsivo**: le
sottocartelle dei domini gli sono invisibili.

Ha due facce, entrambe volute:

- e' cio' che protegge chi non ha aderito — nessun consumer si porta a casa la
  tabella di un dominio che non usa, senza bisogno di codice nostro;
- **chi ha aderito non puo' usare `vendor:publish --tag=wm-package-migrations`**,
  che pure e' il passo di setup documentato nei consumer. Serve
  `publish-migration <dominio>/<stub>`.

Vale anche per `loadMigrationsFrom()`: gli stub di un dominio non vengono mai
eseguiti dal package.

## Aggiungere un dominio nuovo

1. Una sezione in `config/wm-package.php` sotto `features`, con `enabled` a
   `false` di default.
2. Gli stub in `database/migrations/<dominio>/`. Sono identificati come
   `<dominio>/<nome-stub>`: il dominio serve ai comandi, **non** entra nel nome
   del file pubblicato nel consumer.
3. I comandi del dominio agganciati in
   `config('wm-package.features.<dominio>.commands')`, le risorse Nova in
   `features.<dominio>.nova_resources`, le route in
   `routes/domains/<dominio>.php`. Li registra
   `WmPackageServiceProvider::registerEnabledDomains()`, che gira solo sui
   domini accesi.

**Vincolo:** **nessuno stub di dominio puo' avere lo stesso nome-base di uno stub
della root o di un altro dominio.** L'identificatore qualificato risolve
l'ambiguita' solo dentro il package: dal momento in cui lo stub e' pubblicato,
la ricerca torna a cercare per suffisso del nome file, che il dominio non lo
contiene. Due omonimi renderebbero il gate verde su uno stub mai pubblicato.
Il vincolo e' verificato a runtime: `stubBaseNames()` fallisce con un messaggio
esplicito se trova due identificatori con lo stesso nome-base.

**Le risorse Nova di un dominio non possono stare in `src/Nova`.**
`Nova::resourcesIn()` scandisce quella cartella in modo ricorsivo e registra
tutto cio' che ci trova, a prescindere dall'interruttore. Vanno dichiarate in
`config('wm-package.features.<dominio>.nova_resources')` e collocate altrove; lo
stesso vale per i comandi (`features.<dominio>.commands`). Le route di un
dominio vivono in `routes/domains/<dominio>.php`, caricato solo a dominio
acceso. Un test di regressione
(`OptionalDomainRegistrationTest::test_no_declared_domain_has_a_folder_under_src_nova`)
fallisce se qualcuno crea `src/Nova/<Dominio>/`.

## Spegnere un dominio

Spegnere l'interruttore nasconde la feature ma **non rimuove la tabella**: i dati
restano sul database, e nessun comando li cancella.

L'ordine corretto e':

1. **bonificare i dati** con i comandi del dominio, finche' esistono;
2. **spegnere l'interruttore**;
3. **rimuovere lo schema** con una migration del consumer.

Spegnere per primo toglie i comandi del dominio, cioe' lo strumento che serve per
il passo 1. Non c'e' nessun automatismo che lo impedisca.

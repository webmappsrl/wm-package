> Ticket: oc:8492

# Stub di migration opzionali e feature opt-in nel wm-package

## Cosa cambia

Il package impara il concetto di **dominio opzionale**: un insieme di stub di
migration, comandi e codice che esiste nel package ma che un consumer riceve
solo se lo attiva esplicitamente. A dominio spento il consumer trova il package
esattamente com'e' oggi.

Tre pezzi:

1. **Interruttore** — ogni dominio ha una sezione propria in
   `config/wm-package.php`, sotto `features`, spenta di default. A dominio
   spento non si registrano comandi, route, Nova resource e voci di menu del
   dominio.
2. **Marcatore degli stub** — gli stub di un dominio vivono in una sottocartella
   di `database/migrations/` (es. `database/migrations/trail_registry/`), non
   nella root. Il glob della root resta invariato, quindi i 67 stub obbligatori
   si comportano esattamente come prima.
3. **Copertura del gate** — `publish-missing-migrations` (e il suo `--dry-run`)
   include gli stub dei soli domini accesi, piu' quelli passati esplicitamente
   con la nuova opzione `--with=<dominio>`.

## Perche'

L'integrazione del Catasto Sentieri con il SUS porta nel package tre aggiunte
decise in sede di scrum — algoritmo del codice REI (oc:8489), prevalidazione
(oc:8490), validazione istanze (oc:8491) — perche' serviranno a Lombardia,
Toscana e a un framework catasto condiviso.

Sono additive e opzionali: un catasto regionale le usa, camminiditalia no. Ma
oggi il package non ha alcun modo di dire "questo pezzo non e' per tutti".

### La ragione principale: il codice che si registra dove non dovrebbe

Senza interruttore, il codice del catasto si registra in **ogni** consumer che
aggiorna il submodule. Gli amministratori di camminiditalia si troverebbero le
voci del Catasto Sentieri nel menu di Nova, e i suoi comandi in
`php artisan list`. Non e' una tabella nascosta in un database: e' un difetto
visibile nel prodotto di un altro cliente.

Per questa parte **non esiste una versione economica**: o c'e' un interruttore,
o il codice si registra ovunque.

### La ragione secondaria: gli stub obbligatori per costruzione

`InteractsWithWmPackageMigrationStubs::stubBaseNames()` enumera gli stub con
`glob('*.stub')` sull'intera cartella: **ogni stub e' obbligatorio per
costruzione**. Aggiungendo lo stub del catasto, `publish-missing-migrations` lo
segnalerebbe come mancante in ogni consumer.

Una volta che l'interruttore va scritto comunque, escludere gli stub dal gate
costa poco in piu': sono le stesse chiavi di configurazione, lette da un punto
diverso.

### L'alternativa scartata: non fare niente

Va nominata, perche' regge piu' di quanto sembri. Lo stub del catasto va nella
root come tutti gli altri; maphub diventa rosso una volta, pubblica e migra una
tabella che non usera', torna verde; gli altri consumer se la prendono quando
capita. Costo: una migration inerte, una volta sola. Nessun meccanismo
permanente da mantenere.

Non basta per due motivi, di peso molto diverso:

- **Decisivo:** copre solo le migration. Meta' dei requisiti di questo ticket
  riguarda la registrazione del codice (route, Nova, menu, comandi), su cui
  l'alternativa non dice nulla — vedi sopra.
- **Minore:** non e' una tabella ma tre, piu' quelle che verranno. Le tabelle
  morte non restano innocue: fra due anni nessuno sa piu' se si possono
  cancellare, e nessuno se ne assume la responsabilita'.

### Quanto danno farebbero davvero gli stub obbligatori

Misura utile per dimensionare il problema, **non** la ragione principale del
ticket. Tutto cio' che segue descrive cosa succederebbe **se oc:8489 aggiungesse
lo stub del catasto senza questo ticket**: con il meccanismo di opt-in non
accade nulla di tutto questo.

Cercando `publish-missing-migrations` nei workflow dei consumer di
`wm/wm-package` presenti in locale (contati leggendo il blocco `require` del
loro `composer.json`, quindi senza includere il package stesso), il gate CI
risulta attivo in **1 repo su 16**:

| Gate `--dry-run` in CI | Repo |
|---|---|
| si' (1) | maphub (`run-tests.yml:50-51`) |
| no (15) | forestas, camminiditalia, osm2cai2, geohub2, carg, dem, ersaf, ersaf-osm, hoqu2, orchestrator, osmfeatures, prc-taxonomies, mpt2, boilerplate-test, laravel-postgis-boilerplate |

- **maphub**: pipeline rossa alla prima PR. Danno visibile e rimediabile.
- **gli altri 15**: nessun segnale. Al primo `publish-missing-migrations` che un
  dev esegue per un motivo suo — il workflow documentato in `CLAUDE.md` — la
  tabella verrebbe pubblicata e migrata insieme alle altre, senza che nessuno la
  noti.

Il ticket stima tre pipeline rosse; la misura ne da' una.

### Il secondo caso non e' ipotetico

Il meccanismo non nasce per un caso solo. `webmapp/wm-osmfeatures` e' oggi un
**submodule separato** montato solo da osm2cai2 (`.gitmodules`,
`composer.json:42`): un pezzo gia' opzionale, risolto con lo strumento piu'
pesante disponibile — un repo con la sua CI e il suo versioning. E' il
candidato naturale a rientrare nel package come dominio opzionale una volta che
questo meccanismo e' collaudato.

La sua forma e' diversa da quella del catasto, e ha vincolato il disegno:

| Componente | wm-osmfeatures |
|---|---|
| Stub di migration | 1 (`create_wm_osmfeatures_table.php.stub`) |
| Config file | 1 file proprio (`config/wm-osmfeatures.php`) |
| Comandi artisan | 4 |
| Altro | Jobs, Traits, Facade, Interfaces, Exceptions |
| Nova / route | nessuna |

Da cui: **un dominio non e' una cartella di stub**, e' un insieme che comprende
anche comandi e impostazioni proprie.

## Forma della configurazione

Ogni dominio ha una sezione unica in `config/wm-package.php`: l'interruttore e
le sue impostazioni stanno insieme.

```php
'features' => [
    'trail_registry' => [
        'enabled' => env('WM_TRAIL_REGISTRY_ENABLED', false),

        // Registrati solo a dominio acceso, da
        // WmPackageServiceProvider::registerEnabledDomains().
        'commands' => [],
        'nova_resources' => [],
    ],
    'osmfeatures' => [
        'enabled' => env('WM_OSMFEATURES_ENABLED', false),
        // qui finira' il contenuto di config/wm-osmfeatures.php
    ],
],
```

Le route di un dominio vivono in `routes/domains/<dominio>.php`, caricato dallo
stesso metodo solo se il dominio e' acceso.

**Un dominio non registra file di configurazione propri.** `configurePackage()`
mantiene la sua lista statica di 18 file: renderla variabile significherebbe un
service provider che legge la configurazione per decidere quale configurazione
registrare, con un ordine di avvio delicato e problemi che si manifestano come
comportamenti strani invece che come errori.

Prezzo dichiarato in anticipo: quando `wm-osmfeatures` rientrera' nel package,
il suo file non verra' trasportato intatto ma riversato sotto
`features.osmfeatures`, e ogni `config('wm-osmfeatures.x')` nel suo codice
diventera' `config('wm-package.features.osmfeatures.x')`. Lavoro meccanico,
tutto dentro quel ticket.

## Le quattro superfici che un dominio puo' toccare

`WmPackageServiceProvider::registerEnabledDomains()` e' il punto unico dove si
registra cio' che appartiene a un dominio acceso:

| Superficie | Dove si dichiara |
|---|---|
| Comandi artisan | `features.<dominio>.commands` |
| Risorse Nova | `features.<dominio>.nova_resources` |
| Route | `routes/domains/<dominio>.php`, caricato se esiste |
| Voci di menu | seguono la risorsa Nova |

**Vincolo strutturale, non convenzione: le risorse Nova di un dominio non
possono stare in `src/Nova`.** `Nova::resourcesIn()` (`WmPackageServiceProvider:432`)
scandisce quella cartella in modo **ricorsivo** e registra tutto cio' che ci
trova, a dominio spento incluso — quindi una risorsa collocata li' sarebbe
visibile in tutti e 16 i consumer qualunque cosa dica l'interruttore. Il vincolo
e' presidiato da un test
(`OptionalDomainRegistrationTest::test_no_declared_domain_has_a_folder_under_src_nova`)
che fallisce se qualcuno crea `src/Nova/<Dominio>/`.

## Come si identifica uno stub

Con le sottocartelle il basename smette di essere una chiave univoca: due domini
potrebbero avere stub omonimi, e `findStubPath()` li risolverebbe in modo
arbitrario. L'identificatore di uno stub opzionale e' quindi **qualificato dal
dominio**.

| | Stub obbligatorio | Stub di un dominio |
|---|---|---|
| Dove sta nel package | `database/migrations/create_users_table.php.stub` | `database/migrations/trail_registry/create_settings_table.php.stub` |
| Come lo si chiama da CLI | `create_users_table` | `trail_registry/create_settings_table` |
| Nome una volta pubblicato | `2026_..._create_users_table.php` | `2026_..._create_settings_table.php` |

Il dominio non compare mai nel nome del file pubblicato: e' il vincolo che ha
fatto scartare il prefisso nel nome, dato che `findPublishedPathForStub()` cerca
per suffisso. Vive solo nell'identificatore usato dai comandi.

**Il vincolo e' piu' forte di come sembra, ed e' imposto a runtime.** Il lato
pubblicato cerca per suffisso del nome file, che il dominio non lo contiene:
quindi non basta che due domini non creino la stessa tabella — **nessuno stub di
dominio puo' avere lo stesso nome-base di uno stub della root o di un altro
dominio**, altrimenti il gate potrebbe risultare verde su uno stub mai
pubblicato. `stubBaseNames()` rileva gli omonimi e fallisce con un messaggio
esplicito.

## Rapporto con `vendor:publish`

`configurePackage()` usa `->discoversMigrations()`, e
`ProcessMigrations::discoverPackageMigrations()` legge la cartella con
`Filesystem::files()`, che **non e' ricorsivo** (verificato in
`vendor/spatie/laravel-package-tools`). Conseguenze, entrambe volute ma nessuna
delle due ovvia:

- gli stub di un dominio sono gia' invisibili a
  `vendor:publish --tag=wm-package-migrations` senza che serva codice nostro —
  ed e' cio' che protegge chi non ha aderito;
- **chi ha aderito non puo' pubblicarli con `vendor:publish`**, che e' il passo 3
  del setup documentato in `forestas/CLAUDE.md`. L'unica via e'
  `publish-migration <dominio>/<stub>`. Anche `loadMigrationsFrom()` non li
  vedra' mai.

Non e' una scelta di naming: e' un cambio di semantica di un meccanismo di terze
parti. Va scritto nella guida e in `CLAUDE.md`, non lasciato da scoprire.

## Requisiti

- [ ] Ogni dominio ha una sezione in `config('wm-package.features')` con
      `enabled` spento di default; le sue impostazioni stanno nella stessa
      sezione, mai in un file di configurazione proprio
- [ ] A dominio spento: nessun comando, route, Nova resource o voce di menu del
      dominio viene registrata. Le classi restano autoloadabili (lettura
      leggera): la protezione reale e' la tabella non pubblicata
- [ ] Gli stub di un dominio vivono in `database/migrations/<dominio>/`, e sono
      identificati come `<dominio>/<basename>`
- [ ] `stubBaseNames()` continua a fare glob sulla sola root: comportamento
      identico per i 67 stub esistenti e per i consumer attuali
- [ ] `publish-missing-migrations` e `--dry-run` ignorano gli stub dei domini
      spenti: nessun consumer li vede come mancanti, nessun gate CI si rompe
- [ ] `publish-missing-migrations` **include** gli stub dei domini accesi: chi ha
      aderito ottiene lo stesso gate che ha oggi sugli obbligatori
- [ ] Nuova opzione `--with=<dominio>`, ripetibile, che **aggiunge** domini alla
      verifica senza poterne togliere. Valida il valore contro le chiavi
      dichiarate in `config('wm-package.features')`, non contro l'esistenza
      della sottocartella: un dominio dichiarato ma ancora privo di stub e'
      legittimo, un nome non dichiarato fallisce con errore esplicito. Senza
      questa scelta, `--with=trail_registry` nella CI di forestas romperebbe la
      pipeline fino al merge di oc:8489
- [ ] `publish-migration <dominio>/<stub>` pubblica qualunque stub, anche
      opzionale, su richiesta esplicita
- [ ] Un consumer che ha pubblicato uno stub opzionale non vede segnalazioni
      anomale nei comandi

## Decisioni

| # | Titolo | Esito |
|---|---|---|
| 1 | **Interruttore di dominio** | Un interruttore unico per dominio, non uno per feature: le tre aggiunte del catasto si accendono insieme. Nome `trail_registry`. Spento di default. Lettura leggera |
| 2 | **Marcatore stub opzionali** | Sottocartella `database/migrations/<dominio>/*.stub`, con identificatore qualificato `<dominio>/<basename>`. Scartato il prefisso nel nome del file: `findPublishedPathForStub()` matcha per suffisso, quindi il marcatore viaggerebbe fino al consumer |
| 3 | **Copertura del gate** | `--dry-run` include gli stub dei domini accesi, piu' quelli passati con `--with`. L'opzionalita' e' una proprieta' del consumer, non del package |
| 4 | **Guardia flag acceso senza tabella** | Nessuna guardia a boot: un `Schema::hasTable()` a ogni avvio costerebbe una query per richiesta e rischierebbe di bloccare `migrate`, cioe' la via d'uscita. Lo copre il gate — ma solo dove il gate esiste, cioe' maphub e forestas |
| 5 | **Dove vive l'interruttore** | Default nel package; adesione nel `.env` del consumer. **Mai** pubblicando il config: congelerebbe una copia delle altre chiavi, che smetterebbero di aggiornarsi col package |
| 6 | **Adozione del gate in forestas** | Dentro questo ciclo, documentata in `forestas/docs/features/8492-.../overview.md` |
| 7 | **osmfeatures come secondo caso** | Il disegno e' stato provato anche su `wm-osmfeatures`, non solo sul catasto. La sua migrazione effettiva resta out of scope |
| 8 | **Nessun file di configurazione per dominio** | Le impostazioni di un dominio stanno sotto `features.<dominio>`, cosi' `configurePackage()` resta una lista statica |
| 10 | **Le quattro superfici, non solo i comandi** | Il primo disegno agganciava solo i comandi. La code review ha rilevato che `Nova::resourcesIn()` e' ricorsivo, quindi il requisito piu' importante del ticket non aveva alcun presidio: `registerEnabledDomains()` copre ora comandi, Nova e route, e un test impedisce di collocare risorse Nova dove verrebbero registrate comunque |
| 9 | **Nessuna verifica automatica dell'interruttore al deploy** | Scartata dopo averla proposta: il rischio che l'interruttore sparisca da un `.env` di produzione e' reale ma poco probabile, e non giustifica un passo in piu' nel deploy. Rischio accettato, vedi sotto |

## Da decidere in fase di piano

**Comando diagnostico.** Un `wm-package:domains` che elenca lo stato dei domini
e, dato un dominio, dice cosa manca per considerarlo attivo (interruttore non
impostato, `phpunit.xml` senza la variabile, step di CI senza `--with`),
stampando le righe esatte da aggiungere. **Non modifica** i file del consumer:
sono file di un altro repo, in tre formati diversi, con commenti e ordinamenti
scelti da chi li mantiene. Il valore rispetto a una guida scritta e' che sa dire
se hai finito. Voce di stima propria, non inclusa nell'opt-in.

## Rischi

- **L'interruttore va ricordato in sei posti**, di cui uno fuori dal
  repository: `.env` locale, `.env-deploy`, `.env-example`, `phpunit.xml`,
  `.env.testing` (letto dai comandi artisan lanciati con `--env=testing`, dove
  `phpunit.xml` non arriva) e il `.env` del server. Solo i primi cinque si
  vedono in un diff.
- **Rischio accettato, senza mitigazione (decisione 9): l'interruttore che
  sparisce in produzione.** `scripts/deploy_prod.sh` esegue `php artisan
  optimize`, che congela la configurazione. Se il `.env` del server perde la
  chiave — server ricostruito, ambiente nuovo allineato male — route e Nova
  resource del catasto spariscono mentre la tabella resta piena di dati veri. Il
  SUS riceve 404, cioe' il sintomo che indirizza nella direzione sbagliata. Poco
  probabile, non coperto: la causa va cercata qui.
- **Il gate diventa piu' severo per chi aderisce.** Forestas, attivando il
  catasto, si porta in CI una verifica che prima non aveva.
- **`--with` e configurazione possono divergere.** Mitigato per costruzione:
  `--with` puo' solo aggiungere domini, mai toglierne. Al massimo rende il gate
  piu' severo della configurazione, mai piu' permissivo.
- **`--with` diventa contratto verso i consumer** dal momento in cui entra in un
  workflow: cambiarne la semantica rompe la CI di chi l'ha adottata.
- **Il doppio montaggio di osmfeatures.** Se un giorno osm2cai2 accendera' il
  dominio mentre monta ancora il submodule, avra' due volte lo stesso codice e
  lo stesso nome di tabella. Nessuna guardia lo rileva: e' un vincolo da
  rispettare nel ticket che fara' la migrazione.
- **`vendor:publish` non vede gli stub dei domini** — vedi sezione dedicata. Chi
  aderisce deve usare `publish-migration`.

## Out of scope

- Le tre feature che dipendono da questo meccanismo: oc:8489, oc:8490, oc:8491
- La migrazione di `wm-osmfeatures` da submodule a dominio opzionale
- L'adozione del gate CI negli altri 14 repo che ne restano privi dopo forestas:
  ognuno ha la sua bonifica da fare, ticket propri
- Revisione dei 67 stub esistenti per capire se qualcuno fosse in realta'
  opzionale
- Rollback del meccanismo: il codice torna indietro, i dati gia' migrati e le
  chiavi gia' scritte nei `.env` dei consumer no. Non e' coperto, per scelta
- Disinstallazione di un dominio gia' attivato: spegnere l'interruttore nasconde
  la feature ma **non rimuove la tabella**

## Moduli toccati

Tutti in `wm-package`.

| File | Cosa |
|---|---|
| `src/Services/FeaturesService.php` | Nuovo: fonte di verita' sui domini dichiarati e accesi |
| `src/Commands/Concerns/InteractsWithWmPackageMigrationStubs.php` | `stubBaseNames()` include le sottocartelle dei domini accesi; `findStubPath()` risolve identificatori qualificati |
| `src/Commands/WmPackagePublishMissingMigrationsCommand.php` | Nuova opzione `--with=<dominio>`, ripetibile e validata |
| `src/Commands/WmPackagePublishMigrationCommand.php` | Risoluzione di uno stub in sottocartella |
| `src/WmPackageServiceProvider.php` | Registrazione condizionale di comandi/route/Nova per dominio |
| `config/wm-package.php` | Nuovo blocco `features` con la sezione `trail_registry` |
| `docs/resources/OptionalDomains.md` | Guida: cos'e' un dominio opzionale, come se ne aggiunge uno, come si attiva su un consumer, perche' `vendor:publish` non basta, e che spegnerlo non rimuove la tabella |
| `CLAUDE.md` | Sezione "Migration wm-package": titolo da aggiornare (non sono piu' tutti obbligatori) + rimando di una riga alla guida |
| `tests/` | Copertura del meccanismo (vedi sotto) |

## Verifica

**Test automatici in wm-package** — rete permanente, veloci, senza database. Con
uno stub opzionale di **fixture** creato dalla suite, non lo stub reale del
catasto, che arriva con oc:8489:

- a dominio spento, `stubBaseNames()` restituisce **lo stesso insieme** di prima
  — asserito per insieme, mai per conteggio: un test che verifica "sono 67"
  diventa rosso al primo stub aggiunto da un ticket non correlato, e il primo
  dev che lo trova lo cancella
- a dominio acceso, l'insieme cresce del solo stub del dominio
- `findStubPath()` risolve l'identificatore qualificato in entrambi i casi
- `--with=<dominio>` aggiunge il dominio anche a interruttore spento
- `--with=<dominio dichiarato ma senza stub>` passa senza errori — e' il caso di
  forestas fino al merge di oc:8489
- `--with=<nome non dichiarato>` fallisce con errore esplicito

**Verifica manuale su maphub prima del merge.** La prova proposta dal ticket
(«`--dry-run` resta verde su un consumer che non ha pubblicato uno stub
opzionale») **non e' eseguibile qui**: in 8492 non esiste ancora nessuno stub
opzionale reale. Quello che si verifica adesso e' la **non-regressione**:
`--dry-run` su maphub resta verde e continua a considerare gli stessi stub di
prima. La prova completa va eseguita su maphub al merge di oc:8489, e va scritta
come requisito di accettazione di quel ticket.

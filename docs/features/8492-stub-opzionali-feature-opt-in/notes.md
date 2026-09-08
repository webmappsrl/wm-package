> Ticket: oc:8492

# Notes — Stub di migration opzionali e feature opt-in

## Divergenze dal piano, task per task

Ogni voce e' richiamata da una riga nel `plan.md` del task corrispondente.

### Task 2: identificatori qualificati

Lo stub di fixture non e' un file del repository come descritto nel piano: viene
creato a runtime dai test e rimosso in `tearDown`. Vivendo in
`database/migrations/` sarebbe finito nell'artefatto distribuito a tutti i
consumer, dove `publish-migration` avrebbe permesso di crearne la tabella in
produzione.

### Task 3: opzione with

Le asserzioni dei test non sono quelle del piano. Il `setUp()` della classe crea
di proposito uno stub disallineato, quindi un gate verde non e' asseribile senza
prima rimuoverlo; i test che devono osservare un gate pulito ora lo fanno, e
asseriscono `doesntExpectOutputToContain()`, che e' l'asserzione di merito.
Resta un limite dichiarato: un gate globalmente verde non e' asseribile in
quella suite, perche' il database di test del consumer ha disallineamenti
propri. Il comando ha inoltre acquisito un avviso quando si pubblica lo stub di
un dominio spento.

### Task 4: registrazione condizionale

Il task e' stato eseguito come descritto, poi corretto dopo la code review:
`registerDomainCommands()` copriva solo i comandi, ma `Nova::resourcesIn()` e'
ricorsivo e avrebbe registrato le risorse Nova di un dominio in ogni consumer, a
interruttore spento — cioe' la ragione principale del ticket, non prevenuta. Il
metodo si chiama ora `registerEnabledDomains()` e copre comandi, risorse Nova e
route, con un test che fallisce se qualcuno crea `src/Nova/<Dominio>/`.

### Task 5: documentazione

Il vincolo descritto nel piano ("due domini non possono avere stub che creano la
stessa tabella") e' piu' debole del vero: vale sui **nomi-base** degli stub, non
sulle tabelle, ed e' imposto a runtime da `guardAgainstDuplicateBaseNames()`. La
guida pubblicata riporta la formulazione corretta.

## Deviazioni dal piano

- **I test non si eseguono dalla directory del package.** Il piano diceva
  `cd wm-package && vendor/bin/pest`, ma nel container `wm-package` non ha un
  proprio `vendor`. Si esegue da forestas, che monta il submodule via symlink
  (`forestas/vendor/wm/wm-package -> ../../wm-package/`):

  ```bash
  docker exec -w /var/www/html/forestas php-forestas vendor/bin/pest wm-package/tests/...
  ```

  L'isolamento del database resta garantito: `forestas/phpunit.xml` punta a
  `forestas_testing`. Verificato a fine ciclo che il database reale fosse
  intatto (4 utenti, 791 tracce, 962 POI).

- **PHPStan non gira con la configurazione del package** in questo container:
  `phpstan.neon.dist` scrive la cache in `build/phpstan/cache`, su cui l'utente
  del container non ha permesso di scrittura. Aggirato con una configurazione
  temporanea in `/tmp` che punta ai soli file modificati. La verifica completa
  con baseline resta alla CI.

## Bug trovati

- **Nessuno introdotto.** Un errore PHPStan mio (`array_values()` ridondante su
  una lista, `InteractsWithWmPackageMigrationStubs:40`) e' stato trovato e
  corretto prima del commit. Dopo la correzione PHPStan torna all'unico errore
  preesistente sui file toccati.

## Decisioni

- **`publishStubToProject()` toglie il dominio dal nome del file.** E' il punto
  piu' delicato della modifica: senza `baseNameFromIdentifier()`, il file di
  destinazione conterrebbe una barra (`..._trail_registry/create_x.php`) e la
  `copy()` fallirebbe con un errore poco leggibile. Coperto dal test
  `test_published_optional_stub_is_not_reported_as_missing`, che verifica anche
  la forma del nome pubblicato.

- **Il punto di aggancio nasce senza domini da registrare, di proposito.**
  Nessun dominio ha ancora comandi o risorse proprie — il primo sara' il catasto
  con oc:8489. Esiste da subito perche' la regola "un dominio spento non
  registra nulla" va scritta una volta sola e rispettata da ogni dominio futuro.
  *(Nato come `registerDomainCommands()`, copriva solo i comandi: la code review
  ha mostrato che era la meta' meno importante del problema — vedi la sezione
  sulle correzioni piu' sotto.)*

- **Il conteggio degli stub non e' stabile, e nessun test lo asserisce.** Il
  ticket parlava di 66 stub, durante il ciclo sono diventati 67 per un
  aggiornamento del submodule fatto dal developer. Un test che avesse asserito
  il numero sarebbe diventato rosso per un motivo estraneo alla feature. Tutte
  le asserzioni confrontano insiemi (`array_diff` prima/dopo), mai cardinalita'.

## Correzioni dopo la code review formale

La review (5 finder paralleli) ha prodotto tre finding bloccanti, tutti
confermati contro il codice e corretti prima del commit.

- **Il presidio copriva solo i comandi.** `Nova::resourcesIn()`
  (`WmPackageServiceProvider:432`) scandisce `src/Nova` in modo **ricorsivo**:
  una risorsa Nova del catasto messa li' sarebbe stata registrata in tutti e 16
  i consumer, a interruttore spento — cioe' esattamente «la ragione principale»
  del ticket, non prevenuta. `registerDomainCommands()` e' diventato
  `registerEnabledDomains()` e copre ora comandi, risorse Nova
  (`features.<dominio>.nova_resources`) e route
  (`routes/domains/<dominio>.php`). Il vincolo «le risorse Nova di un dominio
  non stanno in `src/Nova`» e' presidiato da un test che fallisce se qualcuno
  crea quella cartella. Segnalato da tre finder su cinque, indipendentemente.

- **Due asserzioni indebolite senza dichiararlo.** Avevo tolto
  `assertSuccessful()` da un test e sostituito l'altro con un confronto fra
  exit code, perche' il `setUp()` della classe crea di proposito uno stub
  disallineato. Corretto rimuovendo quello stub nei test che devono osservare un
  gate pulito e aggiungendo `doesntExpectOutputToContain()`, che e' l'asserzione
  di merito. **Resta un limite dichiarato:** un gate globalmente verde non e'
  asseribile in quella suite, perche' il database di test del consumer ha
  disallineamenti propri estranei alla feature.

- **La fixture viaggiava nel package distribuito.** Lo stub di test viveva in
  `database/migrations/fixture_domain/`, cioe' nella cartella che il package
  spedisce a tutti; `publish-migration` avrebbe permesso di crearne la tabella
  in produzione. Ora e' creato a runtime dai test e rimosso in `tearDown`,
  seguendo il pattern gia' in uso nella stessa suite.

### Cleanup applicati

- Vocabolario allineato: i metodi del trait che ricevono identificatori
  qualificati non chiamano piu' il parametro `$baseName`.
- `stubBaseNames()` ignora le chiavi di dominio non valide: una chiave vuota
  produceva il pattern `.../migrations//*.stub`, che duplicava ogni stub della
  root.
- Nuovo `guardAgainstDuplicateBaseNames()`: due stub omonimi in domini diversi
  fanno fallire subito il comando invece di rendere il gate verde su uno stub
  mai pubblicato. Il vincolo reale e' sui **nomi-base**, non sulle tabelle come
  avevo scritto in un primo momento.
- `publish-migration` avvisa se il dominio non e' dichiarato o e' spento.
  Pubblicare resta consentito — e' un requisito — ma non piu' in silenzio.
- `FeaturesService` aggiunto a "Moduli toccati", da cui mancava pur essendo il
  file centrale della feature.

### Un test preesistente e' stato riscritto

`test_create_users_table_is_not_applied_to_database_on_maphub` asseriva lo stato
dello schema dell'ambiente, non il comportamento del codice: e' diventato rosso
nel momento in cui forestas ha pubblicato quello stub, che era il lavoro di
questo stesso ticket. Riscritto come
`test_unpublished_stub_is_reported_as_needing_publishing`, che usa la fixture di
dominio — non pubblicata da nessuna parte — e vale ovunque giri la suite.

## Follow-up

- **La verifica completa su maphub e' rimandata a oc:8489.** In questo ticket non
  esiste ancora uno stub opzionale reale: la prova che `--dry-run` resta verde su
  un consumer che non ha aderito va eseguita al merge del primo stub del catasto,
  e va scritta come requisito di accettazione di quel ticket.

- **Lo stub di fixture resta nel package.** `database/migrations/fixture_domain/`
  contiene uno stub usato solo dai test, in un dominio non dichiarato in
  configurazione: invisibile a chiunque non lo accenda esplicitamente via
  `config([...])`. Da valutare se spostarlo sotto `tests/` quando esistera' un
  dominio reale su cui appoggiare i test.

- **`wm-osmfeatures` come secondo dominio.** Oggi e' un submodule montato solo da
  osm2cai2. Quando rientrera' nel package, il suo `config/wm-osmfeatures.php`
  andra' riversato sotto `features.osmfeatures` e ogni `config('wm-osmfeatures.x')`
  diventera' `config('wm-package.features.osmfeatures.x')`. Attenzione al doppio
  montaggio: per un periodo osm2cai2 potrebbe avere sia il submodule sia il
  dominio, con lo stesso nome di tabella e nessuna guardia che lo rilevi.

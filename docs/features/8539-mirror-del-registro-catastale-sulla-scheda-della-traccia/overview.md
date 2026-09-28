> Ticket: oc:8539

# Dominio Catasto Sentieri estendibile dagli shard

> Parte generica di oc:8539. La customizzazione che la usa, cioè il mirror del registro catastale di
> Forestas, è descritta in `forestas/docs/features/8539-mirror-del-registro-catastale-sulla-scheda-della-traccia/overview.md`.
> Qui non entra nulla che conosca il registro, l'Excel o Forestas.

## Cosa cambia

L'intero dominio Catasto Sentieri diventa estendibile come il resto del package: Resource Nova,
modelli, service e tipi di anomalia.

1. **Resource Nova come `EcTrack`.** Il package **smette di registrare** `TrailRegistryCode`,
   `TrailApplication` e `TrailRegistryAnomaly` (`nova_resources` in `config/wm-package.php`,
   `WmPackageServiceProvider::registerEnabledDomains()`). Lo shard che accende il dominio ha le sue
   sottoclassi in `app/Nova` e Nova registra quelle, come per `EcTrack`. A dominio spento la classe
   base del package le nasconde (navigazione e autorizzazione), così lo shard non deve ricordarsene.
2. **Modelli sostituibili da config.** `TrailRegistryCode`, `TrailRegistryCodeEvent`,
   `TrailApplication` e `TrailRegistryAnomaly` si dichiarano in config; relazioni, `$model` delle
   Resource, service e comandi del dominio li risolvono da lì. Serve la config perché i modelli si
   richiamano fra loro e non hanno un posto dove «scoprire» la sottoclasse dello shard.
3. **Service risolti dal container**, così lo shard può sostituirli.
4. **Tipi di anomalia estendibili.**
   - `type` si legge come stringa e si risolve con un registro dei tipi: quelli del catasto
     (l'enum attuale) più quelli dichiarati dallo shard, ciascuno con etichetta e renderer del
     dettaglio.
   - Renderer del dettaglio, filtro per tipo, legenda della mappa e titolo passano dal registro; un
     tipo sconosciuto si mostra con un dettaglio generico invece di dare errore.
   - Ogni anomalia ha una **provenienza** (il catasto, oppure la sorgente dello shard).
   - `trail-registry-normalize` riscrive da zero **solo** le anomalie del catasto.
   - `ec_track_id` diventa nullable, per anomalie che riguardano una sorgente esterna e non una
     traccia.
5. **Interfaccia delle Anomalie sovrascrivibile:** testo in testa alla lista, titolo e
   presentazione di un'anomalia senza traccia diventano metodi che la sottoclasse dello shard può
   ridefinire. Il package tiene i suoi testi, scritti per il catasto.

## Perché

La regola del repo è che classi e Resource del package si estendono nello shard. Il Catasto è nato
come dominio opzionale (oc:8492), con il package che registrava da sé le proprie Resource perché
bastasse l'interruttore: così l'estensione non è mai stata possibile. Verificato con una
simulazione: una sottoclasse di `TrailRegistryCode` registrata dallo shard ha lo stesso `uriKey`, e
Nova continua a usare la classe del package senza segnalare nulla; il package inoltre si avvia
prima dei provider dello shard.

Il dev ha stabilito che **tutto** il dominio deve essere estendibile in questo ticket, e non in uno
successivo: la mancanza di estendibilità è un errore dei ticket precedenti.

Le anomalie sono la coda di lavoro di chi pulisce i dati: uno shard con una sorgente in più deve
poterci aggiungere i propri casi invece di aprire una seconda lista.

## Requisiti

- [ ] Il package non registra più le Resource del dominio; lo shard le registra come sottoclassi in
      `app/Nova`.
- [ ] A dominio spento le sottoclassi dello shard non compaiono in Nova (menu, ricerca globale,
      accesso diretto per URL).
- [ ] Modelli del dominio sostituibili da config; ogni relazione, query e service del dominio usa
      la classe configurata. Una classe dichiarata che non esiste o non estende quella del package
      solleva un'eccezione chiara all'avvio.
- [ ] Service del dominio risolti dal container.
- [ ] Registro dei tipi di anomalia: tipi del catasto più tipi dello shard, con etichetta e
      renderer; nessun `match` o confronto `===` sull'enum rimane fuori dal registro; un tipo
      sconosciuto non manda in errore index e detail.
- [ ] Colonna provenienza su `trail_registry_anomalies`: le righe esistenti diventano «catasto»; il
      default serve solo per queste righe e non resta sulla colonna.
- [ ] Il normalize cancella e riscrive solo le anomalie del catasto.
- [x] Superato dalla decisione di modificare direttamente lo stub `create` invece di aggiungere una
      migration: non c'è un `down()` che debba ripristinare `NOT NULL` e `CHECK`, vedi
      [notes.md](notes.md#task-4-provenienza-delle-anomalie-e-normalize-limitato-al-catasto).
- [ ] `ec_track_id` nullable; la verifica documentata «un sentiero con un'anomalia non ha un
      codice» vale solo per le anomalie del catasto, e la documentazione lo dice.
- [ ] Testo in testa, titolo e presentazione senza traccia della Resource Anomalie sovrascrivibili.
- [ ] Documentazione aggiornata: `docs/resources/TrailRegistry.md` (estendere il dominio,
      provenienza delle anomalie), `docs/resources/OptionalDomains.md` e
      `docs/knowledge/domini-opzionali.md` (il dominio non registra più le proprie Resource).
- [ ] **Procedura «Attivare il Catasto Sentieri su uno shard»** in
      `docs/howto/attivare-catasto-sentieri.md`: l'elenco completo e ordinato dei passaggi, oggi
      sparsi fra `TrailRegistry.md`, `OptionalDomains.md` e la conoscenza di forestas. Contiene
      almeno:
  - interruttore `WM_TRAIL_REGISTRY_ENABLED` e tutti i posti dove va allineato;
  - pubblicazione delle migration del dominio e gate in CI;
  - configurazione che varia per shard (`legacy_code_property`, `source_url_property`,
    `source_label`, `sector_source`, `name_code_pattern`, …);
  - sottoclassi Nova obbligatorie in `app/Nova`, sezione di menu «Catasto»;
  - modelli, service e tipi di anomalia sostituibili o aggiungibili, e come;
  - normalize e suo aggancio all'import dello shard;
  - verifiche finali.

  Ogni passaggio riporta il riferimento concreto di forestas come esempio. La procedura si
  verifica ripercorrendola su forestas: ogni passaggio deve avere il suo riscontro nel repo. Va
  linkata dalla sezione «Procedure» del `CLAUDE.md` del package e da `TrailRegistry.md`.
- [ ] Test: Resource dello shard usata da Nova, dominio spento che nasconde le sottoclassi, modello
      sostituito usato dalle relazioni, anomalia di un tipo dello shard che apre index e detail,
      normalize che non tocca le anomalie di altra provenienza.

## Rischi

- **Migration su una tabella già in produzione** (`trail_registry_anomalies`): colonna provenienza,
  `ec_track_id` nullable, `CHECK` sul tipo tolto. Per richiesta del dev (28/09/2026) nessuna
  migration aggiuntiva: le modifiche sono entrate direttamente nello stub `create` — su un DB dove
  la tabella esiste già (es. UAT) lo schema va aggiornato a mano dal team, lo stub `create` esce
  subito grazie a `hasTable`. Vedi
  [notes.md](notes.md#task-4-provenienza-delle-anomalie-e-normalize-limitato-al-catasto).

Vincolo di rilascio: il package si mergia prima; forestas, l'unico shard con il dominio acceso,
bumpa il submodule e aggiunge le sottoclassi Nova del Catasto nello stesso commit.

## Out of scope

- Tutta la logica del registro catastale di Forestas.
- Rendere estendibili i domini diversi dal Catasto.
- Cambiare il calcolo delle anomalie del catasto.

## Moduli toccati

Repo `wm-package`:

- `config/wm-package.php` — tolte le `nova_resources` del dominio, aggiunte le classi dei modelli
  sostituibili.
- `src/WmPackageServiceProvider.php` — registrazione del dominio, menu del Catasto, verifica delle
  classi configurate.
- `src/TrailRegistry/Models/*` — relazioni tramite le classi configurate, cast del tipo.
- `src/TrailRegistry/TrailRegistryService.php`, `src/TrailRegistry/Commands/TrailRegistryNormalizeCommand.php`,
  `src/TrailRegistry/Jobs/*` — classi configurate, normalize limitato al catasto.
- `src/TrailRegistry/Nova/*` — `$model` configurati, occultamento a dominio spento, registro dei
  tipi in renderer, filtro, legenda e titolo, metodi sovrascrivibili.
- `src/TrailRegistry/Enums/TrailRegistryAnomalyType.php` e nuovo registro dei tipi.
- Nuovo stub di migration su `trail_registry_anomalies`.
- `docs/resources/TrailRegistry.md`, `docs/resources/OptionalDomains.md`,
  `docs/knowledge/domini-opzionali.md`.
- Nuovo `docs/howto/attivare-catasto-sentieri.md`; riga in «Procedure» del `CLAUDE.md`.
- Test del dominio.

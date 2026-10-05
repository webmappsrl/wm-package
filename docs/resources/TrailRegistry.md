# TrailRegistry — Catasto Sentieri (dominio opzionale)

> Ultimo aggiornamento: 2026-09-10 · ticket oc:8489

## In breve

Il dominio `trail_registry` assegna e conserva il **codice REI** dei sentieri: la sigla che
identifica un sentiero in modo univoco su tutto il territorio regionale, e che finisce sulla
segnaletica in campo.

È un **dominio opzionale** (oc:8492): spento di default, si accende con
`WM_TRAIL_REGISTRY_ENABLED=true`. A dominio spento non esistono né i comandi, né le Resource Nova,
né la voce di menu; le tabelle restano dove sono, se erano state create. La guida generale al
meccanismo è in [`OptionalDomains.md`](OptionalDomains.md).

## Il codice

`ZNUB535A` si legge così:

| Parte | Esempio | Da dove viene |
|---|---|---|
| Regione | `Z` | prefisso del settore |
| Provincia | `NU` | prefisso del settore |
| Area | `B` | prefisso del settore |
| Settore | `5` | prefisso del settore |
| Numero | `35` | due cifre, `00`–`99` |
| Variante | `A` | una lettera, oppure `0` = «nessuna» |

I primi quattro caratteri più la cifra del settore formano il `full_code` della `TaxonomyWhere` di
tipo settore, che si ricava **dalla geometria** del sentiero, non da ciò che è scritto nel codice.

`variant` non è mai `NULL`: «nessuna variante» si scrive `0` e **si omette in uscita**. Il valore
in colonna resta `0`; è l'accessor `code` a comporre la stringa che l'utente legge.

## Le tre tabelle

| Tabella | Cosa contiene |
|---|---|
| `trail_registry_codes` | il registro: un codice per riga, con il sentiero o l'istanza che lo porta |
| `trail_registry_code_events` | ogni cambio di stato, con data e causa |
| `trail_registry_anomalies` | la lista di lavoro: i sentieri rimasti senza numero, e perché |

Più `trail_applications`, le istanze di accatastamento.

### Cosa entra nel registro

**Solo codici su cui non pende alcun dubbio.** Un sentiero che ha un'anomalia non ha una riga nel
registro: il registro è l'elenco di ciò che è deciso, le anomalie sono ciò che resta da decidere.
La verifica è una join, e deve tornare zero:

```sql
select count(*) from trail_registry_codes c
join trail_registry_anomalies a on a.ec_track_id = c.ec_track_id;
```

### Stati

`reserved` · `assigned` · `released`. Tre, non quattro: **non esiste uno stato «in conflitto»** —
un sentiero la cui posizione è già di un altro non entra affatto (vedi «Anomalie»).

`TrailCodeStatus::active()` restituisce `[Reserved, Assigned]` e **deve coincidere** con la
clausola `WHERE` dell'indice unico parziale:

```sql
CREATE UNIQUE INDEX trail_registry_codes_active_unique
ON trail_registry_codes (region, province, area, sector, number, variant)
WHERE status IN ('reserved', 'assigned')
```

L'indice è parziale perché una riga `released` deve restare in tabella — è la storia di quel
numero — senza impedire che il numero venga riassegnato.

### Provenienza (`origin`)

| Valore | Significato |
|---|---|
| `campo_dedicato` | letto dalla proprietà del codice (`legacy_code_property`) |
| `nome` | estratto dalle parentesi finali del nome del sentiero |
| `assegnato` | proposto dalla piattaforma, primo libero nel settore |

Un codice scritto nel nome invece che nel campo dedicato **non è un'anomalia**: si legge, si
registra, e la provenienza dice da dove è arrivato. Ciò che si può risolvere si risolve.

## Il service

`Wm\WmPackage\TrailRegistry\TrailRegistryService` è l'unico posto in cui si decide quale numero
porta un sentiero. Non duplicarne la logica nei consumer.

| Metodo | Cosa fa |
|---|---|
| `resolveSector($wkt)` | il settore che contiene il sentiero, o quello con cui condivide il tratto più lungo |
| `propose($wkt)` | il codice libero più vicino, nel settore |
| `availableNumbers($fullCode)` | i numeri **puri** ancora liberi: è la domanda che serve a `propose()` |
| `availableVariants($fullCode, $number)` | le varianti libere di un numero, `'0'` compreso quando il numero puro è libero |
| `numbersWithAvailableVariants($fullCode)` | i numeri con almeno una variante libera: l'elenco da cui si sceglie nella sostituzione manuale |
| `reserve()` / `confirm()` / `release()` / `replaceNumber()` | il ciclo di vita di un'istanza |
| `registerExistingCode()` | registra un codice storico già esistente |

I tre metodi di lettura rispondono a domande diverse e non sono intercambiabili:
`availableNumbers()` esclude un numero appena il numero puro è occupato,
`numbersWithAvailableVariants()` lo tiene finché gli avanza una lettera. È ciò che rende
raggiungibile la variante di un sentiero esistente (oc:8569).

### Il criterio di vicinanza (oc:8570)

`propose()` non prende più il primo numero libero del settore: i numeri già usati si raggruppano
in **cluster per contiguità numerica** — 11, 12, 13 sono un cluster, 16, 17 un altro — e a decidere
l'ordine è **solo il cluster più vicino alla traccia in esame**. Gli altri cluster del settore non
competono: chi vuole aprire una numerazione lontano dalla propria traccia non passa dalla proposta
automatica, usa l'Action Nova `ReplaceTrailCodeNumber`. I numeri liberi si ordinano per distanza
numerica dai numeri di quel cluster, nelle due direzioni; a parità di distanza vince il numero
precedente, così l'esito resta deterministico anche sugli incroci.

Lo stesso criterio governa il campo `Select` dell'Action `ReplaceTrailCodeNumber`, tramite
`numbersWithAvailableVariants($fullCode, $geometryWkt, $excludeCodeId)`: il terzo parametro esclude
dal calcolo delle distanze il codice che si sta sostituendo, altrimenti quel codice distarebbe zero
da sé stesso, farebbe cluster da solo e coprirebbe i vicini veri.

Un settore senza codici non ha nulla da cui misurare la vicinanza: l'ordine resta quello numerico,
come prima di oc:8570 — è anche il comportamento di `numbersWithAvailableVariants()` quando viene
chiamato senza geometria, per i consumer che non hanno una traccia da cui misurare. Il fallback alle
varianti con lettera, quando il numero puro è saturo, segue lo stesso criterio per vicinanza: non è
un ordine a parte, `orderByProximity()` si applica anche ai numeri liberi di ciascuna variante A-Z.

### `registerExistingCode()` — gli esiti

| Esito | Scrive? | Quando |
|---|---|---|
| `assigned` | sì | codice leggibile, settore coerente, posizione libera |
| `alreadyAssigned` | **no** | quel codice ce l'ha già un altro; l'esito porta `holder`, la sua riga |
| `sectorMismatch` | **no** | il settore scritto nel codice non è quello della geometria |
| `unparsableRef` | no | dal codice non si ricava una coda valida |
| `noSector` | no | la geometria non ricade in alcun settore |
| `alreadyRegistered` | no | quel sentiero ha già una riga attiva |

Regole da non rompere in nessun chiamante:

- **una transazione per singolo sentiero**, non una per l'intera esecuzione: su centinaia di
  record un errore a metà non deve annullare il lavoro già buono;
- **a decidere chi tiene il codice è il database**, che intercetta la violazione di unicità
  (SQLSTATE 23505) dell'indice parziale — non un controllo applicativo, che lascerebbe aperta la
  finestra fra il guardare e lo scrivere;
- **la guardia di idempotenza sull'`ec_track_id`**, senza la quale una seconda esecuzione
  produrrebbe doppioni indistinguibili da quelli veri.

Un sentiero il cui codice è già di un altro non lascia riga, quindi a ogni esecuzione
**ritenta**: è voluto. Se nel frattempo la fonte è stata corretta, il numero gli spetta senza
alcun intervento.

## Anomalie

Ci finiscono **solo i sentieri rimasti senza numero che dovrebbero averlo**. Non è un registro di
imperfezioni: è la coda di lavoro di ciò che manca.

| Tipo | Perché resta senza numero |
|---|---|
| `codice_gia_assegnato` | quel codice ce l'ha già un altro sentiero |
| `settore_discordante` | il codice contraddice la geometria |
| `geometria_duplicata` | due o più sentieri con la stessa traccia: nessuno prende un numero |
| `codice_illeggibile` | dal codice non si ricava un numero valido |
| `fuori_da_ogni_settore` | senza settore non c'è prefisso |

La tabella conserva i **dati** (`context`, jsonb), non la frase: la descrizione si compone in
lettura, con `AnomalyDetailRenderer` e un modello di frase per tipo. Correggere una parola è
modificare quella classe, e ogni riga già scritta si aggiorna al primo caricamento della pagina.

Ogni riga porta il collegamento alla **scheda sulla piattaforma di origine**
(`source_url_property`): le correzioni si fanno lì, e l'importazione successiva le riporta
indietro da sé. Per questo la lista si **riscrive da zero** a ogni esecuzione — è ciò che fa
sparire una riga quando la scheda è stata sistemata.

### Provenienza (`source`)

Ogni anomalia ha una colonna `source`, **non nullable e senza default**: `TrailRegistryAnomaly::SOURCE_CATASTO`
per quelle scritte dal catasto, un valore proprio per quelle di uno shard che aggiunge una sorgente
diversa (vedi «Estendere il dominio»). Lo scope `fromSource()` filtra per provenienza.

`trail-registry-normalize` **cancella e riscrive solo** le anomalie con `source = 'catasto'`: le
anomalie di un'altra provenienza restano intatte a ogni esecuzione.

La verifica «un sentiero con un'anomalia non ha un codice» (la join sopra) **vale solo per le
anomalie di provenienza `catasto`**: un'anomalia di un'altra fonte può riguardare un oggetto che
non è nemmeno un sentiero — è per questo che `ec_track_id` è nullable.

`type` non è più un enum chiuso: si legge come stringa e si risolve con il registro dei tipi
(`TrailRegistryAnomalyTypes`), che unisce i tipi fissi del catasto (`TrailRegistryAnomalyType`) e
quelli dichiarati da uno shard in `anomaly_types`. Un tipo sconosciuto al registro si mostra con un
dettaglio generico invece di dare errore in index e detail.

## Il comando

```bash
php artisan wm-package:trail-registry-normalize [--dry-run] [--force]
```

Legge i codici storici dei sentieri e li carica nel registro. Senza opzioni chiede conferma;
`--dry-run` produce solo il rapporto; `--force` scrive senza chiedere.

Le geometrie duplicate si cercano **prima** del ciclo, non a fine corsa: serve saperlo in anticipo
per escludere quei sentieri. Il numero suggerito per i codici illeggibili si calcola **dopo**, a
registro completo, altrimenti proporrebbe numeri che verrebbero occupati poco dopo.

## Configurazione

Tutto sotto `config('wm-package.features.trail_registry')`:

| Chiave | Default | A cosa serve |
|---|---|---|
| `enabled` | `false` | l'interruttore del dominio |
| `commands` | — | i comandi artisan del dominio, registrati a dominio acceso |
| `models.*` | le classi del package | modelli sostituibili da uno shard: `code`, `event`, `application`, `anomaly` — vedi «Estendere il dominio» |
| `anomaly_types` | `[]` | tipi di anomalia dichiarati da uno shard, accanto a quelli fissi del catasto — vedi «Estendere il dominio» |
| `legacy_code_property` | `ref` | dove vive il codice storico nelle proprietà del tracciato |
| `source_url_property` | **vuota** | dove vive l'indirizzo della scheda sulla piattaforma di origine |
| `source_label` | **vuota** | come si chiama quella piattaforma («Apri su Drupal») |
| `sector_source` | `osm2cai` | da quale sorgente arrivano i settori, in `taxonomy_wheres.properties->source` |
| `nova_uri_keys.*` | `ec-tracks`, `taxonomy-wheres`, `trail-applications` | le chiavi con cui Nova indirizza le Resource collegate |
| `name_code_pattern` | parentesi finali | come si riconosce un codice scritto nel nome; vuota = non si cerca |
| `code_format` | 2 cifre, varianti a lettera | forma del codice |
| `trail_type_identifier` | `null` | la tassonomia che marca un tracciato come sentiero |

### Cosa cambia da uno shard all'altro

Il dominio non presume di girare su forestas. Le cose che variano sono tutte in configurazione,
e un consumer che non le imposta ottiene il comportamento prudente — nessun collegamento inventato,
nessun codice indovinato:

- **dove sta il codice storico** (`legacy_code_property`): su forestas `ref`;
- **se e dove esiste una scheda di origine** (`source_url_property`, `source_label`): su forestas
  `forestas.url` e `Drupal`. Vuote di default: il package non può dare per scontato né il nome
  dello shard né che una piattaforma esterna esista;
- **da dove arrivano i settori** (`sector_source`): il default `osm2cai` vale per i catasti che
  importano i settori CAI; uno shard che li carica altrove deve cambiarlo, altrimenti nessun
  settore viene trovato e ogni sentiero risulta «fuori da ogni settore»;
- **come si riconosce un codice scritto nel nome** (`name_code_pattern`): le parentesi finali sono
  la convenzione di Sardegna Sentieri, non una legge;
- **le chiavi delle Resource Nova** (`nova_uri_keys`): servono solo a chi sovrascrive `uriKey()`
  di `EcTrack` o `TaxonomyWhere`; `trail_application` deve coincidere con l'uriKey fisso della
  Resource delle istanze (`trail-applications`);
- **la lingua del nome del sentiero**: presa da `app.locale` e `app.fallback_locale`, non da un
  elenco fisso.

`TrailRegistryShardNeutralityTest` presidia tutto questo: ogni presunzione che rientri di nascosto
fa fallire uno di quei test.

Le Resource base del dominio vivono in `src/TrailRegistry/Nova` e **il package non le registra
più**: è lo shard a registrare le proprie sottoclassi in `app/Nova`, come per `EcTrack` — vedi
«Estendere il dominio». Un test (`TrailRegistryDomainRegistrationTest`) fallisce se le Resource base
finiscono in `src/Nova`, scandita integralmente da `Nova::resourcesIn()`.

## Migration

Gli stub stanno in `database/migrations/trail_registry/` e **`vendor:publish` non li pubblica** —
la scoperta delle migration di Spatie non è ricorsiva, ed è ciò che protegge chi non ha aderito.
Servono uno per uno:

```bash
php artisan wm-package:publish-migration trail_registry/<nome-stub>
php artisan migrate
```

Il gate di CI va invocato con `--with=trail_registry`.

## Interfaccia Nova

Le tre Resource stanno nella sezione di menu **Catasto**. Chi le vede in Nova non è il package: è
lo shard, con le proprie sottoclassi in `app/Nova` — vedi «Estendere il dominio». A dominio spento
`HidesWhenTrailRegistryDisabled` le nasconde (navigazione e autorizzazione: vista, creazione,
modifica, cancellazione, Action) senza che lo shard debba ricordarsene.

- **Registro dei codici** — sola lettura: un codice non si crea, non si modifica e non si
  sostituisce da qui.
  Ricerca **per codice**, che Nova non saprebbe fare da sé (il codice non è una colonna, si compone
  da sei) — vedi `applySearch()`. Nella scheda: mappa con settore, sentiero ed eventuale istanza,
  legenda e storia dei cambi di stato.
  Sulla mappa ci sono anche **gli altri sentieri dello stesso settore**: servono a giudicare un
  numero, perché la numerazione segue una logica di zona e un numero si sceglie guardando i
  vicini, non il primo libero. Entrano i codici che occupano una posizione — `Reserved` e
  `Assigned`, gli stessi della select del «sostituisci numero» — con la geometria del sentiero o,
  se il codice è solo riservato, quella dell'istanza (oc:8568).
  I numeri sono **segnavia CAI** (banda, fascia bianca con il numero, banda), orizzontali e posati
  a metà tracciato, con l'ultima cifra del settore davanti (`211`, `210A` nel settore ZNUG2): bande rosse per un sentiero validato (`Assigned`), bordo rosso e bande vuote
  per un numero proposto da un'altra istanza (`Reserved`). **Il numero del codice in esame** ha
  bande arancioni, il colore della traccia dell'istanza, e sta sopra tutti gli altri; se il codice
  è stato liberato è grigio e barrato. I numeri dei vicini compaiono da un certo zoom in poi
  (`labelMinZoom` sul campo, 12 di default), quello del codice in esame sempre (oc:8662).
  Sotto la mappa c'è il **profilo altimetrico**: del sentiero se c'è, altrimenti della traccia
  dell'istanza. La linea del profilo la indica il GeoJSON (`slopeChart` sulla feature del codice
  in esame), perché su una mappa con molte linee il componente condiviso non saprebbe sceglierla
  (oc:8662).
- **Istanze** — il ciclo di accatastamento, con le azioni che ne fanno avanzare lo stato. Da qui
  si sostituisce anche il numero prenotato, scegliendo in due tendine — il numero, poi la
  variante, con «nessuna variante» fra le opzioni della seconda: il gesto avviene mentre si
  guarda la mappa dell'istanza, che è il contesto su cui si decide (oc:8569).
  Nel dettaglio ci sono la mappa del codice dell'istanza (quello attivo, o l'ultimo se l'istanza è
  rifiutata) con la sua legenda — qui numero e profilo stanno sempre sulla traccia proposta, anche
  dopo l'approvazione, perché è quella che il gestore valuta (oc:8662) — il link al «File GPX/GeoJSON caricato» e il tab DEM. Si modifica
  solo in istruttoria, e solo nei nove valori manuali del tab; l'index mostra le sei colonne di
  sempre. Come nasce e si calcola il DEM dell'istanza è in
  [docs/knowledge/dati-dem-e-valori-manuali.md](../knowledge/dati-dem-e-valori-manuali.md) (oc:8571).
- **Anomalie** — sola lettura, con in testa una card che spiega la schermata
  (`TrailRegistryNoticeCard`).

### Chi vede il Catasto (oc:8700)

Il Catasto lo vedono solo gli utenti con ruolo `Administrator` o `Editor`. La regola sta in un
punto solo, `TrailRegistryPolicy::allows()` (`src/TrailRegistry/Policies/`, costante `ROLES`), e la
usano tutte le altre parti:

- tre Policy, una per modello: `TrailApplicationPolicy`, `TrailRegistryCodePolicy`,
  `TrailRegistryAnomalyPolicy`. Sono tre classi, non una, perché il Gate di Laravel toglie il nome
  della classe prima di chiamare `create()`. Estendono `TrailRegistryPolicy`: le due del registro
  dei codici e delle anomalie sono di sola lettura (`create` e `update` negati), quella delle
  istanze concede `create` a chi `allows()` e `update` a chi `allows()` quando l'istanza è
  `UnderReview`;
- `WmPackageServiceProvider` le registra **sempre**, anche a dominio spento, sia per il modello
  base sia per quello configurato dallo shard (`TrailRegistryClasses`). A dominio spento le
  Resource restano nascoste da `HidesWhenTrailRegistryDisabled`, che mette il dominio in AND con la
  policy;
- le tre Action di `TrailApplication` (approva, rifiuta, sostituisci numero) hanno `canSee` e
  `canRun` che richiamano `TrailRegistryPolicy::allows()`, e `authorizedToUpdate()` della Resource
  lo include;
- la sezione di menu «Catasto» si mostra solo se almeno una delle sue voci è visibile
  (`visibleWhenAnyItemIs()`), sia quando la riempie `injectMenuSectionItems()` nel menu dello
  shard sia nel ramo di `Nova::mainMenu` senza menu dello shard.

Uno shard che ha le proprie Resource del Catasto, sottoclassi di quelle del package, eredita la
regola. Se ne scrive di nuove o ridefinisce un `authorizedTo*()`, richiama
`TrailRegistryPolicy::allows($request->user())`.

Il componente Vue della card è registrato con una **render function in JS puro**
(`resources/js/domains/trail_registry.js`), non con un bundle compilato: il Vue che Nova carica è
la build runtime-only, che non compilerebbe un template scritto come stringa, mentre la globale
`Vue` è garantita. Lo script si carica solo a dominio acceso, con la stessa convenzione delle
route: `resources/js/domains/<dominio>.js`.

## Estendere il dominio

Il Catasto è estendibile come il resto del package: Resource Nova, modelli, service e tipi di
anomalia. La procedura completa, con i riferimenti di Forestas, è in
[docs/howto/attivare-catasto-sentieri.md](../howto/attivare-catasto-sentieri.md). In breve:

- **Resource Nova come `EcTrack`.** Il package non registra `TrailRegistryCode`,
  `TrailApplication` né `TrailRegistryAnomaly`: lo shard che accende il dominio crea le proprie
  sottoclassi in `app/Nova` (anche vuote) e le registra come le altre Resource. A dominio spento
  `HidesWhenTrailRegistryDisabled` le nasconde senza che lo shard debba fare nulla. `uriKey()` è
  fisso su ciascuna Resource base (`trail-registry-codes`, `trail-applications`,
  `trail-registry-anomalies`): la sottoclasse la eredita, e non va cambiata — altre parti del
  dominio (`ComposesTrailRegistryMap.php`, `nova_uri_keys`) la danno per scontata.
- **Modelli sostituibili da config.** `config('wm-package.features.trail_registry.models.*')`
  (`code`, `event`, `application`, `anomaly`): relazioni, `newModel()` delle Resource, service e
  comandi risolvono la classe da lì tramite `TrailRegistryClasses`. La sostituzione va in
  `AppServiceProvider::register()`, e `$model` della sottoclasse Nova va allineato alla config
  (se la sottoclasse lo cambia, `newModel()` segue lei); menu e `BelongsTo` fra Resource del
  dominio le cercano per uriKey, quindi reggono anche un disallineamento. Una classe dichiarata che non
  esiste o non estende quella del package fa fallire l'avvio (`TrailRegistryClasses::assertValid()`,
  chiamata da `WmPackageServiceProvider::packageBooted()`).
- **Service risolti dal container**, sempre con `app(TrailRegistryService::class)`: uno shard li
  sostituisce con un binding.
- **Tipi di anomalia**: il registro (`TrailRegistryAnomalyTypes`) unisce i tipi fissi del catasto
  e quelli dichiarati in `anomaly_types` (chiave => classe che implementa
  `AnomalyTypeDefinition`, con `label()` e `detailRows()`). Ogni tipo aggiunto da uno shard deve
  avere una provenienza propria, diversa da `TrailRegistryAnomaly::SOURCE_CATASTO`: il normalize
  tocca solo le anomalie di provenienza `catasto`.
- **Interfaccia delle Anomalie sovrascrivibile**: `noticeBody()`, `titleFor()` e `subjectField()`
  sono metodi che la sottoclasse dello shard può ridefinire; il package tiene i propri, scritti per
  il catasto.

## Trappole

- **Il cast a `::geometry` nel filtro spaziale disattiva l'indice GiST.** In `resolveSector()` il
  `ST_Intersects` lavora su `geography` senza cast (Index Scan); il cast compare solo dentro
  `ST_Length(ST_Intersection(...))`, cioè nell'ordinamento, dove le righe sono già poche. Misurato
  sui dati reali: 652 ms contro oltre 2 minuti.
- **L'ordine dei cicli in `propose()` non è invertibile.** Il ciclo esterno è la variante, quello
  interno il numero: invertirli tratterebbe la variante come diramazione del numero, proponendo
  `ZNUB500A` invece di `ZNUB501`.
- **Le colonne obbligatorie sono dichiarate nullable nei modelli.** Nova costruisce i campi anche
  su un'istanza vuota, per ricavare le colonne dell'elenco: dichiararle non nullable nasconde un
  caso che in produzione fa rispondere 500 alla pagina.
- **Il titolo di una Resource non può essere una colonna enum.** `public static $title = 'type'`
  fa convertire l'enum in stringa (`Resource.php:416`) e la pagina non si apre: serve un metodo
  `title()`.
- **La factory crea le istanze con `properties = []`, un array jsonb.** Su un array `||` concatena
  invece di unire: `'[]'::jsonb || '{"dem_data": …}'` dà `[{"dem_data": …}]`, e la chiave non
  esiste. Nel SQL che scrive in `properties` si parte da
  `CASE WHEN jsonb_typeof(properties) = 'object' THEN properties ELSE '{}'::jsonb END` (oc:8571).
- **Una restrizione messa solo negli `authorizedTo*()` di una Resource senza policy non protegge.**
  Nasconde i bottoni, ma detail, modifica e download restano aperti a chi conosce l'URL: serve una
  policy registrata (oc:8700).
- **Un'Action con `canRun()` salta l'autorizzazione della Resource e della policy.** Nova, in
  `filterForExecution()`, usa solo il `canRun()`: la regola dei ruoli va ripetuta lì dentro, o un
  POST all'Action passa anche per chi non vede il bottone (oc:8700).
- **Chi ridefinisce un `authorizedTo*()` in una Resource del Catasto deve rifare due controlli.**
  Il metodo ridefinito vince su quello del trait: vanno ripetuti sia il controllo del dominio
  (`trailRegistryEnabled()`) sia `TrailRegistryPolicy::allows()` (oc:8700).
- **Un `canSee` messo dallo shard sulla sezione «Catasto» resta, ma non basta a mostrarla.**
  `injectMenuSectionItems()` ricostruisce la sezione e riporta il `canSee` dello shard, in AND con
  la regola «almeno una voce visibile»: se la policy nega tutte le voci, la sezione sparisce anche
  con un `canSee` che dice sì (oc:8700).

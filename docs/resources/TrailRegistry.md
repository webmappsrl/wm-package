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
| `propose($wkt)` | il primo codice libero in quel settore |
| `availableNumbers($fullCode)` | i numeri liberi, per la sostituzione manuale |
| `reserve()` / `confirm()` / `release()` / `replaceNumber()` | il ciclo di vita di un'istanza |
| `registerExistingCode()` | registra un codice storico già esistente |

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
| `commands`, `nova_resources` | — | cosa registrare a dominio acceso |
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
- **le chiavi delle Resource Nova** (`nova_uri_keys`): servono solo a chi sovrascrive `uriKey()`;
- **la lingua del nome del sentiero**: presa da `app.locale` e `app.fallback_locale`, non da un
  elenco fisso.

`TrailRegistryShardNeutralityTest` presidia tutto questo: ogni presunzione che rientri di nascosto
fa fallire uno di quei test.

Le Resource Nova del dominio **non possono stare in `src/Nova`**, scandita integralmente da
`Nova::resourcesIn()`: vivono in `src/TrailRegistry/Nova` e sono dichiarate in `nova_resources`.
Un test (`TrailRegistryDomainRegistrationTest`) fallisce se qualcuno le sposta.

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

Le tre Resource stanno nella sezione di menu **Catasto**.

- **Registro dei codici** — sola lettura: un codice non si crea e non si modifica da un form,
  cambiarne il numero significherebbe cambiare un numero già comunicato e forse già stampato.
  Ricerca **per codice**, che Nova non saprebbe fare da sé (il codice non è una colonna, si compone
  da sei) — vedi `applySearch()`. Nella scheda: mappa con settore, sentiero ed eventuale istanza,
  legenda e storia dei cambi di stato.
- **Istanze** — il ciclo di accatastamento, con le azioni che ne fanno avanzare lo stato.
- **Anomalie** — sola lettura, con in testa una card che spiega la schermata
  (`TrailRegistryNoticeCard`).

Il componente Vue della card è registrato con una **render function in JS puro**
(`resources/js/domains/trail_registry.js`), non con un bundle compilato: il Vue che Nova carica è
la build runtime-only, che non compilerebbe un template scritto come stringa, mentre la globale
`Vue` è garantita. Lo script si carica solo a dominio acceso, con la stessa convenzione delle
route: `resources/js/domains/<dominio>.js`.

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

# Domini opzionali

Feature del package che non tutti i consumer usano, accese da un interruttore di configurazione.
Guida operativa: [docs/resources/OptionalDomains.md](../resources/OptionalDomains.md).

## Stato attuale

Un **dominio** è un insieme di stub di migration, comandi, route e risorse Nova che il consumer
riceve solo se lo attiva. L'interruttore è
`config('wm-package.features.<dominio>.enabled')`, **spento di default**; le impostazioni del
dominio stanno nella **stessa sezione**, mai in un file di configurazione proprio — altrimenti
`configurePackage()` dovrebbe leggere la configurazione per decidere quale configurazione
registrare.

**Le risorse Nova di un dominio non possono stare in `src/Nova`**: `Nova::resourcesIn()` scandisce
quella cartella **ricorsivamente** e registra tutto, a interruttore spento incluso. Vanno
dichiarate in `features.<dominio>.nova_resources`; i comandi in `features.<dominio>.commands`; le
route in `routes/domains/<dominio>.php`. Registra tutto
`WmPackageServiceProvider::registerEnabledDomains()`, e `OptionalDomainRegistrationTest` fallisce
se qualcuno crea `src/Nova/<Dominio>/`.

**Nessuno stub di dominio può avere lo stesso nome-base di uno stub della root o di un altro
dominio.** L'identificatore qualificato risolve l'ambiguità solo dentro il package: il lato
pubblicato cerca per suffisso del nome file, che il dominio non lo contiene, e due omonimi
renderebbero il gate verde su uno stub mai pubblicato. `stubBaseNames()` lo rileva e fallisce.

**`vendor:publish` non pubblica gli stub dei domini**: la scoperta delle migration di
`spatie/laravel-package-tools` usa `Filesystem::files()`, non ricorsivo. È ciò che protegge chi non
ha aderito, ed è il motivo per cui chi ha aderito usa `publish-migration <dominio>/<stub>`.

**`--with=<dominio>` può solo aggiungere** domini alla verifica del gate, mai toglierne, ed è
validato contro le chiavi dichiarate in configurazione — non contro l'esistenza della cartella:
così un dominio dichiarato ma ancora privo di stub è legittimo.

**Spegnere un dominio non rimuove la tabella.** L'ordine corretto è: bonifica dei dati con i
comandi del dominio, poi spegnimento, poi rimozione dello schema. Spegnere per primo toglie i
comandi, cioè lo strumento per i due passi successivi (oc:8492).

## Catasto Sentieri (codice REI)

Fonte di verità: [docs/resources/TrailRegistry.md](../resources/TrailRegistry.md).

- **Nel registro entrano solo codici senza dubbi.** Un sentiero con un'anomalia non ha una riga:
  `trail_registry` è l'elenco di ciò che è deciso, `trail_registry_anomalies` ciò che resta da
  decidere. La verifica è una join fra le due su `ec_track_id` e deve tornare zero.
- **Non esiste uno stato «in conflitto».** `TrailCodeStatus` ha tre casi
  (`reserved`/`assigned`/`released`). Una posizione già occupata non produce una riga di scarto:
  `registerExistingCode()` torna l'esito `conflict` portando `holder`, e chi chiama ne fa
  un'anomalia. Conseguenza voluta: quel sentiero **ritenta** a ogni esecuzione, e se il conflitto
  si è sciolto il numero gli spetta senza intervento.
- **`TrailCodeStatus::active()`, la clausola WHERE dell'indice unico parziale e il CHECK sullo
  stato si cambiano insieme**: sono la stessa regola scritta in tre posti.
- **Il cast a `::geometry` disattiva l'indice GiST.** In `resolveSector()` il filtro
  `ST_Intersects` lavora su `geography` senza cast (Index Scan); il cast sta solo dentro
  `ST_Length(ST_Intersection(...))`, cioè nell'ordinamento, dove le righe sono già poche. Misurato
  sui dati reali: 652 ms contro oltre 2 minuti.
- **I cicli di `propose()` non sono invertibili**: esterno la variante, interno il numero.
  Invertirli proporrebbe `ZNUB500A` invece di `ZNUB501`, trattando la variante come diramazione
  del numero.
- **Le anomalie conservano i dati, non la frase.** La colonna è `context` (jsonb); la descrizione
  si compone in lettura con `AnomalyDetailRenderer`, un modello per tipo — così correggere una
  parola non richiede di rigenerare le righe. La lista si riscrive da zero a ogni esecuzione: è ciò
  che fa sparire una riga quando la scheda è stata sistemata alla fonte.
- **Un codice scritto nel nome non è un'anomalia**: si legge, si registra, e la colonna `origin`
  dice da dove viene (`campo_dedicato` / `nome` / `assegnato`).
- **Ciò che varia fra shard sta in configurazione, con default prudenti**: dove sta il codice
  storico (`legacy_code_property`), se esiste una scheda di origine e come si chiama la
  piattaforma (`source_url_property`, `source_label` — **vuote** di default, il package non presume
  né il nome dello shard né l'esistenza di una fonte esterna), da quale sorgente arrivano i settori
  (`sector_source`), come si riconosce un codice nel nome (`name_code_pattern`), le chiavi Nova
  delle Resource collegate (`nova_uri_keys`). La lingua del nome viene da `app.locale`, non da un
  elenco fisso. `TrailRegistryShardNeutralityTest` fallisce se una presunzione rientra di nascosto
  (oc:8489).

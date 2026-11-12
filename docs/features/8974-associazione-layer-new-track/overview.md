> Ticket: oc:8974
> Stato: approvata il 2026-11-12

# Le tassonomie storiche non si rileggono per ogni voce di un elenco

## Cosa cambia

Costruire i campi di una Resource non costa più una rilettura completa delle tassonomie storiche.
Oggi ogni costruzione rilegge l'intera tabella di ogni tassonomia storica, geometrie comprese, e
Nova costruisce i campi una volta per ogni voce di un elenco di relazione: su uno shard con le
Zone popolate questo rende impossibile associare un contenuto a un layer.

## Perché

Su camminiditalia (oc:8974) il menu che associa un cammino a una tappa non si apre più: la
richiesta `GET /nova-api/ec-tracks/{id}/attachable/layers` impiega **123.951 ms**, con 1.736
query, 96 s di database e 305 MB di picco di memoria. Il difetto è del package e ce l'hanno tutti
gli shard; si vede solo dove `taxonomy_wheres` è popolata — su camminiditalia 7.930 righe e
104 MB, su ersaf 0 righe.

## Punto di partenza

`makeLegacyTaxonomyFieldsByTab()` (`src/Nova/Concerns/BuildsTaxonomyFields.php:300-332`) carica le
righe di ogni tassonomia storica con `$model::query()->whereNotIn(...)->orderBy('id')->get()`,
cioè un `select *`. Per le Zone significa trasferire e idratare anche la colonna `geometry`, che
da sola pesa 93 MB dei 104 della tabella e che quel codice non usa mai: delle righe servono `id`
(valore dell'opzione), `name` (etichetta, già JSON con tutte le lingue), `identifier` (ripiego
quando `name` è vuoto) e `icon`, che però esiste solo su `taxonomy_activities` e
`taxonomy_poi_types`, non su `taxonomy_wheres`.

Misura sulle 7.930 righe vere:

| Query | Tempo | Memoria |
|---|---|---|
| `select *` (oggi) | 0,48 s | 218 MB |
| `select id, name, identifier` | 0,068 s | 14 MB |

La stessa lista viene poi riletta da capo per ogni voce dell'elenco: nella richiesta misurata,
124 volte.

Le Zone entrano fra le tassonomie storiche del layer in `Wm\WmPackage\Nova\Layer::legacyTaxonomies()`
(`src/Nova/Layer.php:133-146`).

## Requisiti

- <a id="r1"></a>**R1** — Costruire i campi di una Resource legge di ogni tassonomia storica solo
  le colonne che servono a comporre le opzioni, mai le geometrie.
- <a id="r2"></a>**R2** — Dentro una stessa richiesta HTTP, le opzioni di una tassonomia storica
  si leggono una volta sola, per quante volte i campi vengano costruiti.
- <a id="r3"></a>**R3** — Le opzioni mostrate restano identiche a oggi: stesse voci, stesso
  ordine, stesse etichette tradotte, e le icone continuano a comparire dove esistono (Attività e
  Tipi POI).

## Rischi

- La lista tenuta in memoria per la durata della richiesta diventa vecchia se nella stessa
  richiesta qualcuno modifica una tassonomia. Succede in un salvataggio che cambia le tassonomie e
  poi ricostruisce i campi: va verificato che il valore mostrato dopo il salvataggio resti quello
  giusto.
- La select mirata non può essere un elenco fisso di colonne: `icon` non esiste su
  `taxonomy_wheres` e chiederla lì è un errore SQL, mentre ometterla su Attività e Tipi POI fa
  sparire le icone.

## Out of scope

- Il modello unico `taxonomy_terms` non è toccato: qui si parla solo delle tassonomie storiche.

## Moduli toccati

- `src/Nova/Concerns/BuildsTaxonomyFields.php`
- `tests/Feature/Nova/` — test su colonne lette e numero di letture.

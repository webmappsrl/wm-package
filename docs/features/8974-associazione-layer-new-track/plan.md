> Ticket: oc:8974
> Stato: approvato il 2026-11-12

# Piano — Le tassonomie storiche non si rileggono per ogni voce di un elenco

## Task 1 — Leggere solo le colonne che servono

Realizza [R1](overview.md#r1).

In `makeLegacyTaxonomyFieldsByTab()` (`src/Nova/Concerns/BuildsTaxonomyFields.php`), la query che
carica le righe di una tassonomia storica seleziona solo le colonne usate per comporre le
opzioni: `id`, `name`, `identifier`, più `icon` **solo se quella colonna esiste** sulla tabella
del modello — `icon` non c'è su `taxonomy_wheres` e chiederla lì è un errore SQL, mentre ometterla
su Attività e Tipi POI fa sparire le icone ([R3](overview.md#r3)).

Il filtro `whereNotIn('identifier', …)` resta dov'è: una colonna si può filtrare senza
selezionarla.

Commit: `perf(oc:8974): le tassonomie storiche leggono solo le colonne delle opzioni`

## Task 2 — Leggere la lista una volta sola per richiesta

Realizza [R2](overview.md#r2).

Le righe di una tassonomia storica si memorizzano per la durata della richiesta, con chiave data
dal modello e dalle voci nascoste. La memoria va legata al container (istanza risolta
dall'application), non a una proprietà statica: su un runtime che tiene vivo il processo fra una
richiesta e l'altra una statica sopravviverebbe, e mostrerebbe tassonomie vecchie.

Commit: `perf(oc:8974): le opzioni di una tassonomia storica si leggono una volta per richiesta`

## Task 3 — Test

Realizza [R3](overview.md#r3), e fissa [R1](overview.md#r1) e [R2](overview.md#r2).

- Le opzioni prodotte per Attività e Tipi POI sono identiche a prima del cambiamento, icone
  comprese, e per le Zone restano identiche senza icona.
- Costruendo i campi più volte nella stessa richiesta, la query sulla tassonomia parte una volta
  sola (contatore su `DB::listen`).
- La query non nomina la colonna `geometry`.

Commit: `test(oc:8974): colonne lette e numero di letture delle tassonomie storiche`

## Verifica finale

Sullo shard camminiditalia, con `taxonomy_wheres` popolata (7.930 righe), la richiesta
`GET /nova-api/ec-tracks/{id}/attachable/layers` scende da 123.951 ms a meno di un secondo.

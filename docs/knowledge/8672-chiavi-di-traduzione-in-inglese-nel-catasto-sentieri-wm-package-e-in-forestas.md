# Chiavi di traduzione

## Come funziona oggi

- Le chiavi di `__()` sono in inglese. In `resources/lang/en.json` la voce ripete la chiave, in
  `resources/lang/it.json` porta il testo italiano. Dall'oc:8672 vale anche per il Catasto Sentieri.
- Le voci del package arrivano a ogni shard che lo monta: Laravel unisce `resources/lang/` del
  package e `lang/` dello shard, e a parità di chiave vince lo shard. Una chiave generica tradotta
  per un dominio (`Details`, `Detail`, `Status`, `Source`) cambia il testo anche nelle altre
  schermate che la usavano senza voce.
- Nessuno dei due errori produce un messaggio: una chiave italiana compare in italiano con
  qualunque lingua; una chiave inglese senza voce in `it.json` compare in inglese all'operatore
  italiano.
- Quali testi Nova traduce da sé e quali no (testi dei bottoni delle Action, nome dei filtri) è
  una trappola in `.claude/rules/nova.md`.
- `tests/Feature/TrailRegistry/TrailRegistryTranslationKeysTest.php` verifica, per le chiavi del
  Catasto convertite dall'oc:8672, che la voce italiana sia identica al testo di prima. L'elenco
  atteso è scritto nel test, ricavato dal codice prima della conversione: rigenerarlo da `it.json`
  lo renderebbe inutile. Le chiavi letterali già inglesi senza voce le elenca su STDERR, senza
  fallire.
- La chiave della sezione di menu del Catasto: `docs/howto/attivare-catasto-sentieri.md`.

## Perché così

- **Chiave in inglese, testo italiano nella voce** (oc:8672): è la convenzione del resto del
  package e degli shard. Con la chiave italiana il testo non si traduce in altre lingue e compare
  in italiano anche con la piattaforma in inglese.
- **`Catasto` → `Trail registry`** (oc:8672): il ticket chiedeva di convertire anche quella chiave.
  In inglese «Trail registry» è il Catasto e «Code registry» il registro dei codici.
- **Le chiavi generiche si traducono una volta sola** (oc:8672): `Details` → «Dettagli» cambia anche
  le tab di `EcTrack` ed `EcPoi`, ed è voluto, per avere traduzioni coerenti in tutta la
  piattaforma.

## Come ci siamo arrivati

- **Chiavi italiane nel Catasto** (oc:8489, superata da oc:8672): la regola traduzioni di
  `wm-plan` chiedeva il testo base nella lingua di default del repo, ricavata da `APP_LOCALE=it`
  di forestas. La regola è stata corretta in webmappsrl/claude-marketplace#23.
- **`Catasto` come nome proprio anche in inglese** (oc:8672, scartata): con la lingua inglese
  l'operatore avrebbe continuato a leggere «Catasto», cioè proprio il sintomo che il ticket chiede
  di eliminare.

> Ticket: oc:8464

# Notes — Analytics shard name per query PostHog

## Deviazioni dal piano

Nessuna deviazione rispetto a `plan.md` — Task 1 e Task 2 eseguiti esattamente come pianificato (config key, fallback a runtime in `shardNameClause()`, docblock, 3 nuovi test). Task 4 (bump gitlink nel repo principale) resta sospeso in attesa del push di questo branch, come previsto dal piano stesso.

## Bug trovati

Nessuno.

## Decisioni

- **Rifiutata esplicitamente, durante l'esecuzione, l'alternativa "tutto in config"**: `'analytics_shard_name' => env('ANALYTICS_SHARD_NAME', env('SHARD_NAME', env('APP_NAME')))` nel file di config, senza fallback nel metodo. Il dev l'ha proposta due volte durante il review del codice scritto; verificata dal vivo (non solo per lettura del codice) applicandola temporaneamente e rilanciando la suite: **3 test su 4 falliscono**, incluso un test preesistente non introdotto da questo ciclo (`test_where_clause_filters_by_configured_shard_name`). Causa: `env()` viene risolto una sola volta all'avvio; i test impostano `wm-package.shard_name` a runtime via `config([...])`, quindi un fallback pre-calcolato nel file di config non segue quell'override e resta "congelato" al valore letto dalle env var al boot. Confermato che il fallback deve restare nel metodo `shardNameClause()`, letto a runtime ad ogni chiamata — non nel file di config. File ripristinati dopo il test e verificati nuovamente verdi (58/58) prima di procedere.
- **`wm-package/build/report.junit.xml` escluso dal diff/commit**: file di report JUnit già tracciato in git (commit preesistente del 2026-05-20, nonostante `build/` sia in `.gitignore` — cruft storico non introdotto da questo ciclo), rigenerato/modificato localmente dall'esecuzione della suite di test durante questo ciclo. Ripristinato con `git checkout -- build/report.junit.xml` prima del commit per non introdurre un diff estraneo alla feature.

## Follow-up

- Nessun backfill/azione su `build/report.junit.xml`: resta un file tracciato che si sporca ad ogni run locale della suite nonostante sia in `.gitignore` — segnalato come cruft pre-esistente, non risolto in questo ciclo (fuori scope).
- Task 4 (bump gitlink `wm-package` nel repo principale) da eseguire subito dopo il push di questo branch su `wm-package`.

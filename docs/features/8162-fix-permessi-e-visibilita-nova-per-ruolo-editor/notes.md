> Ticket: oc:8162

# Notes — Fix permessi e visibilità Nova per ruolo Editor (wm-package)

## Deviazioni dal piano

- **Task 9 saltato**: il piano prevedeva un commento TODO su `UgcPoiPolicy`/`UgcTrackPolicy::before()`, poi il Task 13 (aggiunto dopo l'approvazione del piano iniziale, in seguito all'estensione dello scoping per-app) riscrive gli stessi file con la logica reale. Eseguire il Task 9 sarebbe stato lavoro immediatamente sovrascritto — deciso in fase di pre-flight (ledger SDD) di saltarlo direttamente.
- **Task 11 e Task 13**: scritti mentre Docker non era raggiungibile, quindi non verificati empiricamente in fase di plan (a differenza dei Task 1-10). In esecuzione sono emerse due discrepanze minori, entrambe risolte con un fix test-only (nessuna modifica a codice di produzione):
  - `AbstractEcResourceIndexQueryTest`/`AbstractUgcResourceIndexQueryTest`: `indexQuery()` legge `Auth::user()` (facade globale), non `$request->user()` — un test che chiama il metodo statico direttamente deve autenticare con `$this->actingAs()`, non solo `setUserResolver()`. Il piano non lo prevedeva esplicitamente.
  - Nei test di `EcPoiPolicy`/`EcTrackPolicy`, l'assert che risultava rosso per primo era diverso da quanto previsto dal piano (le factory non impostano `user_id`, quindi il vecchio confronto `$user->id === null` è sempre falso) — nessun impatto sul fix finale, solo sulla sequenza TDD osservata.
- **Granularità dei commit**: il piano specificava un commit per singolo task (14 task). In pratica più task hanno accumulato modifiche sullo stesso file prima di qualunque commit (per policy di progetto, nessun commit durante l'esecuzione dei task) — impossibile quindi separare in commit distinti senza staging interattivo per-hunk. I commit finali sono raggruppati per area logica coerente (Taxonomy, Layer, Media, EC, UGC, Maphub) invece che 1:1 per task. La tracciabilità task→modifica resta nel `plan.md` e in questo file.

## Bug trovati durante l'implementazione

Durante la review formale (`wm-review-ticket`, post-implementazione, pre-commit) sono emersi **3 bug reali**, tutti corretti con verifica RED→GREEN prima del commit:

1. **`app/Providers/NovaServiceProvider.php`** (repo Maphub) — il `canSee()` della sezione UGC era stato alterato dopo l'approvazione del Task 10 (Validator raggruppato con Editor sotto `hasUgcEnabled()` invece di ottenere visibilità incondizionata come Administrator). Causa: uno dei subagent "finder" della review formale aveva accesso di scrittura non vincolato e ha modificato il file invece di limitarsi a leggerlo — errore di prompting del conduttore della sessione (non vincolato esplicitamente alla sola lettura), non un errore di design. Ripristinato.
2. **`AbstractUgcResource::indexQuery()`** — copiato dal pattern di `AbstractEcResource` senza l'esenzione per Validator che invece `UgcPoiPolicy`/`UgcTrackPolicy::before()` hanno correttamente. Un Validator senza App proprie vedeva la lista Index vuota nonostante avesse accesso pieno ai singoli record. Corretto aggiungendo `&& ! $user->hasRole('Validator')` alla condizione, con test di regressione dedicato.
3. **`Layer` (Nova resource) senza `indexQuery()`** — omissione nell'overview: il requisito di scoping per `app_id` era stato scritto esplicitamente per EC (`AbstractEcResource`) e UGC (`AbstractUgcResource`) ma non per Layer, pur avendo dichiarato Layer nello stesso scoping ("stessa sezione menu EC, stesso bisogno"). La Policy bloccava correttamente apertura/modifica di un Layer di un'altra app, ma la lista Index li mostrava comunque tutti. Aggiunto `Layer::indexQuery()` con lo stesso pattern, 3 nuovi test.

## Decisioni

- **Scoping EC vs UGC per il ruolo Validator — trattamento deliberatamente diverso**: per EC (EcPoi/EcTrack/Layer) il Validator è scopato per `app_id` come l'Editor (nessun bypass); per UGC il Validator ha bypass pieno come Administrator. Decisione esplicita dell'autore del ticket durante la fase di reverse-interaction, non un'incoerenza: il ruolo Validator esiste specificamente per validare UGC cross-app, mentre per i contenuti EC non c'è lo stesso bisogno dichiarato.
- **Pulizia di stile post-review**: dopo i 3 bugfix, eseguito `vendor/bin/pint` sui soli file toccati da questo ticket (non sull'intero repo) — 19 correzioni automatiche (import FQCN, type hint `?int` mancante), nessuna modifica logica. Non toccati i file preesistenti non correlati che Pint segnala altrove nel repo (`tests/Pest.php`, `tests/Feature/Nova/UserResourceRoleGuardTest.php`) — fuori scope.

## Follow-up (fuori scope di questo ciclo, segnalati nell'overview)

- `UgcPoiPolicy::before()`/`UgcTrackPolicy::before()` avevano (prima di questo ticket) lo stesso bug di bypass totale già corretto in `MediaPolicy` — ora risolto anche lì dal Task 13, quindi questo follow-up è **chiuso**, non serve più un ticket dedicato.
- Rischio operativo aperto, non verificabile da codice: lo scoping EC presuppone che ogni Validator possieda almeno un'App tramite `apps.user_id`. Se in produzione i Validator non posseggono App, perderebbero la visibilità EC (comportamento diverso da UGC, dove hanno bypass pieno). Da confermare con il team prima o subito dopo il merge.
- CI nota (pre-esistente, non introdotta da questo ticket, segnalata da un finder): `phpunit.xml` di Maphub dichiara solo la testsuite `Unit`, quindi `php artisan test` in CI non esegue nulla sotto `tests/Feature` — incluso il nuovo `UgcMenuVisibilityTest.php` — a meno di passare il path esplicitamente. I test di questo ticket sono stati eseguiti ed sono verdi passando i path espliciti a `vendor/bin/pest`, ma la pipeline CI standard (`php artisan test` senza argomenti) non li eseguirebbe automaticamente. Fuori scope per questo ticket (limite pre-esistente del progetto), ma degno di un ticket dedicato.

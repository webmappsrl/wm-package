> Ticket: oc:8242

# Notes — Endpoint export Apps (contratto v1)

## Deviazioni dal piano

- Nessuna deviazione strutturale: route, middleware, resource e controller come da piano.

## Bug trovati

- **`App::author()` aveva la FK implicita sbagliata**: `belongsTo(User::class)` cercava `author_id`, ma la colonna è `user_id`. Relazione rotta da sempre (nessun consumer interno). Corretta con `belongsTo(User::class, 'user_id')` — senza questo fix `author_email`/`author_name` dell'export sarebbero sempre stati null.

## Decisioni

- I test usano `App::factory()->createQuietly()` (convenzione già presente nel package): il `saved` dell'`AppObserver` invoca `AppConfigService::writeAppConfigOnAws()` che esplode in ambiente test.
- Nel test workbench le route del package sono registrate sia sotto `/api` sia sotto `/api/v2` (comportamento del ServiceProvider): l'URL canonico del contratto è `/api/v1/export/apps` (prefisso `v1/export` dentro il gruppo `/api`).
- `updated_after` validato con la regola `date` di Laravel (422 automatico su input malformato, mai fallback silenzioso al full fetch).

## Follow-up

- Release del package e deploy sugli shard; configurare `WM_EXPORT_TOKEN` per abilitare l'endpoint (senza ENV risponde 403).
- Il payload v1 non include media/loghi (Spatie Media Library): se Orchestrator ne avrà bisogno, aggiungere campi al contratto v1 (additivo, non breaking).

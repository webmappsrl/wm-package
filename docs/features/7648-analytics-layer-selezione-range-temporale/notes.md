> Ticket: oc:7648

# Notes — Analytics Layer: Selezione range temporale

## Deviazioni dal piano

- Il test helper `createLayerMockWithTrackIds` nel piano usava un mock generico per `ecTracks()`, ma PHP 8 enforce il return type `MorphToMany` — risolto mockando esplicitamente `MorphToMany` e aggiungendo SQLite in-memory per il lookup dei nomi EcTrack
- Il piano prevedeva `$layer->created_at?->format('Y-m-d')` in `LayerAnalyticsCard.php`, ma `created_at` arriva come stringa — risolto con `Carbon::parse()`

## Bug trovati

- `toYYYYMM(timestamp) = toUInt32(...)` non è supportato da PostHog HogQL — sostituito con range di date esplicito `timestamp >= 'YYYY-MM-01' AND timestamp < 'YYYY-MM+1-01'`
- `getTranslation('name', app()->getLocale())` restituiva stringa vuota invece di null (locale non italiano) — risolto con cascade `['it', 'en', app()->getLocale()]` e `?: fallback`
- `$tracks[$row['track_id']]` su Collection Eloquent lancia `ErrorException` per chiavi assenti — sostituito con `$tracks->get($row['track_id'])`
- Colori hardcodati `#374151` nella tabella non visibili in dark mode Nova — rimossi, eredita `currentColor` dal tema

## Decisioni

- WHERE per mesi: `timestamp >= 'start' AND timestamp < 'end'` invece di `toYYYYMM` — più portabile tra versioni PostHog
- `webpack` pinnato a `^5.75.0` in `package.json` per compatibilità con `laravel-mix@6` (versioni più recenti rompono `ProgressPlugin`)
- Test unitari usano SQLite in-memory via `getEnvironmentSetUp` + `defineDatabaseMigrations` per evitare connessioni al DB PostgreSQL

## Follow-up

- Nessuno

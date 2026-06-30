> Ticket: oc:8160

# Notes — Layer Nova: sposta Geometry fuori dal panel Ec Tracks e mostra anche EcPoi sulla mappa

## Deviazioni dal piano

Nessuna deviazione rilevante. Tutti gli step del piano sono stati eseguiti come pianificato.

## Bug trovati

Nessuno.

## Decisioni

- **Pattern EcPoi identico a EcTrack** — Il revisore adversariale aveva segnalato come criticità l'`id` del layer iniettato su ogni feature da `addFeaturesForMap()`. Verificando il codice EcTrack esistente, si è confermato che lo stesso comportamento è già presente per le tracce senza causare problemi: il click usa la property `link` (URL completo), non `id`. L'`id` viene usato solo in modalità popup, non in uso qui. Nessuna modifica al trait necessaria.

- **Test in wm-package con `Wm\WmPackage\Tests\TestCase`** — I test sono in `wm-package/tests/Feature/` e usano il TestCase del package, coerentemente con tutti gli altri test esistenti in quella directory. Non girano via `php artisan test` di camminiditalia (richiedono il DB separato `wm_package` e la suite phpunit propria del package).

- **Tabella `ec_pois` hardcoded** — Nessun `ec_poi_table` in config (a differenza di `ec_track_table`). Usato lo stesso approccio di `taxonomy_wheres` nello stesso metodo: nome tabella hardcoded. Rischio accettato per coerenza con il pattern esistente.

## Follow-up

- Valutare se aggiungere `ec_poi_table` alla configurazione del package per simmetria con `ec_track_table` — bassa priorità, nessun caso d'uso noto che richieda un nome tabella custom per EcPoi.

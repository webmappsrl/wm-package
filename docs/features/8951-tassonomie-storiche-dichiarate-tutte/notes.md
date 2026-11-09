> Ticket: oc:8951

# Notes

## Decisioni

- **Il criterio è «il package dichiara, lo shard sottrae»**, deciso dal dev durante il lavoro sul
  consumer. Vale per le prossime tassonomie storiche che venissero aggiunte.
- **Tipo del commit `fix:`**: la modifica completa una dichiarazione che il meccanismo prevedeva
  già, non aggiunge una capacità nuova. Con release-please questo produce una patch (1.6.2).

## Deviazioni dal piano

Nessuna.

## Bug trovati

**La rotta v1 `/api/v1/app/{id}/pois.geojson` risponde 500.** Il metodo è `pois(App $app)` ma il
parametro di rotta si chiama `{id}`: il model binding implicito non lega, `$app->id` è `null` e
`StorageService::getPoisGeojson(int $appId)` solleva un TypeError. La v2 usa `{app}` e funziona.
Verificato su camminiditalia in locale, in produzione e in dev: fallisce ovunque, da prima di
questo lavoro. Nei log di produzione di novembre compare solo nelle chiamate di prova fatte
durante questa verifica, quindi nessuno passa più da quella rotta. Non corretto qui: fuori scope.

## Follow-up

- I **Temi** non hanno una relazione sui contenuti EC: per dichiararli come le altre tassonomie
  storiche servirebbe prima quella relazione.
- La rotta v1 di cui sopra.

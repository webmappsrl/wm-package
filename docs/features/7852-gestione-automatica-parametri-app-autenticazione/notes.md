> Ticket: oc:7852

# Notes — Gestione automatica dei parametri di App dipendenti da autenticazione

## Deviazioni dal piano

- **Step 1 skippato:** la verifica empirica `dependsOn` nei tab annidati richiede browser — non eseguita durante l'implementazione automatica. Deve essere eseguita manualmente prima del merge (vedi Step 5 del plan).
- **Help text aggiunto (non nel plan originale):** l'utente ha richiesto che il campo grayed out mostri un testo esplicativo. Il metodo `mobileAuthDependent()` appende dinamicamente il lockMessage all'help text originale del campo tramite `implode(' — ', array_filter([$field->helpText, $lockMessage]))`.

## Bug trovati

Nessuno.

## Decisioni

- **Solo UI, nessuna modifica al DB:** l'approccio finale è puramente visivo — `->readonly(true)` + help text concatenato nel callback `dependsOn`. Il DB non viene mai toccato, il valore reale rimane visibile (grayed out).
- **Backup-restore via observer scartato:** durante l'implementazione è stato esplorato un approccio in cui l'observer salvava il valore originale in `properties['_auth_backup_geolocation']` e lo ripristinava al cambio di `auth_show_at_startup`. Abbandonato perché aumentava la complessità senza beneficio reale: il valore DB rimane sempre quello scelto dall'admin.
- **`webappAuthDependent()` non creato:** rimandato a quando esisterà un consumatore reale nel tab Webapp.
- **`AppConfigService` non modificato:** il config generato legge il valore reale dal DB, indipendentemente dallo stato UI.

## Follow-up

- Creare `webappAuthDependent()` quando vengono aggiunti campi dipendenti da `webapp_auth_show_at_startup` nel tab Webapp.
- Se in futuro si vuole enforced coerenza nel config, aggiornare `AppConfigService::config_section_geolocation()` per sopprimere `GEOLOCATION.record.enable` quando `auth_show_at_startup = false`.

> Ticket: oc:7852

# Notes — Gestione automatica dei parametri di App dipendenti da autenticazione

## Deviazioni dal piano

- **Step 1 originale (verifica empirica `dependsOn`) skippato:** richiede browser — da eseguire manualmente prima del merge.
- **HTML field aggiunto per detail view (non nel piano originale):** decisione di riunione con Giuseppe — la detail view deve mostrare il valore effettivo calcolato (false se padre è false), non il valore grezzo del DB.
- **Boolean cambiato da `hideFromIndex()` a `onlyOnForms()`:** necessario per evitare che il Boolean appaia anche in detail view dove già c'è l'HTML field (duplicazione). `mobileAuthDependent()` rimane attivo in edit.

## Bug trovati

Nessuno.

## Decisioni

- **HTML field in detail view:** `Text::make(..., fn() => ...)->asHtml()->onlyOnDetail()` mostra icona verde se `auth_show_at_startup && geolocation_record_enable`, rossa altrimenti. SVG da `@heroicons/vue@2.2.0` 24/solid (`w-6 h-6`) — stessa dimensione del Boolean nativo Nova. Pattern già usato nel file per `pois_data_icon`/`tracks_data_icon`.
- **`mobileAuthDependent()` mantenuto in edit:** il grayed-out in edit rimane attivo — l'utente può ancora modificare il campo ma riceve feedback visivo. Non rimosso per decisione esplicita.
- **`AppConfigService` non modificato:** il config generato legge il valore reale dal DB, indipendentemente dalla visualizzazione Nova.

## Follow-up

- Creare `webappAuthDependent()` (o equivalente HTML) quando vengono aggiunti campi dipendenti da `webapp_auth_show_at_startup` nel tab Webapp.
- Se in futuro si vuole enforced coerenza nel config, aggiornare `AppConfigService::config_section_geolocation()` per sopprimere `GEOLOCATION.record.enable` quando `auth_show_at_startup = false`.

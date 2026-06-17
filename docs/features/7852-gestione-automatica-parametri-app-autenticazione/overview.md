> Ticket: oc:7852

# Gestione automatica dei parametri di App dipendenti da autenticazione nel wm-package

## Cosa cambia

Nel form edit/create Nova del modello App, i campi che dipendono dall'autenticazione appaiono oscurati (visibili ma non modificabili, grayed out) quando il campo principale "Show Auth at startup" è `false`.

Nella detail view Nova, lo stesso campo viene renderizzato come HTML con valore calcolato: icona verde se `auth_show_at_startup=true` AND `geolocation_record_enable=true` nel DB, icona rossa altrimenti.

Il valore nel database non viene mai toccato da questa logica.

## Perché

Evitare configurazioni incoerenti: un admin potrebbe abilitare la geolocalizzazione senza rendersi conto che richiede l'autenticazione abilitata. Il feedback visivo immediato (grayed-out in edit, icona calcolata in detail) chiarisce la dipendenza senza forzare modifiche ai dati.

## Requisiti

- [ ] In **detail view**: `geolocation_record_enable` viene renderizzato come HTML con icona calcolata: verde se `auth_show_at_startup && geolocation_record_enable`, rossa altrimenti (identica al rendering nativo dei Boolean field di Nova — heroicons 24/solid, `w-6 h-6`)
- [ ] In **edit/create**: quando `auth_show_at_startup` è `false`, `geolocation_record_enable` appare grayed-out tramite `mobileAuthDependent()` (`->readonly(true)` nel callback `dependsOn`) con lockMessage — il valore reale del DB rimane visibile
- [ ] Il valore di `geolocation_record_enable` nel database **non viene mai modificato** da questa feature
- [ ] `AppConfigService` non viene modificato — continua a leggere il valore reale dal DB
- [ ] Il tab Mobile espone un helper privato `mobileAuthDependent(Boolean $field): Boolean` riutilizzabile per futuri campi dipendenti da `auth_show_at_startup`
- [ ] L'helper `webappAuthDependent()` **non viene creato ora** — rimandato a quando esisterà un consumatore reale nel tab Webapp
- [ ] I campi dipendenti rimangono raggruppati visivamente subito sotto il rispettivo campo principale

## Rischi

- **Split field (HTML+Boolean sullo stesso attributo):** pattern già usato nel file per `pois_data_icon` e `tracks_data_icon` — nessun rischio architetturale nuovo.
- **Stato incoerente preesistente non sanato:** app già configurate con `geolocation_record_enable = true` e `auth_show_at_startup = false` vedranno il campo come falso in detail ma il DB mantiene il valore reale. Accettato: la detail view riflette l'effetto reale sull'app, il DB mantiene la preconfigurazione admin.

## Out of scope

- Modifica del valore nel database quando `auth_show_at_startup` cambia
- Aggiornamento di `AppConfigService` per sopprimere `GEOLOCATION.record.enable`
- Helper `webappAuthDependent()` (rimandato a quando ci sarà un consumatore reale)
- Validazione server-side sulla coerenza dei campi
- Migrazione dati per app con stato incoerente preesistente

## Moduli toccati

| File | Repo | Modifica |
|------|------|----------|
| `src/Nova/App.php` | `wm-package` | Aggiunto HTML field `onlyOnDetail()` per `geolocation_record_enable`; Boolean wrappato con `mobileAuthDependent()->onlyOnForms()` |
| `resources/lang/it.json` | `wm-package` | Traduzione lockMessage già presente — invariata |

> Ticket: oc:7852

# Gestione automatica dei parametri di App dipendenti da autenticazione nel wm-package

## Cosa cambia

Nel form di edit/create Nova del modello App, i campi che dipendono dall'autenticazione appaiono oscurati (visibili ma non modificabili, grayed out) quando il campo principale "Show Auth at startup" è `false`. Il comportamento è puramente visivo: il valore nel database non viene mai letto, scritto o modificato da questa logica.

## Perché

Evitare configurazioni incoerenti: un admin potrebbe abilitare la geolocalizzazione senza rendersi conto che richiede l'autenticazione disabilitata. Il feedback visivo immediato chiarisce la dipendenza senza forzare modifiche ai dati.

## Requisiti

- [x] Nel form edit/create, quando `auth_show_at_startup` è `false`, il campo `geolocation_record_enable` appare oscurato (grayed out) tramite `->readonly(true)` nel callback `dependsOn` — il valore reale del DB rimane visibile
- [x] Il valore di `geolocation_record_enable` nel database **non viene mai modificato** da questa feature
- [x] `AppConfigService` non viene modificato — continua a leggere il valore reale dal DB
- [x] Il tab Mobile espone un helper privato `mobileAuthDependent(Boolean $field): Boolean` riutilizzabile per futuri campi dipendenti da `auth_show_at_startup`
- [x] L'helper `webappAuthDependent()` **non viene creato ora** — rimandato a quando esisterà un consumatore reale nel tab Webapp (documentato in `notes.md`)
- [x] I campi dipendenti rimangono raggruppati visivamente subito sotto il rispettivo campo principale (ordine già corretto nel codice attuale)
- [x] Il campo grayed out mostra un help text che spiega la dipendenza ("Requires authentication at startup to be enabled"), concatenato all'help text originale del campo
- [ ] Verifica empirica che `dependsOn` su `Boolean` funzioni nei tab annidati di Nova 5 (da eseguire manualmente in browser)

## Rischi

- **`dependsOn` in tab annidati non testato:** `auth_show_at_startup` e `geolocation_record_enable` vivono entrambi dentro `mobile_tab()` → `Tab::group`. Se i due campi finiscono in componenti Vue separati il reactive binding potrebbe non propagarsi. Mitigazione: verifica manuale prima del merge — se non funziona, alternativa è un custom Vue component.
- **Stato incoerente preesistente non sanato:** app già configurate con `geolocation_record_enable = true` e `auth_show_at_startup = false` non ricevono nessun segnale correttivo. Accettato: la feature gestisce solo le nuove interazioni via Nova, il DB non viene toccato.

## Out of scope

- Modifica del valore nel database quando `auth_show_at_startup` cambia
- Aggiornamento di `AppConfigService` per sopprimere `GEOLOCATION.record.enable`
- Helper `webappAuthDependent()` (rimandato a quando ci sarà un consumatore reale)
- Validazione server-side sulla coerenza dei campi
- Migrazione dati per app con stato incoerente preesistente

## Moduli toccati

| File | Repo | Modifica |
|------|------|----------|
| `src/Nova/App.php` | `wm-package` | Aggiunta metodo privato `mobileAuthDependent()`; `geolocation_record_enable` wrappato con `mobileAuthDependent()`; aggiunto import `FormData` |
| `resources/lang/it.json` | `wm-package` | Aggiunta traduzione italiana per "Requires authentication at startup to be enabled" |

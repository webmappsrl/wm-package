> Ticket: oc:8247

# Notes — Validazione dimensioni minime icon/splash screen

## Deviazioni dal piano

- Il piano originale ipotizzava locale di default `it` (letto da `config/app.php`). Durante l'esecuzione dei test è emerso che `.env` imposta esplicitamente `APP_LOCALE=en` (e `APP_FALLBACK_LOCALE=en`) — la locale reale di runtime in questo progetto è l'inglese, non l'italiano. I test sono stati corretti di conseguenza (il caso "locale di default" verifica il messaggio inglese, il caso esplicito `it` verifica l'italiano).

## Bug trovati

- Nessun bug introdotto rilevato durante l'implementazione. La challenge pre-implementazione aveva già intercettato il bug più critico (uso di `->rules()` invece di `->singleMediaRules()`, che avrebbe rotto ogni salvataggio del form) prima che venisse scritto codice.

## Decisioni

- **Validazione client-side (prima del click su "Update") esplicitamente fuori scope.** Richiesta dal developer dopo verifica manuale in Nova, ma richiederebbe di estendere/sostituire il componente Vue del campo `Ebess\AdvancedNovaMediaLibrary\Fields\Images` (vendor di terze parti) o costruire un campo Nova custom — lavoro frontend non banale, non compatibile con la stima approvata di 2 ore. Decisione: lasciare com'è, il comportamento attuale (validazione server-side all'invio del form) risolve già l'obiettivo originale del ticket (errore scoperto all'upload, non più in fase di build gulp/cordova-res).
- **Messaggio custom non mostrabile nel toast di Nova.** Verificato nel codice sorgente di Nova (`vendor/laravel/nova/resources/js/mixins/HandlesFormRequest.js`): per ogni errore 422 il toast mostra sempre la stringa fissa "There was a problem submitting the form.", hardcoded nel core Nova — non personalizzabile dal nostro campo senza patchare il vendor (sconsigliato, si romperebbe ad ogni aggiornamento di Nova). Il messaggio custom resta visibile correttamente in linea sotto il campo, comportamento standard Nova per gli errori di validazione per-campo.
- Verificata empiricamente (non solo per lettura di codice) la struttura reale del FormData inviato dal componente Vue di Ebess (`__media__[icon][0]`) prima di scrivere i test, per garantire che replicassero il comportamento reale e non un'approssimazione.
- Verificato con `git stash`/`stash pop` che i fallimenti preesistenti in `AppExportApiTest.php`, `AppLayerFiltersTest.php`, `AppConfigServiceOverlaysTest.php` non sono regressioni introdotte da questa feature (falliscono identicamente sulla baseline pre-modifica).

## Follow-up

- Se in futuro si vuole feedback immediato (prima del salvataggio) sulla selezione del file, valutare un ticket dedicato per un campo Nova custom con validazione dimensioni via JS (`FileReader`/`Image()`) lato client, oppure un wrapper del componente Vue di `Ebess\AdvancedNovaMediaLibrary`.
- Duplicazione soglie cross-repo (1024×1024 icon, 2732×2732 splash) tra questo package e la configurazione cordova-res/capacitor-assets di `pap`/`webmapp-app`, senza meccanismo di sync — accettato come rischio noto (vedi overview.md §Rischi), da riconsiderare se quelle soglie cambiano in futuro.

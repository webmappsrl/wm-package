> Ticket: oc:8586

# Notes — Privacy: rimuovere il nome utente reale dal marker live sulla mappa

## Deviazioni dal piano
Nessuna. I 3 task sono stati eseguiti esattamente come descritto in `plan.md`. Il rebuild del `dist` (Task 3, Step 2) è stato eseguito con successo con `npm run prod` direttamente sull'host (il `node_modules` locale del campo era presente e funzionante) — il piano lo dava per una verifica del dev, ma girava già in questa sessione.

## Bug trovati
Nessun bug nuovo scoperto durante l'implementazione.

## Decisioni

- **Reverse-interaction saltata (inizio sessione):** confermato dal dev di saltare il dialogo socratico min. 5 domande, come già annotato nella `description` del ticket da una sessione precedente.
- **Rimozione anche del `link` Nova, non solo del nominativo (Challenge, asse "assunzioni fragili"):** la customer_request chiedeva solo di anonimizzare il tooltip, ma il `link` era gated solo su `$user` risolto, non sul nominativo — lasciarlo avrebbe reso la fix cosmetica (il pallino restava comunque cliccabile verso il profilo reale). Decisione del dev: rimuovere anche il link.
- **`console.log` leak (Challenge, asse "blind spot"):** incluso nello scope del ticket la rimozione del `console.log('GeoJSON loaded:', data)` incondizionato in `FeatureCollectionMap.vue`, che loggava il payload completo (nome+link, prima della fix) ad ogni apertura della mappa.
- **Endpoint senza autorizzazione per-record (Challenge, asse "blind spot"):** `GET /nova-vendor/feature-collection-map/{model}/{id}` non ha controllo di ruolo/possesso sul layer — qualsiasi utente Nova (Guest incluso) può chiamarlo direttamente per un layer id qualsiasi. Preesistente, non introdotto da oc:8586. Decisione del dev: nessun ticket separato, resta annotato come rischio accettato in `overview.md`.
- **Nessuna predisposizione architetturale per un futuro gate per ruolo (Challenge, asse "rischi architetturali"):** `getFeatureCollectionMap()` non riceve un parametro di ruolo/contesto. Decisione del dev: nessun codice ora, solo follow-up (vedi sotto) per quando arriverà il parere legale.
- **Nessun bump del submodule/deploy in camminiditalia in questo piano:** decisione del dev, gestito manualmente dopo il merge.
- **Nessuna verifica di altri consumer di wm-package (forestas, maphub, osm2cai2):** rischio cross-consumer accettato senza verifica (Challenge, asse "difficoltà di rollback").
- **Esecuzione test non tentata in questa sessione:** verificato empiricamente che `docker exec laravel-camminiditalia php artisan test wm-package/tests/...` fallisce con `Class "Wm\WmPackage\Tests\TestCase" not found` (quel namespace non è in `autoload-dev` del repo principale — confermato leggendo `composer.json` e `vendor/composer/autoload_psr4.php`). Il container dedicato `php-forestas` non è attivo su questa macchina. Decisione del dev: nessuna esecuzione automatica, verifica manuale a suo carico.
- **Bypass del gate PHPStan (review-gate, 2026-09-22T13:19:51+0000):** eseguito `vendor/bin/phpstan analyse` in standalone sull'host (non nel container `php-forestas`, non disponibile). Risultato: 998 errori totali diffusi su quasi tutto il repo, inclusi `Class App\Models\User not found` — sintomo di un ambiente di analisi incompleto rispetto a quello ufficiale (il consumer che fornisce `App\Models\User` non è presente in un run standalone di wm-package). Dei 2 file toccati da questo ticket: `Layer.php` ha 11 errori preesistenti, **nessuno nelle righe 497-556 modificate da questa fix**; `AnalyticsService.php` ha 0 errori. Il dev ha confermato esplicitamente il bypass con questa motivazione (ambiente non rappresentativo, nessun errore sul codice toccato), responsabilità attribuita al dev.

## Review (wm-review-ticket)
Eseguita su diff non committato (`develop` vs working tree, branch `feature/oc-8586-privacy-nome-utente-marker-live`), 5 finder paralleli. Verdetto: **APPROVATO**, nessun finding bloccante. 2 finding cleanup, entrambi corretti su richiesta del dev prima dei commit:
- Docblock obsoleto in `tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php` (test `..._has_no_matching_user`): menzionava ancora "non produrre un link rotto", motivazione priva di senso dopo la fix (nessun link mai prodotto). Aggiornato.
- `overview.md` (sezione Rischi) affermava "nessun altro consumer noto" di `tooltip`/`link` — impreciso: esiste un secondo consumer nello stesso package (`feature-collection-map.blade.php:439-440`, letto in `showFeatureClick`/`handleFeatureClick`), non trovato nella ricerca iniziale. La fix lo copre comunque correttamente per costruzione (stessa fonte dati). Frase corretta in `overview.md`.

## Decisione post-review: logica preservata dietro flag hardcoded (supera una decisione della Challenge)
Dopo la review, il dev ha chiesto di non eliminare la logica di risoluzione nominativo/link, ma di conservarla per una futura riattivazione quando arriverà il parere legale — richiesta emersa mentre si stava per aggiornare lo status del ticket, non durante la Challenge. Questo **supera** la decisione della Challenge "nessuna predisposizione architetturale per un futuro gate per ruolo, solo follow-up testuale" (vedi sezione Decisioni sopra): quella decisione resta corretta per la parte "nessun parametro di ruolo/contesto", ma non per "nessun codice preservato".

Implementazione scelta (tra 3 opzioni proposte — codice commentato, config flag, metodo privato non chiamato): un booleano locale `$showLiveUserIdentity` **hardcoded a `false`** dentro `Layer::getFeatureCollectionMap()` (non una chiave di config: il dev ha chiesto esplicitamente di non esporlo via `.env`, per evitare che qualcuno lo riattivi per errore in un ambiente). Tutto il blocco try/catch + risoluzione utente + generazione `link` originale è stato ripristinato, gated da questo flag e da un commento `TODO(oc:8586)` che spiega quando e come riattivarlo. Comportamento osservabile identico a quanto descritto in `overview.md` (marker sempre anonimo, nessun link) perché il flag è `false` — cambia solo che il codice per il comportamento pre-fix resta presente e leggibile nel file, non cancellato.

Effetto sui test: nessuna modifica necessaria — con il flag a `false`, `$user` è sempre `null`, quindi i test riscritti in Task 1 (che assumono sempre tooltip anonimo e nessun link) restano corretti senza toccarli di nuovo.

## Follow-up
- Quando arriverà il parere legale: riattivare per tutti è un cambio di una riga (`$showLiveUserIdentity = true` in `Layer::getFeatureCollectionMap()`, vedi TODO nel codice). Un gate solo per Administrator è invece ancora una feature nuova, non un revert: `getFeatureCollectionMap()` non ha un parametro di contesto/ruolo, e la route Nova che lo invoca è model-agnostic su 7 classi diverse (vedi finding del finder 5 della review — `src/Nova/Fields/FeatureCollectionMap/Routes/api.php`).
- Bump del submodule `wm-package` e deploy in camminiditalia — a cura del dev dopo il merge di questo branch.
- Esecuzione reale della suite Pest/PHPUnit di wm-package (`composer test` / `vendor/bin/pest tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php`) nell'ambiente corretto (container `php-forestas` o equivalente) — a cura del dev.
- Verifica manuale nel browser (Task 3, Step 3 del piano): apertura del campo `FeatureCollectionMap` in Nova con una posizione live attiva, verifica che il marker sia anonimo, senza link cliccabile, e che la console DevTools non mostri più il payload completo — a cura del dev.
- Valutare se il gap di autorizzazione per-record sull'endpoint del campo Nova (qualsiasi ruolo Nova, incluso Guest, può chiamarlo direttamente per un layer id qualsiasi) merita un ticket dedicato in un ciclo futuro.

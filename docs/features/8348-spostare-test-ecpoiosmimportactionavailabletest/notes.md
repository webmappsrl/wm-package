> Ticket: oc:8348

# Notes — Spostare test EcPoiOsmImportActionAvailableTest in wm-package

## Deviazioni dal piano

Nessuna.

## Bug trovati

Nessuno nel codice di questo ticket. Verifica Pest locale non eseguibile: standalone `composer install` fallito per licenza Nova non abilitata a scaricare `laravel/nova@5.9.0` con le credenziali disponibili in questo ambiente, fallback git SSH senza chiavi.

**Trovato in CI dopo l'apertura della PR** (wm-package#256): il job `run-tests` fallisce allo step "Install dependencies" — Composer blocca `laravel/framework` per security advisory Packagist (`PKSA-*`), nessuna versione 11.x/12.x/13.x compatibile con `ebess/advanced-nova-media-library` risulta installabile con la policy di sicurezza di default. **Verificato pre-esistente e sistemico**: lo stesso identico fallimento compare su ogni PR `run-tests` di wm-package delle ultime 3 settimane, incluse feature scollegate da questo ticket (oc:8239, oc:8303, oc:7546, oc:8241, oc:8231, oc:8247) — non causato da questa modifica, fuori scope risolverlo qui. Segnalato solo come nota; eventuale ticket dedicato a discrezione del team.

## Decisioni

- Nuovo test posizionato per primo nel file (prima dei 6 test di autorizzazione esistenti), senza `Auth::login()` — verifica solo il wiring azione↔resource, non l'autorizzazione
- Non riusato l'helper `resolveImportEcPoiFromOsmAction()` (decisione presa in `plan.md`); un revisore formale (`wm-skills:wm-review-ticket`) ha segnalato che l'helper non richiede in realtà assunzioni di ruolo e sarebbe stato riusabile — mantenuto comunque inline su richiesta esplicita del dev: l'obiettivo di questo ciclo è eliminare l'errore PHPStan, non refactoring

## Follow-up

- Eventuale consolidamento per rimuovere la duplicazione logica tra il nuovo test e l'helper esistente, se necessario in un ciclo futuro

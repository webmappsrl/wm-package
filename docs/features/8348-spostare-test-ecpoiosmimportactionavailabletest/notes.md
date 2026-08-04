> Ticket: oc:8348

# Notes — Spostare test EcPoiOsmImportActionAvailableTest in wm-package

## Deviazioni dal piano

Nessuna.

## Bug trovati

Nessuno. Verifica Pest locale non eseguibile: standalone `composer install` fallito per licenza Nova non abilitata a scaricare `laravel/nova@5.9.0` con le credenziali disponibili in questo ambiente, fallback git SSH senza chiavi. La suite verrà eseguita realmente in CI (credenziali valide via secrets GitHub Actions in `run-tests.yml`).

## Decisioni

- Nuovo test posizionato per primo nel file (prima dei 6 test di autorizzazione esistenti), senza `Auth::login()` — verifica solo il wiring azione↔resource, non l'autorizzazione
- Non riusato l'helper `resolveImportEcPoiFromOsmAction()` (decisione presa in `plan.md`); un revisore formale (`wm-skills:wm-review-ticket`) ha segnalato che l'helper non richiede in realtà assunzioni di ruolo e sarebbe stato riusabile — mantenuto comunque inline su richiesta esplicita del dev: l'obiettivo di questo ciclo è eliminare l'errore PHPStan, non refactoring

## Follow-up

- Eventuale consolidamento per rimuovere la duplicazione logica tra il nuovo test e l'helper esistente, se necessario in un ciclo futuro

> Ticket: oc:8333

# Throttle sul login API (parte package del branch API SUS)

## Cosa cambia

`POST /auth/login` (`routes/api.php:22`) passa sotto `throttle:100,1` — 100
tentativi al minuto per IP, la stessa soglia già applicata a `signup` nella riga
immediatamente successiva.

È l'unica modifica al package prodotta dal ticket oc:8333: tutta la logica
dell'integrazione SUS (route group, controller, ruolo, middleware di
restrizione, comandi di provisioning, canale di log, documentazione) è custom e
vive nel repo `forestas`. Vedi
`forestas/docs/features/8333-branch-api-sus-con-autenticazione-dedicata/overview.md`.

## Perché

Oggi `auth/login` non ha alcun limite di tentativi, mentre `signup` sulla riga
adiacente ce l'ha: è una dimenticanza, non una scelta. In Laravel 11/12 il
gruppo di middleware `api` non porta più `throttle:api` per default, e i
consumer del package non compensano — in `forestas`, `bootstrap/app.php` ha
`withMiddleware()` vuoto. Il login resta quindi esposto a tentativi illimitati
in tutti i progetti che usano il package.

Il ticket oc:8333 introduce un client machine-to-machine per un ente esterno la
cui unica protezione è una password su quell'endpoint, e in quel contesto
l'assenza di throttle è emersa come lacuna. La correzione però non è specifica
del SUS: riguarda ogni consumer del package, e per questo vive qui e non in
`forestas`.

## Requisiti

- [ ] `throttle:100,1` su `POST /auth/login` in `routes/api.php:22`
- [ ] Nessun rate limiter da definire: l'alias `throttle` è registrato dal framework, quindi la sintassi `throttle:100,1` funziona senza configurazione aggiuntiva
- [ ] Nessuna modifica al comportamento di `AppAuthController`: cambia solo il middleware sulla route
- [ ] Nessuna logica dell'integrazione SUS in questo repo

## Rischi

- **Cambio di comportamento per tutti i consumer del package.** Un client che oggi supera i 100 login al minuto da un singolo IP inizierebbe a ricevere 429. La soglia è la stessa già in uso su `signup` e resta larga per un endpoint di login: gli utenti mobile autenticano raramente, e anche molti utenti dietro lo stesso NAT restano ampiamente sotto. Rischio valutato basso, ma reale — il caso che morderebbe non sono gli utenti veri ma gli script: un test automatico o un job che rifà login a ogni iterazione supera 100 al minuto senza difficolta'. **Azione:** verificare i flussi di login degli altri consumer al momento del bump del submodule pointer, non prima del merge di questa PR (i consumer non sono ispezionabili da questo repo).
- **Diagnosi a distanza.** Un 429 su un login è un sintomo poco frequente e facile da attribuire alla causa sbagliata. Va annotato in `CLAUDE.md` del package, così un futuro debug non parte da zero.

## Out of scope

- Rate limiter nominato e configurabile per consumer (`RateLimiter::for('login', ...)`): sovradimensionato per una soglia condivisa.
- `throttle` sulle altre route del gruppo `auth:api` (`refresh`, `me`, `user`, `delete`): richiedono già un token valido, quindi non sono un vettore di brute-force sulle credenziali.
- Ramo mobile/referrer di `AppAuthController::login` (riga 108): `$user->app` non è una relazione definita su `User` — esiste solo `apps(): HasMany` e la tabella `users` non ha colonna `app_id` — quindi quel ramo non può funzionare per nessun utente. Va affrontato in un ticket dedicato, dopo aver verificato se qualche consumer dipende da quel comportamento.

## Moduli toccati

- `routes/api.php:22`
- `CLAUDE.md` — nota sul throttle del login

**Ordine di merge vincolante:** questa PR va mergiata **prima** del bump del submodule pointer in `forestas`.

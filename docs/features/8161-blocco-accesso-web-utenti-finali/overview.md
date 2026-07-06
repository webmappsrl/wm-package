> Ticket: oc:8161

# Blocco accesso web per utenti finali (logica condivisa)

## Cosa cambia

Il wm-package espone una logica riusabile che nega la sessione web (login Fortify/Nova) a chi **non possiede il permesso Spatie `access-nova`**, senza impattare l'autenticazione API JWT. Il permesso viene assegnato ai ruoli di gestione (`Editor`, `Validator`, `Administrator` su Maphub); ogni shard decide quali ruoli lo ricevono nel proprio seed, ma il meccanismo di blocco nel package verifica sempre lo stesso permesso, non un nome di ruolo configurabile.

## Perché

Un check basato su permesso invece che su nome di ruolo evita la duplicazione di logica "chi può accedere al web" tra gate applicativo (es. Nova) e meccanismo di blocco login, ed è corretto anche per utenti con più ruoli contemporaneamente. Centralizzare il meccanismo nel package evita che ogni shard reinventi la stessa logica con nomi di ruolo diversi.

## Requisiti

- [ ] La logica di blocco nega la sessione web già al login (non dopo) per chi non ha il permesso `access-nova`.
- [ ] Il permesso `access-nova` è definito centralmente nel wm-package (`RolesAndPermissionsService`), assegnabile a qualunque ruolo per shard.
- [ ] Comportamento di default per utenti senza permesso: **negato** — mai fail-open per assenza di dati o configurazione.
- [ ] Non interferisce con l'autenticazione API JWT (`AppAuthController`/`AuthController`), che resta invariata.
- [ ] Non interferisce con le route di password reset Fortify/Nova (`password.*`) — uno shard deve poter whitelistarle esplicitamente, con un elenco esplicito delle route coinvolte (non una whitelist "a tentativi").
- [ ] Logga (livello `warning`) i tentativi di accesso web bloccati, in modo utilizzabile da ogni shard.
- [ ] La migration che introduce il permesso è idempotente (`insertOrIgnore`/`firstOrCreate`, pattern oc:8042) per non rompere installazioni esistenti.
- [ ] Test Pest nel package coprono il comportamento generico (utente con `access-nova` ottiene sessione, utente senza permesso no, utente senza alcun ruolo negato di default, API non impattata).

## Rischi

- **Migrazione su shard esistenti**: introdurre un nuovo permesso richiede che ogni shard pubblichi la migration e assegni `access-nova` ai propri ruoli di gestione — se uno shard dimentica di farlo, tutti i suoi utenti (incluso Administrator) restano bloccati fuori da Nova dopo l'upgrade del package. Mitigazione: documentare esplicitamente il passo di assegnazione nel changelog del package e in un test di regressione per shard.
- **Duplicazione temporanea**: se Maphub avesse già iniziato a implementare qualcosa lato progetto prima di questa decisione, va rimosso per evitare due logiche di blocco parallele (nessuna logica esistente trovata durante l'analisi, quindi rischio basso in pratica).

## Out of scope

- Introduzione di nuovi ruoli (es. `Contributor`) — resta a carico di ogni shard/ticket specifico (vedi oc:8158 per Maphub).
- Fix di route prive di middleware `auth` scoperte durante l'analisi (`import/geojson`, `import/confirm`) — segnalato come rischio a parte, richiede ticket dedicato.

## Moduli toccati

- `wm-package`: `RolesAndPermissionsService` (definizione permesso `access-nova`), nuova logica di blocco login riusabile basata su `can('access-nova')` (posizione esatta da definire in Fase: write-plan), migration idempotente, test Pest.

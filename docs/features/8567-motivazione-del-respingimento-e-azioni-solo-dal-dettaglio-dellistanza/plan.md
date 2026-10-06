> Ticket: oc:8567

# Piano — Motivazione del respingimento, e azioni solo dal dettaglio dell'istanza

Requisiti, rischi e domande aperte sono in [overview.md](overview.md). Il piano copre due repo:
`wm-package` (task 1-9) e `forestas` (task 10-11). Tutto il codice passa prima da `wm-package`,
come chiede la regola del package: prima il merge qui, poi il consumer aggiorna il puntatore.

**Nessun commit automatico.** I commit indicati sono istruzioni per la dev: vanno fatti solo
dopo la sua approvazione esplicita.

## Risposte del reviewer recepite

La review della PR webmappsrl/wm-package#292 ha approvato l'overview e chiuso le domande aperte
(sezione «Risposte del reviewer» dell'overview). Nel piano cambiano tre cose:

- **task 6:** le traduzioni vanno anche in `de.json`, `es.json` e `fr.json`;
- **task 7:** la validazione si verifica sulle `rules` del campo, mai chiamando `handle()`;
  `onlyOnDetail` si verifica con `shownOnIndex()` e `shownOnDetail()` sulle azioni di
  `TrailApplication::actions()`;
  c'è un test sulle chiavi nuove in `it.json` ed `en.json`;
- **task 11:** su UAT l'`ALTER` lo lancia il team prima del deploy.

Restano confermati: chiavi in inglese solo per le due azioni, messaggi al plurale invariati, gate
delle migration non toccato, nessun rollback.

## Task 1 — Colonna `rejection_reason` nello stub esistente

Repo: `wm-package`

File: `database/migrations/trail_registry/zz_2026_09_09_000001_create_trail_applications_table.php.stub`

- Dentro `Schema::create('trail_applications', …)`, dopo `$table->jsonb('properties')->nullable();`,
  aggiungere:
  ```php
  // Perche' l'istanza e' stata respinta: lo scrive l'operatore nell'azione
  // «Respingi» e, con l'integrazione SUS, e' il testo che torna al
  // richiedente. Null finche' l'istanza non e' respinta (oc:8567).
  $table->text('rejection_reason')->nullable();
  ```
- **Non** creare uno stub nuovo: è l'indicazione di Giuseppe allo scrum del 30/09.
- La guardia `if (Schema::hasTable('trail_applications')) { return; }` resta com'è.

Verifica: `runTrailRegistryStubs()` nei test del package crea la tabella da questo stub, quindi i
test del task 7 esercitano la colonna.

## Task 2 — `rejection_reason` nel modello

Repo: `wm-package`

File: `src/TrailRegistry/Models/TrailApplication.php`

- Aggiungere `'rejection_reason'` a `$fillable`, dopo `'properties'`.
- Nessun cast: è testo.

## Task 3 — Azione «Respingi»: campo, salvataggio, solo dal dettaglio

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-lettura-del-campo-motivazione)

Repo: `wm-package`

File: `src/TrailRegistry/Nova/Actions/RejectTrailApplication.php`

1. `public $onlyOnDetail = true;`, con un commento di una riga sul perché: la motivazione riguarda
   la singola domanda, e una frase copiata su più respingimenti non dice nulla a nessuno.
2. `fields()` restituisce:
   ```php
   Textarea::make(__('Rejection reason'), 'rejection_reason')
       ->rules('required', 'max:2000')
       ->help(__('Visible to the applicant: explain what to correct in the new application.')),
   ```
3. In `handle()`, dentro la `DB::transaction` esistente, lo stato e la motivazione si scrivono con
   la stessa `update()`:
   ```php
   $application->update([
       'status' => TrailApplicationStatus::Rejected,
       'rejection_reason' => $fields->rejection_reason,
   ]);
   ```
   Il ciclo su `$models`, la guardia sullo stato e `release($code, 'application_rejected', …)`
   restano invariati.
4. Chiavi in inglese:

   | Oggi | Dopo |
   |---|---|
   | `Respingi` | `Reject` |
   | `Istanze respinte.` | `Applications rejected.` |
   | `Istanze respinte: :rejected. Saltate perche' non in istruttoria: :refused.` | `Applications rejected: :rejected. Skipped because not under review: :refused.` |
   | `Nessuna istanza respinta: solo le istanze in istruttoria possono essere respinte.` | `No application rejected: only applications under review can be rejected.` |

## Task 4 — Azione «Approva»: solo dal dettaglio

Repo: `wm-package`

File: `src/TrailRegistry/Nova/Actions/ApproveTrailApplication.php`

1. `public $onlyOnDetail = true;`, con un commento di una riga: approvare vuol dire assegnare un
   codice guardando la mappa della singola istanza.
2. Chiavi in inglese:

   | Oggi | Dopo |
   |---|---|
   | `Approva` | `Approve` |
   | `Istanze approvate.` | `Applications approved.` |
   | `Istanze approvate: :approved. Saltate perche' non in istruttoria: :refused.` | `Applications approved: :approved. Skipped because not under review: :refused.` |
   | `Nessuna istanza approvata: solo le istanze in istruttoria possono essere approvate.` | `No application approved: only applications under review can be approved.` |

## Task 5 — Motivazione nel dettaglio dell'istanza

Repo: `wm-package`

File: `src/TrailRegistry/Nova/TrailApplication.php`

- In `fields()`, subito dopo `$this->summaryFields($request)` e prima della mappa:
  ```php
  $fields[] = Textarea::make(__('Rejection reason'), 'rejection_reason')
      ->onlyOnDetail()
      ->alwaysShow()
      ->canSee(fn () => $this->resource->status === TrailApplicationStatus::Rejected);
  ```
- **Non** in `summaryFields()`, che alimenta anche l'index.
- La sola lettura è già garantita: `authorizedToUpdate()` è falso per un'istanza respinta, e il
  campo non è in `fieldsForUpdate()`.
- Le 2 istanze già respinte senza motivazione mostrano il campo vuoto (il trattino di Nova).

## Task 6 — Traduzioni

Repo: `wm-package`

File: `resources/lang/en.json`, `it.json`, `de.json`, `es.json`, `fr.json`

- Aggiungere le 10 chiavi dei task 3, 4 e 5 in **tutti e cinque** i file, in coda (i file non sono
  in ordine alfabetico), mantenendo il JSON valido. In `en.json` il valore è uguale alla chiave.
- Controllare prima che `Approve` e `Reject` non esistano già con un altro significato
  (verificato il 30/09: non esistono).

| Chiave (`en`) | `it` | `de` | `es` | `fr` |
|---|---|---|---|---|
| `Approve` | Approva | Genehmigen | Aprobar | Approuver |
| `Reject` | Respingi | Ablehnen | Rechazar | Rejeter |
| `Applications approved.` | Istanze approvate. | Anträge genehmigt. | Solicitudes aprobadas. | Demandes approuvées. |
| `Applications rejected.` | Istanze respinte. | Anträge abgelehnt. | Solicitudes rechazadas. | Demandes rejetées. |
| `Applications approved: :approved. Skipped because not under review: :refused.` | Istanze approvate: :approved. Saltate perché non in istruttoria: :refused. | Anträge genehmigt: :approved. Übersprungen, weil nicht in Prüfung: :refused. | Solicitudes aprobadas: :approved. Omitidas porque no están en revisión: :refused. | Demandes approuvées : :approved. Ignorées car pas en cours d'instruction : :refused. |
| `Applications rejected: :rejected. Skipped because not under review: :refused.` | Istanze respinte: :rejected. Saltate perché non in istruttoria: :refused. | Anträge abgelehnt: :rejected. Übersprungen, weil nicht in Prüfung: :refused. | Solicitudes rechazadas: :rejected. Omitidas porque no están en revisión: :refused. | Demandes rejetées : :rejected. Ignorées car pas en cours d'instruction : :refused. |
| `No application approved: only applications under review can be approved.` | Nessuna istanza approvata: solo le istanze in istruttoria possono essere approvate. | Kein Antrag genehmigt: Nur Anträge in Prüfung können genehmigt werden. | Ninguna solicitud aprobada: solo se pueden aprobar las solicitudes en revisión. | Aucune demande approuvée : seules les demandes en cours d'instruction peuvent être approuvées. |
| `No application rejected: only applications under review can be rejected.` | Nessuna istanza respinta: solo le istanze in istruttoria possono essere respinte. | Kein Antrag abgelehnt: Nur Anträge in Prüfung können abgelehnt werden. | Ninguna solicitud rechazada: solo se pueden rechazar las solicitudes en revisión. | Aucune demande rejetée : seules les demandes en cours d'instruction peuvent être rejetées. |
| `Rejection reason` | Motivazione del respingimento | Ablehnungsgrund | Motivo del rechazo | Motif du rejet |
| `Visible to the applicant: explain what to correct in the new application.` | Visibile al richiedente: spiega cosa correggere nella nuova domanda. | Für den Antragsteller sichtbar: Erkläre, was im neuen Antrag zu korrigieren ist. | Visible para el solicitante: explica qué corregir en la nueva solicitud. | Visible par le demandeur : explique ce qu'il faut corriger dans la nouvelle demande. |

Le traduzioni in `de`, `es` e `fr` sono una proposta: vanno fatte rileggere, se nel team c'è chi
conosce la lingua.

## Task 7 — Test

Repo: `wm-package`

File: `tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php` (qui vivono già i test del
respingimento) e `tests/Feature/TrailRegistry/TrailRegistryNovaResourcesTest.php`

Prima di lanciare la suite, leggere la regola in cima al `CLAUDE.md` del package e
`.claude/rules/test.md`: il DB dei test del package è `wm_package`, separato.

Test nuovi:
1. **respingendo salva la motivazione**: `handle()` con
   `new ActionFields(collect(['rejection_reason' => 'Tracciato sovrapposto al sentiero 105']), collect())`,
   poi `rejection_reason` salvato e stato `Rejected`. Qui `handle()` va bene, perché si verifica il
   salvataggio e non la validazione.
2. **il campo motivazione è obbligatorio e al massimo 2000 caratteri**: le `rules` del campo
   restituito da `fields()` contengono `required` e `max:2000`. **Non** si chiama `handle()` senza
   motivazione: senza passare da Nova non valida niente, e il test passerebbe comunque.
3. **un'istanza non in istruttoria non riceve la motivazione**: su un'istanza approvata, il
   `handle()` con motivazione non scrive `rejection_reason`.
4. **le due azioni non compaiono nell'elenco** (in `TrailRegistryNovaResourcesTest`): fra le azioni
   restituite da `TrailApplication::actions()`, `ApproveTrailApplication` e
   `RejectTrailApplication` hanno `shownOnIndex() === false` e `shownOnDetail() === true`. Si
   verificano i metodi, non la proprietà `$onlyOnDetail`: un test sulla proprietà non si
   accorgerebbe di un `->showOnIndex()` aggiunto in `actions()` della Resource.
5. **la motivazione si vede solo sulle istanze respinte** (in `TrailRegistryNovaResourcesTest`): il
   campo `rejection_reason` è autorizzato a comparire per un'istanza `Rejected`, e non per una
   `UnderReview`.
6. **le chiavi nuove esistono in `it.json` ed `en.json`**: le 10 chiavi del task 6 sono presenti in
   tutti e due i file. Il test generale su tutto il package è oc:8672.

I test esistenti, che passano `emptyActionFields()`, devono restare verdi senza modifiche.

Comando:
```bash
docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/TrailRegistry"
```

## Task 8 — Documentazione d'uso

Repo: `wm-package`

File: `docs/resources/TrailRegistry.md`

- Sezione «Interfaccia Nova», voce **Istanze**:
  - approva e respingi si fanno solo dal dettaglio;
  - respingere richiede una motivazione, che poi si vede in sola lettura nel dettaglio.
- Sezione «Migration»: quando si aggiunge una colonna a uno stub già pubblicato, i DB che hanno
  già la tabella vanno aggiornati con un `ALTER`, e il gate `publish-missing-migrations` non lo
  segnala: è un limite noto.

## Task 9 — Verifiche finali nel package

Repo: `wm-package`

- `composer format` **solo sui file toccati**: senza scope riformatta tutto il repo. Poi
  `git status`, scartando i file fuori dal lavoro.
- PHPStan: `docker exec php-forestas vendor/bin/phpstan analyse`.
- Pest sulla cartella `tests/Feature/TrailRegistry` (task 7).

Commit suggerito, da fare solo dopo l'approvazione della dev:
```
feat(oc:8567): motivazione obbligatoria del respingimento e azioni solo dal dettaglio
```

## Task 10 — Migration pubblicata in forestas

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-10-migration-di-forestas-modificata-prima-del-merge-del-package)

Repo: `forestas`

File: `database/migrations/2026_09_10_073030_zz_2026_09_09_000001_create_trail_applications_table.php`

- Renderlo identico al nuovo stub del task 1: stessa riga, stesso commento.
- Verifica:
  ```bash
  diff <(sed 's/[[:space:]]//g' database/migrations/2026_09_10_073030_zz_2026_09_09_000001_create_trail_applications_table.php) \
       <(sed 's/[[:space:]]//g' wm-package/database/migrations/trail_registry/zz_2026_09_09_000001_create_trail_applications_table.php.stub)
  ```
  non deve stampare nulla.

## Task 11 — DB locale, puntatore del submodule, note

Repo: `forestas`

1. **DB locale della dev**: la tabella esiste già, quindi la colonna va aggiunta a mano. È una
   scrittura sul DB reale di sviluppo, e va chiesta alla dev prima di lanciarla:
   ```sql
   ALTER TABLE trail_applications ADD COLUMN rejection_reason text NULL;
   ```
   Poi la verifica:
   ```sql
   select column_name from information_schema.columns
   where table_name = 'trail_applications' and column_name = 'rejection_reason';
   ```
   **Mai `migrate:rollback`**: annulla tutto il batch 9.
   Su **UAT** la tabella esiste già: l'`ALTER` lo lancia il team prima del deploy.
2. **Puntatore del submodule** `wm-package`: si aggiorna solo dopo il merge della PR del package
   (webmappsrl/wm-package#292), nello stesso commit del task 10.
3. **Prova manuale in Nova, sul locale**:
   - dall'elenco delle istanze non ci sono più «Approva» e «Respingi»;
   - dal dettaglio di un'istanza in istruttoria, «Respingi» senza motivazione non si conferma;
   - con la motivazione, l'istanza passa a respinta e il testo compare nel dettaglio;
   - il testo non si può modificare.

Commit suggerito in forestas, dopo il merge del package:
```
feat(oc:8567): migration di trail_applications con rejection_reason e bump wm-package
```

La PR di forestas va verso `develop`.

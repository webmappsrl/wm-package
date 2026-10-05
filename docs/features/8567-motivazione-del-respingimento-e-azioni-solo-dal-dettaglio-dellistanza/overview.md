> Ticket: oc:8567

# Motivazione del respingimento, e azioni solo dal dettaglio dell'istanza

## Cosa cambia

- Quando respinge un'istanza di accatastamento, l'operatore deve scrivere **perché** la respinge.
  Il testo viene salvato sull'istanza, in una colonna nuova `rejection_reason`, e compare in sola
  lettura nel dettaglio delle istanze respinte.
- Approvazione e respingimento si possono fare **solo dal dettaglio** dell'istanza: dall'elenco
  spariscono, sia come azioni sulla selezione multipla sia come azioni sulla singola riga.
- Le chiavi di traduzione delle due azioni passano dall'italiano all'inglese, per coerenza con il
  resto del package, dove la chiave è il testo nella lingua di default (`en`).

## Perché

- Un'istanza respinta non si riapre: il richiedente ne presenta una nuova. Senza un motivo scritto
  non sa cosa correggere. Quando ci sarà l'integrazione con il SUS, sarà questo il testo che
  riceve.
- Approvare o respingere più istanze insieme non ha senso. Un'unica motivazione copiata su tre
  respingimenti diversi non dice nulla a nessuno dei tre richiedenti. Allo stesso modo, approvare
  in blocco vuol dire assegnare più codici senza aver guardato nessuna mappa.
- Allo scrum del 30/09 Giuseppe Bonfanti ha dato a Carla queste indicazioni:
  - «Lo vedi e respingi gli devi aggiungere un campo di testo che è la motivazione»;
  - «Quando ranni la action devi salvare questa motivazione»;
  - sulle migration: «se fai una migrazione non gliela devi far creare nuova, gli devi dire devi
    modificare la quella esistente».

## Requisiti

- [ ] L'azione «Respingi» mostra un `Textarea` per la motivazione. È **obbligatoria**, con
      validazione `required` e `max:2000`, e senza di essa l'azione non si conferma.
- [ ] La motivazione va nella colonna `trail_applications.rejection_reason` (`text`, nullable).
      Viene scritta nella **stessa transazione** che cambia lo stato in `rejected` e libera il
      numero.
- [ ] La colonna si aggiunge **modificando lo stub esistente**
      `database/migrations/trail_registry/zz_2026_09_09_000001_create_trail_applications_table.php.stub`,
      senza creare una migration nuova (indicazione di Giuseppe del 30/09). `rejection_reason` va
      anche in `$fillable` del modello `TrailApplication`.
- [ ] Nel dettaglio dell'istanza la motivazione compare **in sola lettura** e **solo se lo stato è
      `rejected`**. Non compare nell'elenco.
- [ ] `ApproveTrailApplication` e `RejectTrailApplication` hanno `public $onlyOnDetail = true;`,
      come fa già `ReplaceTrailCodeNumber`.
- [ ] Resta invariato tutto il resto del respingimento e dell'approvazione:
  - la guardia che ammette solo istanze in istruttoria, in `canRun()` e dentro `handle()`, perché
    l'endpoint Nova si può chiamare direttamente;
  - la liberazione del numero riservato con causa `application_rejected`;
  - la transazione che tiene insieme le scritture.
- [ ] Tutte le stringhe delle due azioni, quelle esistenti e quelle nuove, usano chiavi in
      **inglese**, con la traduzione in tutti i file di lingua del package:
      `resources/lang/en.json`, `it.json`, `de.json`, `es.json` e `fr.json`. In forestas e su UAT
      (`APP_LOCALE=it`) le etichette restano in italiano come oggi.
- [ ] I messaggi di esito al plurale («Applications rejected: :rejected. Skipped because not under
      review: :refused.» e gli analoghi) restano come sono, tradotti. Il ciclo in `handle()` resta
      per le chiamate dirette all'endpoint.
- [ ] Test (Pest, nel package):
  - il respingimento con motivazione salva il testo e cambia lo stato (qui si può chiamare
    `handle()`, perché si verifica il salvataggio, non la validazione);
  - l'obbligatorietà e il limite di 2000 caratteri si verificano sulle `rules` del campo
    restituito da `fields()`, oppure passando dall'endpoint Nova dell'azione (POST su
    `/nova-api/trail-applications/action?action=reject-trail-application`). **Non** chiamando
    `handle()` direttamente: così Nova non valida niente, e il test passerebbe comunque;
  - sulle azioni restituite da `TrailApplication::actions()`, per `ApproveTrailApplication` e
    `RejectTrailApplication` `shownOnIndex()` è `false` e `shownOnDetail()` è `true`. Si verificano
    i metodi, non la proprietà `$onlyOnDetail`: un test sulla proprietà non si accorgerebbe di un
    `->showOnIndex()` aggiunto in `actions()` della Resource;
  - le chiavi nuove esistono in `it.json` ed `en.json`. Un test generale sulle traduzioni di tutto
    il package è oc:8672;
  - i test esistenti del respingimento (liberazione del numero, guardia sullo stato) restano
    verdi.

## Rischi

- **Su un DB che ha già la tabella, la colonna non arriva da sola.** Lo stub inizia con
  `if (Schema::hasTable('trail_applications')) { return; }` e Laravel non riesegue una migration già
  registrata. Chi ha già la tabella deve aggiornarla: i DB locali dei dev e **UAT**, dove la
  tabella esiste già e l'`ALTER` lo lancia il team prima del deploy. Il comando, una tantum:
  ```sql
  ALTER TABLE trail_applications ADD COLUMN rejection_reason text NULL;
  ```
  Un `migrate:rollback` non va usato: in forestas annullerebbe tutto il batch 9, cioè 10 migration,
  fra cui `add_identifier_to_taxonomy_wheres`. Se l'`ALTER` viene dimenticato, il primo
  respingimento fallisce con un errore di colonna inesistente. Nessuno strumento lo segnala:
  il gate `publish-missing-migrations --dry-run` dà «allineati» anche senza la colonna (limite
  noto, fuori scope: il gate non si tocca), e il deploy (`scripts/deploy_prod.sh`) fa solo `migrate --force`, che non
  la aggiunge. La mitigazione è riportare il comando nelle note del ticket, con la query di
  verifica:
  ```sql
  select column_name from information_schema.columns
  where table_name = 'trail_applications' and column_name = 'rejection_reason';
  ```
  Se non restituisce righe, serve l'`ALTER`. Il dominio non è ancora in produzione, quindi il
  danno si limita agli ambienti di sviluppo e di test.
- **La motivazione è testo libero e arriverà a un cittadino.** Con l'integrazione SUS sarà ciò che
  legge il richiedente: va scritta con la stessa cura di una risposta al cliente. L'etichetta e il
  testo di aiuto del campo lo dicono esplicitamente.
- **Limitare le azioni al dettaglio toglie una scorciatoia che oggi esiste.** Sui volumi dichiarati
  dal cliente, circa 10 istanze al mese, non è un problema.
- **Il campo non è retroattivo.** Le istanze già respinte (2 nel DB locale) restano senza
  motivazione.

## Risposte del reviewer

Le domande aperte sono state chiuse nella review della PR webmappsrl/wm-package#292:

1. **Chiavi italiane negli altri file del Catasto e di forestas:** vanno portate in inglese, ma in
   **oc:8672**, che copre le chiavi italiane rimaste nel Catasto, le tre fuori dal Catasto e le 34
   di forestas. Qui si portano in inglese solo quelle di `ApproveTrailApplication` e
   `RejectTrailApplication`.
2. **UAT:** la tabella `trail_applications` esiste già. L'`ALTER` di `rejection_reason` lo lancia
   il team prima del deploy.
3. **Messaggi al plurale:** restano come sono, tradotti.
4. **Call del 15/09 citata nella description:** la data non cambia nulla, perché il requisito lo
   fissano la `customer_request` e lo scrum del 30/09. La description resta com'è.
5. **Gate delle migration:** fuori scope, non si tocca. Il limite è noto.
6. **Rollback:** resta fuori dal documento, basta l'`ALTER`.
7. **`APP_LOCALE` del server:** su UAT è `it`. `.env-deploy` è solo il template.
8. **Test sulle traduzioni:** quello generale, su tutte le chiavi dei due repo, è oc:8672. Qui
   basta verificare che le chiavi nuove esistano in `it.json` ed `en.json`.
9. **Chiavi italiane del Catasto:** non erano una scelta voluta. Sono nate dalla regola sulle
   traduzioni di wm-plan, che chiedeva il testo base nella lingua di `APP_LOCALE`; la regola è
   corretta in webmappsrl/claude-marketplace#23.

## Out of scope

- La trasmissione della motivazione al SUS: oggi `routes/sus.php` di forestas espone solo login,
  refresh e ping, e l'endpoint di ritorno non esiste.
- Il terzo esito «accettato con riserva».
- Una motivazione anche sull'approvazione.
- La modifica della motivazione dopo il respingimento: si scrive una volta sola, dall'azione.
- Qualsiasi modifica alla liberazione del numero.
- Le chiavi in italiano degli altri file del Catasto e di forestas: oc:8672.
- La correzione del gate `publish-missing-migrations`.
- Il test generale sulla completezza delle traduzioni del package: oc:8672.

## Moduli toccati

**`wm-package`**
- `database/migrations/trail_registry/zz_2026_09_09_000001_create_trail_applications_table.php.stub`:
  colonna `rejection_reason`
- `src/TrailRegistry/Models/TrailApplication.php`: `rejection_reason` in `$fillable`
- `src/TrailRegistry/Nova/Actions/RejectTrailApplication.php`: campo in `fields()`, salvataggio
  in `handle()`, `$onlyOnDetail`, chiavi in inglese
- `src/TrailRegistry/Nova/Actions/ApproveTrailApplication.php`: `$onlyOnDetail`, chiavi in inglese
- `src/TrailRegistry/Nova/TrailApplication.php`: campo in sola lettura nel dettaglio, visibile solo
  se `rejected`
- `resources/lang/en.json`, `it.json`, `de.json`, `es.json`, `fr.json`
- `tests/Feature/TrailRegistry/`: test del respingimento e delle azioni
- `docs/resources/TrailRegistry.md`: motivazione e azioni dal dettaglio

**`forestas`**
- `database/migrations/2026_09_10_073030_zz_2026_09_09_000001_create_trail_applications_table.php`:
  reso identico al nuovo stub, così il gate in CI resta verde
- puntatore del submodule `wm-package`, aggiornato dopo il merge del package

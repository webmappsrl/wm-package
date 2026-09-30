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
      **inglese**, con la traduzione in `resources/lang/en.json` e `resources/lang/it.json` del
      package. In forestas (`APP_LOCALE=it`) le etichette restano in italiano come oggi.
      `de`, `es` e `fr` non si toccano.
- [ ] Test (Pest, nel package):
  - il respingimento con motivazione salva il testo e cambia lo stato;
  - senza motivazione la validazione fallisce e lo stato non cambia;
  - una motivazione oltre i 2000 caratteri viene rifiutata;
  - le due azioni hanno `onlyOnDetail`;
  - i test esistenti del respingimento (liberazione del numero, guardia sullo stato) restano
    verdi.

## Rischi

- **Su un DB che ha già la tabella, la colonna non arriva da sola.** Lo stub inizia con
  `if (Schema::hasTable('trail_applications')) { return; }` e Laravel non riesegue una migration già
  registrata. Chi ha già la tabella (i DB locali dei dev, ed eventuali UAT) deve aggiornarla. Il
  modo consigliato è un comando una tantum:
  ```sql
  ALTER TABLE trail_applications ADD COLUMN rejection_reason text NULL;
  ```
  In alternativa si può fare un rollback mirato delle 4 migration del Catasto e poi `migrate`. È
  un'operazione che svuota codici, anomalie, eventi e istanze: i codici e le anomalie si
  ricostruiscono rilanciando l'import, lo storico degli eventi e le istanze no. Un semplice
  `migrate:rollback` non va usato: in forestas annullerebbe tutto il batch 9, cioè 10 migration,
  fra cui `add_identifier_to_taxonomy_wheres`. Se l'`ALTER` viene dimenticato, il primo
  respingimento fallisce con un errore di colonna inesistente. La mitigazione è riportare il
  comando nelle note del ticket, e il gate `publish-missing-migrations --dry-run` segnala la
  colonna mancante. Il dominio non è ancora in produzione, quindi il danno si limita agli ambienti
  di sviluppo e di test.
- **La motivazione è testo libero e arriverà a un cittadino.** Con l'integrazione SUS sarà ciò che
  legge il richiedente: va scritta con la stessa cura di una risposta al cliente. L'etichetta e il
  testo di aiuto del campo lo dicono esplicitamente.
- **Limitare le azioni al dettaglio toglie una scorciatoia che oggi esiste.** Sui volumi dichiarati
  dal cliente, circa 10 istanze al mese, non è un problema.
- **Il campo non è retroattivo.** Le istanze già respinte (2 nel DB locale) restano senza
  motivazione.

## Domande aperte per il reviewer

1. **Chiavi di traduzione in italiano nei file che questo ticket non tocca.** Le chiavi delle due
   azioni passano all'inglese solo per coerenza con il resto del package, dove 422 chiavi di
   `en.json` su 483 sono in inglese. Restano in italiano:
   - «Sostituisci numero», «Numero», «Variante», «nessuna variante», «Numero sostituito.» e
     «Questa istanza non ha un codice attivo da sostituire.», in
     `src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php`;
   - le etichette della Resource `src/TrailRegistry/Nova/TrailApplication.php`: «Istanze»,
     «Istanza», «Denominazione», «Codice», «Stato istruttoria» e le altre;
   - «Tipologie di POI associate a questo punto di interesse», fuori dal Catasto.

   Vanno portate in inglese anche queste? Se sì, in questo ticket o in uno dedicato?
2. **Su UAT o sul server il dominio `trail_registry` è già attivo?** La dev pensa di sì, ma non
   ha una conferma, e dal repo non si vede. Se è attivo, prima del deploy di questo ticket va
   lanciato anche lì l'`ALTER` di `rejection_reason`. Chi gestisce l'ambiente può verificarlo
   con `php artisan wm-package:publish-missing-migrations --dry-run` lanciato sul server.
3. **I messaggi di esito al plurale.** Con `onlyOnDetail` l'azione riceve sempre una sola istanza,
   quindi «Istanze respinte: :rejected. Saltate perché non in istruttoria: :refused.» non si vedrà
   più dall'interfaccia. Il ciclo in `handle()` resta, perché l'endpoint si può chiamare
   direttamente con più id. Semplifichiamo i messaggi al singolare o li lasciamo come sono,
   tradotti?
4. **Il riferimento alla call del 15/09 nella description del ticket.** La description cita un
   passaggio del 15/09 (~00:40) in cui Piccioli mostra le due azioni, ma nelle call di quel giorno
   non si trova: se ne parla il 21/09 (Bonfanti, spiegazione delle azioni) e il 30/09 (istruzioni
   a Carla). Correggiamo la description?

## Out of scope

- La trasmissione della motivazione al SUS: oggi `routes/sus.php` di forestas espone solo login,
  refresh e ping, e l'endpoint di ritorno non esiste.
- Il terzo esito «accettato con riserva».
- Una motivazione anche sull'approvazione.
- La modifica della motivazione dopo il respingimento: si scrive una volta sola, dall'azione.
- Qualsiasi modifica alla liberazione del numero.
- La traduzione in `de`, `es` e `fr`.

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
- `resources/lang/en.json`, `resources/lang/it.json`
- `tests/Feature/TrailRegistry/`: test del respingimento e delle azioni
- `docs/resources/TrailRegistry.md`: motivazione e azioni dal dettaglio

**`forestas`**
- `database/migrations/2026_09_10_073030_zz_2026_09_09_000001_create_trail_applications_table.php`:
  reso identico al nuovo stub, così il gate in CI resta verde
- puntatore del submodule `wm-package`, aggiornato dopo il merge del package

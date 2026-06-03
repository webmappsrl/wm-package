> Ticket: oc:7749

# Aggiungere create app

## Cosa cambia
Viene abilitato il pulsante "Crea App" nella risorsa Nova `App` in tutti i progetti che usano `wm-package`. Gli Administrator possono creare nuove App tramite un form minimale con i soli campi obbligatori.

## Perché
La risorsa Nova `App` non aveva né il metodo `create` nella policy né un form di creazione, rendendo impossibile creare nuove App dall'interfaccia Nova. I tentativi manuali di creazione (documentati in oc:7757) fallivan con errori NOT NULL su campi non presenti nel form. Questa feature porta la funzionalità in modo stabile e condiviso nel package.

## Requisiti
- [ ] `AppPolicy::create()` abilita la creazione solo per utenti con ruolo `Administrator`
- [ ] Il form di creazione mostra solo i campi obbligatori: `name`, `customer_name`, `sku`, `author` (BelongsTo User, nullable, searchable)
- [ ] I campi con default nel DB (`default_language`, `start_end_icons_min_zoom`, `ref_on_track_min_zoom`, ecc.) non compaiono nel form di creazione e vengono valorizzati dai default DB
- [ ] La modifica è in `wm-package` ed è attiva in tutti i progetti che usano il package
- [ ] Il campo `author` è selezionabile manualmente (un Administrator può creare un'app per conto di un altro utente)

## Rischi
- **Abilitazione globale**: la modifica abilita la creazione di App in tutti i progetti. Progetti che per policy non devono permettere la creazione di App dovranno fare override di `authorizedToCreate()` nella propria `Nova/App.php`. Da comunicare al team.
- **Campi NOT NULL senza default**: se in futuro vengono aggiunti colonne NOT NULL senza default alla tabella `apps`, il form minimo potrebbe tornare a fallire. Il `fieldsForCreate()` deve essere aggiornato contestualmente alle migration.

## Out of scope
- Auto-popolamento di `customer_name` dal campo `name` (annotato per ciclo futuro)
- Validazione o formato specifico per `sku` (es. bundle ID)
- Override per-progetto dell'autorizzazione (ogni progetto gestirà autonomamente eventuali restrizioni aggiuntive)
- Fix dei campi Nova per la pagina di edit (non richiesto da questo ticket)

## Moduli toccati
| File | Repo | Tipo modifica |
|------|------|---------------|
| `src/Policies/AppPolicy.php` | `wm-package` | Aggiunta metodo `create()` |
| `src/Nova/App.php` | `wm-package` | Aggiunta metodo `fieldsForCreate()` |

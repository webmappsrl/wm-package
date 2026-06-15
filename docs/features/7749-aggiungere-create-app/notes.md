> Ticket: oc:7749

# Notes — Aggiungere create app

## Deviazioni dal piano
- **`AppPolicy::create()`**: inizialmente implementato con `hasRole('Administrator')`; successivamente allineato a `SuperAdminService::allowsUser()` per coerenza con le action Nova sulla stessa risorsa (PBF, Scout, AWS, ecc.) e per limitare la creazione allo staff in allowlist, non a tutti gli Administrator del tenant.

## Bug trovati
- **FK errata su `author()`**: `belongsTo(User::class)` senza foreign key esplicita inferiva `author_id` (inesistente), mentre la colonna reale è `user_id`. Corretto in Step 1 come bug latente del model.
- **`AppPolicy` non registrata**: la policy esisteva ma non era registrata in nessun provider, quindi il metodo `create()` non sarebbe mai stato invocato. Risolto registrando esplicitamente in `WmPackageServiceProvider`.

## Decisioni
- La registrazione di `AppPolicy` è stata messa in `WmPackageServiceProvider` (e non nel progetto host) per garantire che valga per tutti i progetti che usano il package.
- `AppPolicy::create()` usa `SuperAdminService` (allowlist `wm-package.super_admin_emails` / `WM_SUPER_ADMIN_EMAILS`), non il ruolo Spatie `Administrator`, in linea con `Nova/App::actions()`.
- `fieldsForCreate()` usa `App\Nova\User` come classe Nova per il `BelongsTo`, seguendo il pattern già stabilito in `Nova/Layer.php`.
- I fix da oc:7757 (`->default()` e `->rules('required')` su `default_language`, `start_end_icons_min_zoom`, `ref_on_track_min_zoom`) sono stati incorporati in questo ciclo come Step 6, chiudendo di fatto anche quel ticket.

## Follow-up
- **Auto-popolamento `customer_name`**: valutare in un ciclo futuro se pre-popolare `customer_name` con il valore di `name` per ridurre la digitazione ridondante in fase di creazione.
- **Config AWS su creazione**: `AppObserver::saved()` scrive immediatamente `config.json` su AWS anche per un'App appena creata con soli 4 campi. Il `config.json` risultante sarà parziale fino al primo salvataggio completo. Valutare in futuro un meccanismo di stato `draft/published` per bloccare la scrittura AWS finché l'App non è completa.
- **Cleanup AWS su delete**: non esiste un observer su `deleted` che rimuova il `config.json` da AWS. Un rollback del codice non rimuove gli artefatti già scritti su storage esterno.

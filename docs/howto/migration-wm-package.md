# Migration di wm-package nei consumer

Vale per ogni consumer (forestas, maphub, camminiditalia, osm2cai2, …). Gli stub della root sono
**obbligatori** per tutti; quelli in sottocartella appartengono a un
[dominio opzionale](../knowledge/domini-opzionali.md) e riguardano solo chi lo ha attivato.

**Mai `vendor:publish` in deploy; mai `vendor:publish --force` in locale.** `vendor:publish` non
pubblica gli stub dei domini: serve `publish-migration <dominio>/<stub>`.

I passi che chiudono con un commit sono **del dev**: l'agente scrive i file e si ferma.

## Workflow

```bash
php artisan wm-package:publish-missing-migrations --dry-run
php artisan wm-package:publish-missing-migrations   # se exit 1
php artisan migrate
```

Poi il dev committa `database/migrations/`.

`publish-missing-migrations --dry-run` è il gate CI del consumer, eseguito dopo `migrate` sullo
stesso DB dei test: esce 1 se non è allineato.

## Se `--dry-run` fallisce

1. Leggi lo stub in `database/migrations/<nome>.php.stub` del package.
2. Cerca una migration equivalente nel consumer **per contenuto e schema, non per nome**.
3. Schema già allineato → nessuna azione.
4. Gap sul DB → `publish-migration <stub>` (o `publish-missing-migrations`), poi `migrate`, poi
   il dev committa.
5. File identico già committato ma non migrato → `php artisan migrate`.

## Tabella dei casi

| Scenario | `--dry-run` | Azione |
|---|---|---|
| Schema già completo | pass | Nessuna |
| Gap schema, nessun file identico | fail | `publish-missing-migrations` / `publish-migration` |
| File identico in git, non migrato | fail | `migrate` |
| Suffisso uguale, contenuto diverso | fail | Pubblica lo stub del package |
| Schema ok via migration custom | pass | Nessuna |

Il suffisso del nome file **non basta** come criterio: `publish-migration <stub>` non si ferma
al suffisso se il contenuto è diverso (caso tipico, `create_users_table`).

## Come decide `InteractsWithWmPackageMigrationStubs`

| Metodo | Cosa stabilisce |
|---|---|
| `schemaGapsForStub` | colonne, tabelle o ruoli mancanti sul DB |
| `isAppliedToDatabase` | nessun gap (o migration eseguita per suffisso, se lo stub non è parsabile) |
| `needsPublishing` | c'è un gap e nessun file committato identico allo stub |
| `stubsPendingMigration` | file identico committato, ma assente dalla tabella `migrations` |

## Da dove viene un ruolo o un permesso nel DB

Se un ruolo o permesso Spatie risulta presente e non è chiaro da dove arrivi, **guarda prima la
tabella `migrations`**:

```sql
select * from migrations where migration like '%<nome>%';
```

È quasi sempre una migration stub del package (`database/migrations/zz_*` nel consumer), non un
side-effect di un job applicativo. Il dettaglio è in
[docs/knowledge/autorizzazione-e-ruoli.md](../knowledge/autorizzazione-e-ruoli.md).

## Attenzione in locale

I test dei comandi di publish (`WmPackagePublishMigrationCommandTest`,
`WmPackagePublishMissingMigrationsCommandTest`) cancellano file reali su disco: lanciando la
suite in locale si possono perdere file dal working tree (oc:8094).

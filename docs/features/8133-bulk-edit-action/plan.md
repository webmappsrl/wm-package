> Ticket: oc:8133

# Piano — BulkEditAction: bulk edit dinamico da Nova Resource

## Step 1 — Crea `BulkEditAction` in wm-package

**File:** `src/Nova/Actions/BulkEditAction.php`
**Repo:** wm-package

Crea la classe con questa struttura:

```php
namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Contracts\RelatableField;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\FieldMergeValue;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Http\Requests\NovaRequest;

class BulkEditAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $onlyOnIndex = true;

    public function __construct(
        protected string $novaResource,
        protected array $fields = []
    ) {}

    public function fields(NovaRequest $request): array
    {
        $model = $this->novaResource::newModel();
        $resourceInstance = new $this->novaResource($model);

        // Flat array di tutti i field (primo livello, con appiattimento Tab/Panel)
        $flat = [];
        foreach ($resourceInstance->fields($request) as $field) {
            if ($field instanceof FieldMergeValue) {
                foreach ($field->data as $nested) {
                    $flat[] = $nested;
                }
            } else {
                $flat[] = $field;
            }
        }

        $filtered = collect($flat)
            ->filter(fn ($field) =>
                property_exists($field, 'attribute')
                && ! ($field instanceof ID)
                && ! ($field instanceof RelatableField)
                && property_exists($field, 'showOnUpdate') && $field->showOnUpdate
                && ($field->readonlyCallback ?? null) !== true
            )
            ->map(function ($field) {
                // Azzera readonly dinamico (closure) per evitare che il modello vuoto
                // blocchi il campo per tutti i record selezionati
                if (is_callable($field->readonlyCallback ?? null)) {
                    $field->readonly(false);
                }
                return $field->nullable();
            });

        if (! empty($this->fields)) {
            $filtered = $filtered->filter(
                fn ($field) => in_array($field->attribute, $this->fields)
            );
        }

        return $filtered->values()->all();
    }

    public function handle(ActionFields $fields, Collection $models): void
    {
        DB::transaction(function () use ($fields, $models) {
            foreach ($models as $model) {
                foreach ($fields as $attribute => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $model->forceFill([$attribute => $value]);
                }
                $model->saveQuietly();
            }
        });
    }
}
```

**Nota:** `readonlyCallback` è una proprietà `public` del trait `MutableFields` usato da `Field`. Verificare che l'accesso `$field->readonlyCallback` non lanci errori su campi che non usano il trait — il `?? null` lo gestisce in modo null-safe.

**Commit:** `feat(oc:8133): add BulkEditAction to wm-package`

---

## Step 2 — Unit test per `fields()`

**File:** `tests/Unit/Nova/Actions/BulkEditActionTest.php`
**Repo:** wm-package

Usa PHPUnit class-based (stesso pattern di `ExecuteEcTrackDataChainActionTest`). Crea una Nova Resource fittizia inline nel test che include:
- un `ID::make()`
- un campo `Text` con `showOnUpdate = true`
- un campo `Text` con `->hideWhenUpdating()`
- un campo `Boolean` con `->readonly(true)` (statico)
- un campo `Boolean` con `->readonly(fn ($r) => true)` (dinamico)
- un `BelongsTo` (RelatableField)
- un `Panel::make(...)` con dentro un campo `Text` (appiattimento)

Scenari da coprire:

```
test_excludes_id_field
test_excludes_relatable_fields
test_excludes_static_readonly_fields
test_excludes_fields_hidden_on_update
test_includes_dynamic_readonly_fields_with_callback_cleared
test_flattens_panel_fields
test_filters_by_fields_array
test_returns_all_fields_when_fields_array_is_empty
test_all_returned_fields_are_nullable
```

Per creare la Resource fittizia, definire una classe anonima o una class locale nel file di test che estende `\Laravel\Nova\Resource` e implementa `fields()` con i campi sopra. Il modello può essere un Eloquent model factory esistente (es. `EcPoi`).

**Commit:** `test(oc:8133): add unit tests for BulkEditAction fields() filtering`

---

## Step 3 — Feature test per `handle()`

**File:** `tests/Feature/Nova/Actions/BulkEditActionFeatureTest.php`
**Repo:** wm-package

Usa Pest con `uses(TestCase::class, DatabaseTransactions::class)`. Il test agisce su modelli reali in DB.

Scenari da coprire:

```php
it('skips null values and does not update the field')
it('skips empty string values and does not update the field')
it('saves false boolean value (does not skip falsy)')
it('saves true boolean value')
it('rolls back all changes when one save fails')
it('handles multiple models in a single transaction')
```

Per `rolls back all changes when one save fails`: iniettare un'eccezione mockata o usare un vincolo DB che fallisce al secondo record (es. due modelli con stesso unique slug, se disponibile — altrimenti forzare un'eccezione con un partial mock su `saveQuietly()`).

Usa modelli `EcPoi` con `'properties' => []` nel factory (come da nota CLAUDE.md — `AbstractObserver` fallisce senza).

**Commit:** `test(oc:8133): add feature tests for BulkEditAction handle()`

---

## Step 4 — Registra `BulkEditAction` in `App\Nova\EcPoi`

**File:** `app/Nova/EcPoi.php`
**Repo:** camminiditalia

Aggiungi nella lista `actions()`:

```php
use Wm\WmPackage\Nova\Actions\BulkEditAction;

// in actions():
(new BulkEditAction(\App\Nova\EcPoi::class, ['global']))
    ->canSee(fn () => $isAdmin)
    ->canRun(fn ($req, $model) => $isAdmin),
```

Posiziona la action dopo le esistenti, prima della chiusura dell'array.

**Commit:** `feat(oc:8133): register BulkEditAction on EcPoi for global bulk edit`

---

## Step 5 — Esegui i test

```bash
docker exec laravel-camminiditalia php artisan test --filter=BulkEditAction
```

Verifica che tutti i test passino. Se falliscono i test sulla Resource fittizia per problemi di namespace Nova, considerare l'uso di `EcPoi` direttamente come Resource nei test invece di una class anonima.

---

## Checklist

- [ ] Step 1: `BulkEditAction.php` creato
- [ ] Step 2: Unit test `fields()` verde
- [ ] Step 3: Feature test `handle()` verde
- [ ] Step 4: `EcPoi::actions()` in camminiditalia aggiornato
- [ ] Step 5: tutti i test passano

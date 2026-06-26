<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Contracts\ListableField;
use Laravel\Nova\Contracts\RelatableField;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\FieldMergeValue;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class BulkEditAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $onlyOnIndex = true;

    public function name(): string
    {
        return __('Bulk Edit');
    }

    public function __construct(
        protected string $novaResource,
        protected array $fields = [],
        protected array $exclude = ['name', 'geometry', 'description']
    ) {}

    public function fields(NovaRequest $request): array
    {
        $model = $this->novaResource::newModel();
        $resourceInstance = new $this->novaResource($model);

        $flat = [];
        foreach ($resourceInstance->fields($request) as $field) {
            if ($field instanceof FieldMergeValue) {
                foreach ($field->data as $nested) {
                    $flat[] = $nested;
                }
            } elseif (property_exists($field, 'originalFields') && is_array($field->originalFields)) {
                // Translatable containers (e.g. NovaTabTranslatable): expand original fields.
                // The container's fillInto() corrupts ActionFields via Fluent::__call — use the
                // unwrapped fields instead so forceFill(['description' => $v]) works normally.
                foreach ($field->originalFields as $originalField) {
                    $flat[] = $originalField;
                }
            } else {
                $flat[] = $field;
            }
        }

        $filtered = collect($flat)
            ->filter(fn ($field) => property_exists($field, 'attribute')
                && ! ($field instanceof ID)
                && ! ($field instanceof RelatableField)
                && ! ($field instanceof ListableField)
                && ! property_exists($field, 'data')
                && property_exists($field, 'showOnUpdate') && $field->showOnUpdate
                && ($field->readonlyCallback ?? null) !== true
            )
            ->map(function ($field) {
                // Boolean fields are converted to a tri-state Select so the modal can represent
                // "no change" (null) vs "set to true" ('1') vs "set to false" ('0').
                // A plain checkbox sends false when unchecked, making it impossible to distinguish
                // "user didn't touch it" from "user explicitly set it to false".
                if ($field instanceof Boolean) {
                    return Select::make($field->name, $field->attribute)
                        ->options(['1' => __('Yes'), '0' => __('No')])
                        ->nullable()
                        ->displayUsingLabels()
                        ->withMeta(['isBulkBoolean' => true]);
                }

                // Azzera readonly dinamico per evitare che il modello vuoto blocchi il campo
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

        if (! empty($this->exclude)) {
            $filtered = $filtered->filter(
                fn ($field) => ! in_array($field->attribute, $this->exclude)
            );
        }

        return $filtered->values()->all();
    }

    public function handle(ActionFields $fields, Collection $models): void
    {
        $changes = $this->resolveChanges($fields);

        if (empty($changes)) {
            return;
        }

        DB::transaction(function () use ($changes, $models) {
            foreach ($models as $model) {
                foreach ($changes as $attribute => $value) {
                    if (str_contains($attribute, '->')) {
                        [$column, $path] = explode('->', $attribute, 2);
                        $current = $model->{$column};
                        if (! is_array($current)) {
                            $current = json_decode($current ?? '[]', true) ?: [];
                        }
                        Arr::set($current, str_replace('->', '.', $path), $value);
                        $model->{$column} = $current;
                    } else {
                        $model->forceFill([$attribute => $value]);
                    }
                }
                $model->saveQuietly();
            }
        });
    }

    /**
     * Risolve i valori effettivamente compilati dal modale, indicizzati per attributo.
     *
     * Nova serializza i campi arrow notation (`properties->contact_email`) in un oggetto
     * annidato sotto `properties`, non come chiave letterale `properties->contact_email`.
     * Iterare direttamente le chiavi top-level di ActionFields tratterebbe `properties`
     * come un unico valore e sovrascriverebbe l'intero JSON. Risolviamo invece il valore
     * per ogni campo definito in fields() con data_get(), così funziona sia con la struttura
     * annidata reale di Nova sia con le chiavi flat usate nei test.
     *
     * @return array<string, mixed>
     */
    private function resolveChanges(ActionFields $fields): array
    {
        $definedFields = collect($this->fields(app(NovaRequest::class)));

        $booleanAttributes = $definedFields
            ->filter(fn ($f) => ($f->meta['isBulkBoolean'] ?? false))
            ->pluck('attribute')
            ->all();

        $raw = $fields->getAttributes();

        $changes = [];

        foreach ($definedFields as $field) {
            $attribute = $field->attribute;

            // Supporta sia la chiave letterale flat (es. 'properties->contact_email', usata
            // nei test e per gli attributi piatti) sia la struttura annidata reale di Nova
            // (es. ['properties' => ['contact_email' => ...]]).
            $value = array_key_exists($attribute, $raw)
                ? $raw[$attribute]
                : data_get($raw, str_replace('->', '.', $attribute));

            if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                continue;
            }

            $changes[$attribute] = in_array($attribute, $booleanAttributes, true)
                ? (bool) $value
                : $value;
        }

        return $changes;
    }
}

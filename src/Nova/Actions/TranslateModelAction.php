<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Jobs\TranslateModelJob;

class TranslateModelAction extends Action
{
    use InteractsWithQueue, Queueable;

    protected string $resourceClass;

    protected array $additionalFieldRules;

    protected array $targetLocales;

    public function __construct(
        string $resourceClass,
        array $additionalFieldRules = [],
        array $targetLocales = ['en', 'de', 'fr', 'es']
    ) {
        foreach (array_keys($additionalFieldRules) as $key) {
            if (in_array($key, ['name', 'description'], true)) {
                throw new InvalidArgumentException(
                    "additionalFieldRules non può contenere '{$key}': è già gestito come campo hardcoded."
                );
            }
        }

        $this->resourceClass = $resourceClass;
        $this->additionalFieldRules = $additionalFieldRules;
        $this->targetLocales = $targetLocales;

        $fieldNames = array_merge(['name', 'description'], array_keys($additionalFieldRules));
        $this->confirmText = __('Missing translations will be updated for the following fields: :fields', [
            'fields' => implode(', ', $fieldNames),
        ]);
    }

    public function name(): string
    {
        return __('Translate :label Contents', [
            'label' => $this->resourceClass::singularLabel(),
        ]);
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $dispatched = 0;
        $skipped = 0;

        foreach ($models as $model) {
            $properties = $model->properties ?? [];

            $missingLocales = $this->getMissingLocales($properties);

            if (empty($missingLocales)) {
                $skipped++;

                continue;
            }

            TranslateModelJob::dispatch($model, $missingLocales, $this->additionalFieldRules);
            $dispatched++;
        }

        if ($dispatched === 0) {
            return Action::message(__('No fields to translate (already translated or missing Italian source).'));
        }

        return Action::message(__(':dispatched translation jobs dispatched, :skipped models skipped.', [
            'dispatched' => $dispatched,
            'skipped' => $skipped,
        ]));
    }

    /**
     * Restituisce le lingue target che mancano in almeno uno dei campi traducibili
     * (i due hardcoded + gli eventuali campi extra abilitati per questa risorsa).
     * Il job verrà dispatched solo se c'è qualcosa da tradurre.
     */
    protected function getMissingLocales(array $properties): array
    {
        $missing = [];
        $fieldNames = array_merge(['name', 'description'], array_keys($this->additionalFieldRules));

        foreach ($fieldNames as $field) {
            $value = $properties[$field] ?? null;

            if (empty($value)) {
                continue;
            }

            if (is_string($value)) {
                $value = ['it' => $value];
            }

            if (! is_array($value) || empty($value['it'] ?? null)) {
                continue;
            }

            foreach ($this->targetLocales as $locale) {
                if (empty($value[$locale] ?? null)) {
                    $missing[] = $locale;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Get the fields available on the action.
     */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}

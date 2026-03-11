<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Jobs\TranslateModelJob;

class TranslateModelAction extends Action
{
    use InteractsWithQueue, Queueable;

    protected array $targetLocales;

    public function __construct(array $targetLocales = ['en', 'de', 'fr', 'es'])
    {
        $this->targetLocales = $targetLocales;
    }

    public function name(): string
    {
        return __('Translate Descriptions & Names');
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

            TranslateModelJob::dispatch($model, $missingLocales);
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
     * Restituisce le lingue target che mancano in almeno uno dei campi traducibili.
     * Il job verrà dispatched solo se c'è qualcosa da tradurre.
     */
    protected function getMissingLocales(array $properties): array
    {
        $missing = [];

        foreach (['description', 'name'] as $field) {
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

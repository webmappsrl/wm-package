<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\MultiSelect;
use Laravel\Nova\Http\Requests\NovaRequest;
use Throwable;
use Wm\WmPackage\Jobs\Print\GeneratePrintPdfJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Print\PrintService;

/**
 * Accoda la generazione della scheda PDF degli elementi selezionati, nelle lingue scelte (oc:8982).
 *
 * Per ogni elemento e lingua parte un `GeneratePrintPdfJob`, unico per modello e lingua: una seconda
 * richiesta mentre la prima è in coda non ne aggiunge un'altra.
 */
class GeneratePrintPdfAction extends Action
{
    public const DEFAULT_LOCALE = 'it';

    public function __construct()
    {
        // Solo sugli elementi di un'App con la stampa accesa (`properties.ec_pdf_enabled`).
        $this->canRun(fn ($request, $model) => $model instanceof Model && PrintService::enabledFor($model));
    }

    public function name()
    {
        return __('Generate print PDF');
    }

    /**
     * Il campo delle lingue: quelle dell'app se gli elementi selezionati appartengono a una sola app,
     * altrimenti l'elenco di configurazione.
     *
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            MultiSelect::make(__('Languages'), 'languages')
                ->options($this->languageOptions($this->selectedModels($request)))
                ->default(function () use ($request) {
                    $codes = array_keys($this->languageOptions($this->selectedModels($request)));

                    return in_array(self::DEFAULT_LOCALE, $codes, true) ? [self::DEFAULT_LOCALE] : array_slice($codes, 0, 1);
                })
                // Nella richiesta il MultiSelect è una stringa JSON, decodificata solo nel fill: una
                // regola `array` sul valore grezzo fallirebbe sempre.
                ->rules('required', 'json', function (string $attribute, mixed $value, \Closure $fail) {
                    $decoded = is_string($value) ? json_decode($value, true) : $value;
                    if (! is_array($decoded) || $decoded === []) {
                        $fail(__('validation.required', ['attribute' => __('Languages')]));
                    }
                }),
        ];
    }

    /**
     * Le lingue offerte, nella forma codice => etichetta, per i modelli indicati.
     *
     * @param  Collection<int, Model>  $models
     * @return array<string, string>
     */
    public function languageOptions(Collection $models): array
    {
        $configured = (array) config('wm-app-languages.languages', []);

        $appIds = $models->map(fn (Model $model) => $model->getAttribute('app_id'))->unique()->values();

        if ($appIds->count() === 1 && $appIds->first() !== null) {
            $app = App::find($appIds->first());
            $available = $app?->available_languages;
            $available = is_string($available) ? json_decode($available, true) : $available;

            if (is_array($available) && $available !== []) {
                $restricted = array_intersect_key($configured, array_flip($available));
                // Intersezione vuota: si ricade sull'elenco di configurazione, il campo è obbligatorio.
                $configured = $restricted !== [] ? $restricted : $configured;
            }
        }

        return array_filter(
            $configured,
            fn ($label, $code) => preg_match(PrintService::LOCALE_PATTERN, (string) $code) === 1,
            ARRAY_FILTER_USE_BOTH
        );
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $languages = collect($fields->get('languages') ?? [self::DEFAULT_LOCALE])
            ->filter(fn ($locale) => is_string($locale) && preg_match(PrintService::LOCALE_PATTERN, $locale) === 1)
            ->unique()
            ->values();

        // Nova chiama handle() una volta per chunk e il client riceve l'ultima risposta: il messaggio
        // non riporta quindi un conteggio, che sarebbe quello dell'ultimo chunk.
        foreach ($models as $model) {
            // canRun() filtra già la selezione; il controllo resta anche in dispatchFor().
            if (! PrintService::enabledFor($model)) {
                continue;
            }
            // Le opzioni del campo sono solo lato client: si ammettono le lingue consentite per l'app del modello.
            $allowed = array_keys($this->languageOptions(collect([$model])));

            foreach ($languages as $locale) {
                if (in_array($locale, $allowed, true)) {
                    GeneratePrintPdfJob::dispatchFor($model, $locale);
                }
            }
        }

        return Action::message(__('PDF generations queued'));
    }

    /**
     * I modelli selezionati, per risalire alla loro app. Vuoto se non si possono risolvere
     * (azione standalone, selezione «tutti i risultati» non risolvibile).
     *
     * @return Collection<int, Model>
     */
    protected function selectedModels(NovaRequest $request): Collection
    {
        try {
            $ids = $request->selectedResourceIds();
            $resource = $request->resource();

            if (! $ids instanceof Collection || $ids->isEmpty()) {
                return collect();
            }

            return $resource::$model::query()->whereKey($ids)->get(['id', 'app_id']);
        } catch (Throwable) {
            return collect();
        }
    }
}

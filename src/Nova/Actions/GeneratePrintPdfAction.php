<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Throwable;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Print\PrintService;

/**
 * Scarica la scheda PDF degli elementi selezionati, in una lingua (oc:8982).
 *
 * Con un elemento apre in una nuova scheda la pagina della scheda (`print.show`), con più elementi
 * quella dello ZIP (`print.zip`, al massimo `zip.max_tracks`). Le due pagine generano ciò che manca,
 * mostrano l'attesa e poi portano al file: l'action non accoda nulla da sé. Una lingua sola, perché
 * ogni esecuzione apre una sola pagina.
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
        return __('Download print PDF');
    }

    /**
     * Il campo della lingua: quelle dell'app se gli elementi selezionati appartengono a una sola app,
     * altrimenti l'elenco di configurazione. Di default la lingua di default dell'app.
     *
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        $models = $this->selectedModels($request);
        $options = $this->languageOptions($models);

        return [
            Select::make(__('Language'), 'language')
                ->options($options)
                ->default(fn () => $this->defaultLocale($models, array_keys($options)))
                ->rules('required', Rule::in(array_keys($options))),
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

        $app = $this->singleApp($models);
        $available = $app?->available_languages;
        $available = is_string($available) ? json_decode($available, true) : $available;

        if (is_array($available) && $available !== []) {
            $restricted = array_intersect_key($configured, array_flip($available));
            // Intersezione vuota: si ricade sull'elenco di configurazione, il campo è obbligatorio.
            $configured = $restricted !== [] ? $restricted : $configured;
        }

        return array_filter(
            $configured,
            fn ($label, $code) => preg_match(PrintService::LOCALE_PATTERN, (string) $code) === 1,
            ARRAY_FILTER_USE_BOTH
        );
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $locale = (string) ($fields->get('language') ?? self::DEFAULT_LOCALE);

        // canRun() filtra già la selezione; le lingue del campo sono solo lato client.
        $printable = $models
            ->filter(fn ($model) => $model instanceof Model && PrintService::enabledFor($model))
            ->filter(fn (Model $model) => array_key_exists($locale, $this->languageOptions(collect([$model]))))
            ->values();

        if ($printable->isEmpty()) {
            return Action::danger(__('No selected element can be printed in this language.'));
        }

        $max = (int) config('wm-package.print.zip.max_tracks', 50);
        if ($printable->count() > $max) {
            return Action::danger(__('Too many elements selected: the maximum is :max.', ['max' => $max]));
        }

        $type = PrintService::typeOf($printable->first());

        if ($printable->count() === 1) {
            return Action::openInNewTab(route('print.show', ['type' => $type, 'id' => $printable->first()->getKey(), 'lang' => $locale]));
        }

        return Action::openInNewTab(route('print.zip', [
            'ids' => $printable->map(fn (Model $model) => (int) $model->getKey())->sort()->values()->all(),
            'lang' => $locale,
            'type' => $type,
        ]));
    }

    /**
     * La lingua proposta: quella di default dell'app se è fra le opzioni, poi l'italiano, poi la prima.
     *
     * @param  Collection<int, Model>  $models
     * @param  list<string>  $codes
     */
    protected function defaultLocale(Collection $models, array $codes): ?string
    {
        $default = (string) ($this->singleApp($models)->default_language ?? '');

        return match (true) {
            in_array($default, $codes, true) => $default,
            in_array(self::DEFAULT_LOCALE, $codes, true) => self::DEFAULT_LOCALE,
            default => $codes[0] ?? null,
        };
    }

    /** @param  Collection<int, Model>  $models */
    protected function singleApp(Collection $models): ?App
    {
        $appIds = $models->map(fn (Model $model) => $model->getAttribute('app_id'))->unique()->values();

        return $appIds->count() === 1 && $appIds->first() !== null ? App::find($appIds->first()) : null;
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

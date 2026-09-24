<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;

/**
 * Inverte il verso di percorrenza di una traccia: l'utente sceglie se invertire la geometria e
 * quali coppie di dati che dipendono dal verso scambiare. Raccoglie le scelte e chiama
 * EcTrackService::reverse(), che fa l'intera operazione e accoda il ricalcolo (oc:8543).
 */
class ReverseTrackDirectionAction extends Action
{
    use InteractsWithQueue, Queueable;

    public function name()
    {
        return __('Reverse Track Direction');
    }

    /**
     * Un flag per la geometria, sempre presente, e uno per ogni coppia valorizzata sulla traccia
     * selezionata. selectedResources() vale sia all'apertura della finestra sia all'esecuzione
     * (ActionRequest usa lo stesso trait InteractsWithResourcesSelection).
     */
    public function fields(NovaRequest $request): array
    {
        $fields = [
            Boolean::make(__('Reverse geometry'), 'reverse_geometry')->default(true),
        ];

        $track = $request->selectedResources()?->first();
        if (! $track instanceof EcTrack) {
            return $fields;
        }

        foreach (app(EcTrackService::class)->directionPairs($track) as $key => [$firstValue, $secondValue]) {
            [, , , $firstLabel, $secondLabel] = EcTrackService::REVERSE_SWAP_PAIRS[$key];
            $fields[] = Boolean::make(
                __('Swap :first / :second', ['first' => __($firstLabel), 'second' => __($secondLabel)]),
                'swap_'.$key
            )
                ->default(false)
                // Nova rende l'help come HTML (v-html): i valori arrivano dal DB e vanno sottoposti a escape.
                ->help(e(__($firstLabel)).': '.e($this->display($firstValue)).' — '.e(__($secondLabel)).': '.e($this->display($secondValue)));
        }

        return $fields;
    }

    /**
     * ->sole() in EcTrack::actions() vale solo lato UI: il server passerebbe comunque a handle()
     * tutte le risorse di una richiesta diretta, quindi si itera su $models (oc:8543).
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(EcTrackService::class);
        $geometry = (bool) $fields->get('reverse_geometry');
        $swaps = array_values(array_filter(
            array_keys(EcTrackService::REVERSE_SWAP_PAIRS),
            fn ($key) => (bool) $fields->get('swap_'.$key)
        ));

        if (! $geometry && $swaps === []) {
            return Action::danger(__('Select at least one operation.'));
        }

        foreach ($models as $track) {
            if ($service->isOsmTrack($track)) {
                return Action::danger(__('This track comes from OpenStreetMap: its direction must be corrected on OpenStreetMap.'));
            }
        }

        $swappedLabels = [];
        foreach ($models as $track) {
            foreach ($service->reverse($track, $geometry, $swaps) as $key) {
                [, , , $firstLabel, $secondLabel] = EcTrackService::REVERSE_SWAP_PAIRS[$key];
                $swappedLabels[$key] = __($firstLabel).' / '.__($secondLabel);
            }
        }

        return Action::message(__('Geometry reversed: :geometry. Swapped: :pairs. Recalculation in progress.', [
            'geometry' => $geometry ? __('Yes') : __('No'),
            'pairs' => $swappedLabels === [] ? __('None') : implode(', ', $swappedLabels),
        ]));
    }

    private function display(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}

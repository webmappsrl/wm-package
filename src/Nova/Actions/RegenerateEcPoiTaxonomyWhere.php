<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;

class RegenerateEcPoiTaxonomyWhere extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * Nome visualizzato dell'action.
     */
    public function name()
    {
        return __('Regenerate Taxonomy Where (Poi)');
    }

    /**
     * Esegue il job che ricalcola le taxonomy_where da Osmfeatures
     * per tutti gli EcPoi selezionati.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $count = 0;

        foreach ($models as $poi) {
            dispatch(new UpdateModelWithGeometryTaxonomyWhere($poi))
                ->onQueue('geometric-computations');
            $count++;
        }

        return Action::message(
            __(':count Poi enqueued for taxonomy where regeneration!', ['count' => $count])
        );
    }

    /**
     * Nessun campo aggiuntivo richiesto per l'action.
     */
    public function fields($request)
    {
        return [];
    }
}


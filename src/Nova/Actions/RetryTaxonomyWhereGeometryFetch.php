<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchOsm2caiSectorGeometryJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchTaxonomyWhereGeometryJob;
use Wm\WmPackage\Models\TaxonomyWhere;

class RetryTaxonomyWhereGeometryFetch extends Action
{
    use InteractsWithQueue, Queueable;

    public function name()
    {
        return __('Ricarica Geometry');
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
            if (! $this->dispatchGeometryJob($model)) {
                $skipped++;

                continue;
            }

            $dispatched++;
        }

        $message = 'Job di recupero geometry rilanciati per '.$dispatched.' record.';

        if ($skipped > 0) {
            $message .= ' '.__(':count records skipped: unknown source.', ['count' => $skipped]);
        }

        return Action::message($message);
    }

    /**
     * Il job da rilanciare dipende dalla sorgente del record: i settori OSM2CAI
     * non hanno osmfeatures_id, quindi il job OSMFeatures uscirebbe senza
     * scrivere nulla.
     */
    private function dispatchGeometryJob(TaxonomyWhere $taxonomyWhere): bool
    {
        $source = $taxonomyWhere->properties['source'] ?? null;

        if ($source === 'osm2cai') {
            FetchOsm2caiSectorGeometryJob::dispatch($taxonomyWhere->id);

            return true;
        }

        if ($source === 'osmfeatures' || ! empty($taxonomyWhere->getOsmfeaturesId())) {
            FetchTaxonomyWhereGeometryJob::dispatch($taxonomyWhere->id);

            return true;
        }

        return false;
    }

    /**
     * Get the fields available on the action.
     *
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        return [];
    }
}

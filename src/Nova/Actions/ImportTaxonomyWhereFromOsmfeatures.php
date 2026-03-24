<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchTaxonomyWhereGeometryJob;
use Wm\WmPackage\Models\TaxonomyWhere;

class ImportTaxonomyWhereFromOsmfeatures extends Action
{
    use InteractsWithQueue, Queueable;

    public function name()
    {
        return __('Import TaxonomyWhere da OSMFeatures');
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $adminLevel = (int) $fields->get('admin_level');
        $client = app(OsmfeaturesClient::class);
        $count = 0;

        foreach ($models as $app) {
            $bbox = $app->map_bbox;
            if (empty($bbox)) {
                continue;
            }

            $items = $client->getAdminAreasIds($bbox, $adminLevel);

            foreach ($items as $item) {
                $taxonomyWhere = TaxonomyWhere::updateOrCreate(
                    ['osmfeatures_id' => $item['id']],
                    [
                        'name'        => $item['name'],
                        'admin_level' => $adminLevel,
                    ]
                );

                FetchTaxonomyWhereGeometryJob::dispatch($taxonomyWhere->id);
                $count++;
            }
        }

        return Action::message("Creati/aggiornati {$count} record TaxonomyWhere. Geometrie in download in background.");
    }

    /**
     * Get the fields available on the action.
     *
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        return [
            Select::make('Admin Level', 'admin_level')
                ->options([
                    4  => 'Regione',
                    6  => 'Provincia',
                    8  => 'Comune',
                    9  => 'Municipio',
                    10 => 'Quartiere',
                ])
                ->rules('required'),
        ];
    }
}

<?php

namespace Wm\WmPackage\Nova\Actions;

use Exception;
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
use Wm\WmPackage\Services\GeometryComputationService;

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
        if ($models->isEmpty()) {
            return Action::danger("Seleziona almeno un'App su cui eseguire l'import.");
        }

        $adminLevel = (int) $fields->get('admin_level');
        $client = app(OsmfeaturesClient::class);
        $count = 0;
        $skippedNoBbox = [];
        $emptyListForApps = [];

        foreach ($models as $app) {
            $bbox = $app->map_bbox;
            if (empty($bbox)) {
                $computed = GeometryComputationService::make()->getEcTracksBboxByAppId($app->id);
                if ($computed !== null) {
                    $bbox = json_encode($computed);
                }
            }

            if (empty($bbox)) {
                $skippedNoBbox[] = $app->name ?? '#' . $app->id;
                continue;
            }

            try {
                $items = $client->getAdminAreasIds($bbox, $adminLevel);
            } catch (Exception $e) {
                return Action::danger(
                    'Errore chiamata OSMFeatures (app: ' . ($app->name ?? '#' . $app->id) . '): ' . $e->getMessage()
                );
            }

            if (count($items) === 0) {
                $emptyListForApps[] = $app->name ?? '#' . $app->id;
            }

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

        $parts = [];
        if ($skippedNoBbox !== []) {
            $parts[] = 'App senza bbox utilizzabile (map_bbox vuoto e nessuna geometria dalle track per calcolarlo): ' . implode(', ', $skippedNoBbox);
        }
        if ($emptyListForApps !== []) {
            $parts[] = 'OSMFeatures ha restituito 0 aree per: ' . implode(', ', $emptyListForApps) . ' (verifica bbox e admin level).';
        }

        if ($count === 0) {
            $detail = $parts !== [] ? ' ' . implode(' ', $parts) : '';

            return Action::danger('Nessun TaxonomyWhere importato.' . $detail);
        }

        $suffix = $parts !== [] ? ' (' . implode(' ', $parts) . ')' : '';

        return Action::message("Creati/aggiornati {$count} record TaxonomyWhere. Geometrie in download in background." . $suffix);
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

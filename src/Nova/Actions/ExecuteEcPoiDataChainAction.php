<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Services\Models\EcPoiService;

class ExecuteEcPoiDataChainAction extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * Esegue la updateDataChain per tutti gli EcPoi selezionati.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $ecPoiService = app(EcPoiService::class);
        $processedCount = 0;

        foreach ($models as $poi) {
            try {
                $ecPoiService->updateDataChain($poi);
                $processedCount++;
            } catch (\Exception $e) {
                Log::error('Failed to process EcPoi', [
                    'poi_id' => $poi->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return Action::message(__(':count Poi processed!', ['count' => $processedCount]));
    }

    /**
     * Nome visualizzato dell'action.
     */
    public function name()
    {
        return __('Execute EcPoi Data Chain');
    }
}

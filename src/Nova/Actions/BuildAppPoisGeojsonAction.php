<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class BuildAppPoisGeojsonAction extends Action
{
    public function name(): string
    {
        return __('Regenerate pois.geojson');
    }

    public function authorizedToRun(Request $request, $model): bool
    {
        return RolesAndPermissionsService::allows($request);
    }

    /**
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $processedCount = 0;
        $errors = [];

        foreach ($models as $app) {
            if (! $app instanceof App) {
                continue;
            }

            $exitCode = Artisan::call('wm:build-pois-geojson', ['app_id' => $app->id]);

            if ($exitCode !== 0) {
                $output = trim(Artisan::output());
                $errors[] = $output !== ''
                    ? $output
                    : __('Command failed for app :name (ID: :id)', ['name' => $app->name, 'id' => $app->id]);

                continue;
            }

            $processedCount++;
        }

        if ($processedCount === 0 && $errors === []) {
            return Action::danger(__('No app selected.'));
        }

        $message = __('pois.geojson generation job queued for :count app.', ['count' => $processedCount]);

        if ($errors !== []) {
            return Action::danger($message.' '.__('Errors').': '.implode('; ', $errors));
        }

        return Action::message($message);
    }
}

<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Abstracts\MultiLineString;

class ExportTracksFeatureCollectionAction extends Action
{
    /**
     * Avoid writing one action log row per selected track on large selections.
     *
     * @var bool
     */
    public $withoutActionEvents = true;

    public function name()
    {
        return __('Export tracks as GeoJSON');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $models = $models->filter(fn ($m) => $m instanceof MultiLineString);
        if ($models->isEmpty()) {
            return Action::danger(__('No track models in the selection.'));
        }

        $appIds = $models->pluck('app_id')->filter()->unique();
        if ($appIds->count() > 1) {
            return Action::danger(__('All selected tracks must belong to the same app.'));
        }

        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];

        foreach ($models as $track) {
            $feature = $track->getGeojson();
            if (is_array($feature)) {
                $featureCollection['features'][] = $feature;
            }
        }

        if ($featureCollection['features'] === []) {
            return Action::danger(__('No valid geometry found for the selected tracks.'));
        }

        $fileName = sprintf(
            'tracks_export_%s.geojson',
            now()->format('Y-m-d'),
        );

        Storage::disk('public')->put(
            $fileName,
            json_encode($featureCollection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        $signedUrl = URL::temporarySignedRoute(
            'download.export',
            now()->addMinutes(5),
            ['fileName' => $fileName]
        );

        return Action::redirect($signedUrl);
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}

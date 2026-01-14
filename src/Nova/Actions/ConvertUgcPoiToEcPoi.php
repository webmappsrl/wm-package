<?php

namespace Wm\WmPackage\Nova\Actions;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcPoi;

class ConvertUgcPoiToEcPoi extends Action
{
    use InteractsWithQueue, Queueable;

    public function name()
    {
        return __('Convert To EcPoi');
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $already_existing_ec_pois = [];
        foreach ($models as $model) {
            $properties = $model->properties;
            $alreadyExists = isset($properties['ec_poi_id']) && ! empty($properties['ec_poi_id']);
            // $shareUgcPoi = isset($properties['share_ugcpoi']) && $properties['share_ugcpoi'] === 'yes';

            if ($alreadyExists) {
                $already_existing_ec_pois[] = $model->id;

                continue;
            }

            $ecPoi = EcPoi::create([
                'name' => $model->name,
                'geometry' => $model->geometry,
                'user_id' => auth()->user()->id,
                'app_id' => $model->app_id,
            ]);

            if ($ecPoi) {

                // Attach Medias
                $medias = $model->media;
                if (count($medias) > 0) {
                    foreach ($medias as $media) {
                        try {
                            $duplicatedMedia = $media->replicate();
                            $duplicatedMedia->model()->associate($ecPoi);
                            $duplicatedMedia->save();
                            Log::info('Media copied successfully: '.$media->id.' from UgcPoi '.$model->id.' to EcPoi '.$ecPoi->id);
                        } catch (Exception $e) {
                            Log::error('ConvertUgcPoiToEcPoi: media copy error -> '.$e->getMessage());
                        }
                    }
                }

                $properties = $model->properties;
                $properties['ec_poi_id'] = $ecPoi->id;
                $model->properties = $properties;
                $model->saveQuietly();
            }
        }

        if (count($already_existing_ec_pois) > 0) {
            return Action::message('Conversion completed successfully! The following UgcPois already have an associated EcPoi: '.implode(', ', $already_existing_ec_pois));
        }

        return Action::message('Conversion completed successfully');
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}

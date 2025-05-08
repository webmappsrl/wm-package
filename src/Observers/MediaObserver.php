<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Services\GeometryComputationService;

class MediaObserver extends AbstractAuthorableObserver
{
    public function saving($media)
    {
        // OVERRIDE AbstractObserver saving method
        // Do not set name in properties
    }

    /**
     * Handles the "creating" event of the Media model
     *
     * @return void
     */
    public function creating(Model $media)
    {
        try {
            // Sets app_id and geometry if needed
            $this->setAppIdAndGeometry($media);
        } catch (\Exception $e) {
            $this->handleException($e, $media);
        }
    }

    public function created(Model $media)
    {
        parent::created($media);
        $this->removeGeometryFromCustomProperties($media);
    }

    /**
     * Sets app_id and geometry for the media
     *
     * @return void
     */
    private function setAppIdAndGeometry(Media $media)
    {
        // If app_id is missing, we need to find the related model
        if (! $media->app_id) {
            try {
                $model = $media->model;

                if (! $model) {
                    Log::warning('MediaObserver-creating: Related model not found');
                    $this->setDefaultValues($media);

                    return;
                }

                if ($model instanceof App) {
                    $media->app_id = $model->id;
                } elseif (! $this->validateModelHasAppId($model)) {
                    return;
                }

                // Sets app_id from parent model
                if (is_null($media->app_id)) {
                    $media->app_id = $model->app_id;
                }

                if (isset($media->custom_properties['geometry']) && $media->custom_properties['geometry'] !== null) {
                    $media->geometry = $media->custom_properties['geometry'];
                    $properties = $media->custom_properties;
                    unset($properties['geometry']);
                    $media->custom_properties = $properties;

                    return;
                }

                // Sets geometry based on model geometry if not found in custom properties
                $this->setGeometryFromModel($media, $model);
            } catch (\Exception $e) {
                $this->handleException($e, $media);
            }
        } elseif (! $media->geometry) {
            try {
                // If only geometry is missing, set default value
                $this->setDefaultGeometry($media);
            } catch (\Exception $e) {
                $this->handleException($e, $media);
            }
        }
    }

    /**
     * Verifies that the model has app_id
     *
     * @return bool
     */
    private function validateModelHasAppId(Model $model)
    {
        try {
            if (! isset($model->app_id)) {
                Log::warning('MediaObserver-creating: app_id missing in parent model');
                $this->setDefaultValues($model);

                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->handleException($e, $model);

            return false;
        }
    }

    /**
     * Sets geometry based on related model
     *
     * @return void
     */
    private function setGeometryFromModel(Media $media, Model $model)
    {
        try {
            // Utilizziamo il servizio GeometryComputationService per gestire qualsiasi tipo di geometria
            $geometryService = new GeometryComputationService;

            if (str_contains($model::class, 'Layer') && $model->bbox) {
                $media->geometry = $geometryService->convertToPoint($model, 'bbox');
            } elseif ($model->geometry) {
                $media->geometry = $geometryService->convertToPoint($model);
            } else {
                $this->setDefaultGeometry($media);
            }
        } catch (\Exception $e) {
            $this->handleException($e, $media);
            $this->setDefaultGeometry($media);
        }
    }

    /**
     * Handles exceptions during media creation
     *
     * @return void
     */
    private function handleException(\Exception $e, Media $media)
    {
        Log::error('Error in MediaObserver-creating: '.$e->getMessage());
        Log::error($e->getTraceAsString());
        // In case of error, set default values to avoid crashes
        $this->setDefaultValues($media);
    }

    /**
     * Sets default values for app_id and geometry
     *
     * @return void
     */
    private function setDefaultValues(Media $media)
    {
        try {
            // Set default app_id (1 is usually the main app ID)
            if (! $media->app_id) {
                $media->app_id = 1;
            }

            $this->setDefaultGeometry($media);
        } catch (\Exception $e) {
            Log::error('Error setting default values: '.$e->getMessage());
            // Last resort fallback
            $media->app_id = 1;
            $media->geometry = 'POINT(10.4018624 43.7159395)';
        }
    }

    /**
     * Sets a default geometry (a point in Pisa)
     *
     * @return void
     */
    private function setDefaultGeometry(Media $media)
    {
        try {
            // Default point (Pisa, Italy)
            $media->geometry = 'POINTZ(10.4018624 43.7159395 0)';
        } catch (\Exception $e) {
            Log::error('Error setting default geometry: '.$e->getMessage());
        }
    }

    private function removeGeometryFromCustomProperties(Media $media)
    {
        $customProperties = $media->custom_properties;
        unset($customProperties['geometry']);
        $media->update([
            'custom_properties' => $customProperties,
        ]);
    }
}

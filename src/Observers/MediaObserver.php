<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;

class MediaObserver extends AbstractAuthorableObserver
{
    /**
     * Handles the "creating" event of the Media model
     *
     * @param Model $media
     * @return void
     */
    public function creating(Model $media)
    {
        try {
            // Executes the AbstractAuthorableObserver logic to set the author
            parent::creating($media);

            // Sets app_id and geometry if needed
            $this->setAppIdAndGeometry($media);
        } catch (\Exception $e) {
            $this->handleException($e, $media);
        }
    }

    /**
     * Sets app_id and geometry for the media
     *
     * @param Media $media
     * @return void
     */
    private function setAppIdAndGeometry(Media $media)
    {
        // If app_id is missing but we have model_id and model_type
        if (!$media->app_id && $media->model_id && $media->model_type) {
            try {
                $model = $this->findRelatedModel($media);

                if (!$model) {
                    return;
                }

                if (!$this->validateModelHasAppId($model)) {
                    return;
                }

                // Sets app_id from parent model
                $media->app_id = $model->app_id;

                // Sets geometry based on model type
                $this->setGeometryFromModel($media, $model);
            } catch (\Exception $e) {
                $this->handleException($e, $media);
            }
        } elseif (!$media->geometry) {
            try {
                // If only geometry is missing, set default value
                $this->setDefaultGeometry($media);
            } catch (\Exception $e) {
                $this->handleException($e, $media);
            }
        }
    }

    /**
     * Finds the model related to the media
     *
     * @param Media $media
     * @return Model|null
     */
    private function findRelatedModel(Media $media)
    {
        try {
            $modelId = $media->model_id;
            $modelClass = $media->model_type;
            $model = null;

            // Checks if the model is UgcTrack (from package or app)
            if ($modelClass === 'App\\Models\\UgcTrack' || $modelClass === UgcTrack::class) {
                $model = UgcTrack::find($modelId);
                Log::info("MediaObserver-creating: Found UgcTrack from package");
            }
            // Checks if the model is UgcPoi (from package or app)
            elseif ($modelClass === 'App\\Models\\UgcPoi' || $modelClass === UgcPoi::class) {
                $model = UgcPoi::find($modelId);
                Log::info("MediaObserver-creating: Found UgcPoi from package");
            } else {
                // Tries to load the original model
                if (class_exists($modelClass)) {
                    $model = $modelClass::find($modelId);
                    Log::info("MediaObserver-creating: Using original class {$modelClass}");
                } else {
                    Log::warning("MediaObserver-creating: Class {$modelClass} does not exist");
                    $this->setDefaultValues($media);
                    return null;
                }
            }

            if (!$model) {
                Log::warning("MediaObserver-creating: Model with ID {$modelId} not found");
                $this->setDefaultValues($media);
                return null;
            }

            return $model;
        } catch (\Exception $e) {
            $this->handleException($e, $media);
            return null;
        }
    }

    /**
     * Verifies that the model has app_id
     *
     * @param Model $model
     * @return bool
     */
    private function validateModelHasAppId(Model $model)
    {
        try {
            if (!isset($model->app_id)) {
                Log::warning("MediaObserver-creating: app_id missing in parent model");
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
     * @param Media $media
     * @param Model $model
     * @return void
     */
    private function setGeometryFromModel(Media $media, Model $model)
    {
        try {
            if ($model instanceof UgcPoi && $model->geometry) {
                // For UgcPoi, directly copy the geometry
                $media->geometry = $model->geometry;
                Log::info("Media: geometry copied from UgcPoi {$model->id}");
            } elseif ($model instanceof UgcTrack) {
                $this->setGeometryFromTrack($media, $model);
            } else {
                // For other models, set default value
                $this->setDefaultGeometry($media);
            }
        } catch (\Exception $e) {
            $this->handleException($e, $media);
            $this->setDefaultGeometry($media);
        }
    }

    /**
     * Sets geometry from a UgcTrack model
     *
     * @param Media $media
     * @param UgcTrack $track
     * @return void
     */
    private function setGeometryFromTrack(Media $media, UgcTrack $track)
    {
        try {
            // For UgcTrack, calculate the centroid of the multilinestring
            $centroid = DB::selectOne(
                "SELECT ST_AsText(ST_Centroid(geometry)) as centroid FROM ugc_tracks WHERE id = ?",
                [$track->id]
            );

            if ($centroid && $centroid->centroid) {
                $media->geometry = $centroid->centroid;
                Log::info("Media: geometry calculated from UgcTrack {$track->id}");
            } else {
                // If centroid calculation is not possible, set default value
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
     * @param \Exception $e
     * @param Media $media
     * @return void
     */
    private function handleException(\Exception $e, Media $media)
    {
        Log::error("Error in MediaObserver-creating: " . $e->getMessage());
        Log::error($e->getTraceAsString());
        // In case of error, set default values to avoid crashes
        $this->setDefaultValues($media);
    }

    /**
     * Sets default values for app_id and geometry
     * 
     * @param Media $media
     * @return void
     */
    private function setDefaultValues(Media $media)
    {
        try {
            // Set default app_id (1 is usually the main app ID)
            if (!$media->app_id) {
                $media->app_id = 1;
            }

            $this->setDefaultGeometry($media);
        } catch (\Exception $e) {
            Log::error("Error setting default values: " . $e->getMessage());
            // Last resort fallback
            $media->app_id = 1;
            $media->geometry = 'POINT(10.4018624 43.7159395)';
        }
    }

    /**
     * Sets a default geometry (a point in Pisa)
     * 
     * @param Media $media
     * @return void
     */
    private function setDefaultGeometry(Media $media)
    {
        try {
            // Default point (Pisa, Italy)
            $media->geometry = 'POINT(10.4018624 43.7159395)';
        } catch (\Exception $e) {
            Log::error("Error setting default geometry: " . $e->getMessage());
        }
    }
}

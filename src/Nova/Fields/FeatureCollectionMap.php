<?php

namespace Wm\WmPackage\Nova\Fields;

use Laravel\Nova\Fields\Text;

class FeatureCollectionMap extends Text
{
    protected $modelType;

    /**
     * Create a new field.
     *
     * @param  string  $name
     * @param  string|callable|null  $attribute
     * @return void
     */
    public function __construct($name, $attribute = null, ?callable $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);

        // Configura automaticamente il field
        $this->configureField();
    }

    /**
     * Set the model type for hiking routes.
     *
     * @return $this
     */
    public function forHikingRoute()
    {
        $this->modelType = 'hiking-route';

        return $this;
    }

    /**
     * Set the model type for poles.
     *
     * @return $this
     */
    public function forPoles()
    {
        $this->modelType = 'poles';

        return $this;
    }

    /**
     * Configure the field with default settings.
     *
     * @return $this
     */
    protected function configureField()
    {
        return $this
            ->resolveUsing(function ($value, $resource) {
                // Se non è specificato un modelType, prova a inferirlo dal nome della risorsa
                if (! $this->modelType) {
                    $this->modelType = $this->inferModelType(get_class($resource));
                }

                // Genera l'URL per il GeoJSON dinamico basato sull'ID del record
                $geojsonUrl = url("/widget/feature-collection-map-url/{$this->modelType}/{$resource->id}");

                return <<<HTML
                        <div style="min-height: 400px; position: relative;background: white;">
                            <iframe 
                                src="/widget/feature-collection-map?geojson={$geojsonUrl}"
                                style="width: 100%; height: 500px; border: none; border-radius: 4px;"
                                frameborder="0"
                                allowfullscreen>
                            </iframe>
                        </div>
                    HTML;
            })
            ->asHtml()
            ->onlyOnDetail();
    }

    /**
     * Infer the model type from the resource class name.
     */
    protected function inferModelType($resourceClass): string
    {
        $modelName = class_basename($resourceClass);

        return match (true) {
            str_contains($modelName, 'HikingRoute') => 'hiking-route',
            str_contains($modelName, 'Poles') => 'poles',
            default => 'hiking-route', // default fallback
        };
    }
}

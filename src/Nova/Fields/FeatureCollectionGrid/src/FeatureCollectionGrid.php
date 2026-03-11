<?php

namespace Wm\WmPackage\Nova\Fields\FeatureCollectionGrid;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\Text;

class FeatureCollectionGrid extends Text
{
    /**
     * Method or callback to generate GeoJSON FeatureCollection
     *
     * @var string|callable|null
     */
    protected $geojsonSource = null;

    /**
     * Relations to sync after selection (array of relation names)
     *
     * @var array
     */
    protected $relationsToSync = [];

    /**
     * Create a new field.
     *
     * @param  string  $name
     * @param  string|callable|null  $attribute  Method name or callback that returns GeoJSON FeatureCollection
     * @return void
     */
    public function __construct($name, $attribute = null, ?callable $resolveCallback = null)
    {
        // Always pass null as attribute since we use resolveUsing
        parent::__construct($name, null, $resolveCallback);

        // Set as HTML and only on detail
        $this->asHtml();
        $this->onlyOnDetail();

        // Set resolve callback to generate map HTML
        $this->resolveUsing(function ($value, $resource) {
            Log::info('FeatureCollectionGrid resolve called for resource ID: '.$resource->id);

            return $this->generateMapHtml($resource);
        })->asHtml();

        // Hide when creating
        $this->hideWhenCreating();
    }

    /**
     * Set the method or callback to generate GeoJSON
     *
     * @param  string|callable  $source
     * @return $this
     */
    public function geojsonSource($source)
    {
        $this->geojsonSource = $source;

        return $this;
    }

    /**
     * Set relations to sync after feature selection
     *
     * @param  array  $relations  Array of relation names to sync
     * @return $this
     */
    public function syncRelations(array $relations)
    {
        $this->relationsToSync = $relations;

        return $this;
    }

    /**
     * Generate HTML for the map widget
     *
     * @param  mixed  $resource
     * @return string
     */
    protected function generateMapHtml($resource)
    {
        // Get resource name and ID
        $resourceName = $this->getResourceName($resource);
        $resourceId = $resource->id ?? null;

        // Build widget URL
        $widgetUrl = url("/nova-vendor/feature-collection-grid/widget/{$resourceName}/{$resourceId}");
        if ($this->geojsonSource) {
            $widgetUrl .= '?geojson_source='.urlencode($this->geojsonSource);
        }

        return <<<HTML
            <div style="min-height: 400px; position: relative;background: white;">
                <iframe 
                    src="{$widgetUrl}"
                    style="width: 100%; height: 500px; border: none; border-radius: 4px;"
                    frameborder="0"
                    allowfullscreen>
                </iframe>
            </div>
        HTML;
    }

    /**
     * Get selected feature IDs from configured relations
     *
     * @param  mixed  $resource
     */
    protected function getSelectedFeatureIds($resource): array
    {
        $selectedIds = [];

        if (empty($this->relationsToSync)) {
            return $selectedIds;
        }

        foreach ($this->relationsToSync as $relationName) {
            if (method_exists($resource, $relationName)) {
                $relation = $resource->{$relationName}();
                if ($relation) {
                    // Get the related model class to determine table name
                    $relatedModel = $relation->getRelated();
                    $tableName = $relatedModel->getTable();

                    // Specify table name explicitly to avoid ambiguous column error
                    $ids = $relation->select($tableName.'.id')->pluck('id')->toArray();
                    $selectedIds = array_merge($selectedIds, $ids);
                }
            }
        }

        return array_unique($selectedIds);
    }

    /**
     * Get resource name from resource instance
     *
     * @param  mixed  $resource
     */
    protected function getResourceName($resource): string
    {
        $className = class_basename(get_class($resource));

        return Str::kebab($className);
    }

    public function fillModelWithData(object $model, mixed $value, string $attribute): void
    {
        // the save is done via api
    }
}

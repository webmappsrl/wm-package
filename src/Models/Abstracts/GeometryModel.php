<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Database\Eloquent\Model;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Services\GeoJsonService;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\ImageService;
use Wm\WmPackage\Services\StorageService;

abstract class GeometryModel extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'name',
        'geometry',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    //
    // FROM GEOHUB App\Traits\GeometryFeatureTrait
    //

    /**
     * Calculate the geojson of a model with only the geometry
     */
    public function getGeojson(): array
    {
        return GeoJsonService::make()->getModelAsGeojson($this);
    }

    /**
     * Calculate the kml on a model with geometry
     */
    public function getKml(): ?string
    {
        return GeometryComputationService::make()->getModelGeometryAsKml($this);
    }

    /**
     * Calculate the gpx on a model with geometry
     *
     * @return mixed|null
     */
    public function getGpx()
    {
        return GeometryComputationService::make()->getModelGeometryAsGpx($this);
    }

    /**
     * Return a feature collection with the related UGC features
     */
    public function getRelatedUgcGeojson(): array
    {
        return GeometryComputationService::make()->getRelatedUgcGeojson($this);
    }

    public function populateProperties(): void
    {
        $properties = [];
        $propertiesToClear = ['key'];
        if (isset($this->name)) {
            $properties['name'] = $this->name;
        }
        if (isset($this->description)) {
            $properties['description'] = $this->description;
        }
        if (isset($this->metadata)) {
            $metadata = json_decode($this->metadata, true);
            $properties = array_merge($properties, $metadata);
        }
        if (! empty($this->raw_data)) {
            $properties = array_merge($properties, (array) json_decode($this->raw_data, true));
        }
        foreach ($propertiesToClear as $property) {
            unset($properties[$property]);
        }
        $this->properties = $properties;
        $this->saveQuietly();
    }

    public function populatePropertyForm($acqisitionForm): void
    {
        if (is_numeric($this->app_id)) {
            $app = App::where('id', $this->app_id)->first();
        } else {
            $sku = $this->app_id;
            if ($sku === 'it.net7.parcoforestecasentinesi') {
                $sku = 'it.netseven.forestecasentinesi';
            }
            $app = App::where('sku', $this->app_id)->first();
        }
        if ($app && $app->$acqisitionForm) {
            $formSchema = json_decode($app->$acqisitionForm, true);
            $properties = $this->properties;
            // Trova lo schema corretto basato sull'ID, se esiste in `raw_data`
            if (isset($properties['id'])) {
                $currentSchema = collect($formSchema)->firstWhere('id', $properties['id']);

                if ($currentSchema) {
                    // Rimuove i campi del form da `properties` e li aggiunge sotto la chiave `form`
                    $form = [];
                    if (isset($properties['index'])) {
                        $form['index'] = $properties['index'];
                        unset($properties['index']); // Rimuovi `index` da `properties`
                    }
                    if (isset($properties['id'])) {
                        $form['id'] = $properties['id'];
                        unset($properties['id']); // Rimuovi `id` da `properties`
                    }
                    foreach ($currentSchema['fields'] as $field) {
                        $label = $field['name'] ?? 'unknown';
                        if (isset($properties[$label])) {
                            $form[$label] = $properties[$label];
                            unset($properties[$label]); // Rimuove il campo da `properties`
                        }
                    }

                    $properties['form'] = $form; // Aggiunge i campi del form sotto `form`
                    $properties['id'] = $this->id;
                    $this->properties = $properties;
                    $this->saveQuietly();
                }
            }
        }
    }

    public function populatePropertyMedia(): void
    {
        $media = [];
        $properties = $this->properties;
        if (isset($this->relative_url)) {
            $media['webPath'] = StorageService::make()->getLocalImageUrl($this->relative_url);
        }
        $properties['photo'] = $media;
        $this->properties = $properties;
        $this->saveQuietly();
    }

    /**
     * Get the class name for polymorphic relations.
     *
     * @return string
     */
    public function getMorphClass()
    {
        return 'App\\Models\\'.class_basename($this);
    }

    //
    // MEDIA
    //

    public function registerMediaConversions($media = null): void
    {
        foreach (ImageService::make()->getThumbnailSizes() as $size) {
            $this
                ->addMediaConversion('thumbnail')
                ->fit(Fit::Contain, $size['width'], $size['height'])
                ->nonQueued();
        }
    }

    public function registerMediaCollections(): void
    {
        // add options
        // you can define as many collections as needed
        $this->addMediaCollection('default');
    }
}

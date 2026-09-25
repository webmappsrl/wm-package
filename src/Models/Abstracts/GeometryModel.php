<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\GeoJsonService;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\MediaService;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;
use Wm\WmPackage\Traits\HasSafeTranslatable;

abstract class GeometryModel extends Model implements HasMedia
{
    use HasSafeTranslatable, InteractsWithMedia;

    protected $fillable = [
        'name',
        'geometry',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    //
    // FROM GEOHUB App\Traits\GeometryFeatureTrait
    //

    /**
     * Calculate the geojson of a model with only the geometry
     */
    public function getGeojson(): ?array
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

    /**
     * FeatureCollection per il campo Nova FeatureCollectionMap (dettaglio / form).
     * Modelli con geometria complessa (es. EcTrack, Layer) possono sovrascrivere.
     */
    public function getFeatureCollectionMap(): array
    {
        $feature = $this->getGeojson();

        return GeoJsonService::make()->wrapAsFeatureCollection($feature);
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
        $app = App::find($this->app_id);

        if ($app->$acqisitionForm) {
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
     * Get a valid localized name from taxonomy where data.
     *
     * @deprecated Nessun chiamante interno da oc:8588: la stessa logica (con lo scarto esplicito
     *             delle voci senza nome) vive ora in `TaxonomyWhereDisplayService::orderedNames()`.
     *             Resta pubblico per compatibilità con eventuali consumer esterni.
     */
    public function getValidName(array $whereData): string
    {
        // New shape: ['name' => ['it' => '...', 'en' => '...'], ...]
        // Legacy shape: ['it' => '...', 'en' => '...', ...]
        $candidate = $whereData['name'] ?? $whereData;

        if (is_string($candidate)) {
            $trimmed = trim($candidate);
            if ($trimmed === '') {
                return '';
            }

            // Defensive: handle JSON serialized string values.
            if (str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $candidate = $decoded;
                } else {
                    return $trimmed;
                }
            } else {
                return $trimmed;
            }
        }

        if (! is_array($candidate)) {
            return '';
        }

        $localized = $candidate['it'] ?? $candidate['en'] ?? null;
        if (is_string($localized)) {
            return trim($localized);
        }

        foreach ($candidate as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * Get ordered taxonomy wheres for Elasticsearch.
     * Sorted by _admin_level ascending (nulls first).
     *
     * @return string[]
     */
    public function getOrderedTaxonomyWheres(): array
    {
        $wheres = $this->properties['taxonomy_where'] ?? [];

        return is_array($wheres) ? app(TaxonomyWhereDisplayService::class)->orderedNames($wheres) : [];
    }

    /**
     * Applica l'opzione "Località mostrate" dell'App proprietaria alle proprietà di
     * un'uscita pubblica (json statico, pois.geojson, API, Elasticsearch — oc:8588).
     * Non va usata da job interni che rileggono o risalvano `properties`.
     */
    public function applyTaxonomyWhereDisplay(array $properties): array
    {
        unset($properties['_taxonomy_where_backup']);

        if (! isset($properties['taxonomy_where']) || ! is_array($properties['taxonomy_where'])) {
            return $properties;
        }

        $service = app(TaxonomyWhereDisplayService::class);
        $filtered = $service->filter(
            $properties['taxonomy_where'],
            $service->selectedCategoriesForApp($this->app_id)
        );

        $properties['taxonomy_where'] = $filtered;
        $properties['taxonomyWheres'] = $service->orderedNames($filtered);

        return $properties;
    }

    /**
     * Applica `applyTaxonomyWhereDisplay()` a una feature GeoJSON (`['properties' => [...], ...]`),
     * se ha una chiave `properties` — stesso controllo `isset($x['properties'])` che era duplicato
     * in tre punti (`EcTrackController::getGeojson()`/`multiple()`, `App::getAllPoisGeojson()`),
     * estratto qui (oc:8588, review). Una feature `null` (es. `EcTrack::getGeojson()` che non
     * trova il record) o senza `properties` viene restituita invariata.
     *
     * @param  array<string, mixed>|null  $feature
     * @return array<string, mixed>|null
     */
    public function applyTaxonomyWhereDisplayToFeature(?array $feature): ?array
    {
        if (isset($feature['properties'])) {
            $feature['properties'] = $this->applyTaxonomyWhereDisplay($feature['properties']);
        }

        return $feature;
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
        $mediaService = MediaService::make();
        foreach ($mediaService->getThumbnailSizes() as $size) {
            $this
                ->addMediaConversion(
                    $mediaService->getMediaConversionNameByWidthAndHeight($size['width'], $size['height'])
                )
                ->fit(Fit::Crop, $size['width'], $size['height'])
                ->queued();
        }
    }

    public function registerMediaCollections(): void
    {
        // add options
        // you can define as many collections as needed
        $this->addMediaCollection('default');
    }
}

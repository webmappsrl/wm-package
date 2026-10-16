<?php

namespace Wm\WmPackage\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Models\Abstracts\Point;
use Wm\WmPackage\Models\Interfaces\LayerRelatedModel;
use Wm\WmPackage\Observers\EcPoiObserver;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Traits\EcFeatureTrait;
use Wm\WmPackage\Traits\TaxonomyAbleModel;
use Wm\WmPackage\Traits\TaxonomyWhereAbleModel;

class EcPoi extends Point implements LayerRelatedModel
{
    use EcFeatureTrait, HasTranslations, TaxonomyAbleModel, TaxonomyWhereAbleModel;

    protected $fillable = [
        'name',
        'app_id',
        'geometry',
        'out_source_feature_id',
        'addr_complete',
        'capacity',
        'contact_phone',
        'contact_email',
        'related_url',
        'properties',
        'user_id',
        'global',
        'accessibility_validity_date',
        'accessibility_pdf',
        'access_mobility_check',
        'access_mobility_level',
        'access_mobility_description',
        'access_hearing_check',
        'access_hearing_level',
        'access_hearing_description',
        'access_vision_check',
        'access_vision_level',
        'access_vision_description',
        'access_cognitive_check',
        'access_cognitive_level',
        'access_cognitive_description',
        'access_food_check',
        'access_food_description',
    ];

    public array $translatable = ['name', 'description', 'excerpt', 'audio', 'properties->description', 'properties->excerpt'];

    public bool $skip_update = false;

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'related_url' => 'array',
        'properties' => 'array',
        'properties->accessibility_validity_date' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        // Registra l'observer usando static:: per funzionare anche con le classi estese
        static::observe(EcPoiObserver::class);

        // Imposta un default per properties se è null
        static::creating(function ($model) {
            if (is_null($model->properties)) {
                $model->properties = [];
            }
        });
    }

    /**
     * Convert 2D geometry to 3D when setting geometry attribute
     */
    public function setGeometryAttribute($value)
    {
        $this->attributes['geometry'] = GeometryComputationService::make()->convertTo3DGeometry($value);
    }

    public function ecTracks(): BelongsToMany
    {
        return $this->belongsToMany(EcTrack::class);
    }

    /**
     * Get the user that owns the EcPoi.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function taxonomyActivities(): MorphToMany
    {
        return $this->morphToMany(TaxonomyActivity::class, 'taxonomy_activityable')
            ->using(TaxonomyActivityable::class); // this is necessary to make events on pivot working
        // https://github.com/chelout/laravel-relationship-events/issues/16;;
    }

    public function taxonomyPoiTypes(): MorphToMany
    {
        return $this->morphToMany(TaxonomyPoiType::class, 'taxonomy_poi_typeable')
            ->using(TaxonomyPoiTypeable::class); // this is necessary to make events on pivot working
        // https://github.com/chelout/laravel-relationship-events/issues/16;;
    }

    public function getShowImageOnMap(): ?bool
    {
        $value = ($this->properties ?? [])['show_image_on_map'] ?? null;
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }

    public function resolveShowImageOnMap(): bool
    {
        return $this->getShowImageOnMap() ?? false;
    }

    // /**
    //  * Return the json version of the ec poi, avoiding the geometry
    //  * TODO: unit TEST
    //  */
    // public function getJson($allData = true, $app_id = 0): array
    // {
    //     $array = $this->setOutSourceValue();

    //     $array = $this->array_filter_recursive($array);

    //     if (array_key_exists('name', $array) && $array['name']) {
    //         foreach ($array['name'] as $lang => $val) {
    //             if (empty($val) || ! $val) {
    //                 unset($array['name'][$lang]);
    //             }
    //         }
    //     }

    //     if ($this->user_id) {
    //         $user = User::find($this->user_id);
    //         $array['author_email'] = $user->email;
    //     }

    //     if ($this->featureImage) {
    //         $array['feature_image'] = $this->featureImage->getJson($allData);
    //     }

    //     if ($this->ecMedia) {
    //         $gallery = [];
    //         $ecMedia = $this->ecMedia()->orderBy('rank', 'asc')->get();
    //         foreach ($ecMedia as $media) {
    //             $gallery[] = $media->getJson($allData);
    //         }
    //         if (count($gallery)) {
    //             // Ensure the feature_image is the first in the gallery and not duplicated
    //             if (isset($array['feature_image'])) {
    //                 // Remove any duplicate of the feature_image in the gallery
    //                 $gallery = array_filter($gallery, function ($image) use ($array) {
    //                     return $image['id'] != $array['feature_image']['id'];
    //                 });

    //                 // Add feature_image to the start of the gallery
    //                 array_unshift($gallery, $array['feature_image']);
    //             } else {
    //                 // Set the first image in the gallery as feature_image if it isn't set
    //                 $array['feature_image'] = $gallery[0];
    //             }

    //             $array['image_gallery'] = $gallery;
    //         }
    //     }

    //     // if (isset($this->outSourcePoi->source_id) && strpos($this->outSourcePoi->source_id, '/')) {
    //     //     $array['osm_url'] = 'https://www.openstreetmap.org/' . $this->outSourcePoi->source_id;
    //     // }

    //     $fileTypes = ['geojson', 'gpx', 'kml'];
    //     foreach ($fileTypes as $fileType) {
    //         $array[$fileType . '_url'] = route('api.ec.poi.download.' . $fileType, ['id' => $this->id]);
    //     }

    //     if (array_key_exists('related_url', $array) && ! is_array($array['related_url']) && empty($array['related_url'])) {
    //         unset($array['related_url']);
    //     }

    //     $poitypes = [];
    //     foreach ($this->taxonomyPoiTypes as $poitype) {
    //         $result = $poitype->getGeoJson();
    //         if ($result['id'] != 17) {
    //             $poitypes[] = $poitype->getGeoJson();
    //         }
    //     }
    //     $poitype = [];
    //     if (is_array($poitypes) && count($poitypes) > 0) {
    //         $poitype = $poitypes[0];
    //     }

    //     $taxonomy = [
    //         'activity' => $this->taxonomyActivities()->pluck('id')->toArray(),
    //         'theme' => $this->taxonomyThemes()->pluck('id')->toArray(),
    //         'when' => $this->taxonomyWhens()->pluck('id')->toArray(),
    //         'where' => $this->taxonomyWheres()->pluck('id')->toArray(),
    //         'who' => $this->taxonomyTargets()->pluck('id')->toArray(),
    //         'poi_type' => $poitype, // deprecated
    //         'poi_types' => $poitypes,
    //     ];

    //     $taxonomiesidentifiers = array_merge(
    //         $this->taxonomyActivities()->pluck('identifier')->toArray(),
    //         $this->taxonomyThemes()->pluck('identifier')->toArray(),
    //         $this->addPrefix($this->taxonomyWhens()->pluck('identifier')->toArray(), 'when'),
    //         $this->addPrefix($this->taxonomyWheres()->pluck('identifier')->toArray(), 'where'),
    //         $this->addPrefix($this->taxonomyTargets()->pluck('identifier')->toArray(), 'who'),
    //         $this->addTaxonomyPoiTypes()
    //     );

    //     foreach ($taxonomy as $key => $value) {
    //         if (count($value) === 0) {
    //             unset($taxonomy[$key]);
    //         }
    //     }

    //     $array['taxonomy'] = $taxonomy;
    //     // TODO non so se modificare taxonomy rompe qualcosa per ora ho inseritono una nuova proprietà
    //     $array['taxonomyIdentifiers'] = $taxonomiesidentifiers;

    //     $propertiesToClear = ['geometry'];
    //     foreach ($array as $property => $value) {
    //         if (
    //             in_array($property, $propertiesToClear)
    //             || is_null($value)
    //             || (is_array($value) && count($value) === 0)
    //         ) {
    //             unset($array[$property]);
    //         }
    //     }

    //     $array['searchable'] = $this->getSearchableString($app_id);

    //     return $array;
    // }

    public function addPrefix($array, $prefix)
    {
        return array_map(function ($elem) use ($prefix) {
            return $prefix.'_'.$elem;
        }, $array);
    }

    public function addTaxonomyPoiTypes()
    {
        $taxonomyPoiTypes = $this->taxonomyPoiTypes()->pluck('identifier')->toArray();
        if (count($taxonomyPoiTypes) > 1 && in_array('poi', $taxonomyPoiTypes) == true) {
            $taxonomyPoiTypes = array_diff($taxonomyPoiTypes, ['poi']);

            return $this->addPrefix($taxonomyPoiTypes, 'poi_type');
        }
        if (in_array('poi', $taxonomyPoiTypes) == false) {
            return $this->addPrefix($taxonomyPoiTypes, 'poi_type');
        }

        return ['poi_type_poi'];
    }

    public function getTaxonomies()
    {
        return [
            'activity' => $this->getValuesOfMorphToMany($this->taxonomyActivities(), 'activity'),
            'when' => $this->getValuesOfMorphToMany($this->taxonomyWhens(), 'when'),
            'who' => $this->getValuesOfMorphToMany($this->taxonomyTargets(), 'who'),
            'poi_type' => $this->getValuesOfMorphToMany($this->taxonomyPoiTypes(), 'poi_type'),
        ];
    }

    public function getLayerRelationName(): string
    {
        return 'ecPois';
    }

    private function getValuesOfMorphToMany($relation, $slug): array
    {
        return $relation->get(['identifier', 'name', 'id', 'icon', 'color'])->map(function ($item) use ($slug) {
            unset($item['pivot']);
            $item['identifier'] = $slug.'_'.$item['identifier'];

            return $item;
        })->toArray();
    }

    // private function setOutSourceValue(): array
    // {
    //     $array = $this->toArray();
    //     if (isset($this->out_source_feature_id)) {
    //         $keys = [
    //             'description',
    //             'excerpt',
    //         ];
    //         foreach ($keys as $key) {
    //             $array = $this->setOutSourceSingleValue($array, $key);
    //         }
    //     }

    //     return $array;
    // }

    // private function setOutSourceSingleValue($array, $varname): array
    // {
    //     if (isReallyEmpty($array[$varname])) {
    //         if (isset($this->outSourcePOI->tags[$varname])) {
    //             $array[$varname] = $this->outSourcePOI->tags[$varname];
    //         }
    //     }

    //     return $array;
    // }

    /**
     * Testo su cui l'app cerca i POI (`properties.searchable` di pois.geojson). Segue la regola
     * di Geohub: `poi_searchables` dell'app sceglie i campi, una lista vuota o non valida vuol
     * dire tutti. Descrizione ed estratto stanno in `properties`, gli accenti restano leggibili e
     * i campi vuoti non aggiungono nulla (oc:8677).
     */
    public function getSearchableString(?App $app = null): string
    {
        $searchables = $this->poiSearchablesOf($app);
        $includes = fn (string $field): bool => $searchables === [] || in_array($field, $searchables, true);

        $parts = [];
        if ($includes('name')) {
            $parts[] = $this->searchableTranslations($this->getTranslations('name'));
        }
        foreach (['description', 'excerpt'] as $field) {
            if ($includes($field)) {
                $parts[] = $this->searchableTranslations($this->properties[$field] ?? null, true);
            }
        }
        if ($includes('osmid') && ! empty($this->osmid)) {
            $parts[] = (string) $this->osmid;
        }
        if ($includes('taxonomyPoiTypes')) {
            /** @var TaxonomyPoiType $tax */
            foreach ($this->taxonomyPoiTypes as $tax) {
                $parts[] = $this->searchableTranslations($tax->getTranslations('name'));
            }
        }

        $parts = array_filter($parts, fn (string $part): bool => $part !== '');

        return trim(implode(' ', $parts));
    }

    /**
     * Campi scelti in `poi_searchables`; [] vuol dire tutti (anche senza app o con un valore che
     * non è una lista di stringhe).
     *
     * @return array<int, string>
     */
    private function poiSearchablesOf(?App $app): array
    {
        $value = $app?->poi_searchables;
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        foreach ($value as $field) {
            if (! is_string($field)) {
                return [];
            }
        }

        return $value;
    }

    /**
     * Traduzioni nel formato di Geohub (`{it:Testo,en:Text}`), senza escape degli accenti.
     */
    private function searchableTranslations(mixed $translations, bool $stripTags = false): string
    {
        if (is_string($translations)) {
            $translations = ['it' => $translations];
        }
        if (! is_array($translations)) {
            return '';
        }
        $translations = array_filter($translations, fn ($text): bool => is_string($text) && trim($text) !== '');
        if ($translations === []) {
            return '';
        }
        // Prima le entità (&nbsp;, &lt;b&gt;), poi i tag sostituiti da uno spazio (strip_tags
        // incollerebbe «Uno</p><p>Due»), infine gli spazi, compreso lo spazio non separabile, in uno
        // solo: a capo escapati diventerebbero lettere spurie ("rnLa" su Geohub) una volta tolti i
        // backslash (oc:8677).
        $translations = array_map(
            function (string $text) use ($stripTags): string {
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($stripTags) {
                    $text = (string) preg_replace('/<[^>]*>/', ' ', $text);
                }

                return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
            },
            $translations
        );

        $json = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return str_replace(['"', '\\'], '', (string) $json);
    }

    public function array_filter_recursive($array)
    {
        $result = [];
        foreach ($array as $key => $val) {
            if (! is_array($val) && ! empty($val) && $val) {
                $result[$key] = $val;
            } elseif (is_array($val)) {
                foreach ($val as $lan => $cont) {
                    if (! is_array($cont) && ! empty($cont) && $cont) {
                        $result[$key][$lan] = $cont;
                    }
                }
            }
        }

        return $result;
    }
}

<?php

namespace Wm\WmPackage\Models;

use App\Models\User;
use ChristianKuri\LaravelFavorite\Traits\Favoriteable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Models\Interfaces\LayerRelatedModel;
use Wm\WmPackage\Observers\EcTrackObserver;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Services\Models\MediaService;
use Wm\WmPackage\Nova\Traits\HasDemClassification;
use Wm\WmPackage\Traits\EcFeatureTrait;
use Wm\WmPackage\Traits\NormalizesHexColor;
use Wm\WmPackage\Traits\TaxonomyAbleModel;
use Wm\WmPackage\Traits\TaxonomyWhereAbleModel;

class EcTrack extends MultiLineString implements LayerRelatedModel
{
    use EcFeatureTrait, Favoriteable, HasDemClassification, NormalizesHexColor, Searchable, TaxonomyAbleModel, TaxonomyWhereAbleModel;

    public const DEFAULT_COLOR_HEX = '#FF0000';

    protected $table;

    protected $fillable = [
        'name',
        'geometry',
        'app_id',
        'properties',
        'user_id',
        'osmid',
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

    public function __construct(array $attributes = [])
    {
        $this->table = config('wm-package.ec_track_table', 'ec_tracks');
        parent::__construct($attributes);
    }

    protected $casts = [
        'properties' => 'array',
        'properties->accessibility_validity_date' => 'datetime',
    ];

    public $translatable = [
        'name',
        'properties->description',
        'properties->excerpt',
        'properties->difficulty',
        'properties->not_accessible_message',
    ];

    public static string $geometryType = 'LineString';

    protected static function booted()
    {
        parent::booted();

        EcTrack::observe(EcTrackObserver::class);

        // Imposta un default per properties se è null
        static::creating(function ($model) {
            if (is_null($model->properties)) {
                $model->properties = [];
            }
        });

        // Gestisci il caso in cui properties sia null, stringa vuota o stringa JSON
        static::retrieved(function ($model) {
            // Se properties è null o stringa vuota, imposta un array vuoto
            if (is_null($model->properties) || $model->properties === '') {
                $model->properties = [];

                return;
            }

            // Se properties è una stringa JSON, decodificala
            if (is_string($model->properties)) {
                $decoded = json_decode($model->properties, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    // Controlla se ci sono campi traducibili e converte le stringhe in array di traduzioni
                    if (isset($model->translatable)) {
                        foreach ($model->translatable as $field) {
                            if (strpos($field, 'properties->') === 0) {
                                $translationKey = str_replace('properties->', '', $field);
                                if (isset($decoded[$translationKey]) && is_string($decoded[$translationKey]) && $decoded[$translationKey] !== '') {
                                    $decoded[$translationKey] = ['it' => $decoded[$translationKey]];
                                }
                            }
                        }
                    }
                    $model->properties = $decoded;
                } else {
                    // Se la decodifica fallisce, imposta un array vuoto
                    $model->properties = [];
                }
            }
        });
    }

    // TODO FIX MAP MULTILINESTRING NOVA FIELD BUG 3D GEOMETRY
    public function setGeometryAttribute($value)
    {
        $this->attributes['geometry'] = GeometryComputationService::make()->convertTo3DGeometry($value);
    }

    //
    // RELATIONS
    //

    public function ecPois(): BelongsToMany
    {
        $pivotTable = config('wm-package.ec_poi_track_pivot_table', 'ec_poi_ec_track');

        return $this->belongsToMany(EcPoi::class, $pivotTable)
            ->using(EcPoiEcTrack::class)
            ->withPivot('order')
            ->orderByPivot('order');
    }

    public function taxonomyActivities(): MorphToMany
    {
        return $this->morphToMany(TaxonomyActivity::class, 'taxonomy_activityable')
            ->using(TaxonomyActivityable::class); // this is necessary to make events on pivot working
        // https://github.com/chelout/laravel-relationship-events/issues/16;;
    }

    public function usersCanDownload(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'downloadable_ec_track_user');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * Get the user that owns the EcTrack.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    //
    // ATTRIBUTE SETTERS
    //

    public function setColorAttribute($value)
    {
        if (strpos($value, '#') !== false) {
            $this->attributes['color'] = hexToRgba($value);
        }
    }

    //
    // ATTRIBUTE GETTERS
    //

    // public function getLayersAttribute()
    // {
    //     // Recupera i layer associati tramite la relazione
    //     $associatedLayers = $this->associatedLayers->pluck('id')->toArray();

    //     // Ritorna l'elenco dei layer associati come array
    //     return $associatedLayers;
    // }

    public function getLayerRelationName(): string
    {
        return 'ecTracks';
    }

    /**
     * @return Collection<int, Layer>
     */
    public function layersOrderedByRankDesc(): Collection
    {
        $query = $this->layers()
            ->orderBy('rank')
            ->orderBy('id');

        return $query->get();
    }

    public function getInheritedTrackColorHex(): string
    {
        /** @var \Illuminate\Support\Collection<int, Layer> $layers */
        $layers = $this->layersOrderedByRankDesc();

        /** @var Layer|null $layer */
        $layer = $this->getPreferredLayerForInheritedColor($layers);

        if (! $layer) {
            return self::DEFAULT_COLOR_HEX;
        }

        $layerColor = $layer->getStrokeColorHex();

        return $layerColor ?? self::DEFAULT_COLOR_HEX;
    }

    public function getTrackColorProvenance(): array
    {
        $properties = is_array($this->properties) ? $this->properties : [];

        $storedHex = $this->normalizeHexColor($properties['color'] ?? null);

        $inheritedHex = $this->getInheritedTrackColorHex();
        $effectiveHex = $storedHex ?? $inheritedHex;

        /** @var \Illuminate\Support\Collection<int, Layer> $layers */
        $layers = $this->layersOrderedByRankDesc();
        /** @var Layer|null $layer */
        $layer = $this->getPreferredLayerForInheritedColor($layers);

        $layerInfo = null;
        if ($layer) {
            $layerInfo = [
                'id' => $layer->id,
                'name' => $layer->getStringName(),
            ];
        }

        $source = 'default';
        if ($storedHex !== null && $storedHex !== '' && $storedHex !== $inheritedHex) {
            $source = 'custom';
        } elseif ($layer) {
            $source = 'layer';
        }
        $result = [
            'effective_hex' => $effectiveHex,
            'inherited_hex' => $inheritedHex,
            'source' => $source,
            'layer' => $layerInfo,
            'stored_hex' => $storedHex,
        ];

        return $result;
    }

    /**
     * Sceglie il layer da cui ereditare il colore.
     * Regola: prende SEMPRE il layer con rank più basso; se non ha colore -> default.
     *
     * @param  Collection<int, Layer>  $layersOrderedByRankAsc
     */
    private function getPreferredLayerForInheritedColor(Collection $layersOrderedByRankAsc): ?Layer
    {
        return $layersOrderedByRankAsc->first();
    }

    // normalizeHexColor estratto nel trait NormalizesHexColor

    /**
     * Return the json version of the ec track, avoiding the geometry
     */
    // public function getJson(): array
    // {

    //     $array = $this->setOutSourceValue();

    //     $array = $this->array_filter_recursive($array);

    //     if (array_key_exists('excerpt', $array) && $array['excerpt']) {
    //         foreach ($array['excerpt'] as $lang => $val) {
    //             $array['excerpt'][$lang] = strip_tags($val);
    //         }
    //     }

    //     if ($this->color) {
    //         $array['track_color'] = $this->color;
    //     }

    //     if ($this->user_id) {
    //         $user = User::find($this->user_id);
    //         $array['author_email'] = $user->email;
    //     }

    //     if ($this->featureImage) {
    //         $array['feature_image'] = $this->featureImage->getGeoJson();
    //     }

    //     if ($this->ecMedia) {
    //         $gallery = [];
    //         $ecMedia = $this->ecMedia()->orderBy('rank', 'asc')->get();
    //         foreach ($ecMedia as $media) {
    //             $gallery[] = $media->getGeoJson();
    //         }
    //         if (count($gallery)) {
    //             $array['image_gallery'] = $gallery;
    //         }
    //     }

    //     if (isset($this->osmid)) {
    //         $array['osm_url'] = 'https://www.openstreetmap.org/relation/' . $this->osmid;
    //     }

    //     $fileTypes = ['geojson', 'gpx', 'kml'];
    //     foreach ($fileTypes as $fileType) {
    //         $array[$fileType . '_url'] = route('api.ec.track.download.' . $fileType, ['id' => $this->id]);
    //     }

    //     $activities = [];

    //     foreach ($this->taxonomyActivities as $activity) {
    //         $activities[] = $activity->getGeoJson();
    //     }

    //     $wheres = [];

    //     $wheres = $this->taxonomyWheres()->pluck('id')->toArray();

    //     if ($this->taxonomy_wheres_show_first) {
    //         $re = $this->taxonomy_wheres_show_first;
    //         $wheres = array_diff($wheres, [$re]);
    //         array_push($wheres, $this->taxonomy_wheres_show_first);
    //         $wheres = array_values($wheres);
    //     }

    //     $taxonomies = [
    //         'activity' => $activities,
    //         'theme' => $this->taxonomyThemes()->pluck('id')->toArray(),
    //         'when' => $this->taxonomyWhens()->pluck('id')->toArray(),
    //         'where' => $wheres,
    //         'who' => $this->taxonomyTargets()->pluck('id')->toArray(),
    //     ];

    //     foreach ($taxonomies as $key => $value) {
    //         if (count($value) === 0) {
    //             unset($taxonomies[$key]);
    //         }
    //     }

    //     $array['taxonomy'] = $taxonomies;

    //     $durations = [];
    //     $activityTerms = $this->taxonomyActivities()->whereIn('identifier', ['hiking', 'cycling'])->get()->toArray();
    //     if (count($activityTerms) > 0) {
    //         foreach ($activityTerms as $term) {
    //             $durations[$term['identifier']] = [
    //                 'forward' => $term['pivot']['duration_forward'],
    //                 'backward' => $term['pivot']['duration_backward'],
    //             ];
    //         }
    //     }

    //     $array['duration'] = $durations;

    //     $propertiesToClear = ['geometry', 'slope'];
    //     foreach ($array as $property => $value) {
    //         if (
    //             in_array($property, $propertiesToClear)
    //             || is_null($value)
    //             || (is_array($value) && count($value) === 0)
    //         ) {
    //             unset($array[$property]);
    //         }
    //     }

    //     $relatedPoi = $this->ecPois;
    //     if (count($relatedPoi) > 0) {
    //         $array['related_pois'] = [];
    //         foreach ($relatedPoi as $poi) {
    //             $array['related_pois'][] = $poi->getGeojson();
    //         }
    //     }

    //     $mbtilesIds = $this->mbtiles;
    //     if ($mbtilesIds) {
    //         $mbtilesIds = json_decode($mbtilesIds, true);
    //         if (count($mbtilesIds)) {
    //             $array['mbtiles'] = $mbtilesIds;
    //         }
    //     }

    //     $user = auth('api')->user();
    //     $array['user_can_download'] = isset($user);

    //     if (isset($array['difficulty']) && is_array($array['difficulty']) && is_null($array['difficulty']) === false && count(array_keys($array['difficulty'])) === 1 && isset(array_values($array['difficulty'])[0]) === false) {
    //         $array['difficulty'] = null;
    //     }

    //     if ($this->allow_print_pdf) {
    //         $user = User::find($this->user_id);
    //         if ($user->apps->count() > 0) {
    //             $pdf_url = url('/track/pdf/' . $this->id . '?app_id=' . $user->apps[0]->id);
    //             $array['related_url']['Print PDF'] = $pdf_url;
    //         } else {
    //             $pdf_url = url('/track/pdf/' . $this->id);
    //             $array['related_url']['Print PDF'] = $pdf_url;
    //         }
    //     }

    //     return $array;
    // }

    // private function setOutSourceValue(): array
    // {
    //     $array = $this->toArray();
    //     if (isset($this->out_source_feature_id)) {
    //         $keys = [
    //             'description',
    //             'excerpt',
    //             'distance',
    //             'ascent',
    //             'descent',
    //             'ele_min',
    //             'ele_max',
    //             'ele_from',
    //             'ele_to',
    //             'duration_forward',
    //             'duration_backward',
    //             'ref',
    //             'difficulty',
    //             'cai_scale',
    //             'from',
    //             'to',
    //             'audio',
    //             'related_url',
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
    //         if (isset($this->outSourceTrack->tags[$varname])) {
    //             $array[$varname] = $this->outSourceTrack->tags[$varname];
    //         }
    //     }
    //     if (is_array($array[$varname]) && is_null($array[$varname]) === false && count(array_keys($array[$varname])) === 1 && isset(array_values($array[$varname])[0]) === false) {
    //         $array[$varname] = null;
    //     }

    //     return $array;
    // }

    /**
     * Create the track geojson using the elbrus standard
     */
    public function getElbrusGeojson(): array
    {
        $geojson = $this->getGeojson();
        // MAPPING
        $geojson['properties']['id'] = 'ec_track_' . $this->id;
        $geojson = $this->_mapElbrusGeojsonProperties($geojson);

        if ($this->ecPois) {
            $related = [];
            $pois = $this->ecPois;
            foreach ($pois as $poi) {
                $related['poi']['related'][] = $poi->id;
            }

            if (count($related) > 0) {
                $geojson['properties']['related'] = $related;
            }
        }

        return $geojson;
    }

    /**
     * Map the geojson properties to the elbrus standard
     */
    private function _mapElbrusGeojsonProperties(array $geojson): array
    {
        $fields = ['ele_min', 'ele_max', 'ele_from', 'ele_to', 'duration_forward', 'duration_backward', 'contact_phone', 'contact_email'];
        foreach ($fields as $field) {
            if (isset($geojson['properties'][$field])) {
                $field_with_colon = preg_replace('/_/', ':', $field);

                $geojson['properties'][$field_with_colon] = $geojson['properties'][$field];
                unset($geojson['properties'][$field]);
            }
        }

        $fields = ['kml', 'gpx'];
        foreach ($fields as $field) {
            if (isset($geojson['properties'][$field . '_url'])) {
                $geojson['properties'][$field] = $geojson['properties'][$field . '_url'];
                unset($geojson['properties'][$field . '_url']);
            }
        }

        if (isset($geojson['properties']['taxonomy'])) {
            foreach ($geojson['properties']['taxonomy'] as $taxonomy => $values) {
                $name = $taxonomy === 'poi_type' ? 'webmapp_category' : $taxonomy;

                if ($taxonomy === 'activity') {
                    $geojson['properties']['taxonomy'][$name] = array_map(function ($item) use ($name) {
                        return $name . '_' . $item;
                    }, array_map(function ($item) {
                        return $item['id'];
                    }, $values));
                } else {
                    $geojson['properties']['taxonomy'][$name] = array_map(function ($item) use ($name) {
                        return $name . '_' . $item;
                    }, $values);
                }
            }
        }

        if (isset($geojson['properties']['feature_image'])) {
            $geojson['properties']['image'] = $geojson['properties']['feature_image'];
            unset($geojson['properties']['feature_image']);
        }

        if (isset($geojson['properties']['image_gallery'])) {
            $geojson['properties']['imageGallery'] = $geojson['properties']['image_gallery'];
            unset($geojson['properties']['image_gallery']);
        }

        return $geojson;
    }

    /**
     * Converts a duration value into a consistent float representation in hours.
     * Handles numeric values (assumed to be seconds) and string formats (HH:MM:SS).
     *
     * @param  mixed  $duration
     */
    public function convertDurationToHours($duration): float
    {
        if (empty($duration)) {
            return 0.0;
        }

        // If it's already a number, assume it's in seconds and convert to hours.
        if (is_numeric($duration)) {
            return (float) $duration / 3600;
        }

        // If it's a string, try to parse it.
        if (is_string($duration)) {
            $parts = explode(':', $duration);
            $hours = 0;
            if (count($parts) === 3) { // HH:MM:SS
                $hours = (int) $parts[0] + ((int) $parts[1] / 60) + ((int) $parts[2] / 3600);
            } elseif (count($parts) === 2) { // HH:MM
                $hours = (int) $parts[0] + ((int) $parts[1] / 60);
            }

            return (float) $hours;
        }

        // Fallback for unknown types
        return 0.0;
    }

    public function cleanTrackNameSpecialChar()
    {
        if (! empty($this->name)) {
            $name = str_replace('"', '', $this->name);
        }

        return $name;
    }

    // TODO: ripristinare la indicizzazione del color
    public function setColorEmpty()
    {
        $color = $this->color;
        if (empty($this->color)) {
            $color = '';
        }

        return $color;
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

    /**
     * returns the apps associated to a EcTrack
     */
    public function trackHasApps()
    {
        return collect([$this->app]);
    }

    /**
     * Returns an array of app_id => layer_id associated with the current EcTrack
     */
    // public function getLayersByApp(): array
    // {
    //     $layers = [];

    //     // Estrazione delle tassonomie per il filtro
    //     $taxonomyActivities = $this->taxonomyActivities->pluck('id')->toArray();
    //     $taxonomyWheres = $this->taxonomyWheres->pluck('id')->toArray();
    //     $taxonomyThemes = $this->taxonomyThemes->pluck('id')->toArray();

    //     $trackTaxonomies = [];

    //     if (! empty($taxonomyActivities)) {
    //         $trackTaxonomies['activities'] = $taxonomyActivities;
    //     }
    //     if (! empty($taxonomyWheres)) {
    //         $trackTaxonomies['wheres'] = $taxonomyWheres;
    //     }
    //     if (! empty($taxonomyThemes)) {
    //         $trackTaxonomies['themes'] = $taxonomyThemes;
    //     }

    //     // Verifica se ci sono app associate
    //     if (is_null($this->trackHasApps())) {
    //         return $layers;
    //     }

    //     foreach ($this->trackHasApps() as $app) {
    //         $layersCollection = collect($app->layers);
    //         // Ottieni gli ID dei layer associati tramite la tabella app_layer
    //         // TODO: use morph relation instead of direct query
    //         $associatedLayerIds = DB::table('app_layer')
    //             ->where('layerable_id', $app->id)
    //             ->where('layerable_type', 'LIKE', '%\\Models\\App')
    //             ->pluck('layer_id'); // Ottiene solo gli ID

    //         // Recupera i Layer associati tramite gli ID
    //         $associatedLayers = Layer::whereIn('id', $associatedLayerIds)->get();
    //         // Unisci le due collection e rimuovi eventuali duplicati
    //         $mergedLayers = $layersCollection->merge($associatedLayers)->unique();
    //         $sortedLayers = $mergedLayers->sortBy('rank');

    //         foreach ($sortedLayers as $layer) {
    //             $layerTaxonomies = $layer->getLayerTaxonomyIDs();
    //             $hasAtLeastOneMatch = false; // Assume che nessuna tassonomia corrisponda

    //             foreach ($trackTaxonomies as $taxonomyType => $requiredIds) {
    //                 // Verifica se il layer contiene la tassonomia corrente
    //                 if (isset($layerTaxonomies[$taxonomyType])) {
    //                     // Controlla se c'è almeno una corrispondenza tra le tassonomie del layer e quelle della traccia
    //                     if (array_intersect($layerTaxonomies[$taxonomyType], $requiredIds)) {
    //                         $hasAtLeastOneMatch = true;
    //                         break; // Esce dal loop appena trova una corrispondenza
    //                     }
    //                 }
    //             }

    //             // Se il layer non ha alcuna corrispondenza, non lo includiamo
    //             if ($hasAtLeastOneMatch) {
    //                 $layers[$layer->app_id][] = $layer->id;
    //             }
    //         }

    //         // Se non ci sono layers corrispondenti, crea comunque un array vuoto per l'app
    //         if (empty($layers[$app->id])) {
    //             $layers[$app->id] = [];
    //         }
    //     }

    //     return $layers;
    // }

    //
    // LARAVEL SCOUT - ELASTICSEARCH
    //

    public function toSearchableArray()
    {

        $ecTrackService = EcTrackService::make();
        $mediaService = MediaService::make();
        $firstMedia = $this->getMedia('*')->first();

        try {

            [$start, $end] = GeometryComputationService::make()->getStartEndCoordinates($this);
        } catch (\Exception $e) {
            $start = [0, 0];
            $end = [0, 0];
        }

        $arr = [
            'id' => $this->id,
            'ref' => $this->properties['ref'] ?? '',
            'start' => $start,
            'end' => $end,
            'cai_scale' => $this->properties['cai_scale'] ?? '',
            'app_id' => $this->app_id,
            // 'from' => $this->getActualOrOSFValue('from'),
            // 'to' => $this->getActualOrOSFValue('to'),
            'name' => $this->getTranslation('name', 'it'),
            'taxonomyWheres' => $this->getOrderedTaxonomyWheres(),
            'feature_image' => $firstMedia ? $mediaService->getThumbnailUrl($firstMedia) : '',
            'strokeColor' => isset($this->properties['color']) ? hexToRgba($this->properties['color']) : '',
            'distance' => (float) ($this->classifyField($this, 'distance')['currentValue'] ?? 0),
            'duration_forward' => (float) ($this->classifyField($this, 'duration_forward')['currentValue'] ?? 0),
            'ascent' => isset($this->properties['ascent']) ? (int) ($this->properties['ascent']) : 0,
            'taxonomyActivities' => $ecTrackService->getTaxonomyArray($this->taxonomyActivities),
            'taxonomyIcons' => $ecTrackService->getTaxonomyIcons($this),
            'layers' => $this->layers->pluck('id')->toArray(),
            'searchable' => $this->getSearchableString(),
        ];

        return $arr;
    }

    public function getSearchableString(): string
    {
        $app_id = $this->app_id;
        $stringValue = '';
        $searchables = '';
        if (is_null($app_id)) {
            return $stringValue;
        }

        $app = App::find($app_id);
        if ($app && $app->track_searchables) {
            $searchables = json_decode($app->track_searchables);
        } else {
            $searchables = ['name'];
        }

        if (empty($searchables) || (in_array('name', $searchables) && ! empty($this->name))) {
            $stringValue .= str_replace('"', '', json_encode($this->getTranslations('name'))) . ' ';
        }
        if ((empty($searchables) || in_array('description', $searchables)) && ! empty($this->properties['description'] ?? null)) {
            $description = is_array($this->properties['description']) ? json_encode($this->properties['description']) : $this->properties['description'];
            $description = str_replace('"', '', $description);
            $description = str_replace('\\', '', $description);
            $stringValue .= strip_tags($description) . ' ';
        }
        if ((empty($searchables) || in_array('excerpt', $searchables)) && ! empty($this->properties['excerpt'] ?? null)) {
            $excerpt = str_replace('"', '', json_encode($this->properties['excerpt']));
            $excerpt = str_replace('\\', '', $excerpt);
            $stringValue .= strip_tags($excerpt) . ' ';
        }
        if (isset($this->properties['ref']) && empty($searchables) || (in_array('ref', $searchables) && ! empty($this->properties['ref']))) {
            $stringValue .= $this->properties['ref'] . ' ';
        }
        if (isset($this->properties['osmid']) && empty($searchables) || (in_array('osmid', $searchables) && ! empty($this->properties['osmid']))) {

            $stringValue .= $this->properties['osmid'] . ' ';
        }

        if (empty($searchables) || (in_array('taxonomyActivities', $searchables) && ! empty($this->taxonomyActivities))) {
            foreach ($this->taxonomyActivities as $tax) {
                $stringValue .= str_replace('"', '', json_encode($tax->getTranslations('name'))) . ' ';
            }
        }

        $taxonomyWheres = $this->getOrderedTaxonomyWheres();
        if (empty($searchables) || (in_array('taxonomyWheres', $searchables) && ! empty($taxonomyWheres))) {
            $stringValue .= implode(' ', $taxonomyWheres) . ' ';
        }

        return html_entity_decode($stringValue);
    }

    /**
     * Get track as GeoJSON feature collection for map widget
     *
     * @return array GeoJSON feature collection
     */
    public function getFeatureCollectionMap(): array
    {
        $features = [];

        if ($this->geometry) {
            $geojson = DB::select("SELECT ST_AsGeoJSON(ST_GeomFromWKB(decode(?, 'hex'))) as geojson", [$this->geometry]);

            if (! empty($geojson)) {
                $feature = $this->getFeatureMap();

                $provenance = $this->getTrackColorProvenance();
                $effectiveHex = $provenance['effective_hex'] ?? self::DEFAULT_COLOR_HEX;

                $feature['properties'] = array_merge(
                    is_array($this->properties) ? $this->properties : [],
                    [
                        'strokeColor' => hexToRgba($effectiveHex),
                        'strokeWidth' => 4,
                        'effective_color' => $effectiveHex,
                        'color_source' => $provenance['source'] ?? 'default',
                    ]
                );
                $features[] = $feature;
            }
        }

        $relatedPoi = $this->ecPois ?? collect();
        foreach ($relatedPoi as $poi) {
            $poiFeature = $poi->getGeojson();
            if ($poiFeature) {
                $lang = app()->getLocale() ?? 'it';
                $tooltip = $poi->getTranslation('name', $lang) ?: $poi->getTranslation('name', 'it');
                $linkPath = trim(config('nova.path', '/nova'), '/') . '/resources/ec-pois/' . $poi->id;

                $poiFeature['properties'] = [
                    ...($poiFeature['properties'] ?? []),
                    'tooltip' => $tooltip ?? '',
                    'pointRadius' => 4,
                    'link' => url($linkPath),
                ];
                $features[] = $poiFeature;
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    public function getFeatureMap($geometry = null)
    {
        if ($geometry === null) {
            $geometry = $this->geometry;
        }
        $geojson = DB::select("SELECT ST_AsGeoJSON(ST_GeomFromWKB(decode(?, 'hex'))) as geojson", [$geometry]);
        $geometry = json_decode($geojson[0]->geojson, true);

        return [
            'type' => 'Feature',
            'geometry' => $geometry,
        ];
    }
}

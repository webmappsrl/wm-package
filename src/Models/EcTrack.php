<?php

namespace Wm\WmPackage\Models;

use ChristianKuri\LaravelFavorite\Traits\Favoriteable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Observers\EcTrackObserver;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Traits\TaxonomyAbleModel;

class EcTrack extends MultiLineString
{
    use Favoriteable, HasTranslations, Searchable, TaxonomyAbleModel;

    protected $fillable = [
        'name',
        'geometry',
        'user_id',
        'properties'
    ];

    public $translatable = ['name'];


    public static string $geometryType = 'LineString';

    protected static function booted()
    {
        EcTrack::observe(EcTrackObserver::class);
    }

    //
    // RELATIONS
    //
    public function associatedLayers(): BelongsToMany
    {
        return $this->belongsToMany(Layer::class, 'ec_track_layer');
    }

    public function updateManualDataField($field, $value)
    {
        $this->manual_data[$field] = $value;
    }

    public function ecPois(): BelongsToMany
    {
        return $this->belongsToMany(EcPoi::class)->withPivot('order')->orderByPivot('order');
    }

    public function usersCanDownload(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'downloadable_ec_track_user');
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

    public function getLayersAttribute()
    {
        // Recupera i layer associati tramite la relazione
        $associatedLayers = $this->associatedLayers->pluck('id')->toArray();

        // Ritorna l'elenco dei layer associati come array
        return $associatedLayers;
    }

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
     * Create a geojson from the ec track
     */
    public function getGeojson(): array
    {
        $feature = parent::getGeojson();

        $feature['properties']['roundtrip'] = GeometryComputationService::make()->isRoundtrip($feature['geometry']['coordinates']);

        return $feature;
    }

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

    public function setEmptyValueToZero($value)
    {
        if (empty($value)) {
            $value = 0;
        }

        return $value;
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
        if (empty($this->user_id)) {
            return null;
        }

        $user = User::find($this->user_id);
        if ($user->apps->count() == 0) {
            return null;
        }

        return $user->apps;
    }

    /**
     * Returns an array of app_id => layer_id associated with the current EcTrack
     */
    public function getLayersByApp(): array
    {
        $layers = [];

        // Estrazione delle tassonomie per il filtro
        $taxonomyActivities = $this->taxonomyActivities->pluck('id')->toArray();
        $taxonomyWheres = $this->taxonomyWheres->pluck('id')->toArray();
        $taxonomyThemes = $this->taxonomyThemes->pluck('id')->toArray();

        $trackTaxonomies = [];

        if (! empty($taxonomyActivities)) {
            $trackTaxonomies['activities'] = $taxonomyActivities;
        }
        if (! empty($taxonomyWheres)) {
            $trackTaxonomies['wheres'] = $taxonomyWheres;
        }
        if (! empty($taxonomyThemes)) {
            $trackTaxonomies['themes'] = $taxonomyThemes;
        }

        // Verifica se ci sono app associate
        if (is_null($this->trackHasApps())) {
            return $layers;
        }

        foreach ($this->trackHasApps() as $app) {
            $layersCollection = collect($app->layers);
            // Ottieni gli ID dei layer associati tramite la tabella app_layer
            // TODO: use morph relation instead of direct query
            $associatedLayerIds = DB::table('app_layer')
                ->where('layerable_id', $app->id)
                ->where('layerable_type', 'LIKE', '%\\Models\\App')
                ->pluck('layer_id'); // Ottiene solo gli ID

            // Recupera i Layer associati tramite gli ID
            $associatedLayers = Layer::whereIn('id', $associatedLayerIds)->get();
            // Unisci le due collection e rimuovi eventuali duplicati
            $mergedLayers = $layersCollection->merge($associatedLayers)->unique();
            $sortedLayers = $mergedLayers->sortBy('rank');

            foreach ($sortedLayers as $layer) {
                $layerTaxonomies = $layer->getLayerTaxonomyIDs();
                $hasAtLeastOneMatch = false; // Assume che nessuna tassonomia corrisponda

                foreach ($trackTaxonomies as $taxonomyType => $requiredIds) {
                    // Verifica se il layer contiene la tassonomia corrente
                    if (isset($layerTaxonomies[$taxonomyType])) {
                        // Controlla se c'è almeno una corrispondenza tra le tassonomie del layer e quelle della traccia
                        if (array_intersect($layerTaxonomies[$taxonomyType], $requiredIds)) {
                            $hasAtLeastOneMatch = true;
                            break; // Esce dal loop appena trova una corrispondenza
                        }
                    }
                }

                // Se il layer non ha alcuna corrispondenza, non lo includiamo
                if ($hasAtLeastOneMatch) {
                    $layers[$layer->app_id][] = $layer->id;
                }
            }

            // Se non ci sono layers corrispondenti, crea comunque un array vuoto per l'app
            if (empty($layers[$app->id])) {
                $layers[$app->id] = [];
            }
        }

        return $layers;
    }

    //
    // LARAVEL SCOUT - ELASTICSEARCH
    //

    public function toSearchableArray()
    {
        $geom = $this->getGeometry();
        $taxonomy_activities = $this->getTaxonomyArray($this->taxonomyActivities);
        $taxonomy_wheres = $this->getTaxonomyWheres();
        $taxonomy_themes = $this->getTaxonomyArray($this->taxonomyThemes);
        $feature_image = $this->getFeatureImage();

        [$start, $end] = $this->getStartEndCoordinates($geom);

        return [
            'id' => $this->id,
            'ref' => $this->ref,
            'start' => $start,
            'end' => $end,
            'cai_scale' => $this->cai_scale,
            'from' => $this->getActualOrOSFValue('from'),
            'to' => $this->getActualOrOSFValue('to'),
            'name' => $this->name,
            'taxonomyActivities' => $taxonomy_activities,
            'taxonomyWheres' => $taxonomy_wheres,
            'taxonomyThemes' => $taxonomy_themes,
            'feature_image' => $feature_image,
            'strokeColor' => hexToRgba($this->color),
            'distance' => $this->setEmptyValueToZero($this->distance),
            'duration_forward' => $this->setEmptyValueToZero($this->duration_forward),
            'ascent' => $this->setEmptyValueToZero($this->ascent),
            'activities' => $this->taxonomyActivities->pluck('identifier')->toArray(),
            'themes' => $this->taxonomyThemes->pluck('identifier')->toArray(),
            'layers' => $this->layer_ids,
            'searchable' => json_encode($this->getSearchableString()),
        ];
    }

    public function getSearchableString()
    {
        $app_id = $this->app_id;
        $string = '';
        $searchables = '';
        if (empty($app_id) && ! empty($this->user_id)) {
            $user = User::find($this->user_id);
            if ($user->apps->count() > 0) {
                $app_id = $user->apps[0]->id;
            }
        }
        if ($app_id) {
            $app = App::find($app_id);
            $searchables = json_decode($app->track_searchables);
        }

        if (empty($searchables) || (in_array('name', $searchables) && ! empty($this->name))) {
            $string .= str_replace('"', '', json_encode($this->getTranslations('name'))) . ' ';
        }
        if (empty($searchables) || (in_array('description', $searchables) && ! empty($this->description))) {
            $description = str_replace('"', '', json_encode($this->getTranslations('description')));
            $description = str_replace('\\', '', $description);
            $string .= strip_tags($description) . ' ';
        }
        if (empty($searchables) || (in_array('excerpt', $searchables) && ! empty($this->excerpt))) {
            $excerpt = str_replace('"', '', json_encode($this->getTranslations('excerpt')));
            $excerpt = str_replace('\\', '', $excerpt);
            $string .= strip_tags($excerpt) . ' ';
        }
        if (empty($searchables) || (in_array('ref', $searchables) && ! empty($this->ref))) {
            $string .= $this->ref . ' ';
        }
        if (empty($searchables) || (in_array('osmid', $searchables) && ! empty($this->osmid))) {
            $string .= $this->osmid . ' ';
        }
        if (empty($searchables) || (in_array('taxonomyThemes', $searchables) && ! empty($this->taxonomyThemes))) {
            foreach ($this->taxonomyThemes as $tax) {
                $string .= str_replace('"', '', json_encode($tax->getTranslations('name'))) . ' ';
            }
        }
        if (empty($searchables) || (in_array('taxonomyActivities', $searchables) && ! empty($this->taxonomyActivities))) {
            foreach ($this->taxonomyActivities as $tax) {
                $string .= str_replace('"', '', json_encode($tax->getTranslations('name'))) . ' ';
            }
        }

        return html_entity_decode($string);
    }
}

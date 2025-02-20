<?php

namespace Wm\WmPackage\Models;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Observers\AppObserver;
use Wm\WmPackage\Services\Models\App\AppConfigService;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Traits\HasPackageFactory;

/**
 * Class App
 *
 *
 * @property string app_id
 * @property string available_languages
 */
class App extends Model
{
    use HasPackageFactory, HasTranslations, Searchable;

    protected $fillable = [
        'welcome',
        'classification_start_date',
        'classification_end_date',
    ];

    public array $translatable = ['welcome', 'tiles_label', 'overlays_label', 'data_label', 'pois_data_label', 'tracks_data_label', 'page_project', 'page_privacy', 'page_disclaimer', 'page_credits', 'filter_activity_label', 'filter_theme_label', 'filter_poi_type_label', 'filter_track_duration_label', 'filter_track_distance_label', 'social_share_text'];

    protected $casts = [
        'keywords' => 'array',
        'translations_it' => 'array',
        'translations_en' => 'array',
        'classification_start_date' => 'datetime',
        'classification_end_date' => 'datetime',
        'track_technical_details' => 'array',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['user_email'];

    protected static function boot()
    {
        parent::boot();
        App::observe(AppObserver::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function layers()
    {
        return $this->hasMany(Layer::class);
    }

    public function associatedLayers()
    {
        return $this->morphToMany(Layer::class, 'layerable', 'app_layer', 'layerable_id', 'layer_id');
    }

    public function overlayLayers()
    {
        return $this->hasMany(OverlayLayer::class);
    }

    public function ugc_medias()
    {
        return $this->hasMany(Media::class);
    }

    public function ugc_pois()
    {
        return $this->hasMany(UgcPoi::class);
    }

    public function ugc_tracks()
    {
        return $this->hasMany(UgcTrack::class);
    }

    public function taxonomyThemes(): MorphToMany
    {
        return $this->morphToMany(TaxonomyTheme::class, 'taxonomy_themeable');
    }

    public function getUserEmailById($user_id)
    {
        $user = User::find($user_id);

        return $user->email;
    }

    public function ecTracks(): HasMany
    {
        return $this->author->ecTracks();
    }

    public function getGeojson()
    {
        $tracks = EcTrack::where('user_id', $this->user_id)->get();

        if (! is_null($tracks)) {
            $geoJson = ['type' => 'FeatureCollection'];
            $features = [];
            foreach ($tracks as $track) {
                $geojson = $track->getGeojson();
                //                if (isset($geojson))
                $features[] = $geojson;
            }
            $geoJson['features'] = $features;

            return json_encode($geoJson);
        }
    }

    public function getMostViewedPoiGeojson()
    {
        $pois = EcPoi::where('user_id', $this->user_id)->limit(10)->get();

        if (! is_null($pois)) {
            $geoJson = ['type' => 'FeatureCollection'];
            $features = [];
            foreach ($pois as $count => $poi) {
                $feature = $poi->getEmptyGeojson();
                if (isset($feature['properties'])) {
                    $feature['properties']['name'] = $poi->name;
                    $feature['properties']['visits'] = (11 - $count) * 10;
                }

                $features[] = $feature;
            }
            $geoJson['features'] = $features;

            return json_encode($geoJson);
        }
    }

    public function getUGCPoiGeojson($sku)
    {
        $pois = UgcPoi::where('sku', $sku)->get();

        if ($pois->count() > 0) {
            $geoJson = ['type' => 'FeatureCollection'];
            $features = [];
            foreach ($pois as $count => $poi) {
                $feature = $poi->getEmptyGeojson();
                if (isset($feature['properties'])) {
                    $feature['properties']['view'] = '/resources/ugc-pois/'.$poi->id;
                }

                $features[] = $feature;
            }
            $geoJson['features'] = $features;

            return json_encode($geoJson);
        }
    }

    public function getUGCMediaGeojson($sku)
    {
        $medias = UgcMedia::where('sku', $sku)->get();

        if ($medias->count() > 0) {
            $geoJson = ['type' => 'FeatureCollection'];
            $features = [];
            foreach ($medias as $count => $media) {
                $feature = $media->getEmptyGeojson();
                if (isset($feature['properties'])) {
                    $feature['properties']['view'] = '/resources/ugc-medias/'.$media->id;
                }

                $features[] = $feature;
            }
            $geoJson['features'] = $features;

            return json_encode($geoJson);
        }
    }

    public function getiUGCTrackGeojson($sku)
    {
        $tracks = UgcTrack::where('sku', $sku)->get();

        if ($tracks->count() > 0) {
            $geoJson = ['type' => 'FeatureCollection'];
            $features = [];
            foreach ($tracks as $count => $track) {
                $feature = $track->getEmptyGeojson();
                if (isset($feature['properties'])) {
                    $feature['properties']['view'] = '/resources/ugc-tracks/'.$track->id;
                }

                $features[] = $feature;
            }
            $geoJson['features'] = $features;

            return json_encode($geoJson);
        }
    }

    public function getAllPoisGeojson()
    {
        $themes = $this->taxonomyThemes()->get();

        $pois = [];
        foreach ($themes as $theme) {
            foreach ($theme->ecPois()->orderBy('name')->get() as $poi) {
                $item = $poi->getGeojson(false, $this->id);
                $item['properties']['related'] = false;
                unset($item['properties']['pivot']);

                array_push($pois, $item);
            }
        }

        return $pois;
    }

    public function BuildPoisGeojson()
    {
        $json = [
            'type' => 'FeatureCollection',
            'features' => $this->getAllPoisGeojson(),
        ];
        StorageService::make()->storePois($this->id, json_encode($json));

        return $json;
    }

    public function BuildConfJson()
    {
        $appConfigService = new AppConfigService($this);

        $json = $appConfigService->config();
        $jidoTime = $appConfigService->config_get_jido_time();
        if (! is_null($jidoTime)) {
            $json['JIDO_UPDATE_TIME'] = $jidoTime;
        }
        StorageService::make()->storeAppConfig($this->id, json_encode($json));

        return $json;
    }

    public function getAllPoiTaxonomies()
    {
        $themes = $this->taxonomyThemes()->get();
        $res = [
            'activity' => [],
            'theme' => [],
            'when' => [],
            'where' => [],
            'who' => [],
            'poi_type' => [],
        ];

        foreach ($themes as $theme) {
            $theme_id = $theme->id;
            // NEW CODE
            $where_result = [];
            $where_ids = DB::select("select distinct taxonomy_where_id from taxonomy_whereables where taxonomy_whereable_type LIKE '%EcPoi%' AND taxonomy_whereable_id in (select taxonomy_themeable_id from taxonomy_themeables where taxonomy_theme_id=$theme_id and taxonomy_themeable_type LIKE '%EcPoi%');");
            if (! empty($where_ids)) {
                $where_ids_implode = implode(',', collect($where_ids)->pluck('taxonomy_where_id')->toArray());
                $where_db = DB::select("select id, identifier, name, color, icon from taxonomy_wheres where id in ($where_ids_implode)");
                $where_array = json_decode(json_encode($where_db), true);

                foreach ($where_array as $akey => $aval) {
                    $new_array = [];
                    foreach ($aval as $key => $val) {
                        if ($key == 'name') {
                            $new_array[$key] = json_decode($val, true);
                        }
                        if ($key == 'identifier') {
                            $new_array[$key] = 'poi_type_'.$val;
                        }
                        if (! empty($val) && $key != 'name' && $key != 'identifier') {
                            $new_array[$key] = $val;
                        }
                    }
                    array_push($where_result, $new_array);
                }
            }

            $poi_result = [];
            $poi_type_ids = DB::select("select distinct taxonomy_poi_type_id from taxonomy_poi_typeables where taxonomy_poi_typeable_type LIKE '%EcPoi%' AND taxonomy_poi_typeable_id in (select taxonomy_themeable_id from taxonomy_themeables where taxonomy_theme_id=$theme_id and taxonomy_themeable_type LIKE '%EcPoi%');");
            if (! empty($poi_type_ids)) {
                $poi_type_ids_implode = implode(',', collect($poi_type_ids)->pluck('taxonomy_poi_type_id')->toArray());
                $poi_db = DB::select("select id, identifier, name, color, icon from taxonomy_poi_types where id in ($poi_type_ids_implode)");
                $poi_array = json_decode(json_encode($poi_db), true);

                foreach ($poi_array as $akey => $aval) {
                    $new_array = [];
                    foreach ($aval as $key => $val) {
                        if ($key == 'name') {
                            $new_array[$key] = json_decode($val, true);
                        }
                        if ($key == 'identifier') {
                            $new_array[$key] = 'poi_type_'.$val;
                        }
                        if (! empty($val) && $key != 'name' && $key != 'identifier') {
                            $new_array[$key] = $val;
                        }
                    }
                    array_push($poi_result, $new_array);
                }
            }
            $res = [
                'where' => $this->unique_multidim_array(array_merge($res['where'], $where_result), 'id'),
                'poi_type' => $this->unique_multidim_array(array_merge($res['poi_type'], $poi_result), 'id'),
            ];
        }

        return $res;
    }

    public function getEcTracks(): Collection
    {
        if ($this->api == 'webmapp') {
            return EcTrack::all();
        }

        return EcTrack::where('user_id', $this->user_id)->get();
    }

    /**
     * @todo: differenziare la tassonomia "taxonomyActivities" !!!
     */
    public function listTracksByTerm($term, $taxonomy_name): array
    {
        switch ($taxonomy_name) {
            case 'activity':
                $query = EcTrack::where('user_id', $this->user_id)
                    ->whereHas('taxonomyActivities', function ($q) use ($term) {
                        $q->where('id', $term);
                    });
                break;
            case 'where':
                $query = EcTrack::where('user_id', $this->user_id)
                    ->whereHas('taxonomyWheres', function ($q) use ($term) {
                        $q->where('id', $term);
                    });
                break;
            case 'when':
                $query = EcTrack::where('user_id', $this->user_id)
                    ->whereHas('taxonomyWhens', function ($q) use ($term) {
                        $q->where('id', $term);
                    });
                break;
            case 'target':
            case 'who':
                $query = EcTrack::where('user_id', $this->user_id)
                    ->whereHas('taxonomyTargets', function ($q) use ($term) {
                        $q->where('id', $term);
                    });
                break;
            case 'theme':
                $query = EcTrack::where('user_id', $this->user_id)
                    ->whereHas('taxonomyThemes', function ($q) use ($term) {
                        $q->where('id', $term);
                    });
                break;
            default:
                throw new \Exception('Wrong taxonomy name: '.$taxonomy_name);
        }

        $tracks = $query->orderBy('name')->get();
        $tracks_array = [];
        foreach ($tracks as $track) {
            $geojson = $track->getElbrusGeojson();
            if (isset($geojson['properties'])) {
                $tracks_array[] = $geojson['properties'];
            }
        }

        return $tracks_array;
    }

    public function buildAllRoutine()
    {

        $this->BuildPoisGeojson();
        $this->BuildConfJson();
    }

    public function GenerateAppConfig()
    {
        $this->BuildConfJson();
    }

    public function GenerateAppPois()
    {
        $this->BuildPoisGeojson();
    }

    /**
     * Returns array of all tracks'id in APP through layers deifinition
     *  $tracks = [
     *               t1_d => [l11_id,l12_id, ... , l1N_1_id],
     *               t2_d => [l21_id,l22_id, ... , l2N_2_id],
     *               ... ,
     *               tM_d => [lM1_id,lM2_id, ... , lMN_M_id],
     *            ]
     * where t*_id are tracks ids and l*_id are layers where tracks are found
     */
    public function getTracksFromLayer(): array
    {
        $res = [];
        if ($this->layers->count() > 0) {
            foreach ($this->layers as $layer) {
                $tracks = $layer->ecTracks->pluck('id')->toArray();
                $layer->computeBB($this->map_bbox);
                if (count($tracks) > 0) {
                    foreach ($tracks as $track) {
                        if (! isset($res[$track])) {
                            $res[$track] = [];
                        }
                        $res[$track][] = $layer->id;
                        // Elimina eventuali duplicati
                        $res[$track] = array_unique($res[$track]);
                    }
                }
            }
        }

        return $res;
    }

    /**
     * Returns array of all tracks'id in APP through layers deifinition
     *  $tracks = [
     *               t1_id => updated_at,
     *               t2_id => updated_at,
     *               ... ,
     *               tM_id => updated_at,
     *            ]
     */
    public function getTracksUpdatedAtFromLayer(): array
    {
        $res = [];
        if ($this->layers->count() > 0) {
            foreach ($this->layers as $layer) {
                $tracks = $layer->ecTracks;
                if (count($tracks) > 0) {
                    foreach ($tracks as $track) {
                        $res[$track->id] = $track->updated_at;
                    }
                }
            }
        }

        return $res;
    }

    /**
     * Returns array of all tracks'id in APP through layers deifinition
     *  $tracks = [
     *               t1_id => updated_at,
     *               t2_id => updated_at,
     *               ... ,
     *               tM_id => updated_at,
     *            ]
     */
    public function getPOIsUpdatedAtFromApp(): array
    {
        $themes = $this->taxonomyThemes()->get();

        $pois = [];
        foreach ($themes as $theme) {
            foreach ($theme->ecPois()->orderBy('name')->get() as $poi) {
                $pois[$poi->id] = $poi->updated_at;
            }
        }

        return $pois;
    }

    /**
     * Determine if the user is an administrator.
     * TODO: refactor
     *
     * @return bool
     */
    public function getUserEmailAttribute()
    {
        $user = User::find($this->user_id);

        return $this->attributes['user_email'] = $user->email;
    }

    /**
     * generate a QR code for the app
     *
     * @return string
     */
    public function generateQrCode(?string $customUrl = null)
    {
        // if the customer has his own customUrl use it, otherwise use the default one
        if (isset($customUrl) && $customUrl != null) {
            $url = $customUrl;
        } else {
            $url = 'https://'.$this->id.'.app.webmapp.it';
        }
        // create the svg code for the QR code

        // https://php-qrcode.readthedocs.io/en/stable/Usage/Quickstart.html#quickstart
        $options = new QROptions;
        $options->outputBase64 = false; // output raw image instead of base64 data URI

        $svg = (new QRCode($options))->render($url);

        $this->qr_code = $svg;
        $this->save();

        // save the file in storage/app/public/qrcode/app_id/
        StorageService::make()->storeAppQrCode($this->id, $svg);

        return $svg;
    }

    public function unique_multidim_array($array, $key)
    {
        $temp_array = [];
        $i = 0;
        $key_array = [];
        foreach ($array as $val) {
            if (! in_array($val[$key], $key_array)) {
                $key_array[$i] = $val[$key];
                $temp_array[$i] = $val;
            }
            $i++;
        }

        return $temp_array;
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
}

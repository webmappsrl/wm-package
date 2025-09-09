<?php

namespace Wm\WmPackage\Models;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;
use Whitecube\NovaFlexibleContent\Value\FlexibleCast;
use Wm\WmPackage\Observers\AppObserver;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Traits\HasPackageFactory;
use Wm\WmPackage\Traits\TaxonomyAbleModel;

/**
 * Class App
 *
 *
 * @property string app_id
 * @property string available_languages
 */
class App extends Model implements HasMedia
{
    use HasPackageFactory, HasTranslations, InteractsWithMedia, TaxonomyAbleModel;

    protected $guarded = [];

    public array $translatable = ['welcome', 'tiles_label', 'overlays_label', 'data_label', 'pois_data_label', 'tracks_data_label', 'page_project', 'page_privacy', 'page_disclaimer', 'page_credits', 'filter_activity_label', 'filter_theme_label', 'filter_poi_type_label', 'filter_track_duration_label', 'filter_track_distance_label', 'social_share_text'];

    protected $casts = [
        'keywords' => 'array',
        'translations_it' => 'array',
        'translations_en' => 'array',
        'classification_start_date' => 'datetime',
        'classification_end_date' => 'datetime',
        'track_technical_details' => 'array',
        'properties' => 'array',
        'config_home' => FlexibleCast::class,
    ];

    protected static function boot()
    {
        parent::boot();
        self::observe(AppObserver::class);
    }

    public function getGeohubIdAttribute()
    {
        return $this->properties['geohub_id'] ?? null;
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
        return $this->belongsToMany(Layer::class, 'layer_associated_app');
    }

    public function ugc_pois()
    {
        return $this->hasMany(UgcPoi::class);
    }

    public function ugc_tracks()
    {
        return $this->hasMany(UgcTrack::class);
    }

    public function ecTracks(): HasMany
    {
        $modelClass = config('wm-package.ec_track_model');

        return $this->hasMany($modelClass);
    }
    
    public function ecPois(): HasMany
    {
        return $this->hasMany(EcPoi::class);
    }

    public function poiAcquisitionForm($formId = null)
    {
        $forms = json_decode($this->poi_acquisition_form, true) ?? null;
        if ($forms == null) {
            return null;
        }
        if ($formId !== null) {
            foreach ($forms as $form) {
                if (isset($form['id']) && $form['id'] === $formId) {
                    return $form;
                }
            }

            return null;
        }

        return $forms;
    }

    public function trackAcquisitionForm($formId = null)
    {
        $forms = json_decode($this->track_acquisition_form, true) ?? null;
        if ($forms == null) {
            return null;
        }
        if ($formId !== null) {
            foreach ($forms as $form) {
                if (isset($form['id']) && $form['id'] === $formId) {
                    return $form;
                }
            }

            return null;
        }

        return $forms;
    }

    public function acquisitionForms($formId = null)
    {
        $poiForms = $this->poiAcquisitionForm();
        $trackForms = $this->trackAcquisitionForm();

        // Unisco i due array di form
        $allForms = [];

        if ($poiForms !== null) {
            $allForms = array_merge($allForms, $poiForms);
        }

        if ($trackForms !== null) {
            $allForms = array_merge($allForms, $trackForms);
        }

        // Se non ci sono form, restituisco null
        if (empty($allForms)) {
            return null;
        }

        // Se è richiesto un form specifico
        if ($formId !== null) {
            foreach ($allForms as $form) {
                if (isset($form['id']) && $form['id'] === $formId) {
                    return $form;
                }
            }

            return null;
        }

        return $allForms;
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

    public function getAllPoisGeojson()
    {
        $pois = [];
        
        // Usa la relazione diretta come per le tracks
        $appPois = $this->ecPois()->get();
        
        if (count($appPois) > 0) {
            foreach ($appPois as $poi) {
                try {
                    // Verifica che il POI abbia una geometria valida
                    if ($poi->geometry && !empty($poi->geometry)) {
                        $item = $poi->getGeojson(false, $this->id);
                        
                        // Verifica che il geojson sia valido e non null
                        if ($item && isset($item['geometry']) && $item['geometry'] !== null) {
                            $item['properties']['related'] = false;
                            unset($item['properties']['pivot']);
                            
                            array_push($pois, $item);
                        }
                    }
                } catch (\Exception $e) {
                    // Log dell'errore ma continua con gli altri POI
                    \Log::warning("Errore nel processare POI ID {$poi->id}: " . $e->getMessage());
                    continue;
                }
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
        $pois = [];
        
        // Usa la relazione diretta come per le tracks
        $appPois = $this->ecPois()->get();
        
        if (count($appPois) > 0) {
            foreach ($appPois as $poi) {
                try {
                    // Verifica che il POI abbia una geometria valida
                    if ($poi->geometry && !empty($poi->geometry)) {
                        $pois[$poi->id] = $poi->updated_at;
                    }
                } catch (\Exception $e) {
                    // Log dell'errore ma continua con gli altri POI
                    \Log::warning("Errore nel processare POI ID {$poi->id} per updated_at: " . $e->getMessage());
                    continue;
                }
            }
        }

        return $pois;
    }

    // /**
    //  * Determine if the user is an administrator.
    //  * TODO: refactor
    //  *
    //  * @return bool
    //  */
    // public function getUserEmailAttribute()
    // {
    //     $user = User::find($this->user_id);

    //     return $this->attributes['user_email'] = $user->email;
    // }

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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('icon');
        $this->addMediaCollection('icon_small');
        $this->addMediaCollection('splash');
    }

    // Le funzioni custom per config_home sono state spostate nel resolver layerBoxResolver

    /**
     * Mutators for translatable fields to prevent double encoding from NovaTabTranslatable
     * Applies to: welcome, page_disclaimer, page_project, page_credits, page_privacy
     */
    public function setWelcomeAttribute($value)
    {
        $this->setTranslatableJsonField('welcome', $value);
    }

    public function setPageDisclaimerAttribute($value)
    {
        $this->setTranslatableJsonField('page_disclaimer', $value);
    }

    public function setPageProjectAttribute($value)
    {
        $this->setTranslatableJsonField('page_project', $value);
    }

    public function setPageCreditsAttribute($value)
    {
        $this->setTranslatableJsonField('page_credits', $value);
    }

    public function setPagePrivacyAttribute($value)
    {
        $this->setTranslatableJsonField('page_privacy', $value);
    }

    /**
     * Helper for setting translatable JSON fields with double-encoding protection
     */
    private function setTranslatableJsonField(string $field, $value): void
    {
        // If value is null, set directly
        if (is_null($value)) {
            $this->attributes[$field] = null;

            return;
        }

        // If array (from NovaTabTranslatable), encode as JSON after cleaning
        if (is_array($value)) {
            $cleaned = array_filter($value, fn ($v) => ! is_null($v) && $v !== '');
            $this->attributes[$field] = json_encode($cleaned);

            return;
        }

        // If string and looks like double-encoded JSON, fix it
        if (is_string($value) && $this->isDoubleEncodedJson($value)) {
            $fixed = $this->fixDoubleEncoding($value);
            $this->attributes[$field] = $fixed;

            return;
        }

        // Otherwise, use as is
        $this->attributes[$field] = $value;
    }

    /**
     * Check if string is double encoded JSON
     */
    private function isDoubleEncodedJson(string $value): bool
    {
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return false;
        }

        foreach ($decoded as $content) {
            if (is_string($content) && $this->isJson($content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fix double encoded JSON
     */
    private function fixDoubleEncoding(string $value): string
    {
        $decoded = json_decode($value, true);
        $fixed = [];

        foreach ($decoded as $lang => $content) {
            if (is_string($content) && $this->isJson($content)) {
                $innerDecoded = json_decode($content, true);
                if (is_array($innerDecoded) && isset($innerDecoded[$lang])) {
                    $fixed[$lang] = $innerDecoded[$lang];
                } else {
                    $fixed[$lang] = $content;
                }
            } else {
                $fixed[$lang] = $content;
            }
        }

        return json_encode($fixed);
    }

    /**
     * Check if string is valid JSON
     */
    private function isJson($string): bool
    {
        if (! is_string($string)) {
            return false;
        }
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }
}

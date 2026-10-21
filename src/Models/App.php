<?php

namespace Wm\WmPackage\Models;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

    /** Lato minimo, in pixel, del PNG del QR dell'App scaricabile da Nova (oc:8803). */
    public const APP_OPEN_QR_PNG_MIN_SIZE = 1024;

    /** Stile dei pulsanti di download nei riquadri dei QR in Nova. */
    private const QR_BUTTON_STYLE = 'display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:8px;background:#19b7a1;color:#ffffff;padding:8px 20px;font-size:14px;font-weight:600;text-decoration:none;';

    public array $translatable = ['welcome', 'tiles_label', 'overlays_label', 'data_label', 'pois_data_label', 'tracks_data_label', 'page_project', 'page_privacy', 'page_disclaimer', 'page_credits', 'filter_activity_label', 'filter_layer_label', 'filter_poi_type_label', 'filter_track_duration_label', 'filter_track_distance_label', 'social_share_text'];

    protected $casts = [
        'keywords' => 'array',
        'translations_it' => 'array',
        'translations_en' => 'array',
        'classification_start_date' => 'datetime',
        'classification_end_date' => 'datetime',
        'track_technical_details' => 'array',
        'properties' => 'array',
        'config_home' => FlexibleCast::class,
        'config_overlays' => 'array',
        'overlays_label' => 'array',
        'map_def_zoom' => 'integer',
        'map_max_zoom' => 'integer',
        'map_min_zoom' => 'integer',
        'map_max_stroke_width' => 'integer',
        'map_min_stroke_width' => 'integer',
        'start_end_icons_min_zoom' => 'integer',
        'ref_on_track_min_zoom' => 'integer',
        'alert_poi_radius' => 'integer',
        'flow_line_quote_orange' => 'integer',
        'flow_line_quote_red' => 'integer',
        'gps_accuracy_default' => 'integer',
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

    public function isNativeAppDeepLinkEnabled(): bool
    {
        return (bool) ($this->properties['native_app_deep_link_enabled'] ?? false);
    }

    public function author(): BelongsTo
    {
        // FK esplicita: la colonna è user_id, non l'inferita author_id (oc:8242).
        return $this->belongsTo(User::class, 'user_id');
    }

    public function layers()
    {
        return $this->hasMany(Layer::class);
    }

    public function associatedLayers()
    {
        return $this->belongsToMany(Layer::class, 'layer_associated_app');
    }

    public function filterLayers()
    {
        return $this->belongsToMany(Layer::class, 'app_filter_layers');
    }

    public function tiles()
    {
        return $this->belongsToMany(Tile::class, 'app_tile')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('app_tile.sort_order');
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

    public function featureCollections(): HasMany
    {
        return $this->hasMany(FeatureCollection::class);
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
        // Se la colonna 'global' esiste, filtra solo i POI globali, altrimenti restituisce tutti i POI
        $query = $this->ecPois();
        if (Schema::hasColumn((new EcPoi)->getTable(), 'global')) {
            $query->where('global', true);
        }
        // taxonomyPoiTypes caricati insieme: li legge getSearchableString() per ogni POI (oc:8677).
        $appPois = $query->with('taxonomyPoiTypes')->get();

        if (count($appPois) > 0) {
            /** @var EcPoi $poi */
            foreach ($appPois as $poi) {
                try {
                    // Verifica che il POI abbia una geometria valida
                    if ($poi->geometry && ! empty($poi->geometry)) {
                        $item = $poi->applyTaxonomyWhereDisplayToFeature($poi->getGeojson(false, $this->id));

                        // Aggiungo le taxonomy identifiers necessari per filtri
                        $taxonomiesidentifiers = array_merge(
                            $poi->taxonomyActivities()->pluck('identifier')->toArray(),
                            $poi->addPrefix($poi->taxonomyWhens()->pluck('identifier')->toArray(), 'when'),
                            $poi->addPrefix($poi->taxonomyTargets()->pluck('identifier')->toArray(), 'who'),
                            $poi->addTaxonomyPoiTypes()
                        );
                        $item['properties']['taxonomyIdentifiers'] = $taxonomiesidentifiers;

                        // Campi su cui cerca l'app (wm-core store/features/ec/utils.ts), calcolati
                        // qui e non salvati: dipendono da poi_searchables di questa app (oc:8677).
                        $item['properties']['name'] = $poi->getTranslations('name');
                        $item['properties']['searchable'] = $this->searchableForPoi($poi);

                        // Verifica che il geojson sia valido e non null
                        if ($item && isset($item['geometry']) && $item['geometry'] !== null) {
                            $item['properties']['related'] = false;
                            unset($item['properties']['pivot']);

                            array_push($pois, $item);
                        }
                    }
                } catch (\Exception $e) {
                    // Log dell'errore ma continua con gli altri POI
                    \Log::warning("Errore nel processare POI ID {$poi->id}: ".$e->getMessage());

                    continue;
                }
            }
        }

        return $pois;
    }

    /**
     * Testo di ricerca di un POI per questa app. Un errore lascia la ricerca vuota invece di
     * arrivare al catch di getAllPoisGeojson(), che scarterebbe il POI dal file (oc:8677).
     */
    protected function searchableForPoi(EcPoi $poi): string
    {
        try {
            return $poi->getSearchableString($this);
        } catch (\Throwable $e) {
            Log::warning("Testo di ricerca non calcolato per il POI {$poi->id}, in pois.geojson resta vuoto: ".$e->getMessage());

            return '';
        }
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

        $where_result = [];
        // TODO: nel db attualmente non esistono le taxonomy_wheres
        // $where_data = DB::select("
        //     SELECT DISTINCT tw.id, tw.identifier, tw.name, tw.color, tw.icon
        //     FROM taxonomy_wheres tw
        //     INNER JOIN taxonomy_whereables twa ON tw.id = twa.taxonomy_where_id
        //     INNER JOIN ec_pois ep ON twa.taxonomy_whereable_id = ep.id
        //     WHERE twa.taxonomy_whereable_type LIKE '%EcPoi%'
        //     AND ep.app_id = ?
        // ", [$this->id]);

        // foreach ($where_data as $item) {
        //     $new_array = [
        //         'id' => $item->id,
        //         'identifier' => 'poi_type_'.$item->identifier,
        //         'name' => json_decode($item->name, true),
        //         'color' => $item->color,
        //         'icon' => $item->icon,
        //     ];
        //     array_push($where_result, $new_array);
        // }

        $poi_result = [];
        $poi_data = DB::select("
            SELECT DISTINCT tpt.id, tpt.identifier, tpt.name, tpt.icon
            FROM taxonomy_poi_types tpt
            INNER JOIN taxonomy_poi_typeables tpta ON tpt.id = tpta.taxonomy_poi_type_id
            INNER JOIN ec_pois ep ON tpta.taxonomy_poi_typeable_id = ep.id
            WHERE tpta.taxonomy_poi_typeable_type LIKE '%EcPoi%'
            AND ep.app_id = ?
        ", [$this->id]);

        foreach ($poi_data as $item) {
            $new_array = [
                'id' => $item->id,
                'identifier' => 'poi_type_'.$item->identifier,
                'name' => json_decode($item->name, true),
                'icon_name' => $item->icon,
                // 'color' => $item->color, // TODO: manca color nella tabella taxonomy_poi_types (aggiungere in properties?)
            ];
            array_push($poi_result, $new_array);
        }

        $res = [
            'where' => $this->unique_multidim_array($where_result, 'id'),
            'poi_type' => $this->unique_multidim_array($poi_result, 'id'),
        ];

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
                    if ($poi->geometry && ! empty($poi->geometry)) {
                        $pois[$poi->id] = $poi->updated_at;
                    }
                } catch (\Exception $e) {
                    // Log dell'errore ma continua con gli altri POI
                    \Log::warning("Errore nel processare POI ID {$poi->id} per updated_at: ".$e->getMessage());

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

    /**
     * Resolves the domain used to build QR code deep link URLs: the custom `website_url`
     * if set on this App, otherwise the default per-app subdomain `{app_id}.{APP_NAME}.webmapp.it`.
     */
    public function getDeepLinkDomain(): string
    {
        if (! empty($this->website_url)) {
            return rtrim(preg_replace('#^https?://#', '', $this->website_url), '/');
        }

        return $this->id.'.'.config('app.name').'.webmapp.it';
    }

    /**
     * Builds the deep link URL used to open the native app on a Track or Poi.
     *
     * @param  string  $type  'track' or 'poi'
     */
    public function getDeepLinkUrl(string $type, int $id): string
    {
        if (! in_array($type, ['track', 'poi'], true)) {
            throw new \InvalidArgumentException("Invalid deep link type: {$type}. Expected 'track' or 'poi'.");
        }

        return 'https://'.$this->getDeepLinkDomain().'/map?'.$type.'='.$id;
    }

    /**
     * Renders the QR code + copyable link markup for a Track/Poi deep link, shown
     * directly on the Nova detail page (no download action needed). Returns null
     * when the toggle is off, so the caller can hide the Nova field entirely.
     *
     * @param  string  $type  'track' or 'poi'
     */
    public function renderDeepLinkQrCodeHtml(string $type, int $id): ?string
    {
        if (! $this->isNativeAppDeepLinkEnabled()) {
            return null;
        }

        $url = $this->getDeepLinkUrl($type, $id);
        $base64 = base64_encode($this->renderQrSvg($url));
        $downloadName = e("{$type}-{$id}-qrcode.svg");
        $buttonStyle = self::QR_BUTTON_STYLE;

        $actionsHtml = <<<HTML
                    <a
                        href="data:image/svg+xml;base64,{$base64}"
                        download="{$downloadName}"
                        style="{$buttonStyle}"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 3v9m0 0l-3.5-3.5M10 12l3.5-3.5M4 14.5v1a1.5 1.5 0 001.5 1.5h9a1.5 1.5 0 001.5-1.5v-1"/></svg>
                        Download QR
                    </a>
            HTML;

        return $this->renderQrCodeCard('wm-deep-link-qr', $url, $base64, $actionsHtml);
    }

    /**
     * SVG grezzo di un QR code: stesse opzioni per il QR dei deep link e per quello dell'App.
     */
    private function renderQrSvg(string $url): string
    {
        return (new QRCode(new QROptions(['outputBase64' => false])))->render($url);
    }

    /**
     * Riquadro comune ai QR mostrati in Nova: anteprima, azioni di download passate dal
     * chiamante, link in chiaro con il pulsante di copia.
     */
    private function renderQrCodeCard(string $cssClass, string $url, string $svgBase64, string $actionsHtml): string
    {
        $escapedUrl = e($url);
        $copyButtonHtml = $this->renderCopyLinkButton($url);

        return <<<HTML
            <div class="{$cssClass}" style="width:100%;box-sizing:border-box;padding:16px;border-radius:12px;border:1px solid #e5e7eb;background:#f9fafb;">
                <div style="display:flex;flex-direction:column;align-items:center;gap:12px;max-width:480px;width:100%;margin:0 auto;text-align:center;">
                    <div style="border-radius:8px;border:1px solid #e5e7eb;background:#ffffff;padding:8px;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                        <img src="data:image/svg+xml;base64,{$svgBase64}" width="140" height="140" alt="QR Code" style="display:block;" />
                    </div>
            {$actionsHtml}
                    <div style="display:flex;align-items:center;gap:8px;width:100%;">
                        <input
                            type="text"
                            readonly
                            value="{$escapedUrl}"
                            onclick="this.select()"
                            style="flex:1;min-width:0;box-sizing:border-box;border-radius:8px;border:1px solid #d1d5db;background:#ffffff;color:#374151;padding:8px 12px;font-size:13px;text-align:center;"
                        />
                        {$copyButtonHtml}
                    </div>
                </div>
            </div>
            HTML;
    }

    /**
     * Renders a "Copy link" button (clipboard JS + execCommand fallback) for the URL
     * shown under a QR code, matching the pattern already used for the Layer web component
     * copy button (see Nova\Layer::renderLayerWebComponentCopyButton()).
     */
    private function renderCopyLinkButton(string $url): string
    {
        $urlJson = json_encode($url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $defaultLabelJson = json_encode((string) __('Copy link'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $successLabelJson = json_encode((string) __('Link copied'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $errorLabelJson = json_encode((string) __('Copy error'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $onClick = <<<JS
(async function(button) {
  const text = {$urlJson};
  const defaultLabel = {$defaultLabelJson};
  const successLabel = {$successLabelJson};
  const errorLabel = {$errorLabelJson};

  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(text);
    } else {
      const textArea = document.createElement('textarea');
      textArea.value = text;
      textArea.setAttribute('readonly', '');
      textArea.style.position = 'absolute';
      textArea.style.left = '-9999px';
      document.body.appendChild(textArea);
      textArea.select();

      const copied = document.execCommand('copy');

      document.body.removeChild(textArea);

      if (!copied) {
        throw new Error('Clipboard copy failed');
      }
    }

    button.textContent = successLabel;
  } catch (error) {
    button.textContent = errorLabel;
  }

  window.setTimeout(function() {
    button.textContent = defaultLabel;
  }, 2000);
})(this); return false;
JS;

        $escapedOnClick = htmlspecialchars($onClick, ENT_QUOTES, 'UTF-8');
        $buttonLabel = htmlspecialchars((string) __('Copy link'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <button
                type="button"
                onclick="{$escapedOnClick}"
                style="display:inline-flex;flex-shrink:0;white-space:nowrap;align-items:center;justify-content:center;gap:6px;border-radius:8px;border:1px solid #d1d5db;background:#ffffff;color:#374151;padding:8px 20px;font-size:14px;font-weight:600;cursor:pointer;"
            >
                {$buttonLabel}
            </button>
            HTML;
    }

    /**
     * Vero se l'App ha almeno un link a uno store: senza, il QR dell'App non si mostra.
     */
    public function hasStoreLinks(): bool
    {
        return ! empty($this->ios_store_link) || ! empty($this->android_store_link);
    }

    /**
     * Link unico dell'App (oc:8803), codificato nel QR code: il backend manda allo store, all'app
     * o alla webapp a seconda del dispositivo (vedi AppOpenController).
     */
    public function getAppOpenUrl(): string
    {
        return route('wm.app-open', ['app' => $this->id]);
    }

    /**
     * Indirizzo della webapp dell'App, sullo stesso dominio dei deep link: è dove il link
     * unico manda chi non è su iOS né su Android.
     */
    public function getWebappUrl(): string
    {
        return 'https://'.$this->getDeepLinkDomain();
    }

    public function renderAppOpenQrCodeSvg(): string
    {
        return $this->renderQrSvg($this->getAppOpenUrl());
    }

    /**
     * PNG del QR dell'App con lato di almeno APP_OPEN_QR_PNG_MIN_SIZE px, per chi non può
     * usare l'SVG.
     */
    public function renderAppOpenQrCodePng(): string
    {
        $url = $this->getAppOpenUrl();

        // In php-qrcode 5.0 la classe di output vale solo con outputType CUSTOM, deprecato
        // senza sostituto (chillerlan/php-qrcode#223): sparirà con la versione che legge solo
        // outputInterface.
        $settings = [
            'outputType' => QROutputInterface::CUSTOM, // @phpstan-ignore classConstant.deprecated
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => false,
        ];

        // Il lato del PNG è moduli × scale: la scala si ricava dai moduli, zona di rispetto compresa.
        $modules = (new QRCode(new QROptions($settings)))->addByteSegment($url)->getQRMatrix()->getSize();
        $settings['scale'] = (int) ceil(self::APP_OPEN_QR_PNG_MIN_SIZE / $modules);

        return (new QRCode(new QROptions($settings)))->render($url);
    }

    /**
     * Markup del QR dell'App per il tab Release di Nova: anteprima, link copiabile e
     * download in SVG e PNG. Null senza link agli store, così il campo si nasconde.
     */
    public function renderAppOpenQrCodeHtml(): ?string
    {
        if (! $this->hasStoreLinks()) {
            return null;
        }

        $svgBase64 = base64_encode($this->renderAppOpenQrCodeSvg());
        $pngBase64 = base64_encode($this->renderAppOpenQrCodePng());
        $fileBase = $this->sku ?: "app-{$this->id}";
        $svgName = e("{$fileBase}-qrcode.svg");
        $pngName = e("{$fileBase}-qrcode.png");
        $svgLabel = e(__('Download SVG'));
        $pngLabel = e(__('Download PNG'));
        $buttonStyle = self::QR_BUTTON_STYLE;

        $actionsHtml = <<<HTML
                    <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:8px;">
                        <a href="data:image/svg+xml;base64,{$svgBase64}" download="{$svgName}" style="{$buttonStyle}">{$svgLabel}</a>
                        <a href="data:image/png;base64,{$pngBase64}" download="{$pngName}" style="{$buttonStyle}">{$pngLabel}</a>
                    </div>
            HTML;

        return $this->renderQrCodeCard('wm-app-open-qr', $this->getAppOpenUrl(), $svgBase64, $actionsHtml);
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
        $this->addMediaCollection('icon')->singleFile();
        $this->addMediaCollection('icon_small')->singleFile();
        $this->addMediaCollection('splash')->singleFile();
        $this->addMediaCollection('my_paths');
        $this->addMediaCollection('my_downloads');
        // Branded background frame for the generic track-share image (oc:8183) — shareable
        // via any channel (Share.share()), not Instagram Stories specifically, hence
        // `share_frame` rather than `story_frame`. Single file: uploading a new one replaces
        // the previous frame for this app.
        $this->addMediaCollection('share_frame')->singleFile();
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

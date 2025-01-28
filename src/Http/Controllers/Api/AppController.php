<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Services\StorageService;

class AppController extends Controller
{
    public function icon(App $app)
    {
        return $this->getOrDownloadIcon($app);
    }

    public function splash(App $app)
    {

        return $this->getOrDownloadIcon($app, 'splash');
    }

    public function iconSmall(App $app)
    {

        return $this->getOrDownloadIcon($app, 'icon_small');
    }

    public function featureImage(App $app)
    {

        return $this->getOrDownloadIcon($app, 'feature_image');
    }

    public function iconNotify(App $app)
    {

        return $this->getOrDownloadIcon($app, 'icon_notify');
    }

    public function logoHomepage(App $app)
    {

        return $this->getOrDownloadIcon($app, 'logo_homepage');
    }

    protected function getOrDownloadIcon(App $app, $type = 'icon')
    {
        if (! isset($app->$type)) {
            return response()->json(['code' => 404, 'error' => 'Not Found'], 404);
        }

        $pathInfo = pathinfo(parse_url($app->$type)['path']);
        if (substr($app->$type, 0, 4) === 'http') {
            // header("Content-disposition:attachment; filename=$type." . $pathInfo['extension']);
            // header('Content-Type:' . CONTENT_TYPE_IMAGE_MAPPING[$pathInfo['extension']]);
            // readfile($app->$type);

            return response()->streamDownload(function () use ($app, $type) {
                file_get_contents($app->$type);
            }, $type.'.'.$pathInfo['extension']);
        } else {
            // Scaricare risorsa locale
            //            if (Storage::disk('public')->exists($app->$type . '.' . $pathInfo['extension']))
            return Storage::disk('public')->download($app->$type, $type.'.'.$pathInfo['extension']);
            //            else return response()->json(['error' => 'File not found'], 404);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id  the app id in the database
     * @return JsonResponse
     */
    public function vectorStyle(App $app)
    {

        $url = route('api.app.webapp.vector_layer', ['id' => $app->id]);

        $data = <<<EOF
{
  "version": 8,
  "name": "tracks",
  "metadata": {
    "maputnik:renderer": "ol"
  },
  "sources": {
    "tracks1": {
      "type": "vector",
      "url": "$url"
    }
  },
  "sprite": "",
  "glyphs": "https://orangemug.github.io/font-glyphs/glyphs/{fontstack}/{range}.pbf",
  "layers": [
    {
      "id": "EEA",
      "type": "line",
      "source": "tracks",
      "source-layer": "tracks",
      "filter": [
        "all",
        [
          "==",
          "cai_scale",
          "EEA"
        ]
      ],
      "layout": {
        "line-join": "round",
        "line-cap": "round",
        "visibility": "visible"
      },
      "paint": {
        "line-color": "rgba(255, 0, 218, 0.8)",
        "line-width": {
          "stops": [
            [
              10,
              1
            ],
            [
              20,
              10
            ]
          ]
        },
        "line-dasharray": [
          0.001,
          2
        ]
      }
    },
    {
      "id": "EE",
      "type": "line",
      "source": "tracks",
      "source-layer": "tracks",
      "filter": [
        "all",
        [
          "==",
          "cai_scale",
          "EE"
        ]
      ],
      "layout": {
        "line-join": "round",
        "line-cap": "round"
      },
      "paint": {
        "line-color": "rgba(255, 57, 0, 0.8)",
        "line-width": {
          "stops": [
            [
              10,
              1
            ],
            [
              20,
              10
            ]
          ]
        },
        "line-dasharray": [
          0.01,
          2
        ]
      }
    },
    {
      "id": "E",
      "type": "line",
      "source": "tracks",
      "source-layer": "tracks",
      "filter": [
        "all",
        [
          "==",
          "cai_scale",
          "E"
        ]
      ],
      "layout": {
        "line-join": "round",
        "line-cap": "round"
      },
      "paint": {
        "line-color": "rgba(255, 57, 0, 0.8)",
        "line-width": {
          "stops": [
            [
              10,
              1
            ],
            [
              20,
              10
            ]
          ]
        },
        "line-dasharray": [
          2,
          2
        ]
      }
    },
    {
      "id": "T",
      "type": "line",
      "source": "tracks",
      "source-layer": "tracks",
      "filter": [
        "all",
        [
          "==",
          "cai_scale",
          "T"
        ]
      ],
      "layout": {
        "line-join": "round",
        "line-cap": "round",
        "visibility": "visible"
      },
      "paint": {
        "line-color": "rgba(255, 57, 0, 0.8)",
        "line-width": {
          "stops": [
            [
              10,
              1
            ],
            [
              20,
              10
            ]
          ]
        }
      }
    },
    {
      "id": "ref",
      "type": "symbol",
      "source": "tracks",
      "source-layer": "tracks",
      "minzoom": 10,
      "maxzoom": 16,
      "layout": {
        "text-field": "{ref}",
        "visibility": "visible",
        "symbol-placement": "line",
        "text-size": 12,
        "text-allow-overlap": true
      },
      "paint": {
        "text-color": "rgba(255, 57, 0,0.8)"
      }
    }
  ],
  "id": "63fa0rhhq"
}
EOF;
        $data = json_decode($data, true);

        return response()->json($data);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id  the app id in the database
     * @return JsonResponse
     */
    public function vectorLayer(App $app)
    {
        /**
         *   "grids": [
         *       "https://tiles.webmapp.it/sentieri_toscana/{z}/{x}/{y}.grid.json"
         *    ],
         */
        // TODO: Is jido alive?
        $tile_url = "https://jidotile.webmapp.it/?x={x}&y={y}&z={z}&index=geohub_app_{$app->id}";

        $data = <<<EOF
{
  "name": "sentieri_toscana",
  "description": "",
  "legend": "",
  "attribution": "Rendered with <a href=\"https://www.maptiler.com/desktop/\">MapTiler Desktop</a>",
  "type": "baselayer",
  "version": "1",
  "format": "pbf",
  "format_arguments": "",
  "minzoom": 3,
  "maxzoom": 16,
  "bounds": [
    9.662666,
    42.59819,
    12.415403,
    44.573604
  ],
  "scale": "1.000000",
  "profile": "mercator",
  "scheme": "xyz",
  "generator": "MapTiler Desktop Plus 11.2.1-252233dc0b",
  "basename": "sentieri_toscana",
  "tiles": [
    "$tile_url"
  ],
  "tilejson": "2.0.0",
  "vector_layers": [
    {
      "id": "sentieri",
      "description": "",
      "minzoom": 3,
      "maxzoom": 16,
      "fields": {
        "id": "Number",
        "ref": "String",
        "cai_scale": "String"
      }
    }
  ]
}
EOF;

        $data = json_decode($data, true);

        return response()->json($data);
    }

    public function config(App $app)
    {
        $json = StorageService::make()->getAppConfigJson($app->id) ?? $app->BuildConfJson($app->id);

        return response()->json($json);
    }

    public function baseConfig(App $app)
    {
        $json = $app->BuildConfJson($app->id);

        return response()->json($json);
    }

    public function tracksList(App $app)
    {

        $tracks = $app->getTracksUpdatedAtFromLayer();
        if (! empty($tracks)) {
            return response()->json($tracks);
        }

        // TODO: else?
        // temporary error
        return response()->json(['code' => 404, 'error' => '404 not tracks found'], 404);
    }

    public function poisList(App $app)
    {
        $tracks = $app->getPOIsUpdatedAtFromApp();
        if (! empty($tracks)) {
            return response()->json($tracks);
        }

        // TODO: else?
        // temporary error
        return response()->json(['code' => 404, 'error' => '404 not tracks found'], 404);
    }

    // Gets the layer info with the specified id plus all the related EcTracks
    public function layer(App $app, Layer $layer)
    {
        // $app = App::find($id);
        // if (is_null($app)) {
        //   return response()->json(['code' => 404, 'error' => '404 not found'], 404);
        // }
        // $layer = Layer::find($layer_id);
        // if (is_null($layer)) {
        //   return response()->json(['code' => 404, 'error' => '404 not found'], 404);
        // }
        $json = [];
        $json = $layer->toArray();
        if ($layer->feature_image) {
            $json['featureImage'] = $layer->featureImage->getJson();
        }
        $tracks = $layer->ecTracks;
        $tracks = $tracks->map(function ($track) {
            if ($track->feature_image) {
                $track['featureImage'] = $track->featureImage->getJson();
            }
            unset($track['feature_image']);
            unset($track['geometry']);
            unset($track['slope']);

            return $track;
        });

        $json['tracks'] = $tracks;

        return response()->json($json);
    }

    public function getFeaturesByAppAndTerm(App $app, string $taxonomy_name, int $term_id): JsonResponse
    {
        $json = [];
        $code = 200;

        $json = [];

        $taxonomy_names = ['activity', 'theme', 'where', 'poi_type'];

        if (! in_array($taxonomy_name, $taxonomy_names)) {
            $code = 400;
            $json = ['code' => $code, 'error' => 'Taxonomy name not valid'];

            return response()->json($json, $code);
        }

        if ($taxonomy_name === 'poi_type') {
            $tax = TaxonomyPoiType::find($term_id);

            $query = EcPoi::where('user_id', $app->user_id)
                ->whereHas('taxonomyPoiTypes', function ($q) use ($term_id) {
                    $q->where('id', $term_id);
                });

            $features = $query->orderBy('name')->get()->map(function ($feature) {
                if ($feature->feature_image) {
                    $feature['featureImage'] = $feature->featureImage->getJson();
                }
                unset($feature['feature_image']);
                unset($feature['geometry']);

                return $feature;
            })->toArray();

            if ($tax) {
                $json = $tax->getJson();
            }
            $json['features'] = $features;
        } else {
            switch ($taxonomy_name) {
                case 'activity':
                    $tax_name = 'taxonomyActivities';
                    break;
                case 'theme':
                    $tax_name = 'taxonomyThemes';
                    break;
                case 'where':
                    $tax_name = 'taxonomyWheres';
                    break;
                case 'poi_type':
                    $tax_name = 'taxonomyPoiTypes';
                    break;
            }
            $query = EcTrack::where('user_id', $app->user_id)
                ->whereHas($tax_name, function ($q) use ($term_id) {
                    $q->where('id', $term_id);
                });
            $features = $query->orderBy('name')->get()->map(function ($feature) {
                if ($feature->feature_image) {
                    $feature['featureImage'] = $feature->featureImage->getJson();
                }
                unset($feature['feature_image']);
                unset($feature['geometry']);
                unset($feature['slope']);

                return $feature;
            })->toArray();
            $json['features'] = $features;
        }

        return response()->json($json, $code);
    }
}

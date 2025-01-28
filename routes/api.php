<?php

use Wm\WmPackage\Models\User;
use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\AppController;
use Wm\WmPackage\Http\Controllers\AuthController;
use Wm\WmPackage\Http\Controllers\Api\EcPoiController;
use Wm\WmPackage\Http\Controllers\Api\UgcPoiController;
use Wm\WmPackage\Http\Controllers\Api\WalletController;
use Wm\WmPackage\Http\Controllers\Api\AppAuthController;
use Wm\WmPackage\Http\Controllers\Api\EcTrackController;
use Wm\WmPackage\Http\Controllers\Api\LayerAPIController;
use Wm\WmPackage\Http\Controllers\Api\UgcMediaController;
use Wm\WmPackage\Http\Controllers\Api\UgcTrackController;
use Wm\WmPackage\Http\Controllers\Api\V1\AppAPIController;
use Wm\WmPackage\Http\Controllers\Api\WebmappAppController;
use Wm\WmPackage\Http\Controllers\Api\TaxonomyWhenController;
use Wm\WmPackage\Http\Controllers\Api\TaxonomyThemeController;
use Wm\WmPackage\Http\Controllers\Api\TaxonomyWhereController;
use Wm\WmPackage\Http\Controllers\Api\ClassificationController;
use Wm\WmPackage\Http\Controllers\Api\TaxonomyTargetController;
use Wm\WmPackage\Http\Controllers\Api\TaxonomyPoiTypeController;
use Wm\WmPackage\Http\Controllers\Api\EditorialContentController;
use Wm\WmPackage\Http\Controllers\Api\TaxonomyActivityController;
use Wm\WmPackage\Http\Controllers\Api\AppElbrusTaxonomyController;
use Wm\WmPackage\Http\Controllers\Api\UserGeneratedDataController;
use Wm\WmPackage\Http\Controllers\Api\AppElbrusEditorialContentController;

Route::middleware('api')->group(function () {

    Route::post('/auth/login', [AppAuthController::class, 'login'])->name('auth.login');
    Route::middleware('throttle:100,1')->post('/auth/signup', [AppAuthController::class, 'signup'])->name('auth.signup');

    Route::group([
        'middleware' => 'auth.jwt',
        'prefix' => 'auth',
    ], function () {
        Route::post('logout', [AppAuthController::class, 'logout'])->name('auth.logout');
        Route::post('refresh', [AppAuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('me', [AppAuthController::class, 'me'])->name('auth.me');
        Route::post('delete', [AppAuthController::class, 'delete'])->name('auth.delete');
    });

    Route::group([
        'middleware' => 'auth.jwt',
    ], function () {
        Route::prefix('ugc')->name('ugc.')->group(function () {
            Route::prefix('poi')->name('poi.')->group(function () {
                Route::post('store', [UgcPoiController::class, 'store'])->name('store');
                Route::get('index', [UgcPoiController::class, 'index'])->name('index');
                Route::delete('delete/{id}', [UgcPoiController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('track')->name('track.')->group(function () {
                Route::post('store', [UgcTrackController::class, 'store'])->name('store');
                Route::get('index', [UgcTrackController::class, 'index'])->name('index');
                Route::delete('delete/{id}', [UgcTrackController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('media')->name('media.')->group(function () {
                Route::post('store', [UgcMediaController::class, 'store'])->name('store');
                Route::get('index', [UgcMediaController::class, 'index'])->name('index');
                Route::delete('delete/{id}', [UgcMediaController::class, 'destroy'])->name('destroy');
            });
        });
    });
});

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::get('downloadUserUgcMediaGeojson/{user_id}', [UgcMediaController::class, 'downloadUserGeojson'])
    ->name('downloadUserUgcMediaGeojson');

Route::name('api.')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->name('login');
    Route::middleware('throttle:100,1')->post('/auth/signup', [AuthController::class, 'signup'])->name('signup');
    Route::group([
        'middleware' => 'auth.jwt',
        'prefix' => 'auth',
    ], function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
        Route::post('me', [AuthController::class, 'me'])->name('me');
        Route::post('delete', [AuthController::class, 'delete'])->name('delete');
    });

    /**
     * Here should go all the api that need authentication
     */
    Route::group([
        'middleware' => 'auth.jwt',
    ], function () {
        Route::prefix('ugc')->name('ugc.')->group(function () {
            Route::prefix('poi')->name('poi.')->group(function () {
                Route::post('store/{version?}', [UgcPoiController::class, 'store'])->name('store');
                Route::get('index/{version?}', [UgcPoiController::class, 'index'])->name('index');
                Route::get('delete/{id}', [UgcPoiController::class, 'destroy'])->name('destroy');
                Route::post('edit', [UgcPoiController::class, 'edit'])->name('edit');
            });
            Route::prefix('track')->name('track.')->group(function () {
                Route::post('store/{version?}', [UgcTrackController::class, 'store'])->name('store');
                Route::get('index/{version?}', [UgcTrackController::class, 'index'])->name('index');
                Route::get('delete/{id}', [UgcTrackController::class, 'destroy'])->name('destroy');
                Route::post('edit', [UgcTrackController::class, 'edit'])->name('edit');
            });
            Route::prefix('media')->name('media.')->group(function () {
                // TODO: riabilitare quando fixato il bug
                Route::post('store/{version?}', [UgcMediaController::class, 'store'])->name('store');
                Route::get('index/{version?}', [UgcMediaController::class, 'index'])->name('index');
                Route::get('delete/{id}', [UgcMediaController::class, 'destroy'])->name('destroy');
            });
        });
        Route::post('/userGeneratedData/store', [UserGeneratedDataController::class, 'store']);
        Route::post('/usergenerateddata/store', [UserGeneratedDataController::class, 'store']);
        Route::prefix('wallet')->name('wallet.')->group(function () {
            Route::post('/buy', [WalletController::class, 'buy'])->name('buy');
        });
    });

    /**
     * Taxonomies API
     */
    Route::prefix('taxonomy')->name('taxonomy.')->group(function () {
        Route::prefix('activity')->name('activity.')->group(function () {
            Route::get('/{taxonomyActivity}', [TaxonomyActivityController::class, 'show'])->name('json');
            Route::get('/idt/{taxonomyActivity:identifier}', [TaxonomyActivityController::class, 'show'])->name('json.idt');
        });
        Route::prefix('poi_type')->name('poi_type.')->group(function () {
            Route::get('/{taxonomyPoiType}', [TaxonomyPoiTypeController::class, 'show'])->name('json');
            Route::get('/idt/{taxonomyPoiType:identifier}', [TaxonomyPoiTypeController::class, 'show'])->name('json.idt');
        });
        Route::prefix('target')->name('target.')->group(function () {
            Route::get('/{taxonomyTarget}', [TaxonomyTargetController::class, 'show'])->name('json');
            Route::get('/idt/{taxonomyTarget:identifier}', [TaxonomyTargetController::class, 'show'])->name('json.idt');
        });
        Route::prefix('theme')->name('theme.')->group(function () {
            Route::get('/{taxonomyTheme}', [TaxonomyThemeController::class, 'show'])->name('json');
            Route::get('/idt/{taxonomyTheme:identifier}', [TaxonomyThemeController::class, 'show'])->name('json.idt');
        });
        Route::prefix('when')->name('when.')->group(function () {
            Route::get('/{taxonomyWhen}', [TaxonomyWhenController::class, 'show'])->name('json');
            Route::get('/idt/{taxonomyWhen:identifier}', [TaxonomyWhenController::class, 'show'])->name('json.idt');
        });
        Route::prefix('where')->name('where.')->group(function () {
            Route::get('/geojson/{taxonomyWhere}', [TaxonomyWhereController::class, 'getGeoJson'])->name('geojson');
            Route::get('/geojson/idt/{taxonomyWhere:identifier}', [TaxonomyWhereController::class, 'getGeoJson'])->name('geojson.idt');
            Route::get('/{taxonomyWhere}', [TaxonomyWhereController::class, 'show'])->name('json');
            Route::get('/idt/{taxonomyWhere:identifier}', [TaxonomyWhereController::class, 'show'])->name('json.idt');
        });
    });

    /**
     * Ugc API
     */
    Route::prefix('ugc')->name('ugc.')->group(function () {
        Route::prefix('poi')->name('poi.')->group(function () {
            Route::get('/geojson/{id}', [UserGeneratedDataController::class, 'getUgcGeojson'])->name('geojson');
            Route::get('/geojson/{id}/osm2cai', [UserGeneratedDataController::class, 'getUgcGeojsonOsm2cai'])->name('geojson.poi.osm2cai');
            Route::get('/geojson/{app_id}/list', [UserGeneratedDataController::class, 'getUgcList'])->name('ugc_list');
            Route::post('/taxonomy_where', [UserGeneratedDataController::class, 'associateTaxonomyWhereWithUgcFeature'])->name('associate');
        });

        Route::prefix('track')->name('track.')->group(function () {
            Route::get('/geojson/{id}', [UserGeneratedDataController::class, 'getUgcGeojson'])->name('geojson');
            Route::get('/geojson/{id}/osm2cai', [UserGeneratedDataController::class, 'getUgcGeojsonOsm2cai'])->name('geojson.track.osm2cai');
            Route::get('/geojson/{app_id}/list', [UserGeneratedDataController::class, 'getUgcList'])->name('ugc_list');
            Route::post('/taxonomy_where', [UserGeneratedDataController::class, 'associateTaxonomyWhereWithUgcFeature'])->name('associate');
        });
        Route::prefix('media')->name('media.')->group(function () {
            Route::get('/geojson/{id}', [UserGeneratedDataController::class, 'getUgcGeojson'])->name('geojson');
            Route::get('/geojson/{id}/osm2cai', [UserGeneratedDataController::class, 'getUgcGeojsonOsm2cai'])->name('geojson.media.osm2cai');
            Route::get('/geojson/{app_id}/list', [UserGeneratedDataController::class, 'getUgcList'])->name('ugc_list');
            Route::post('/taxonomy_where', [UserGeneratedDataController::class, 'associateTaxonomyWhereWithUgcFeature'])->name('associate');
            Route::get('/download/{id}', [UgcMediaController::class, 'download'])->name('download');
            Route::post('/update/{id}', [UgcMediaController::class, 'update'])->name('update');
        });
    });

    /**
     * ec API
     */
    Route::prefix('ec')->name('ec.')->group(function () {
        Route::prefix('media')->name('media.')->group(function () {
            Route::get('/image/{id}', [EditorialContentController::class, 'getEcImage'])->name('image');
            Route::get('/{id}', [EditorialContentController::class, 'viewEcGeojson'])->name('geojson');
        });
        Route::prefix('poi')->name('poi.')->group(function () {
            Route::put('/update/{ecPoi}', [EditorialContentController::class, 'update'])->name('update');
            Route::prefix('download')->group(function () {
                Route::get('/{id}.geojson', [EditorialContentController::class, 'downloadEcGeojson'])->name('download.geojson');
                Route::get('/{id}.gpx', [EditorialContentController::class, 'downloadEcGpx'])->name('download.gpx');
                Route::get('/{id}.kml', [EditorialContentController::class, 'downloadEcKml'])->name('download.kml');
                Route::get('/{id}', [EditorialContentController::class, 'downloadEcGeojson'])->name('download');
            });
            Route::get('/{ecPoi}/neighbour_media', [EcPoiController::class, 'getNeighbourEcMedia']);
            Route::get('/{ecPoi}/associated_ec_media', [EcPoiController::class, 'getAssociatedEcMedia']);
            Route::get('/{ecPoi}/feature_image', [EcPoiController::class, 'getFeatureImage']);
            Route::get('/{id}.geojson', [EditorialContentController::class, 'viewEcGeojson'])->name('view.geojson');
            Route::get('/{id}.gpx', [EditorialContentController::class, 'viewEcGpx'])->name('view.gpx');
            Route::get('/{id}.kml', [EditorialContentController::class, 'viewEcKml'])->name('view.kml');
            Route::get('/{id}', [EditorialContentController::class, 'viewEcGeojson'])->name('json');
        });
        Route::prefix('track')->name('track.')->group(function () {
            Route::get('/search', [EcTrackController::class, 'search'])->name('search');
            Route::get('/nearest/{lon}/{lat}', [EcTrackController::class, 'nearestToLocation'])->name('nearest_to_location');
            Route::get('/most_viewed', [EcTrackController::class, 'mostViewed'])->name('most_viewed');
            Route::get('/multiple', [EcTrackController::class, 'multiple'])->name('multiple');
            Route::get('/pdf/{ecTrack}', [EcTrackController::class, 'getFeatureCollectionForTrackPdf'])->name('feature_collection_for_pdf');
            Route::middleware('auth.jwt')
                ->prefix('favorite')->name('favorite.')->group(function () {
                    Route::post('/add/{ecTrack}', [EcTrackController::class, 'addFavorite'])->name('add');
                    Route::post('/remove/{ecTrack}', [EcTrackController::class, 'removeFavorite'])->name('remove');
                    Route::post('/toggle/{ecTrack}', [EcTrackController::class, 'toggleFavorite'])->name('toggle');
                    Route::get('/list', [EcTrackController::class, 'listFavorites'])->name('list');
                });
            Route::prefix('download')->group(function () {
                Route::get('/{id}.geojson', [EditorialContentController::class, 'downloadEcGeojson'])->name('download.geojson');
                Route::get('/{id}.gpx', [EditorialContentController::class, 'downloadEcGpx'])->name('download.gpx');
                Route::get('/{id}.kml', [EditorialContentController::class, 'downloadEcKml'])->name('download.kml');
                Route::get('/{id}', [EditorialContentController::class, 'downloadEcGeojson'])->name('download');
            });
            Route::get('/{ecTrack}/neighbour_pois', [EcTrackController::class, 'getNeighbourEcPoi']);
            Route::get('/{ecTrack}/associated_ec_pois', [EcTrackController::class, 'getAssociatedEcPois']);
            Route::get('/{ecTrack}/neighbour_media', [EcTrackController::class, 'getNeighbourEcMedia']);
            Route::get('/{ecTrack}/associated_ec_media', [EcTrackController::class, 'getAssociatedEcMedia']);
            Route::get('/{ecTrack}/feature_image', [EcTrackController::class, 'getFeatureImage']);
            Route::get('/{ecTrack}.geojson', [EcTrackController::class, 'getGeojson'])->name('view.geojson');
            Route::get('/{id}.gpx', [EditorialContentController::class, 'viewEcGpx'])->name('view.gpx');
            Route::get('/{id}.kml', [EditorialContentController::class, 'viewEcKml'])->name('view.kml');
            Route::get('/{id}', [EcTrackController::class, 'getGeojson'])->name('json');
        });
    });

    Route::post('search', [WebmappAppController::class, 'search'])->name('search');

    /**
     * APP API (/app/*)
     */
    Route::prefix('app')->name('app.')->group(function () {
        /**
         * ELBRUS API
         */
        Route::prefix('elbrus')->name('elbrus.')->group(function () {
            Route::get('/{id}/config.json', [AppController::class, 'config'])->name('config');
            Route::get('/{id}/resources/icon.png', [AppController::class, 'icon'])->name('icon');
            Route::get('/{id}/resources/splash.png', [AppController::class, 'splash'])->name('splash');
            Route::get('/{id}/resources/icon_small.png', [AppController::class, 'iconSmall'])->name('icon_small');
            Route::get('/{id}/resources/feature_image.png', [AppController::class, 'featureImage'])->name('feature_image');
            Route::get('/{app}/geojson/ec_poi_{poi}.geojson', [AppElbrusEditorialContentController::class, 'getPoiGeojson'])->name('geojson.poi');
            Route::get('/{app}/geojson/ec_track_{track}.geojson', [AppElbrusEditorialContentController::class, 'getTrackGeojson'])->name('geojson.track');
            Route::get('/{app}/geojson/ec_track_{track}.json', [AppElbrusEditorialContentController::class, 'getTrackJson'])->name('geojson.track.json');
            Route::get('/{app}/taxonomies/track_{taxonomy_name}_{term_id}.json', [AppElbrusTaxonomyController::class, 'getTracksByAppAndTerm'])->where([
                'app_id' => '[0-9]+',
                'taxonomy_name' => '[a-z\_]+',
                'term_id' => '[0-9]+',
            ])->name('track.taxonomies');
            Route::get('/{app}/taxonomies/{taxonomy_name}.json', [AppElbrusTaxonomyController::class, 'getTerms'])->name('taxonomies');
            Route::get('/{app_id}/tiles/map.mbtiles', function ($app_id) {
                return redirect('https://k.webmapp.it/elbrus/' . $app_id . '.mbtiles');
            });
        });
        Route::prefix('webmapp')->name('webmapp.')->group(function () {
            Route::get('/{id}/config.json', [AppController::class, 'config'])->name('config');
            Route::get('/{id}/base-config.json', [AppController::class, 'baseConfig'])->name('baseConfig');
            Route::get('/{app}/classification/ranked_users_near_pois.json', [ClassificationController::class, 'getRankedUsersNearPois'])->name('getRankedUsersNearPois');
            Route::get('/{id}/resources/icon.png', [AppController::class, 'icon'])->name('icon');
            Route::get('/{id}/resources/splash.png', [AppController::class, 'splash'])->name('splash');
            Route::get('/{id}/resources/icon_small.png', [AppController::class, 'iconSmall'])->name('icon_small');
            Route::get('/{id}/resources/feature_image.png', [AppController::class, 'featureImage'])->name('feature_image');
            Route::get('/{id}/resources/icon_notify.png', [AppController::class, 'iconNotify'])->name('icon_notify');
            Route::get('/{id}/resources/logo_homepage.svg', [AppController::class, 'logoHomepage'])->name('logo_homepage');
        });
        Route::prefix('webapp')->name('webapp.')->group(function () {
            Route::get('/{id}/config', [AppController::class, 'config'])->name('config');
            Route::get('/{id}/vector_style', [AppController::class, 'vectorStyle'])->name('vector_style');
            Route::get('/{id}/vector_layer', [AppController::class, 'vectorLayer'])->name('vector_layer');
            Route::get('/{id}/tracks_list', [AppController::class, 'tracksList'])->name('tracks_list');
            Route::get('/{id}/pois_list', [AppController::class, 'poisList'])->name('pois_list');
            Route::get('/{id}/layer/{layer_id}', [AppController::class, 'layer'])->name('layer');
            Route::get('/{id}/taxonomies/{taxonomy_name}/{term_id}', [AppController::class, 'getFeaturesByAppAndTerm'])->where([
                'app_id' => '[0-9]+',
                'taxonomy_name' => '[a-z\_]+',
                'term_id' => '[0-9]+',
            ])->name('feature.taxonomies');
        });
    });

    /**
     * FRONTEND API VERSION 1 (/api/v1)
     */
    Route::prefix('v1')->name('v1.')->group(function () {
        Route::prefix('app')->name('v1.app.')->group(function () {
            Route::get('/{id}/pois.geojson', [AppAPIController::class, 'pois'])->name('app_pois');
            Route::get('/all', [AppAPIController::class, 'all'])->name('apps_json');
        });
    });

    /**
     * FRONTEND API VERSION 2 (/api/v2)
     */
    Route::prefix('v2')->group(function () {
        Route::prefix('app')->group(function () {
            Route::get('/{app}/pois.geojson', [AppAPIController::class, 'pois'])->name('app_pois');
            Route::get('/all', [AppAPIController::class, 'index'])->name('apps_json');
            Route::prefix('webmapp')->name('webmapp.')->group(function () {
                Route::get('/{id}/config.json', [AppController::class, 'config'])->name('config');
                Route::get('/{id}/resources/icon.png', [AppController::class, 'icon'])->name('icon');
                Route::get('/{id}/resources/splash.png', [AppController::class, 'splash'])->name('splash');
                Route::get('/{id}/resources/icon_small.png', [AppController::class, 'iconSmall'])->name('icon_small');
                Route::get('/{id}/resources/feature_image.png', [AppController::class, 'featureImage'])->name('feature_image');
                Route::get('/{id}/resources/icon_notify.png', [AppController::class, 'iconNotify'])->name('icon_notify');
                Route::get('/{id}/resources/logo_homepage.svg', [AppController::class, 'logoHomepage'])->name('logo_homepage');
            });
        });
    });

    // Export API
    Route::prefix('export')->name('export.')->group(function () {
        Route::get('/layers', [LayerAPIController::class, 'index'])->name('export_layers');
        Route::get('/editors', function () {
            return User::whereHas('roles', function ($q) {
                $q->where('role_id', 2);
            })->get()->toArray();
        })->name('export_editors');
        Route::get('/admins', function () {
            return User::whereHas('roles', function ($q) {
                $q->where('role_id', 1);
            })->get()->toArray();
        })->name('export_admins');
        Route::get('/tracks/{email?}', [EcTrackController::class, 'exportTracksByAuthorEmail'])->name('exportTracksByAuthorEmail');
        Route::get('/pois/{email?}', [EcPoiController::class, 'exportPoisByAuthorEmail'])->name('exportPoisByAuthorEmail');
        Route::prefix('taxonomy')->name('taxonomy.')->group(function () {
            Route::get('/themes', [TaxonomyThemeController::class, 'index'])->name('export_themes');
            Route::get('/wheres', [TaxonomyWhereController::class, 'index'])->name('export_wheres_list');
            Route::get('/activities', [TaxonomyActivityController::class, 'index'])->name('export_activities');
            Route::get('/poi_types', [TaxonomyPoiTypeController::class, 'index'])->name('export_poi_types');
            Route::get('/{app}/{name}', function ($app, $name) {
                return Storage::disk('importer')->get("geojson/$app/$name");
            })->name('sardegnasentieriaree');
            Route::get('/{geojson}/{app}/{name}', function ($geojson, $app, $name) {
                return Storage::disk('public')->get("$geojson/$app/$name");
            })->name('getOverlaysPath');
        });
    });

    /**
     * OSF API
     */
    Route::prefix('osf')->name('osf.')->group(function () {
        Route::prefix('track')->name('track.')->group(function () {
            Route::get('/{endpoint_slug}/{source_id}', [EcTrackController::class, 'getTrackGeojsonFromSourceID'])->name('get_ectrack_from_source_id');
        });
        Route::prefix('poi')->name('poi.')->group(function () {
            Route::get('/{endpoint_slug}/{source_id}', [EcPoiController::class, 'getPoiGeojsonFromSourceID'])->name('get_ecpoi_from_source_id');
        });
    });

    /**
     * webapp redirect API with external ID and Slug
     */
    Route::prefix('webapp')->name('webapp.')->group(function () {
        Route::prefix('track')->name('track.')->group(function () {
            Route::get('/{endpoint_slug}/{source_id}', [EcTrackController::class, 'getEcTrackWebappURLFromSourceID'])->name('get_ectrack_webapp_url_from_source_id');
        });
        Route::prefix('poi')->name('poi.')->group(function () {
            Route::get('/{endpoint_slug}/{source_id}', [EcPoiController::class, 'getEcPoiWebappURLFromSourceID'])->name('get_ecpoi_webapp_url_from_source_id');
        });
    });
});

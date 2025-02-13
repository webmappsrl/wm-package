<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\Api\AppAuthController;
use Wm\WmPackage\Http\Controllers\Api\AppController;
use Wm\WmPackage\Http\Controllers\Api\AppElbrusEditorialContentController;
use Wm\WmPackage\Http\Controllers\Api\AppElbrusTaxonomyController;
use Wm\WmPackage\Http\Controllers\Api\ClassificationController;
use Wm\WmPackage\Http\Controllers\Api\EcPoiController;
use Wm\WmPackage\Http\Controllers\Api\EcTrackController;
use Wm\WmPackage\Http\Controllers\Api\EditorialContentController;
use Wm\WmPackage\Http\Controllers\Api\UgcPoiController;
use Wm\WmPackage\Http\Controllers\Api\UgcTrackController;
use Wm\WmPackage\Http\Controllers\Api\V1\AppAPIController;
use Wm\WmPackage\Http\Controllers\Api\WalletController;
use Wm\WmPackage\Http\Controllers\Api\WebmappAppController;

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
        Route::post('/wallet/buy', [WalletController::class, 'buy'])->name('wallet.buy');
    });

    Route::name('.ugc')->prefix('ugc')->middleware('auth.jwt')->group(function () {
        Route::apiResource('pois', UgcPoiController::class)->except('show');
        Route::apiResource('tracks', UgcTrackController::class)->except('show');
    });
});

Route::name('api.')->group(function () {

    /**
     * Taxonomies API
     */
    // Route::prefix('taxonomy')->name('taxonomy.')->group(function () {
    //     Route::prefix('activity')->name('activity.')->group(function () {
    //         Route::get('/{taxonomyActivity}', [TaxonomyActivityController::class, 'show'])->name('json');
    //         Route::get('/idt/{taxonomyActivity:identifier}', [TaxonomyActivityController::class, 'show'])->name('json.idt');
    //     });
    //     Route::prefix('poi_type')->name('poi_type.')->group(function () {
    //         Route::get('/{taxonomyPoiType}', [TaxonomyPoiTypeController::class, 'show'])->name('json');
    //         Route::get('/idt/{taxonomyPoiType:identifier}', [TaxonomyPoiTypeController::class, 'show'])->name('json.idt');
    //     });
    //     Route::prefix('target')->name('target.')->group(function () {
    //         Route::get('/{taxonomyTarget}', [TaxonomyTargetController::class, 'show'])->name('json');
    //         Route::get('/idt/{taxonomyTarget:identifier}', [TaxonomyTargetController::class, 'show'])->name('json.idt');
    //     });
    //     Route::prefix('theme')->name('theme.')->group(function () {
    //         Route::get('/{taxonomyTheme}', [TaxonomyThemeController::class, 'show'])->name('json');
    //         Route::get('/idt/{taxonomyTheme:identifier}', [TaxonomyThemeController::class, 'show'])->name('json.idt');
    //     });
    //     Route::prefix('when')->name('when.')->group(function () {
    //         Route::get('/{taxonomyWhen}', [TaxonomyWhenController::class, 'show'])->name('json');
    //         Route::get('/idt/{taxonomyWhen:identifier}', [TaxonomyWhenController::class, 'show'])->name('json.idt');
    //     });
    // });

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
            Route::get('/{ecPoi}/feature_image', [EcPoiController::class, 'getFeatureImage']);
            Route::get('/{id}.geojson', [EditorialContentController::class, 'viewEcGeojson'])->name('view.geojson');
            Route::get('/{id}.gpx', [EditorialContentController::class, 'viewEcGpx'])->name('view.gpx');
            Route::get('/{id}.kml', [EditorialContentController::class, 'viewEcKml'])->name('view.kml');
            Route::get('/{id}', [EditorialContentController::class, 'viewEcGeojson'])->name('json');
        });
        Route::prefix('track')->name('track.')->group(function () {
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

            Route::get('/{ecTrack}/associated_ec_pois', [EcTrackController::class, 'getAssociatedEcPois']);
            Route::get('/{ecTrack}/feature_image', [EcTrackController::class, 'getFeatureImage']);
            Route::get('/{ecTrack}.geojson', [EcTrackController::class, 'getGeojson'])->name('view.geojson');
            Route::get('/{id}.gpx', [EditorialContentController::class, 'viewEcGpx'])->name('view.gpx');
            Route::get('/{id}.kml', [EditorialContentController::class, 'viewEcKml'])->name('view.kml');
            Route::get('/{ecTrack}', [EcTrackController::class, 'getGeojson'])->name('json');
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
            Route::get('/{app}/config.json', [AppController::class, 'config'])->name('config');
            Route::get('/{app}/resources/icon.png', [AppController::class, 'icon'])->name('icon');
            Route::get('/{app}/resources/splash.png', [AppController::class, 'splash'])->name('splash');
            Route::get('/{app}/resources/icon_small.png', [AppController::class, 'iconSmall'])->name('icon_small');
            Route::get('/{app}/resources/feature_image.png', [AppController::class, 'featureImage'])->name('feature_image');

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
                return redirect('https://k.webmapp.it/elbrus/'.$app_id.'.mbtiles');
            });
        });
        Route::prefix('webmapp')->name('webmapp.')->group(function () {
            Route::get('/{app}/config.json', [AppController::class, 'config'])->name('config');
            Route::get('/{app}/base-config.json', [AppController::class, 'baseConfig'])->name('baseConfig');
            Route::get('/{app}/classification/ranked_users_near_pois.json', [ClassificationController::class, 'getRankedUsersNearPois'])->name('getRankedUsersNearPois');
            Route::get('/{app}/resources/icon.png', [AppController::class, 'icon'])->name('icon');
            Route::get('/{app}/resources/splash.png', [AppController::class, 'splash'])->name('splash');
            Route::get('/{app}/resources/icon_small.png', [AppController::class, 'iconSmall'])->name('icon_small');
            Route::get('/{app}/resources/feature_image.png', [AppController::class, 'featureImage'])->name('feature_image');
            Route::get('/{app}/resources/icon_notify.png', [AppController::class, 'iconNotify'])->name('icon_notify');
            Route::get('/{app}/resources/logo_homepage.svg', [AppController::class, 'logoHomepage'])->name('logo_homepage');
        });
        Route::prefix('webapp')->name('webapp.')->group(function () {
            Route::get('/{app}/config', [AppController::class, 'config'])->name('config');
            Route::get('/{app}/vector_style', [AppController::class, 'vectorStyle'])->name('vector_style');
            Route::get('/{app}/vector_layer', [AppController::class, 'vectorLayer'])->name('vector_layer');
            Route::get('/{app}/tracks_list', [AppController::class, 'tracksList'])->name('tracks_list');
            Route::get('/{app}/pois_list', [AppController::class, 'poisList'])->name('pois_list');
            Route::get('/{app}/layer/{layer}', [AppController::class, 'layer'])->name('layer');
            Route::get('/{app}/taxonomies/{taxonomy_name}/{term_id}', [AppController::class, 'getFeaturesByAppAndTerm'])->where([
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

    // // Export API
    // Route::prefix('export')->name('export.')->group(function () {
    //     Route::get('/layers', [LayerAPIController::class, 'index'])->name('export_layers');
    //     Route::get('/editors', function () {
    //         return User::whereHas('roles', function ($q) {
    //             $q->where('role_id', 2);
    //         })->get()->toArray();
    //     })->name('export_editors');
    //     Route::get('/admins', function () {
    //         return User::whereHas('roles', function ($q) {
    //             $q->where('role_id', 1);
    //         })->get()->toArray();
    //     })->name('export_admins');
    //     Route::get('/tracks/{email?}', [EcTrackController::class, 'exportTracksByAuthorEmail'])->name('exportTracksByAuthorEmail');
    //     Route::get('/pois/{email?}', [EcPoiController::class, 'exportPoisByAuthorEmail'])->name('exportPoisByAuthorEmail');
    //     Route::prefix('taxonomy')->name('taxonomy.')->group(function () {
    //         Route::get('/themes', [TaxonomyThemeController::class, 'index'])->name('export_themes');
    //         Route::get('/activities', [TaxonomyActivityController::class, 'index'])->name('export_activities');
    //         Route::get('/poi_types', [TaxonomyPoiTypeController::class, 'index'])->name('export_poi_types');
    //         Route::get('/{app}/{name}', function ($app, $name) {
    //             return Storage::disk('importer')->get("geojson/$app/$name");
    //         })->name('sardegnasentieriaree');
    //         Route::get('/{geojson}/{app}/{name}', function ($geojson, $app, $name) {
    //             return Storage::disk('public')->get("$geojson/$app/$name");
    //         })->name('getOverlaysPath');
    //     });
    // });
});

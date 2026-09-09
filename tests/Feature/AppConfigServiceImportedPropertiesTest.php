<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;
use Wm\WmPackage\Support\ImportedAppProperties;

uses(DatabaseTransactions::class);

it('emits OPTIONS keys from properties instead of non-existent columns', function () {
    $app = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => [
            'start_url' => '/main/explore',
            'show_edit_link' => false,
            'skip_route_index_download' => true,
            'table_details_show_scale' => true,
            'table_details_show_gpx_download' => false,
            'table_details_show_kml_download' => false,
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS'])->toMatchArray([
        'startUrl' => '/main/explore',
        'showEditLink' => false,
        'skipRouteIndexDownload' => true,
        'show_scale' => true,
        'showGpxDownload' => false,
        'showKmlDownload' => false,
    ]);
});

it('also reflects skip_route_index_download under MAP.pois, a second read site missed by the original setProp() migration', function () {
    // config_section_map() ha un secondo punto di lettura indipendente da OPTIONS
    // (MAP.pois.skipRouteIndexDownload), rimasto sull'attributo colonna inesistente
    // ($this->app->skip_route_index_download, sempre null) dopo la migrazione a setProp()
    // di OPTIONS.skipRouteIndexDownload nello stesso ciclo (oc:8488, fix post-review).
    $app = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => ['skip_route_index_download' => true],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['MAP']['pois']['skipRouteIndexDownload'])->toBeTrue();
});

it('keeps show_favorites on the oc:8176 camelCase behaviour, unaffected by setProp() semantics', function () {
    // show_favorites era già gestito correttamente da oc:8176 prima di questo task: chiave di
    // output showFavorites (camelCase), cast (bool), fallback false se assente in properties.
    // Non passa da setProp()/prop() — quindi non segue il criterio "chiave omessa se null".
    $withValue = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => ['show_favorites' => true],
    ]);
    $withoutValue = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => [],
    ]);

    expect((new AppConfigService($withValue))->config()['OPTIONS']['showFavorites'])->toBeTrue()
        ->and((new AppConfigService($withoutValue))->config()['OPTIONS'])->toHaveKey('showFavorites')
        ->and((new AppConfigService($withoutValue))->config()['OPTIONS']['showFavorites'])->toBeFalse();
});

it('omits a key only when the source value is null, never when it is false or zero', function () {
    $app = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => ['show_edit_link' => false, 'min_zoom_features_in_viewport' => 0],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS'])->toHaveKey('showEditLink')
        ->and($config['OPTIONS']['showEditLink'])->toBeFalse()
        ->and($config['OPTIONS']['minZoomFeaturesInViewport'])->toBe(0)
        ->and($config['OPTIONS'])->not->toHaveKey('startUrl');
});

it('exposes the four previously unread Geohub fields', function () {
    $app = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => [
            'show_embedded_html' => false,
            'show_get_directions' => false,
            'show_media_name' => false,
            'draw_poi_show' => false,
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS'])->toMatchArray([
        'showEmbeddedHtml' => false,
        'showGetDirections' => false,
        'showMediaName' => false,
    ])->and($config['WEBAPP']['draw_poi_show'])->toBeFalse();
});

it('reads the five dual-site fields in OPTIONS even for a non-elbrus app', function () {
    $app = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => [
            'table_details_show_geojson_download' => true,
            'table_details_show_shapefile_download' => true,
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS']['showGeojsonDownload'])->toBeTrue()
        ->and($config['OPTIONS']['showShapefileDownload'])->toBeTrue();
});

it('still gates the TABLES section behind api elbrus', function () {
    $webmapp = App::factory()->createQuietly([
        'api' => 'webmapp', 'properties' => ['table_details_show_ascent' => true],
    ]);
    $elbrus = App::factory()->createQuietly([
        'api' => 'elbrus', 'properties' => ['table_details_show_ascent' => true],
    ]);

    expect((new AppConfigService($webmapp))->config())->not->toHaveKey('TABLES')
        ->and((new AppConfigService($elbrus))->config()['TABLES']['details']['hide_ascent'])->toBeFalse();
});

it('does not hide a table detail that has no value configured', function () {
    // ! null è true: senza default esplicito, un campo non configurato risulterebbe
    // nascosto invece di mostrato — comportamento invariato rispetto a prima del fix.
    $app = App::factory()->createQuietly(['api' => 'elbrus', 'properties' => []]);

    expect((new AppConfigService($app))->config()['TABLES']['details']['hide_ascent'])->toBeFalse();
});

it('emits OFFLINE from properties', function () {
    $app = App::factory()->createQuietly([
        'api' => 'webmapp',
        'properties' => ['offline_enable' => true, 'tracks_on_payment' => true],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['OFFLINE'])->toMatchArray([
        'enable' => true, 'forceAuth' => false, 'tracksOnPayment' => true,
    ]);
});

it('only reads properties keys that ImportedAppProperties declares', function () {
    $source = file_get_contents(__DIR__.'/../../src/Services/Models/App/AppConfigService.php');

    // prop('key', ...) — la chiave è il primo argomento. Case-sensitive: "prop(" non
    // matcha mai la "Prop(" (P maiuscola) dentro "setProp(", quindi non serve escludere
    // esplicitamente le chiamate a setProp() da questo pattern.
    preg_match_all("/prop\(\s*'([a-z_]+)'/", $source, $m1);

    // setProp($qualsiasi_cosa, 'key', 'configKey') — il primo argomento è sempre
    // un'espressione arbitraria (es. $data['OPTIONS']), mai una stringa letterale: la
    // chiave è quindi la PRIMA stringa letterale che segue "setProp(", non la seconda.
    preg_match_all("/setProp\([^,]+,\s*'([a-z_]+)'/", $source, $m2);

    $keys = array_values(array_unique(array_merge($m1[1], $m2[1])));

    expect(array_values(array_diff($keys, ImportedAppProperties::keys())))->toBe([]);
});

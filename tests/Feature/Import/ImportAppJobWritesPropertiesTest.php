<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Import\GeohubImportService;

uses(DatabaseTransactions::class);

function geohubAppRow(array $overrides = []): array
{
    return array_merge([
        'id' => 999,
        'user_id' => 1,
        'name' => 'Synthetic App',
        'sku' => 'it.webmapp.synthetic',
        'primary_color' => '#0055aa',
        'default_feature_color' => '#00aa55',
        'font_family_header' => 'Montserrat',
        'font_family_content' => 'Inter',
        'start_url' => '/main/explore',
        'show_favorites' => true,
        'show_edit_link' => false,
        'skip_route_index_download' => true,
        'draw_poi_show' => false,
        'show_download_tiles_button' => true,
        'min_zoom_features_in_viewport' => 11,
    ], $overrides);
}

it('writes the four theme values under properties.theme, not to the dead columns', function () {
    $result = transformGeohubRow(geohubAppRow());

    expect($result['properties']['theme'])->toMatchArray([
        'primary_color' => '#0055aa',
        'default_feature_color' => '#00aa55',
        'font_family_header' => 'Montserrat',
        'font_family_content' => 'Inter',
    ]);
});

it('writes every mapped Geohub column into properties under its local key', function () {
    $result = transformGeohubRow(geohubAppRow());

    expect($result['properties'])->toMatchArray([
        'start_url' => '/main/explore',
        'show_favorites' => true,
        'skip_route_index_download' => true,
        'min_zoom_features_in_viewport' => 11,
    ]);
});

it('preserves false as false, never dropping it', function () {
    $result = transformGeohubRow(geohubAppRow());

    expect($result['properties'])->toHaveKey('show_edit_link')
        ->and($result['properties']['show_edit_link'])->toBeFalse()
        ->and($result['properties'])->toHaveKey('draw_poi_show')
        ->and($result['properties']['draw_poi_show'])->toBeFalse();
});

it('applies the one declared rename', function () {
    $result = transformGeohubRow(geohubAppRow());

    expect($result['properties'])->toHaveKey('show_download_tiles')
        ->and($result['properties']['show_download_tiles'])->toBeTrue()
        ->and($result['properties'])->not->toHaveKey('show_download_tiles_button');
});

it('no longer writes the four dead theme columns', function () {
    $result = transformGeohubRow(geohubAppRow());

    expect($result)->not->toHaveKey('primary_color')
        ->and($result)->not->toHaveKey('font_family_header')
        ->and($result)->not->toHaveKey('font_family_content')
        ->and($result)->not->toHaveKey('default_feature_color');
});

/**
 * Bug scoperto in produzione durante l'uso reale del campo Nova su
 * track_technical_details (oc:8488): fetchData() legge apps.track_technical_details da
 * Geohub via query grezza, quindi il valore in $data è già una stringa JSON
 * ('{"show_ascent":true,...}'), non un array decodificato. Il copy-through schema-driven di
 * transformData() lo passava così com'era; fill() + il cast locale 'array' lo ri-codificavano
 * con json_encode() su un valore GIÀ stringa JSON, producendo una doppia codifica
 * ('"{\"show_ascent\":true,...}"') che poi fa esplodere qualunque scrittura successiva in
 * stile Nova arrow-notation con un TypeError su Arr::set() ("Cannot access offset of type
 * string on string"). Non specifico a questa colonna: il fix decodifica ogni colonna
 * array/json-cast del modello locale, quindi il test copre anche altre colonne dello stesso
 * tipo (es. keywords) con lo stesso identico meccanismo.
 */
it('decodes an already-JSON-encoded column from Geohub instead of double-encoding it', function () {
    $result = transformGeohubRow(geohubAppRow([
        'track_technical_details' => '{"show_ascent":true,"show_duration_forward":false}',
        'keywords' => '["montagna","trekking"]',
    ]));

    expect($result['track_technical_details'])->toBeArray()
        ->and($result['track_technical_details'])->toBe(['show_ascent' => true, 'show_duration_forward' => false])
        ->and($result['keywords'])->toBeArray()
        ->and($result['keywords'])->toBe(['montagna', 'trekking']);
});

/**
 * Prova end-to-end del fix: un App salvato con questo payload deve restare leggibile e
 * scrivibile via arrow-notation (esattamente l'operazione che Nova esegue su
 * track_technical_details->show_duration_forward), non solo corretto nell'array intermedio
 * di transformData().
 */
it('round-trips through fill() and save() without double-encoding, and stays writable via arrow notation', function () {
    $result = transformGeohubRow(geohubAppRow([
        'track_technical_details' => '{"show_ascent":true,"show_duration_forward":false}',
    ]));

    // App::factory() copre le colonne NOT NULL non presenti in questo payload sintetico
    // (customer_name, ecc.) — non è il payload realistico completo di transformData(), qui
    // interessa solo il comportamento della colonna track_technical_details.
    $app = App::factory()->make();
    $app->forceFill(['track_technical_details' => $result['track_technical_details']]);
    $app->saveQuietly();
    $app->refresh();

    expect($app->track_technical_details)->toBe(['show_ascent' => true, 'show_duration_forward' => false]);

    // Stessa identica scrittura che fa esplodere il bug in Nova: se track_technical_details
    // fosse rimasto doppiamente codificato, questa riga lancerebbe un TypeError.
    $app->{'track_technical_details->show_ascent'} = false;
    $app->saveQuietly();
    $app->refresh();

    expect($app->track_technical_details['show_ascent'])->toBeFalse()
        ->and($app->track_technical_details['show_duration_forward'])->toBeFalse();
});

function transformGeohubRow(array $row): array
{
    // transformData() calls $this->geohubImportService->checkUserExistence(), which hits a
    // "geohub" DB connection not configured in this test environment (no real GeoHub source
    // DB to import users from here). A partial-scope mock avoids requiring that connection
    // while keeping transformData() itself completely real.
    $user = User::factory()->create();

    $service = Mockery::mock(GeohubImportService::class);
    $service->shouldReceive('checkUserExistence')->andReturn($user);

    $job = new ImportAppJob($row['id'], []);
    $method = new ReflectionMethod($job, 'transformData');
    $method->setAccessible(true);

    $prop = new ReflectionProperty($job, 'geohubImportService');
    $prop->setAccessible(true);
    $prop->setValue($job, $service);

    return $method->invoke($job, $row);
}

<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
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

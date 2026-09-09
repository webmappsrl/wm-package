<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Support\ImportedAppProperties;

uses(DatabaseTransactions::class);

it('declares exactly the 37 properties keys imported from Geohub', function () {
    expect(ImportedAppProperties::keys())->toHaveCount(37);
});

it('maps the one Geohub column whose local key differs', function () {
    expect(ImportedAppProperties::geohubColumns())
        ->toHaveKey('show_download_tiles_button')
        ->and(ImportedAppProperties::geohubColumns()['show_download_tiles_button'])
        ->toBe('show_download_tiles');
});

it('resolves every Geohub column to a distinct local key', function () {
    $locals = array_values(ImportedAppProperties::geohubColumns());

    expect($locals)->toHaveCount(count(array_unique($locals)));
});

it('gives every key a supported type', function () {
    foreach (ImportedAppProperties::keys() as $key) {
        expect(ImportedAppProperties::type($key))->toBeIn(['bool', 'text', 'int']);
    }
});

it('excludes the group A keys from Nova generation, because those fields already exist', function () {
    // 30 originarie - 11 rimosse post-review (nessun consumer né in wm-core/webmapp-app né
    // nell'admin di Geohub stesso: start_url, show_edit_link, skip_route_index_download,
    // offline_enable/force_auth, tracks_on_payment, table_details_show_{gpx,kml,geojson,
    // shapefile}_download/scale) = 19.
    expect(ImportedAppProperties::novaKeys())->toHaveCount(19)
        ->and(ImportedAppProperties::novaKeys())->not->toContain('show_travel_mode')
        ->and(ImportedAppProperties::novaKeys())->not->toContain('show_favorites')
        ->and(ImportedAppProperties::novaKeys())->not->toContain('start_url')
        ->and(ImportedAppProperties::novaKeys())->toContain('show_get_directions');
});

it('does not declare any key that is already a real apps column', function () {
    // Se una chiave esistesse anche come colonna, transformData la copierebbe due volte
    // in posti diversi e riapriremmo la doppia sorgente che questo ticket chiude.
    $columns = Schema::getColumnListing('apps');

    expect(array_intersect(ImportedAppProperties::keys(), $columns))->toBe([]);
});

<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Services\Models\App\AppConfigService;

// Gira dallo shard (`vendor/bin/pest wm-package/tests/...`): il TestCase del package vuole il
// vendor del package, che uno shard non ha (vedi .claude/rules/test.md del package).
uses(TestCase::class, DatabaseTransactions::class);

/**
 * Overlay di una FeatureCollection con i colori dati, in un'App con colore principale `#abcdef`.
 *
 * @return array<string, mixed>
 */
function overlayWithColors(?string $fill, ?string $stroke): array
{
    $app = App::factory()->createQuietly([
        'properties' => ['theme' => ['primary_color' => '#abcdef']],
    ]);

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => true,
        'fill_color' => $fill,
        'stroke_color' => $stroke,
    ]);

    $app->forceFill([
        'config_overlays' => [
            'OVERLAYS' => [['box_type' => 'feature_collection', 'feature_collection' => $fc->id]],
        ],
    ])->saveQuietly();

    return (new AppConfigService($app))->config()['MAP']['controls']['overlays'][0];
}

// Sui colori con la trasparenza gli overlay di forestas uscivano neri (oc:9001).
it('uses a fill color with transparency as rgba with its opacity', function () {
    $overlay = overlayWithColors('#a61c0066', '#a61c00');

    expect($overlay['fillColor'])->toBe('rgba(166, 28, 0, 0.4)')
        ->and($overlay['strokeColor'])->toBe('rgba(166, 28, 0, 1)');
});

it('falls back to the app primary color for a 7-digit hex or a hex with a trailing space or newline', function (string $fill) {
    $overlay = overlayWithColors($fill, null);

    expect($overlay['fillColor'])->toBe(hexToRgba('#abcdef'));
})->with(['#a61c006', '#a61c0066 ', "#a61c00\n", "#a61c0066\n"]);

it('still falls back to the app primary color for a 3-digit hex and for plain text', function () {
    $overlay = overlayWithColors('#fff', 'red');

    expect($overlay['fillColor'])->toBe(hexToRgba('#abcdef'))
        ->and($overlay['strokeColor'])->toBe(hexToRgba('#abcdef'));
});

it('leaves sanitizeHexColor unchanged for theme and story share callers', function () {
    expect(sanitizeHexColor('#a61c0066', '#000000'))->toBe('#000000');
});

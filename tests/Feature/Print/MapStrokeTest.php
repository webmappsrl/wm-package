<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Print\PrintService;

uses(DatabaseTransactions::class);

/*
 * Il colore della traccia sulla mappa di stampa (oc:8982): lo shard vince su tutto, poi il layer della
 * traccia (il primo per rank), poi il default di EcTrack.
 */
beforeEach(function () {
    config(['cache.stores.redis.driver' => 'array', 'wm-package.print.map_service.stroke_color' => null]);
    Bus::fake();
    $this->strokeApp = printApp();
});

function strokeLayer(?string $color, int $rank): Layer
{
    return Layer::factory()->createQuietly([
        'app_id' => test()->strokeApp->id,
        'rank' => $rank,
        'properties' => $color === null ? [] : ['color' => $color],
    ]);
}

it('takes the colour of the first layer by rank', function () {
    $track = printSavedTrack(test()->strokeApp);
    $track->associatedLayers()->attach([strokeLayer('#00AA11', 2)->id, strokeLayer('#123456', 1)->id]);

    expect(app(PrintService::class)->mapStroke($track->fresh()))->toBe(['stroke' => '#123456', 'stroke-width' => 4.0]);
});

it('falls back to the EcTrack default without a coloured layer, and for a poi', function () {
    $track = printSavedTrack(test()->strokeApp);
    $track->associatedLayers()->attach(strokeLayer(null, 1)->id);
    $poi = EcPoi::factory()->createQuietly(['app_id' => test()->strokeApp->id, 'osmid' => null]);

    expect(app(PrintService::class)->mapStroke($track->fresh())['stroke'])->toBe(EcTrack::DEFAULT_COLOR_HEX)
        ->and(app(PrintService::class)->mapStroke(printSavedTrack(test()->strokeApp))['stroke'])->toBe(EcTrack::DEFAULT_COLOR_HEX)
        ->and(app(PrintService::class)->mapStroke($poi)['stroke'])->toBe(EcTrack::DEFAULT_COLOR_HEX);
});

it('uses the shard colour over the layer one, and ignores it when it is not a #rrggbb colour', function () {
    $track = printSavedTrack(test()->strokeApp);
    $track->associatedLayers()->attach(strokeLayer('#123456', 1)->id);

    config(['wm-package.print.map_service.stroke_color' => '#2E7D32']);
    expect(app(PrintService::class)->mapStroke($track->fresh())['stroke'])->toBe('#2E7D32');

    config(['wm-package.print.map_service.stroke_color' => 'red']);
    expect(app(PrintService::class)->mapStroke($track->fresh())['stroke'])->toBe('#123456');
});

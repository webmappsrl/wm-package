<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Resources\EcPoiResource;
use Wm\WmPackage\Http\Resources\EcTrackResource;
use Wm\WmPackage\Http\Resources\RelatedEcPoiResource;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config([
        'wm-package.shard_name' => 'testshard',
        'wm-package.ec_track_model' => EcTrack::class,
        'cache.stores.redis.driver' => 'array',
    ]);
    Cache::flush();
    Bus::fake();
    $app = printApp();
    $this->track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
    $this->poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
});

/*
 * Il link alla scheda non sta nel JSON: frontend e plugin lo costruiscono dalla rotta `print.show`
 * quando `OPTIONS.print_track_enable` del config è acceso (scrum del 24/11). Così accendere o
 * spegnere l'opzione dell'App non chiede di riscrivere su wmfe il JSON di tutte le tracce.
 */
it('never puts print_url in the track or poi json, even with the domain and the app option on', function () {
    expect(Route::has('print.show'))->toBeTrue()
        ->and($this->track->getDetailGeojson()['properties'])->not->toHaveKey('print_url')
        ->and((new EcTrackResource($this->track))->toArray(Request::create('/'))['properties'])->not->toHaveKey('print_url')
        ->and((new EcPoiResource($this->poi))->toArray(Request::create('/'))['properties'])->not->toHaveKey('print_url')
        ->and((new RelatedEcPoiResource($this->poi))->toArray(Request::create('/'))['properties'])->not->toHaveKey('print_url');
});

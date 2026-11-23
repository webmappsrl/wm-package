<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Resources\EcPoiResource;
use Wm\WmPackage\Http\Resources\EcTrackResource;
use Wm\WmPackage\Http\Resources\RelatedEcPoiResource;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\WmPackageServiceProvider;

uses(DatabaseTransactions::class);

/** Accende il dominio a runtime, rilanciando ciò che il provider fa al boot. */
function enablePrintDomain(): void
{
    config(['wm-package.features.print.enabled' => true]);
    $provider = app()->getProvider(WmPackageServiceProvider::class);
    (new ReflectionMethod($provider, 'registerEnabledDomains'))->invoke($provider);
    app('router')->getRoutes()->refreshNameLookups();
}

beforeEach(function () {
    config([
        'wm-package.shard_name' => 'testshard',
        'wm-package.ec_track_model' => EcTrack::class,
        'cache.stores.redis.driver' => 'array',
    ]);
    Cache::flush();
    Bus::fake();
    $app = App::factory()->createQuietly();
    $this->track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
    $this->poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
});

it('omits print_url when the domain is disabled', function () {
    expect(Route::has('print.show'))->toBeFalse()
        ->and($this->track->getDetailGeojson()['properties'])->not->toHaveKey('print_url')
        ->and((new EcPoiResource($this->poi))->toArray(Request::create('/'))['properties'])->not->toHaveKey('print_url');
});

it('exposes print_url in the track detail when the domain is enabled', function () {
    enablePrintDomain();

    $expected = route('print.show', ['type' => 'ec-track', 'id' => $this->track->id]);
    $url = $this->track->getDetailGeojson()['properties']['print_url'];

    expect($url)->toBe($expected)->toStartWith('http')->not->toContain('lang');
});

it('exposes print_url in the track resource written to wmfe', function () {
    enablePrintDomain();

    $properties = (new EcTrackResource($this->track))->toArray(Request::create('/'))['properties'];

    expect($properties['print_url'])->toBe(route('print.show', ['type' => 'ec-track', 'id' => $this->track->id]));
});

it('does not put print_url on related pois inside the track json', function () {
    enablePrintDomain();

    $properties = (new EcPoiResource($this->poi))->toArray(Request::create('/'))['properties'];
    $related = (new RelatedEcPoiResource($this->poi))->toArray(Request::create('/'))['properties'];

    expect($properties)->not->toHaveKey('print_url')
        ->and($related)->not->toHaveKey('print_url');
});

it('builds the absolute poi sheet url with sheetUrl when the domain is enabled', function () {
    enablePrintDomain();

    $url = PrintService::sheetUrl('ec-poi', $this->poi->id);

    expect($url)->toBe(route('print.show', ['type' => 'ec-poi', 'id' => $this->poi->id]))
        ->toStartWith('http')->not->toContain('lang');
});

it('returns null from sheetUrl when the domain is disabled', function () {
    expect(PrintService::sheetUrl('ec-poi', $this->poi->id))->toBeNull();
});

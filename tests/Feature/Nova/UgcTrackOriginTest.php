<?php

declare(strict_types=1);

use Laravel\Nova\Http\Requests\ResourceDetailRequest;
use Laravel\Nova\Http\Requests\ResourceIndexRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\AbstractUserResource;
use Wm\WmPackage\Nova\UgcTrack as UgcTrackResource;

class UgcTrackOriginTestUserResource extends AbstractUserResource
{
    public static $model = User::class;
}

if (! class_exists('App\\Nova\\User')) {
    class_alias(UgcTrackOriginTestUserResource::class, 'App\\Nova\\User');
}

beforeEach(function () {
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

function originTestTrack(array $properties, App $app, User $user): UgcTrack
{
    return UgcTrack::factory()->create(['user_id' => $user->id, 'app_id' => $app->id, 'properties' => $properties]);
}

function originTestFieldValue(UgcTrack $track, string $requestClass): mixed
{
    $request = $requestClass::create('/nova-api/ugc-tracks/'.$track->id, 'GET');
    $field = (new UgcTrackResource($track->fresh()))
        ->availableFields($request)
        ->first(fn ($f) => $f->attribute === 'ugc_origin');
    $field?->resolve($track->fresh());

    return $field?->value;
}

it('origin(): un GPX caricato (_gpxType) è importato', function () {
    expect(originTestTrack(['_gpxType' => 'trk', 'distanceFilter' => 10], $this->app_, $this->user)->origin())->toBe('imported');
});

it('origin(): un KML senza distanceFilter è importato', function () {
    expect(originTestTrack(['stroke' => '#ff0000', 'stroke-width' => 2], $this->app_, $this->user)->origin())->toBe('imported');
});

it('origin(): un GeoJSON caricato con properties semplici è importato', function () {
    expect(originTestTrack(['name' => 'Giro', 'description' => 'da file'], $this->app_, $this->user)->origin())->toBe('imported');
});

it('origin(): una registrazione con locations è registrata', function () {
    $locations = [['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0]];

    expect(originTestTrack(['locations' => $locations, 'distanceFilter' => 10], $this->app_, $this->user)->origin())->toBe('recorded');
});

it('origin(): una vecchia registrazione con solo distanceFilter è registrata', function () {
    expect(originTestTrack(['distanceFilter' => 10], $this->app_, $this->user)->origin())->toBe('recorded');
});

it('Nova: il campo Origin nel dettaglio mostra registrata / file importato / GPX', function () {
    $this->actingAs($this->user);

    expect(originTestFieldValue(originTestTrack(['distanceFilter' => 10], $this->app_, $this->user), ResourceDetailRequest::class))->toBe(__('Recorded'));
    expect(originTestFieldValue(originTestTrack(['stroke' => '#f00'], $this->app_, $this->user), ResourceDetailRequest::class))->toBe(__('Imported file'));
    expect(originTestFieldValue(originTestTrack(['_gpxType' => 'rte'], $this->app_, $this->user), ResourceDetailRequest::class))->toBe(__('Imported file (GPX)'));
});

it('Nova: il campo Origin è presente anche nell\'index', function () {
    $this->actingAs($this->user);

    expect(originTestFieldValue(originTestTrack(['distanceFilter' => 10], $this->app_, $this->user), ResourceIndexRequest::class))->toBe(__('Recorded'));
});

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

it('origin(): il solo distanceFilter non basta più (oc:8165)', function () {
    // Cambiato in oc:8165: distanceFilter è un'impostazione del GPS e resta valorizzata anche
    // su un dato importato. Il prezzo, noto: le registrazioni delle versioni 3.1.2-3.1.6
    // dell'app, che non salvavano ancora i punti grezzi, da qui in avanti sono importate.
    expect(originTestTrack(['distanceFilter' => 10], $this->app_, $this->user)->origin())->toBe('imported');
});

it('origin(): properties.import vince su tutto (oc:8764)', function () {
    $locations = [['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0]];

    expect(originTestTrack(['import' => ['format' => 'gpx'], 'locations' => $locations], $this->app_, $this->user)->origin())->toBe('imported');
});

it('Nova: il campo Origin nel dettaglio mostra registrata / file importato / GPX', function () {
    $this->actingAs($this->user);

    $locations = [['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0]];

    expect(originTestFieldValue(originTestTrack(['locations' => $locations], $this->app_, $this->user), ResourceDetailRequest::class))->toBe(__('Recorded'));
    expect(originTestFieldValue(originTestTrack(['stroke' => '#f00'], $this->app_, $this->user), ResourceDetailRequest::class))->toBe(__('Imported file'));
    expect(originTestFieldValue(originTestTrack(['_gpxType' => 'rte'], $this->app_, $this->user), ResourceDetailRequest::class))->toBe(__('Imported file (GPX)'));
});

it('lo scope recorded() dà gli stessi esiti di origin() (oc:8165)', function () {
    // Le due forme della stessa regola — PHP e SQL — devono restare allineate.
    $locations = [['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0]];

    $casi = [
        ['import' => ['format' => 'gpx']],
        ['import' => ['format' => 'geojson'], 'locations' => $locations],
        ['_gpxType' => 'trk'],
        ['locations' => $locations],
        ['locations' => []],
        ['distanceFilter' => 10],
        [],
    ];

    // Senza observer e rileggendo dal database: il confronto dev'essere fra le due forme della
    // regola, non fra quello che un observer ha riscritto e quello che lo scope legge.
    $attesiRegistrati = [];
    foreach ($casi as $properties) {
        $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
            'user_id' => $this->user->id,
            'app_id' => $this->app_->id,
            'properties' => $properties,
        ]))->fresh();

        if ($track->origin() === 'recorded') {
            $attesiRegistrati[] = $track->id;
        }
    }
    sort($attesiRegistrati);

    $daSql = UgcTrack::recorded()->whereIn('user_id', [$this->user->id])->pluck('id')->sort()->values()->all();

    expect($daSql)->toBe($attesiRegistrati);
});

it('Nova: il campo Origin è presente anche nell\'index', function () {
    $this->actingAs($this->user);

    $locations = [['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0]];

    expect(originTestFieldValue(originTestTrack(['locations' => $locations], $this->app_, $this->user), ResourceIndexRequest::class))->toBe(__('Recorded'));
});

<?php

declare(strict_types=1);

use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\ResourceDetailRequest;
use Laravel\Nova\Http\Requests\ResourceIndexRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\AbstractUserResource;
use Wm\WmPackage\Nova\UgcTrack as UgcTrackResource;

class UgcTrackCleanupTestUserResource extends AbstractUserResource
{
    public static $model = User::class;
}

// AbstractUgcResource::fields() referenzia App\Nova\User, la risorsa dello shard: nel package
// da solo non esiste e il BelongsTo non si costruisce.
if (! class_exists('App\\Nova\\User')) {
    class_alias(UgcTrackCleanupTestUserResource::class, 'App\\Nova\\User');
}

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

function trackWithLocations(int $userId, int $appId): UgcTrack
{
    return UgcTrack::factory()->create([
        'user_id' => $userId,
        'app_id' => $appId,
        'properties' => ['name' => 'con locations', 'locations' => [
            ['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
            ['time' => 10_000, 'latitude' => 43.5, 'longitude' => 13.5, 'accuracy' => 7857.0, 'altitude' => 10.0],
            ['time' => 70_000, 'latitude' => 43.0001, 'longitude' => 13.0, 'accuracy' => 6.0, 'altitude' => 10.0],
        ]],
    ]);
}

function updateFieldAttributes(UgcTrack $track): array
{
    $request = NovaRequest::create('/nova-api/ugc-tracks/'.$track->id.'/update-fields', 'GET');

    return (new UgcTrackResource($track->fresh()))
        ->updateFields($request)
        ->map(fn ($field) => $field->attribute)
        ->all();
}

it('nasconde il campo geometria in modifica se la traccia ha locations', function () {
    $this->actingAs($this->user);

    expect(updateFieldAttributes(trackWithLocations($this->user->id, $this->app_->id)))->not->toContain('geometry');
});

it('lascia il campo geometria modificabile se la traccia non ha locations', function () {
    $this->actingAs($this->user);
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'senza']]);

    expect(updateFieldAttributes($track))->toContain('geometry');
});

it('aggiunge alla mappa un tratto tratteggiato per ogni sequenza di punti scartati', function () {
    $collection = trackWithLocations($this->user->id, $this->app_->id)->getFeatureCollectionMap();

    $dashed = array_values(array_filter($collection['features'], fn ($f) => isset($f['properties']['strokeDash'])));

    expect($dashed)->toHaveCount(1);
    expect($dashed[0]['geometry'])->toBe(['type' => 'LineString', 'coordinates' => [[13.0, 43.0], [13.0, 43.0001]]]);
    expect($dashed[0]['properties']['strokeDash'])->toBe([8, 8]);
    expect($dashed[0]['properties']['tooltip'])->toContain('7857');
});

it('non aggiunge tratti per una traccia senza locations', function () {
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'senza']]);

    $dashed = array_filter($track->getFeatureCollectionMap()['features'], fn ($f) => isset($f['properties']['strokeDash']));

    expect($dashed)->toBe([]);
});

function cleanupSummaryText(UgcTrack $track): ?string
{
    $request = NovaRequest::create('/nova-api/ugc-tracks/'.$track->id, 'GET');
    $resource = new UgcTrackResource($track->fresh());
    $field = $resource->detailFields($request)->first(fn ($field) => $field->name === __('GPS cleanup'));
    $field->resolveForDisplay($track->fresh());

    return $field->value;
}

it('nel riepilogo usa il separatore decimale della lingua corrente', function (string $locale, string $expected) {
    $this->actingAs($this->user);
    $track = trackWithLocations($this->user->id, $this->app_->id);
    app()->setLocale($locale);

    expect(cleanupSummaryText($track))->toContain($expected);
})->with([
    'inglese: punto' => ['en', 'km → 0.0 km'],
    'italiano: virgola' => ['it', 'km → 0,0 km'],
    'tedesco: virgola' => ['de', 'km → 0,0 km'],
]);

function geometryFieldLegend(UgcTrack $track, string $requestClass = ResourceDetailRequest::class): ?array
{
    $request = $requestClass::create('/nova-api/ugc-tracks/'.$track->id, 'GET');
    $field = (new UgcTrackResource($track->fresh()))
        ->availableFields($request)
        ->first(fn ($field) => $field->attribute === 'geometry');

    return $field->meta['legend'] ?? null;
}

it('sulla mappa del dettaglio mostra la legenda se ci sono tratti ricostruiti', function () {
    $this->actingAs($this->user);

    $legend = geometryFieldLegend(trackWithLocations($this->user->id, $this->app_->id));

    expect($legend)->toHaveCount(2);
    expect($legend[0])->toBe(['label' => __('Recorded track'), 'color' => 'rgba(0, 0, 255, 1)', 'dash' => false]);
    expect($legend[1]['color'])->toBe(UgcTrack::RECONSTRUCTED_SEGMENT_COLOR);
    expect($legend[1]['dash'])->toBeTrue();
});

it('sulla mappa del dettaglio non mostra la legenda senza locations o senza tratti ricostruiti', function () {
    $this->actingAs($this->user);
    $senza = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'senza']]);
    $pulita = UgcTrack::factory()->create([
        'user_id' => $this->user->id,
        'app_id' => $this->app_->id,
        'properties' => ['name' => 'pulita', 'locations' => [
            ['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
            ['time' => 10_000, 'latitude' => 43.0001, 'longitude' => 13.0, 'accuracy' => 6.0, 'altitude' => 10.0],
        ]],
    ]);

    expect(geometryFieldLegend($senza) ?? [])->toBe([]);
    expect(geometryFieldLegend($pulita) ?? [])->toBe([]);
});

it('nell\'index non calcola la legenda, perché fields() gira per ogni riga', function () {
    $this->actingAs($this->user);
    $track = trackWithLocations($this->user->id, $this->app_->id);

    expect(geometryFieldLegend($track, ResourceDetailRequest::class))->toHaveCount(2);
    expect(geometryFieldLegend($track, ResourceIndexRequest::class) ?? [])->toBe([]);
});

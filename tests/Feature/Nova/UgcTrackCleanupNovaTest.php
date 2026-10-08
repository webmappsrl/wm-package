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

it('segna la linea della traccia per il profilo altimetrico anche con i tratti ricostruiti', function () {
    $features = trackWithLocations($this->user->id, $this->app_->id)->getFeatureCollectionMap()['features'];

    $track = array_values(array_filter($features, fn ($f) => ! isset($f['properties']['strokeDash'])));
    $dashed = array_values(array_filter($features, fn ($f) => isset($f['properties']['strokeDash'])));

    expect($track)->toHaveCount(1);
    expect($track[0]['properties']['slopeChart'] ?? null)->toBeTrue();
    expect($dashed)->not->toBeEmpty();
    foreach ($dashed as $segment) {
        expect($segment['properties'])->not->toHaveKey('slopeChart');
    }
});

it('non aggiunge tratti per una traccia senza locations', function () {
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'senza']]);

    $dashed = array_filter($track->getFeatureCollectionMap()['features'], fn ($f) => isset($f['properties']['strokeDash']));

    expect($dashed)->toBe([]);
});

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

function geometryFieldMeta(UgcTrack $track, string $key, string $requestClass = ResourceDetailRequest::class): mixed
{
    $request = $requestClass::create('/nova-api/ugc-tracks/'.$track->id, 'GET');
    $field = (new UgcTrackResource($track->fresh()))
        ->availableFields($request)
        ->first(fn ($field) => $field->attribute === 'geometry');

    return $field->meta[$key] ?? null;
}

/** Traccia con uno stats scritto a mano (saveQuietly: l'observer lo ricalcolerebbe). */
function trackWithStats(int $userId, int $appId, array $stats): UgcTrack
{
    $track = UgcTrack::factory()->create(['user_id' => $userId, 'app_id' => $appId, 'properties' => ['name' => 'con stats']]);
    $track->properties = ['name' => 'con stats', 'stats' => $stats];
    $track->saveQuietly();

    return $track;
}

it('sotto la mappa del dettaglio mostra i dati tecnici di stats, formattati nella lingua corrente', function (string $locale, array $expected) {
    $this->actingAs($this->user);
    $track = trackWithStats($this->user->id, $this->app_->id, [
        'distance' => 9.37, 'ascent' => 313, 'descent' => 610, 'ele_min' => 935, 'ele_max' => 1456,
        'ele_from' => 1236, 'ele_to' => 939, 'duration' => 165, 'duration_moving' => 144,
        'avg_speed' => 3.4, 'max_speed' => 6.5, 'computed_at' => '2026-10-08T11:42:47Z',
    ]);
    app()->setLocale($locale);

    $rows = geometryFieldMeta($track, 'technicalData');

    expect(array_column($rows, 'value', 'label'))->toBe($expected);
})->with([
    'inglese: punto' => ['en', [
        'Distance' => '9.37 km', 'Ascent' => '313 m', 'Descent' => '610 m',
        'Min elevation' => '935 m', 'Max elevation' => '1456 m', 'Time' => '165 min',
        'Moving time' => '144 min', 'Average speed' => '3.4 km/h', 'Max speed' => '6.5 km/h',
    ]],
    'italiano: virgola' => ['it', [
        'Distanza' => '9,37 km', 'Salita' => '313 m', 'Discesa' => '610 m',
        'Quota minima' => '935 m', 'Quota massima' => '1456 m', 'Tempo' => '165 min',
        'Tempo in movimento' => '144 min', 'Velocità media' => '3,4 km/h', 'Velocità massima' => '6,5 km/h',
    ]],
]);

it('nei dati tecnici un valore null diventa un trattino', function () {
    $this->actingAs($this->user);
    $track = trackWithStats($this->user->id, $this->app_->id, [
        'distance' => 0.5, 'ascent' => null, 'descent' => null, 'ele_min' => null, 'ele_max' => null,
        'ele_from' => null, 'ele_to' => null, 'duration' => null, 'duration_moving' => null,
        'avg_speed' => null, 'max_speed' => null, 'computed_at' => '2026-10-08T11:42:47Z',
    ]);
    app()->setLocale('en');

    $values = array_column(geometryFieldMeta($track, 'technicalData'), 'value', 'label');

    expect($values['Distance'])->toBe('0.50 km');
    expect($values['Ascent'])->toBe('—');
    expect($values['Average speed'])->toBe('—');
});

it('senza stats, o nell\'index, non mostra i dati tecnici', function () {
    $this->actingAs($this->user);
    $senza = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'senza']]);
    $con = trackWithStats($this->user->id, $this->app_->id, ['distance' => 1.0]);

    expect(geometryFieldMeta($senza, 'technicalData') ?? [])->toBe([]);
    expect(geometryFieldMeta($con, 'technicalData', ResourceIndexRequest::class) ?? [])->toBe([]);
});

it('non ha più i campi testo «GPS cleanup» e «Technical data»', function () {
    $this->actingAs($this->user);
    $track = trackWithLocations($this->user->id, $this->app_->id);

    $request = NovaRequest::create('/nova-api/ugc-tracks/'.$track->id, 'GET');
    $names = (new UgcTrackResource($track->fresh()))->detailFields($request)->map(fn ($field) => $field->name)->all();

    expect($names)->not->toContain('GPS cleanup');
    expect($names)->not->toContain('Technical data');
});

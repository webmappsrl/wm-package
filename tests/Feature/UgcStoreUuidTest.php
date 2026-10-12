<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

beforeEach(function () {
    config()->set('wm-package.shard_name', 'test_shard');
    config()->set('medialibrary.disk_name', 'public');
    Storage::fake('s3');
    Storage::fake('wmfe');
    Storage::fake('public');
    $this->withoutMiddleware('auth.jwt');
    $this->artisan('jwt:secret --always-no');
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    $this->actingAs($this->user, 'api');
});

/** Richiesta come la costruisce l'app (wm-core `_buildFormData`): multipart con `feature` in JSON. */
function trackFeature(string $uuid, array $extra = []): array
{
    return [
        'type' => 'Feature',
        'properties' => array_merge([
            'name' => 'Traccia di prova',
            'uuid' => $uuid,
            'app_id' => test()->app_->id,
            'form' => ['id' => 'track', 'title' => 'Traccia di prova', 'difficulty' => 'easy'],
            'updatedAt' => '2026-06-16T14:00:58.399Z',
        ], $extra),
        'geometry' => ['type' => 'LineString', 'coordinates' => [[13.0, 43.0, 10], [13.001, 43.001, 10]]],
    ];
}

function postAppStyle(string $url, array $feature, array $files = [])
{
    // Come l'app: il campo images[] c'è solo se ci sono foto.
    $data = ['feature' => json_encode($feature)];
    if ($files !== []) {
        $data['images'] = $files;
    }

    return test()->post($url, $data);
}

it('crea una traccia nuova quando l\'uuid non esiste', function () {
    $response = postAppStyle('api/ugc/track/store', trackFeature('uuid-nuovo'));

    $response->assertStatus(201);
    expect(UgcTrack::where('properties->uuid', 'uuid-nuovo')->count())->toBe(1);
});

it('aggiorna la traccia esistente quando l\'uuid c\'è già (richiesta multipart come l\'app)', function () {
    $first = postAppStyle('api/ugc/track/store', trackFeature('uuid-retry'))->json('id');

    $second = postAppStyle('api/ugc/track/store', trackFeature('uuid-retry', ['form' => ['id' => 'track', 'difficulty' => 'medium']]));

    $second->assertStatus(201);
    expect($second->json('id'))->toBe($first)
        ->and(UgcTrack::where('properties->uuid', 'uuid-retry')->count())->toBe(1)
        ->and(UgcTrack::find($first)->properties['form']['difficulty'])->toBe('medium');
});

it('conserva le chiavi che l\'app non manda (es. layer_id assegnato dal server)', function () {
    $id = postAppStyle('api/ugc/track/store', trackFeature('uuid-merge'))->json('id');
    $track = UgcTrack::find($id);
    UgcTrack::withoutEvents(function () use ($track) {
        $props = $track->properties;
        $props['layer_id'] = 42;
        $props['chiave_del_progetto'] = false;
        $track->properties = $props;
        $track->saveQuietly();
    });

    postAppStyle('api/ugc/track/store', trackFeature('uuid-merge'))->assertStatus(201);

    expect(UgcTrack::where('properties->uuid', 'uuid-merge')->count())->toBe(1);
    $props = UgcTrack::find($id)->properties;
    expect($props['layer_id'])->toBe(42)
        ->and($props['chiave_del_progetto'])->toBeFalse();
});

it('con duplicati già presenti aggiorna sempre la riga più vecchia', function () {
    $older = postAppStyle('api/ugc/track/store', trackFeature('uuid-doppio'))->json('id');
    $newer = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $this->user->id, 'app_id' => $this->app_->id,
        'properties' => ['name' => 'copia', 'uuid' => 'uuid-doppio'],
    ]))->id;
    expect($newer)->toBeGreaterThan($older);

    $response = postAppStyle('api/ugc/track/store', trackFeature('uuid-doppio'));

    expect($response->json('id'))->toBe($older);
});

it('senza uuid crea sempre un record nuovo', function () {
    $feature = trackFeature('x');
    unset($feature['properties']['uuid']);

    postAppStyle('api/ugc/track/store', $feature)->assertStatus(201);
    postAppStyle('api/ugc/track/store', $feature)->assertStatus(201);

    expect(UgcTrack::where('name', 'Traccia di prova')->count())->toBe(2);
});

it('vale anche per i POI con la richiesta multipart dell\'app', function () {
    $poi = fn () => [
        'type' => 'Feature',
        'properties' => ['name' => 'Poi', 'uuid' => 'uuid-poi', 'app_id' => $this->app_->id, 'form' => ['id' => 'poi']],
        'geometry' => ['type' => 'Point', 'coordinates' => [13.0, 43.0]],
    ];

    $first = postAppStyle('api/ugc/poi/store', $poi())->json('id');
    $second = postAppStyle('api/ugc/poi/store', $poi())->json('id');

    expect($second)->toBe($first)
        ->and(UgcPoi::where('properties->uuid', 'uuid-poi')->count())->toBe(1);
});

it('uno store con l\'uuid di una traccia di un altro utente non la tocca e crea un record nuovo', function () {
    $ownerTrackId = postAppStyle('api/ugc/track/store', trackFeature('uuid-altrui', ['form' => ['id' => 'track', 'difficulty' => 'easy']]))->json('id');

    $other = User::factory()->create(['app_id' => $this->app_->id]);
    $this->actingAs($other, 'api');
    $response = postAppStyle('api/ugc/track/store', trackFeature('uuid-altrui', ['form' => ['id' => 'track', 'difficulty' => 'hard']]));

    $response->assertStatus(201);
    expect($response->json('id'))->not->toBe($ownerTrackId)
        ->and(UgcTrack::find($ownerTrackId)->properties['form']['difficulty'])->toBe('easy')
        ->and(UgcTrack::find($ownerTrackId)->user_id)->toBe($this->user->id)
        ->and(UgcTrack::find($response->json('id'))->user_id)->toBe($other->id);
});

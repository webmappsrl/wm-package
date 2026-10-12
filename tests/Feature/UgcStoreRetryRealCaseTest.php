<?php

declare(strict_types=1);

/*
 * Caso reale (oc:8718), lato store: retry dell'app ricostruiti dalle tracce del DB di sviluppo di
 * camminiditalia (dump della produzione, 07/10/2026). Il payload è quello che l'app costruisce in
 * `_buildFormData()` (wm-core): multipart con la feature in JSON nel campo `feature` e le foto in
 * `images[]`. Properties vere ridotte (niente `locations`), geometrie accorciate, foto sostituite
 * da JPEG minuscoli con gli stessi doppioni delle foto vere.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
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

/** Device reale dei gruppi 806f2dc5 e 3e301343 (Android, app 3.1.9). */
function realAndroidDevice(): array
{
    return ['name' => 'S200', 'model' => 'S200', 'platform' => 'android', 'isVirtual' => false, 'osVersion' => '14',
        'appVersion' => '3.1.9', 'manufacturer' => 'DOOGEE', 'webViewVersion' => '147.0.7727.55',
        'operatingSystem' => 'android', 'androidSDKVersion' => 34];
}

function realFeature(array $properties): string
{
    return json_encode([
        'type' => 'Feature',
        'properties' => $properties + ['app_id' => test()->app_->id],
        'geometry' => ['type' => 'LineString', 'coordinates' => [[10.41, 44.11, 300], [10.42, 44.12, 320], [10.43, 44.13, 310]]],
    ]);
}

/** JPEG minuscolo: stessa etichetta, stessi byte. */
function realPhoto(string $label): UploadedFile
{
    $side = 8 + ord($label) - ord('A');

    return UploadedFile::fake()->image("image_{$label}.jpg", $side, $side);
}

function retryStore(array $properties, array $photoLabels)
{
    $data = ['feature' => realFeature($properties)];
    if ($photoLabels !== []) {
        $data['images'] = array_map('realPhoto', $photoLabels);
    }

    return test()->post('api/v2/ugc/track/store', $data);
}

it('806f2dc5: il primo invio salva una foto sola, il retry con tutte e tre completa la traccia', function () {
    $properties = [
        'name' => 'track 2026/04/29 17:21',
        'uuid' => '806f2dc5-7a46-4d0e-aec7-accf1b2bb896',
        'form' => ['id' => 'track', 'index' => 0, 'title' => 'track 2026/04/29 17:21', 'difficulty' => 'easy', 'description' => ''],
        'createdAt' => '2026-04-29T15:22:43.925Z',
        'updatedAt' => '2026-04-29T15:22:43.925Z',
        'distanceFilter' => 10,
        'device' => realAndroidDevice(),
    ];

    // Stato lasciato dal primo invio: traccia salvata, solo la prima foto arrivata.
    $id = retryStore($properties, ['A'])->assertStatus(201)->json('id');

    // Retry dell'app con tutte le foto (la prima uguale a quella già salvata).
    $retry = retryStore($properties, ['A', 'B', 'C']);

    $retry->assertStatus(201);
    expect($retry->json('id'))->toBe($id)
        ->and(UgcTrack::where('properties->uuid', $properties['uuid'])->count())->toBe(1)
        ->and(UgcTrack::find($id)->getMedia('default'))->toHaveCount(3);
});

it('3e301343: due retry con le stesse tre foto non duplicano né la traccia né le foto', function () {
    $properties = [
        'name' => 'track 2026/04/27 16:12',
        'uuid' => '3e301343-ee5f-4e0e-b2e4-654770410f39',
        'form' => ['id' => 'track', 'index' => 0, 'title' => 'track 2026/04/27 16:12', 'difficulty' => 'easy', 'description' => ''],
        'createdAt' => '2026-04-27T14:14:42.369Z',
        'updatedAt' => '2026-04-27T14:14:42.369Z',
        'distanceFilter' => 10,
        'device' => realAndroidDevice(),
    ];

    $id = retryStore($properties, ['A', 'B', 'C'])->assertStatus(201)->json('id');
    retryStore($properties, ['A', 'B', 'C'])->assertStatus(201);
    retryStore($properties, ['A', 'B', 'C'])->assertStatus(201);

    expect(UgcTrack::where('properties->uuid', $properties['uuid'])->count())->toBe(1)
        ->and(UgcTrack::find($id)->getMedia('default'))->toHaveCount(3);
});

it('traccia 321 (oc:8466): un retry non annulla il cammino corretto a mano', function () {
    // GPX importato con l'app 3.1.15: il form non ha layer_id, il cammino lo assegna il server.
    $properties = [
        'name' => 'Viadeglidei',
        'uuid' => '15696db3-ba78-4428-9fc7-8717c55d9608',
        'form' => ['id' => 'track', 'index' => 0, 'title' => 'Viadeglidei', 'difficulty' => 'medium', 'description' => ''],
        'time' => '2017-07-24T07:22:03Z',
        '_gpxType' => 'trk',
        'createdAt' => '2026-09-01T01:54:12.613Z',
        'updatedAt' => '2026-09-01T01:54:12.613Z',
        'device' => ['name' => 'iPhone', 'model' => 'iPhone15,2', 'platform' => 'ios', 'appVersion' => '3.1.15', 'operatingSystem' => 'ios'],
    ];
    $id = retryStore($properties, [])->assertStatus(201)->json('id');

    // Correzione dell'Administrator da Nova (oc:8575): come la scrive UgcLayerAssignment::apply()
    // in camminiditalia. Il server aveva associato Via Mater Dei (71) invece di Via degli Dei.
    $track = UgcTrack::find($id);
    $corrected = $track->properties;
    $corrected['layer_id'] = 72;
    $corrected['layer_id_auto_resolved'] = false;
    $corrected['form']['layer_id'] = 72;
    $track->properties = $corrected;
    $track->saveQuietly();

    retryStore($properties, [])->assertStatus(201);

    $after = UgcTrack::find($id)->properties;
    expect(UgcTrack::where('properties->uuid', $properties['uuid'])->count())->toBe(1)
        ->and($after['layer_id'])->toBe(72)
        ->and($after['layer_id_auto_resolved'])->toBeFalse();
    // form.layer_id torna quello del retry (l'app manda il form intero): limite accettato, lo
    // legge solo l'observer alla creazione (oc:8718, challenge).
});

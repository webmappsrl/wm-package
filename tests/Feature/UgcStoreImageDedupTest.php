<?php

declare(strict_types=1);

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

function featureWithUuid(string $uuid): string
{
    return json_encode([
        'type' => 'Feature',
        'properties' => ['name' => 'T', 'uuid' => $uuid, 'app_id' => test()->app_->id],
        'geometry' => ['type' => 'LineString', 'coordinates' => [[13.0, 43.0, 10], [13.001, 43.001, 10]]],
    ]);
}

/** JPEG vero generato da GD: a parità di seme (quindi di dimensioni) i byte sono gli stessi. */
function jpeg(string $seed): UploadedFile
{
    $side = 10 + ord($seed) - ord('a');

    return UploadedFile::fake()->image("image_{$seed}.jpg", $side, $side);
}

it('un retry con le stesse immagini non le duplica', function () {
    $id = $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u1'), 'images' => [jpeg('a'), jpeg('b')]])->json('id');
    $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u1'), 'images' => [jpeg('a'), jpeg('b')]]);

    expect(UgcTrack::find($id)->getMedia('default'))->toHaveCount(2);
});

it('un retry con un\'immagine nuova aggiunge solo quella', function () {
    $id = $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u2'), 'images' => [jpeg('a')]])->json('id');
    $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u2'), 'images' => [jpeg('a'), jpeg('c')]]);

    expect(UgcTrack::find($id)->getMedia('default'))->toHaveCount(2);
});

it('salva lo sha256 nei custom_properties del media', function () {
    $file = jpeg('a');
    $expected = hash_file('sha256', $file->getRealPath());
    $id = $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u4'), 'images' => [$file]])->json('id');

    $media = UgcTrack::find($id)->getFirstMedia('default');
    expect($media->getCustomProperty('sha256'))->toBe($expected);
});

it('riconosce un media vecchio senza hash, calcolandolo al volo', function () {
    $id = $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u5'), 'images' => [jpeg('a')]])->json('id');
    $media = UgcTrack::find($id)->getFirstMedia('default');
    $media->forgetCustomProperty('sha256');
    $media->save();

    $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u5'), 'images' => [jpeg('a')]]);

    $track = UgcTrack::find($id);
    expect($track->getMedia('default'))->toHaveCount(1)
        ->and($track->getFirstMedia('default')->getCustomProperty('sha256'))->not->toBeNull();
});

it('un media vecchio con il file non leggibile non blocca il retry', function () {
    $id = $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u6'), 'images' => [jpeg('a')]])->json('id');
    $media = UgcTrack::find($id)->getFirstMedia('default');
    $media->forgetCustomProperty('sha256');
    $media->save();
    Storage::disk($media->disk)->delete($media->getPathRelativeToRoot());

    $response = $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u6'), 'images' => [jpeg('b')]]);

    $response->assertStatus(201);
    expect(UgcTrack::find($id)->getMedia('default'))->toHaveCount(2);
});

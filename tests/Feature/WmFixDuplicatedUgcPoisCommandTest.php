<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

/** POI salvato senza observer, geometria scritta in SQL. */
function dupPoi(int $userId, int $appId, string $uuid, array $props = [], string $wkt = 'POINT Z (11.25 43.77 50)', ?string $createdAt = null): UgcPoi
{
    $poi = UgcPoi::withoutEvents(fn () => UgcPoi::factory()->create([
        'user_id' => $userId,
        'app_id' => $appId,
        'name' => $props['name'] ?? 'Fontana',
        'properties' => array_merge(['name' => 'Fontana', 'uuid' => $uuid, 'form' => ['id' => 'poi']], $props),
    ]));
    DB::update('UPDATE ugc_pois SET geometry = ST_GeomFromEWKT(?), created_at = COALESCE(?::timestamp, created_at) WHERE id = ?', ["SRID=4326;{$wkt}", $createdAt, $poi->id]);

    return $poi->refresh();
}

function poiPhoto(string $label): UploadedFile
{
    return UploadedFile::fake()->image("p{$label}.jpg", 8 + ord($label) - ord('A'), 8);
}

it('unisce i POI duplicati nel più vecchio, sposta le foto scartando i doppioni e archivia le copie', function () {
    $p = dupPoi($this->user->id, $this->app_->id, 'poi-1', ['updatedAt' => '2026-05-01T10:00:00Z', 'form' => ['id' => 'poi', 'title' => 'Fontana']], createdAt: '2026-05-01 10:01:00');
    $c = dupPoi($this->user->id, $this->app_->id, 'poi-1', ['updatedAt' => '2026-05-01T10:30:00Z', 'form' => ['id' => 'poi', 'title' => 'Fontana del borgo']], createdAt: '2026-05-01 10:20:00');
    $p->addMedia(poiPhoto('A'))->toMediaCollection('default');
    $c->addMedia(poiPhoto('A'))->toMediaCollection('default');
    $c->addMedia(poiPhoto('B'))->toMediaCollection('default');

    $this->artisan('wm:fix-duplicated-ugc', ['--type' => 'pois', '--execute' => true])
        ->expectsOutputToContain('1 gruppi trovati')
        ->assertSuccessful();

    expect(UgcPoi::find($c->id))->toBeNull()
        ->and(UgcPoi::find($p->id)->properties['form']['title'])->toBe('Fontana del borgo')
        ->and(UgcPoi::find($p->id)->getMedia('default'))->toHaveCount(2)
        ->and(DB::table('ugc_duplicates_archive')->where('original_id', $c->id)->value('model_type'))->toBe((new UgcPoi)->getMorphClass())
        ->and(DB::table('ugc_duplicates_archive')->where('original_id', $c->id)->value('reference_id'))->toBe($p->id);
});

it('segnala da verificare due POI con lo stesso uuid a più di un metro', function () {
    dupPoi($this->user->id, $this->app_->id, 'poi-2');
    $c = dupPoi($this->user->id, $this->app_->id, 'poi-2', [], 'POINT Z (11.25 43.7701 50)');

    $this->artisan('wm:fix-duplicated-ugc', ['--type' => 'pois', '--execute' => true])
        ->expectsOutputToContain('da verificare')
        ->assertSuccessful();

    expect(UgcPoi::find($c->id))->not->toBeNull();
});

it('--type limita il lavoro a tracce o POI, e senza --type li fa entrambi', function () {
    $poiCopy = dupPoi($this->user->id, $this->app_->id, 'mix-p');
    $poiCopy2 = dupPoi($this->user->id, $this->app_->id, 'mix-p');
    $trackA = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'T', 'uuid' => 'mix-t']]));
    $trackB = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'T', 'uuid' => 'mix-t']]));
    DB::update("UPDATE ugc_tracks SET geometry = ST_GeomFromEWKT('SRID=4326;MULTILINESTRING Z ((13 43 10, 13.001 43.001 10))') WHERE id IN (?, ?)", [$trackA->id, $trackB->id]);

    $this->artisan('wm:fix-duplicated-ugc', ['--type' => 'tracks', '--execute' => true])->assertSuccessful();
    expect(UgcTrack::find($trackB->id))->toBeNull()
        ->and(UgcPoi::find($poiCopy2->id))->not->toBeNull();

    $this->artisan('wm:fix-duplicated-ugc', ['--execute' => true])
        ->expectsOutputToContain('1 gruppi trovati')
        ->assertSuccessful();
    expect(UgcPoi::find($poiCopy2->id))->toBeNull();
});

it('rifiuta un --type sconosciuto', function () {
    $this->artisan('wm:fix-duplicated-ugc', ['--type' => 'media'])->assertFailed();
});

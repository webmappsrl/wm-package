<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\MatchUgcTrackToEcTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    Bus::fake();
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

function trackWithLayerId(int $userId, int $appId): int
{
    $id = matchingUgcTrack($userId, $appId, 'MULTILINESTRING((11 44, 11 44.001))');
    DB::update("UPDATE ugc_tracks SET properties = '{\"layer_id\": 7, \"layer_id_auto_resolved\": true, \"name\": \"x\"}'::jsonb WHERE id = ?", [$id]);

    return $id;
}

it('con --dry-run conta senza scrivere né accodare', function () {
    $id = trackWithLayerId($this->user->id, $this->app_->id);

    $this->artisan('wm:match-ugc-tracks', ['--dry-run' => true])
        ->expectsOutputToContain('1 tracce da calcolare')
        ->expectsOutputToContain('1 tracce con layer_id da togliere')
        ->assertSuccessful();

    Bus::assertNotDispatched(MatchUgcTrackToEcTracksJob::class);
    expect(DB::table('ugc_tracks')->where('id', $id)->value('properties'))->toContain('layer_id');
});

it('senza --dry-run toglie layer_id e accoda il calcolo, lasciando le altre properties', function () {
    $id = trackWithLayerId($this->user->id, $this->app_->id);

    $this->artisan('wm:match-ugc-tracks')->assertSuccessful();

    $properties = json_decode(DB::table('ugc_tracks')->where('id', $id)->value('properties'), true);
    expect($properties)->not->toHaveKey('layer_id');
    expect($properties)->not->toHaveKey('layer_id_auto_resolved');
    expect($properties['name'])->toBe('x');
    Bus::assertDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $id);
});

it('è idempotente: una traccia già calcolata con i parametri attuali non viene riaccodata', function () {
    $id = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    UgcTrackMatchingService::make()->matchUgcTrack($id);

    $this->artisan('wm:match-ugc-tracks')->assertSuccessful();

    Bus::assertNotDispatched(MatchUgcTrackToEcTracksJob::class);
});

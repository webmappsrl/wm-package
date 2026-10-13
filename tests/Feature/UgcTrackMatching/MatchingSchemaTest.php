<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

it('crea le tabelle di corrispondenza e di stato con cancellazione a cascata', function () {
    expect(Schema::hasTable('ugc_track_ec_track_matches'))->toBeTrue();
    expect(Schema::hasTable('ugc_track_matching_runs'))->toBeTrue();

    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['user_id' => $user->id, 'app_id' => $app->id]));

    DB::table('ugc_track_matching_runs')->insert([
        'ugc_track_id' => $track->id, 'status' => 'computed', 'computed_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect($track->matchingRun)->not->toBeNull();

    DB::delete('DELETE FROM ugc_tracks WHERE id = ?', [$track->id]);
    expect(DB::table('ugc_track_matching_runs')->count())->toBe(0);
});

it('legge i parametri di default dalla config come interi', function () {
    expect(config('wm-package.ugc_track_matching.segment_length_meters'))->toBe(400);
    expect(config('wm-package.ugc_track_matching.max_distance_meters'))->toBe(50);
    expect(config('wm-package.ugc_track_matching.queue'))->toBe('ugc-track-matching');
});

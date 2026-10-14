<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\ContextTrackBandsService;

beforeEach(function () {
    Cache::flush();
    $this->app_ = App::factory()->createQuietly();
});

function bandsTrack(int $appId, string $wkt): EcTrack
{
    $track = EcTrack::factory()->createQuietly(['app_id' => $appId, 'osmid' => null]);
    DB::table('ec_tracks')->where('id', $track->id)
        ->update(['geometry' => DB::raw("ST_Force3D(ST_GeomFromText('{$wkt}', 4326))::geography")]);

    return $track;
}

/** @return list<string> "ec:rank:n", ordinati */
function bandKeys(array $bands): array
{
    $keys = array_map(fn ($b) => $b['ec_track_id'].':'.$b['rank'].':'.$b['n'], $bands);
    sort($keys);

    return array_values(array_unique($keys));
}

it('dove due cammini coincidono ognuno ha la sua posizione, dove sono soli la posizione 0', function () {
    // A da 13.0 a 13.02, B ~22 m più a nord da 13.01 a 13.03: in comune 13.01–13.02.
    $a = bandsTrack($this->app_->id, 'MULTILINESTRING((13.0 43.005, 13.02 43.005))');
    $b = bandsTrack($this->app_->id, 'MULTILINESTRING((13.01 43.0052, 13.03 43.0052))');

    $bands = ContextTrackBandsService::make()->bands([$a->id => 10, $b->id => 20], 50);

    expect(bandKeys($bands))->toBe([
        "{$a->id}:0:1", "{$a->id}:0:2", "{$b->id}:0:1", "{$b->id}:1:2",
    ]);
    foreach ($bands as $band) {
        expect($band['geometry']['type'])->toBe('MultiLineString');
        expect($band['cammini'])->toBe($band['n'] === 2 ? [10, 20] : [$band['ec_track_id'] === $a->id ? 10 : 20]);
    }
});

it('due tappe dello stesso cammino non si contano due volte', function () {
    $a = bandsTrack($this->app_->id, 'MULTILINESTRING((13.0 43.005, 13.02 43.005))');
    $b = bandsTrack($this->app_->id, 'MULTILINESTRING((13.01 43.0052, 13.03 43.0052))');

    $bands = ContextTrackBandsService::make()->bands([$a->id => 10, $b->id => 10], 50);

    expect(bandKeys($bands))->toBe(["{$a->id}:0:1", "{$b->id}:0:1"]);
});

it('la posizione segue la chiave del cammino, non l\'ordine degli id', function () {
    $a = bandsTrack($this->app_->id, 'MULTILINESTRING((13.0 43.005, 13.02 43.005))');
    $b = bandsTrack($this->app_->id, 'MULTILINESTRING((13.0 43.0052, 13.02 43.0052))');

    $bands = ContextTrackBandsService::make()->bands([$a->id => 20, $b->id => 10], 50);

    expect(bandKeys($bands))->toBe(["{$a->id}:1:2", "{$b->id}:0:2"]);
});

it('orienta i tratti nello stesso verso, anche se le tappe sono disegnate al contrario', function () {
    // A da ovest a est, B da est a ovest, sullo stesso sentiero.
    $a = bandsTrack($this->app_->id, 'MULTILINESTRING((13.0 43.005, 13.02 43.005))');
    $b = bandsTrack($this->app_->id, 'MULTILINESTRING((13.02 43.0052, 13.0 43.0052))');
    // Tappa più verticale, disegnata da nord a sud.
    $c = bandsTrack($this->app_->id, 'MULTILINESTRING((14.0 43.02, 14.0 43.0))');

    $bands = ContextTrackBandsService::make()->bands([$a->id => 10, $b->id => 20, $c->id => 30], 50);

    foreach ($bands as $band) {
        foreach ($band['geometry']['coordinates'] as $part) {
            [$first, $last] = [reset($part), end($part)];
            if ($band['ec_track_id'] === $c->id) {
                expect($last[1])->toBeGreaterThan($first[1]);
            } else {
                expect($last[0])->toBeGreaterThan($first[0]);
            }
        }
    }
});

it('con una UgcTrack restano solo i pezzi di tappa entro la tolleranza dalla traccia', function () {
    $a = bandsTrack($this->app_->id, 'MULTILINESTRING((13.0 43.005, 13.02 43.005))');
    $user = User::factory()->create(['app_id' => $this->app_->id]);
    $ugc = UgcTrack::factory()->createQuietly(['user_id' => $user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'ugc']]);
    // La traccia segue la tappa solo fra 13.005 e 13.01, ~15 m più a nord.
    DB::table('ugc_tracks')->where('id', $ugc->id)
        ->update(['geometry' => DB::raw("ST_Force3D(ST_GeomFromText('MULTILINESTRING((13.005 43.0051, 13.01 43.0051))', 4326))::geography")]);

    $bands = ContextTrackBandsService::make()->bands([$a->id => 10], 50, $ugc->id);

    expect($bands)->toHaveCount(1);
    $lons = array_map(fn ($p) => $p[0], array_merge(...$bands[0]['geometry']['coordinates']));
    // Pezzi da al più 50 m attorno al tratto seguito: niente oltre ~100 m dai suoi estremi.
    expect(min($lons))->toBeGreaterThan(13.0035);
    expect(max($lons))->toBeLessThan(13.0115);
});

it('usa la cache finché le tappe non cambiano', function () {
    $a = bandsTrack($this->app_->id, 'MULTILINESTRING((13.0 43.005, 13.02 43.005))');
    $service = ContextTrackBandsService::make();
    $first = $service->bands([$a->id => 10], 50);

    DB::table('ec_tracks')->where('id', $a->id)
        ->update(['geometry' => DB::raw("ST_Force3D(ST_GeomFromText('MULTILINESTRING((14.0 43.005, 14.02 43.005))', 4326))::geography")]);
    expect($service->bands([$a->id => 10], 50))->toBe($first);

    DB::table('ec_tracks')->where('id', $a->id)->update(['updated_at' => now()->addMinute()]);
    expect($service->bands([$a->id => 10], 50))->not->toBe($first);
});

it('senza tappe nessuna fascia, e un errore restituisce null', function () {
    expect(ContextTrackBandsService::make()->bands([], 50))->toBe([]);

    $a = bandsTrack($this->app_->id, 'MULTILINESTRING((13.0 43.005, 13.02 43.005))');
    expect(ContextTrackBandsService::make()->bands([$a->id => 10], -1))->toBeNull();
});

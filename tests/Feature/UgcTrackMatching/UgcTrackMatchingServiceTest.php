<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrackMatchingRun;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    $this->service = UgcTrackMatchingService::make();
});

it('salva i segmenti corrispondenti a una traccia parallela entro la distanza massima', function () {
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.009))');

    $this->service->matchUgcTrack($ugc);

    expect(matchedSegments($ugc, $ec))->toBe([0, 1, 2]);
    $row = DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $ugc)->first();
    expect($row->total_segments)->toBe(6);
    expect($row->segment_length_meters)->toBe(400);
    expect($row->max_distance_meters)->toBe(50);
    expect(UgcTrackMatchingRun::find($ugc)->status)->toBe(UgcTrackMatchingRun::STATUS_COMPUTED);
});

it('non salva righe per una traccia lontana ma registra il calcolo', function () {
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.01 44, 11.01 44.009))');

    $this->service->matchUgcTrack($ugc);

    expect(matchedSegments($ugc, $ec))->toBeNull();
    expect(UgcTrackMatchingRun::find($ugc)->status)->toBe(UgcTrackMatchingRun::STATUS_COMPUTED);
});

it('ignora le EcTrack di un\'altra App', function () {
    $other = App::factory()->createQuietly();
    $ec = matchingEcTrack($other->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.009))');

    $this->service->matchUgcTrack($ugc);

    expect(matchedSegments($ugc, $ec))->toBeNull();
});

it('numera i segmenti in modo unico su una EcTrack fatta di due pezzi, e stabile fra due calcoli', function () {
    // Primo pezzo ~789 m (segmenti 0,1), secondo pezzo ~789 m più a nord (segmenti 2,3).
    // Pezzi poco sotto gli 800 m: con 0,0072° (800,009 m) CEIL darebbe un terzo segmento di 9 mm.
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.0071),(11 44.0100, 11 44.0171))');
    // La traccia passa solo sul secondo pezzo.
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44.0100, 11.00025 44.0171))');

    $this->service->matchUgcTrack($ugc);
    $first = matchedSegments($ugc, $ec);
    $this->service->matchUgcTrack($ugc);

    expect($first)->toBe([2, 3]);
    expect(matchedSegments($ugc, $ec))->toBe($first);
    expect(DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $ugc)->value('total_segments'))->toBe(4);
});

it('tratta una traccia di lunghezza zero come calcolata senza righe', function () {
    matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44.001, 11 44.001))');

    $this->service->matchUgcTrack($ugc);

    expect(DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $ugc)->count())->toBe(0);
    expect(UgcTrackMatchingRun::find($ugc)->status)->toBe(UgcTrackMatchingRun::STATUS_COMPUTED);
});

it('usa i parametri della App quando sono impostati', function () {
    $this->app_->properties = array_merge($this->app_->properties ?? [], [
        'ugc_track_matching_segment_length_meters' => 1000,
        'ugc_track_matching_max_distance_meters' => 10,
    ]);
    $this->app_->saveQuietly();

    expect($this->service->params($this->app_->id))->toBe(['segment_length_meters' => 1000, 'max_distance_meters' => 10]);
});

it('ricalcola una EcTrack contro le UgcTrack vicine anche se non avevano righe', function () {
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.009))');
    $this->service->matchUgcTrack($ugc); // nessuna EcTrack ancora: zero righe
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');

    $this->service->matchEcTrack($ec);

    expect(matchedSegments($ugc, $ec))->toBe([0, 1, 2]);
});

it('elenca come da calcolare le tracce senza stato, fallite o con parametri vecchi', function () {
    $never = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    $failed = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    $stale = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    $fresh = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');

    $this->service->markFailed($failed, 'timeout');
    $this->service->matchUgcTrack($stale);
    DB::table('ugc_track_matching_runs')->where('ugc_track_id', $stale)->update(['segment_length_meters' => 300]);
    $this->service->matchUgcTrack($fresh);

    $ids = $this->service->unmatchedUgcTrackIdsQuery($this->app_->id)->pluck('id')->all();
    sort($ids);
    expect($ids)->toBe([$never, $failed, $stale]);
});

it('ricava i layer attraversati via layerables, ignorando i layer inesistenti', function () {
    $layer = \Wm\WmPackage\Models\Layer::factory()->createQuietly(['app_id' => $this->app_->id]);
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    matchingAttachToLayer($layer->id, $ec);
    matchingAttachToLayer(999999, $ec);
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.009))');
    $this->service->matchUgcTrack($ugc);

    expect(\Wm\WmPackage\Models\UgcTrack::find($ugc)->matchedLayerIds())->toBe([$layer->id]);
});

it('i totali di segmenti per EcTrack coincidono con total_segments, anche per EcTrack non toccate e fatte di più pezzi', function () {
    $touched = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $untouched = matchingEcTrack($this->app_->id, 'MULTILINESTRING((12 44, 12 44.0071),(12 44.0100, 12 44.0171))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.009))');
    $this->service->matchUgcTrack($ugc);

    $totals = $this->service->totalSegmentsForEcTracks([$touched, $untouched, 999999], $this->app_->id);

    $stored = DB::table('ugc_track_ec_track_matches')->where(['ugc_track_id' => $ugc, 'ec_track_id' => $touched])->value('total_segments');
    expect($totals[$touched])->toBe($stored)->toBe(6);
    expect($totals[$untouched])->toBe(4);
    expect($totals)->not->toHaveKey(999999);
    expect($this->service->totalSegmentsForEcTracks([], $this->app_->id))->toBe([]);
});

it('i totali di segmenti usano i parametri attuali della App', function () {
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))'); // ~2000,3 m
    $this->app_->properties = array_merge($this->app_->properties ?? [], [UgcTrackMatchingService::SEGMENT_LENGTH_KEY => 1000]);
    $this->app_->saveQuietly();

    expect($this->service->totalSegmentsForEcTracks([$ec], $this->app_->id))->toBe([$ec => 3]);
});

it('trova un segmento corrispondente solo al lato a cavallo fra due pezzi della UgcTrack', function () {
    // EcTrack ~1190 m verso nord: segmenti 0 (0-400 m), 1 (400-800 m), 2 (800-1190 m).
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.0107))');
    // UgcTrack: i primi CHUNK_VERTICES vertici a ~800 m a est, poi un salto a ~800 m a ovest.
    // Nessun vertice è entro la distanza massima: la EcTrack la attraversa solo il lato fra il vertice
    // CHUNK_VERTICES e il successivo, cioè l'ultimo lato del primo pezzo, a lat 44.0054 (~600 m,
    // segmento 1).
    $n = UgcTrackMatchingService::CHUNK_VERTICES;
    $points = [];
    for ($i = 1; $i <= $n; $i++) {
        $points[] = sprintf('11.01 %.4F', 44.0054 - ($n - $i) * 0.0001);
    }
    for ($j = 0; $j < 10; $j++) {
        $points[] = sprintf('10.99 %.4F', 44.0054 + $j * 0.0001);
    }
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING(('.implode(', ', $points).'))');

    $this->service->matchUgcTrack($ugc);

    expect(count($points))->toBeGreaterThan($n);
    expect(matchedSegments($ugc, $ec))->toBe([1]);
});

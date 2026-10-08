<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Models\UgcTrackCleanupService;
use Wm\WmPackage\Services\Models\UgcTrackStatsService;

const UGC_TRACK_STATS_FIXTURES = __DIR__.'/../../fixtures/ugc-track-stats/*.json';

dataset('ugc-track-stats-fixtures', function () {
    foreach (glob(UGC_TRACK_STATS_FIXTURES) as $file) {
        yield basename($file) => [$file];
    }
});

// Un dataset vuoto non fa fallire nulla: senza questo controllo, una cartella spostata o
// rinominata farebbe passare la suite senza verificare alcun caso.
it('trova i casi condivisi', function () {
    expect(count(glob(UGC_TRACK_STATS_FIXTURES)))->toBeGreaterThan(0);
});

it('rispetta il caso condiviso', function (string $file) {
    $case = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    config()->set('wm-package.ugc_track_max_accuracy_meters', (float) $case['params']['max_accuracy']);
    config()->set('wm-package.ugc_track_max_deviation_meters', (float) $case['params']['max_deviation']);
    config()->set('wm-package.ugc_track_max_speed_percentile', (float) $case['params']['max_speed_percentile']);
    config()->set('wm-package.ugc_track_moving_min_speed_kmh', (float) $case['params']['moving_min_speed']);

    expect(UgcTrackCleanupService::make()->keptFlags($case['locations']))->toBe($case['expected_kept']);

    $stats = UgcTrackStatsService::make()->localStats($case['locations']);
    if ($case['expected'] === null) {
        expect($stats)->toBeNull();

        return;
    }
    // stats contiene esattamente le chiavi di expected, più computed_at e le chiavi DEM
    expect(array_keys($stats))->toEqualCanonicalizing([
        ...array_keys($case['expected']), 'computed_at', ...UgcTrackStatsService::DEM_KEYS,
    ]);
    foreach ($case['expected'] as $key => $value) {
        expect($stats[$key])->toBe($value, "$key in ".basename($file));
    }
})->with('ugc-track-stats-fixtures');

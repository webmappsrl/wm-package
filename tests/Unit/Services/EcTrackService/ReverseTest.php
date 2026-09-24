<?php

namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Wm\WmPackage\Jobs\Pbf\GenerateEcTrackPBFBatch;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrack3DDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAppRelationsInfoJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackCurrentDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackGenerateElevationChartImage;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackManualDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackOrderRelatedPoi;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackSlopeValues;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;

class ReverseTest extends AbstractEcTrackServiceTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        CountingEngine::$updates = 0;
        // I test del package non registrano ScoutServiceProvider: senza il singleton ogni
        // app(EngineManager::class) è un'istanza nuova e gli engine registrati qui si perdono.
        $this->app->singleton(EngineManager::class, fn ($app) => new EngineManager($app));
        app(EngineManager::class)->extend('counting', fn () => new CountingEngine);
        app(EngineManager::class)->extend('failing', fn () => new FailingEngine);
        config(['scout.driver' => 'counting']);
    }

    private function track(array $properties = [], string $wkt = 'MULTILINESTRING Z((0 0 0, 1 1 100, 2 2 200))'): EcTrack
    {
        // osmid esplicito: la factory lo valorizza a caso nel 70% dei casi, e una traccia OSM
        // è in sola lettura per l'inversione.
        return EcTrack::factory()->createQuietly([
            'geometry' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
            'properties' => $properties,
            'osmid' => null,
        ]);
    }

    private function wkt(EcTrack $track): string
    {
        return DB::selectOne('SELECT ST_AsText(geometry::geometry) AS wkt FROM ec_tracks WHERE id = ?', [$track->id])->wkt;
    }

    private function storedProperties(EcTrack $track): array
    {
        return EcTrack::query()->findOrFail($track->id)->properties;
    }

    public function test_it_excludes_exactly_four_jobs_from_the_geometry_block()
    {
        $this->assertSame([
            UpdateEcTrackManualDataJob::class,
            UpdateEcTrackCurrentDataJob::class,
            UpdateEcTrack3DDemJob::class,
            SyncModelTaxonomyWhereJob::class,
        ], EcTrackService::REVERSE_EXCLUDED_JOBS);
    }

    public function test_geometry_only_reverses_the_geometry_and_keeps_the_data()
    {
        $properties = ['manual_data' => ['ascent' => 500, 'descent' => 300], 'from' => 'A', 'to' => 'B'];
        $track = $this->track($properties);

        $swapped = $this->ecTrackService->reverse($track, true, []);

        $this->assertSame([], $swapped);
        $this->assertSame('MULTILINESTRING Z ((2 2 200,1 1 100,0 0 0))', $this->wkt($track));
        $this->assertSame($properties['manual_data'], $this->storedProperties($track)['manual_data']);
        $this->assertSame('A', $this->storedProperties($track)['from']);
        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackSlopeValues::class,
            UpdateEcTrackGenerateElevationChartImage::class,
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
    }

    public function test_swaps_only_keep_the_geometry_and_use_the_short_chain()
    {
        $track = $this->track([
            'manual_data' => ['ascent' => 500, 'descent' => 300, 'ele_from' => 10, 'ele_to' => 90],
            'from' => 'A',
            'to' => 'B',
        ]);
        $before = $this->wkt($track);

        $swapped = $this->ecTrackService->reverse($track, false, ['ascent_descent', 'from_to']);

        $this->assertSame(['ascent_descent', 'from_to'], $swapped);
        $this->assertSame($before, $this->wkt($track));
        $stored = $this->storedProperties($track);
        $this->assertSame(300, $stored['manual_data']['ascent']);
        $this->assertSame(500, $stored['manual_data']['descent']);
        $this->assertSame(10, $stored['manual_data']['ele_from']);
        $this->assertSame('B', $stored['from']);
        $this->assertSame('A', $stored['to']);
        Bus::assertChained([GenerateEcTrackPBFBatch::class, UpdateEcTrackAwsJob::class]);
    }

    public function test_a_pair_with_one_value_moves_it_and_empties_the_origin()
    {
        $track = $this->track(['manual_data' => ['ascent' => 500, 'descent' => null], 'from' => 'A']);

        $this->ecTrackService->reverse($track, false, ['ascent_descent', 'from_to']);

        $stored = $this->storedProperties($track);
        $this->assertSame(500, $stored['manual_data']['descent']);
        $this->assertArrayNotHasKey('ascent', $stored['manual_data']);
        $this->assertSame('A', $stored['to']);
        $this->assertArrayNotHasKey('from', $stored);
    }

    public function test_distance_and_elevation_extremes_are_never_touched()
    {
        $track = $this->track(['manual_data' => ['ascent' => 1, 'distance' => 12.5, 'ele_min' => 5, 'ele_max' => 50]]);

        $this->ecTrackService->reverse($track, true, array_keys(EcTrackService::REVERSE_SWAP_PAIRS));

        $stored = $this->storedProperties($track)['manual_data'];
        $this->assertSame(12.5, $stored['distance']);
        $this->assertSame(5, $stored['ele_min']);
        $this->assertSame(50, $stored['ele_max']);
    }

    public function test_it_swaps_when_manual_data_is_a_json_string()
    {
        $track = $this->track(['manual_data' => json_encode(['ascent' => 500, 'descent' => 300])]);

        $this->ecTrackService->reverse($track, false, ['ascent_descent']);

        $stored = $this->storedProperties($track)['manual_data'];
        $this->assertSame(300, $stored['ascent']);
        $this->assertSame(500, $stored['descent']);
    }

    public function test_direction_pairs_lists_only_pairs_with_a_value()
    {
        $track = $this->track([
            'manual_data' => ['ascent' => 500, 'ele_from' => '', 'duration_forward' => null],
            'to' => 'B',
        ]);

        $this->assertSame([
            'ascent_descent' => [500, null],
            'from_to' => [null, 'B'],
        ], $this->ecTrackService->directionPairs($track));
    }

    public function test_nothing_selected_throws_and_writes_nothing()
    {
        $track = $this->track(['manual_data' => ['ascent' => 500]]);
        $before = $this->wkt($track);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->ecTrackService->reverse($track, false, []);
        } finally {
            $this->assertSame($before, $this->wkt($track));
            Bus::assertNothingDispatched();
        }
    }

    public function test_it_does_nothing_when_requested_pairs_are_empty()
    {
        $track = $this->track(['manual_data' => ['distance' => 3]]);

        $swapped = $this->ecTrackService->reverse($track, false, ['ascent_descent']);

        $this->assertSame([], $swapped);
        Bus::assertNothingDispatched();
        $this->assertSame(0, CountingEngine::$updates);
    }

    public function test_osm_tracks_are_read_only()
    {
        $byColumn = $this->track();
        $byColumn->osmid = 123;
        $byColumn->saveQuietly();
        $byProperty = $this->track(['osmid' => 456]);

        foreach ([$byColumn, $byProperty] as $track) {
            $before = $this->wkt($track);
            try {
                $this->ecTrackService->reverse($track, true, []);
                $this->fail('Una traccia OSM non deve essere invertita');
            } catch (InvalidArgumentException) {
                $this->assertSame($before, $this->wkt($track));
            }
        }
        Bus::assertNothingDispatched();
    }

    public function test_updated_at_advances_so_clients_download_the_track_again()
    {
        // App ed export incrementali scelgono le tracce da riscaricare con updated_at: l'update
        // mirato deve aggiornarlo, sia per i soli scambi sia per la geometria (oc:8543).
        foreach ([[false, ['from_to']], [true, []]] as [$geometry, $swaps]) {
            $track = $this->track(['from' => 'A', 'to' => 'B']);
            DB::table('ec_tracks')->where('id', $track->id)->update(['updated_at' => '2020-01-01 00:00:00']);

            $this->ecTrackService->reverse($track, $geometry, $swaps);

            $updatedAt = DB::table('ec_tracks')->where('id', $track->id)->value('updated_at');
            $this->assertGreaterThan('2020-01-01 00:00:00', (string) $updatedAt);
        }
    }

    public function test_it_reindexes_the_track_once()
    {
        $track = $this->track(['manual_data' => ['ascent' => 500]]);

        $this->ecTrackService->reverse($track, true, []);
        $this->assertSame(1, CountingEngine::$updates);

        $this->ecTrackService->reverse($track, false, ['ascent_descent']);
        $this->assertSame(2, CountingEngine::$updates);
    }

    public function test_it_dispatches_the_chain_even_if_reindexing_fails()
    {
        config(['scout.driver' => 'failing']);
        Log::spy();
        $track = $this->track();

        $this->ecTrackService->reverse($track, true, []);

        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackSlopeValues::class,
            UpdateEcTrackGenerateElevationChartImage::class,
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message) => str_contains($message, 'Bulk update error'));
    }

    public function test_manual_data_survives_the_dem_recalculation()
    {
        // Il test che mancava nei cicli precedenti: senza Bus::fake() sul job che scrive
        // properties, gli override devono restare dopo il ricalcolo DEM. Si esegue a mano solo
        // UpdateEcTrackDemJob (DemClient finto): AWS, PBF e profilo scriverebbero su storage
        // esterni, stessa classe di incidente di oc:8251 (oc:8543).
        $track = $this->track(['manual_data' => ['ascent' => 500, 'descent' => 300]]);

        $this->ecTrackService->reverse($track, true, ['ascent_descent']);
        (new UpdateEcTrackDemJob(EcTrack::query()->findOrFail($track->id)))->handle($this->ecTrackService);

        $stored = $this->storedProperties($track);
        $this->assertSame(['ascent' => 300, 'descent' => 500], $stored['manual_data']);
        $this->assertSame(300, $stored['dem_data']['ascent']);
    }
}

class CountingEngine extends NullEngine
{
    public static int $updates = 0;

    public function update($models)
    {
        static::$updates += $models->count();
    }
}

class FailingEngine extends NullEngine
{
    public function update($models)
    {
        throw new \Exception('Bulk update error');
    }
}

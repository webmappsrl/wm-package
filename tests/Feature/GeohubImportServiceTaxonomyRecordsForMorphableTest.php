<?php

namespace Wm\WmPackage\Tests\Feature;

require_once __DIR__.'/../Concerns/SharesGeohubConnectionWithLocal.php';
require_once __DIR__.'/../Concerns/DisablesForeignKeyConstraints.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Tests\Concerns\DisablesForeignKeyConstraints;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class GeohubImportServiceTaxonomyRecordsForMorphableTest extends TestCase
{
    use DatabaseTransactions, DisablesForeignKeyConstraints, SharesGeohubConnectionWithLocal;

    private GeohubImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();
        $this->disableForeignKeyConstraints();

        $this->service = app(GeohubImportService::class);
    }

    protected function tearDown(): void
    {
        $this->restoreForeignKeyConstraints();
        parent::tearDown();
    }

    public function test_resolves_local_theme_associated_with_a_geohub_poi(): void
    {
        $geohubPoiId = 12001;
        $geohubThemeId = 5001;

        $themeId = DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Test Theme']),
            'identifier' => 'test-theme-'.uniqid(),
            'properties' => json_encode(['geohub_id' => $geohubThemeId]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => $geohubThemeId,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $results = $this->service->getTaxonomyRecordsForMorphable('taxonomy_theme', 'App\\Models\\EcPoi', $geohubPoiId);

        $this->assertCount(1, $results);
        $this->assertEquals($themeId, $results->first()->id);
    }

    public function test_returns_empty_collection_when_no_geohub_pivot_rows_exist(): void
    {
        $results = $this->service->getTaxonomyRecordsForMorphable('taxonomy_theme', 'App\\Models\\EcPoi', 999999);

        $this->assertCount(0, $results);
    }

    public function test_skips_pivot_row_when_local_taxonomy_not_yet_imported(): void
    {
        $geohubPoiId = 12002;
        $geohubThemeId = 5002;

        // Nessuna taxonomy_themes locale con geohub_id 5002: simula taxonomy non ancora importata
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => $geohubThemeId,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $results = $this->service->getTaxonomyRecordsForMorphable('taxonomy_theme', 'App\\Models\\EcPoi', $geohubPoiId);

        $this->assertCount(0, $results);
    }

    public function test_resolves_activity_with_pivot_columns_populated(): void
    {
        $geohubTrackId = 12003;
        $geohubActivityId = 5003;

        $activityId = DB::table('taxonomy_activities')->insertGetId([
            'name' => json_encode(['it' => 'Test Activity']),
            'identifier' => 'test-activity-'.uniqid(),
            'properties' => json_encode(['geohub_id' => $geohubActivityId]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $geohubActivityId,
            'taxonomy_activityable_type' => 'App\\Models\\EcTrack',
            'taxonomy_activityable_id' => $geohubTrackId,
            'duration_forward' => 120,
            'duration_backward' => 90,
        ]);

        $results = $this->service->getTaxonomyRecordsForMorphable('taxonomy_activity', 'App\\Models\\EcTrack', $geohubTrackId);

        $this->assertCount(1, $results);
        $this->assertEquals($activityId, $results->first()->id);
        $this->assertEquals(['duration_forward' => 120, 'duration_backward' => 90], $results->first()->pivot_data);
    }

    public function test_ignores_pivot_rows_for_a_different_morphable_type(): void
    {
        $geohubId = 12004;
        $geohubThemeId = 5004;

        DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Other Type Theme']),
            'identifier' => 'test-theme-other-'.uniqid(),
            'properties' => json_encode(['geohub_id' => $geohubThemeId]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => $geohubThemeId,
            'taxonomy_themeable_type' => 'App\\Models\\EcTrack',
            'taxonomy_themeable_id' => $geohubId,
        ]);

        // Interroghiamo per EcPoi con lo stesso geohub_id: la riga è per EcTrack, non deve matchare
        $results = $this->service->getTaxonomyRecordsForMorphable('taxonomy_theme', 'App\\Models\\EcPoi', $geohubId);

        $this->assertCount(0, $results);
    }
}

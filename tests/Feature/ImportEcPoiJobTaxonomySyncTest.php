<?php

namespace Wm\WmPackage\Tests\Feature;

require_once __DIR__.'/../Concerns/SharesGeohubConnectionWithLocal.php';
require_once __DIR__.'/../Concerns/InjectsGeohubImportService.php';
require_once __DIR__.'/../Concerns/DisablesForeignKeyConstraints.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportEcPoiJob;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Tests\Concerns\DisablesForeignKeyConstraints;
use Wm\WmPackage\Tests\Concerns\InjectsGeohubImportService;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class ImportEcPoiJobTaxonomySyncTest extends TestCase
{
    use DatabaseTransactions, DisablesForeignKeyConstraints, InjectsGeohubImportService, SharesGeohubConnectionWithLocal;

    private GeohubImportService $geohubImportService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();
        $this->disableForeignKeyConstraints();

        $this->geohubImportService = app(GeohubImportService::class);
    }

    protected function tearDown(): void
    {
        $this->restoreForeignKeyConstraints();
        parent::tearDown();
    }

    private function makeJob(int $geohubPoiId): \Wm\WmPackage\Jobs\Import\BaseImportJob
    {
        return $this->makeJobWithGeohubImportService(ImportEcPoiJob::class, $geohubPoiId, $this->geohubImportService);
    }

    public function test_ec_poi_theme_pivot_is_populated_even_when_taxonomy_theme_job_never_ran(): void
    {
        // Riproduce l'ordine racy: la Taxonomy locale esiste già (dispatchata prima, come nel
        // flusso reale), ma il job ImportTaxonomyThemeJob dedicato non gira affatto in questo
        // test — solo il job EcPoi. Il pivot deve popolarsi comunque.
        $geohubPoiId = 20001;

        $themeId = DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Test Theme']),
            'identifier' => 'test-theme-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 6001]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 6001,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $job = $this->makeJob($geohubPoiId);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => $geohubPoiId], $poi);

        $this->assertTrue(
            $poi->taxonomyThemes()->where('taxonomy_themes.id', $themeId)->exists(),
            'Il pivot taxonomy_themeables locale deve essere popolato dal sync lato figlio, senza che il job taxonomy dedicato sia mai girato'
        );
    }

    public function test_ec_poi_poi_type_and_activity_pivots_are_populated(): void
    {
        $geohubPoiId = 20002;

        $poiTypeId = DB::table('taxonomy_poi_types')->insertGetId([
            'name' => json_encode(['it' => 'Test Poi Type']),
            'identifier' => 'test-poi-type-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 6002]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => 6002,
            'taxonomy_poi_typeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_poi_typeable_id' => $geohubPoiId,
        ]);

        $activityId = DB::table('taxonomy_activities')->insertGetId([
            'name' => json_encode(['it' => 'Test Activity']),
            'identifier' => 'test-activity-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 6003]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => 6003,
            'taxonomy_activityable_type' => 'App\\Models\\EcPoi',
            'taxonomy_activityable_id' => $geohubPoiId,
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $job = $this->makeJob($geohubPoiId);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => $geohubPoiId], $poi);

        $this->assertTrue($poi->taxonomyPoiTypes()->where('taxonomy_poi_types.id', $poiTypeId)->exists());
        $this->assertTrue($poi->taxonomyActivities()->where('taxonomy_activities.id', $activityId)->exists());
    }

    public function test_missing_local_taxonomy_is_logged_and_skipped_without_exception(): void
    {
        $geohubPoiId = 20003;

        // Pivot GeoHub presente, ma nessuna taxonomy_themes locale con geohub_id 6004
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 6004,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        // GeohubImportService::getTaxonomyRecordsForMorphable() logga il warning "not yet
        // imported" tramite $this->logger, tipizzato in modo stretto su Illuminate\Log\Logger
        // (classe concreta, non un'interfaccia) — Log::spy() non soddisfa quel tipo attraverso
        // la risoluzione facade channel() nel costruttore del service. Mockiamo la classe
        // concreta e la iniettiamo via reflection nell'istanza già condivisa da setUp().
        $loggerMock = \Mockery::mock(\Illuminate\Log\Logger::class);
        $loggerMock->shouldReceive('warning')
            ->withArgs(fn ($message) => str_contains($message, '[child_side_sync]'))
            ->atLeast()->once();

        $loggerProperty = new \ReflectionProperty($this->geohubImportService, 'logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($this->geohubImportService, $loggerMock);

        $job = $this->makeJob($geohubPoiId);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);

        $method->invoke($job, ['id' => $geohubPoiId], $poi);

        $this->assertCount(0, $poi->taxonomyThemes);
    }

    public function test_sync_is_skipped_entirely_when_config_flag_disabled(): void
    {
        config(['wm-geohub-import.child_side_taxonomy_sync.enabled' => false]);

        $geohubPoiId = 20004;

        DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Disabled Flag Theme']),
            'identifier' => 'test-theme-disabled-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 6005]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 6005,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $job = $this->makeJob($geohubPoiId);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => $geohubPoiId], $poi);

        $this->assertCount(0, $poi->taxonomyThemes);
    }

    public function test_re_running_process_dependencies_does_not_duplicate_pivot(): void
    {
        $geohubPoiId = 20005;

        DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Idempotent Theme']),
            'identifier' => 'test-theme-idempotent-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 6006]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 6006,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $job = $this->makeJob($geohubPoiId);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);

        $method->invoke($job, ['id' => $geohubPoiId], $poi);
        $method->invoke($job, ['id' => $geohubPoiId], $poi);

        $this->assertCount(1, $poi->fresh()->taxonomyThemes);
    }
}

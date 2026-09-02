<?php

namespace Wm\WmPackage\Tests\Feature;

require_once __DIR__.'/../Concerns/SharesGeohubConnectionWithLocal.php';
require_once __DIR__.'/../Concerns/SimulatesGeohubMediaSchema.php';
require_once __DIR__.'/../Concerns/InjectsGeohubImportService.php';
require_once __DIR__.'/../Concerns/DisablesForeignKeyConstraints.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Tests\Concerns\DisablesForeignKeyConstraints;
use Wm\WmPackage\Tests\Concerns\InjectsGeohubImportService;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;
use Wm\WmPackage\Tests\Concerns\SimulatesGeohubMediaSchema;

class ImportAppJobTaxonomyScopingTest extends TestCase
{
    use DatabaseTransactions, DisablesForeignKeyConstraints, InjectsGeohubImportService, SharesGeohubConnectionWithLocal, SimulatesGeohubMediaSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();
        $this->disableForeignKeyConstraints();
        $this->simulateGeohubMediaSchema();
    }

    protected function tearDown(): void
    {
        $this->restoreForeignKeyConstraints();

        parent::tearDown();
    }

    public function test_only_taxonomy_themes_used_by_the_app_are_queued_for_import(): void
    {
        Bus::fake();

        $appGeohubId = 70001;
        $appUserId = 70002;

        $track = EcTrack::factory()->createQuietly(['user_id' => $appUserId]);

        DB::table('taxonomy_themes')->insert([
            ['id' => 9201, 'name' => json_encode(['it' => 'Used Theme']), 'identifier' => 'used-theme', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9202, 'name' => json_encode(['it' => 'Unused Theme']), 'identifier' => 'unused-theme', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Only 9201 is referenced by this app's track — 9202 belongs to no content of this app
        // and must NOT be queued.
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9201,
            'taxonomy_themeable_type' => 'App\\Models\\EcTrack',
            'taxonomy_themeable_id' => $track->id,
        ]);

        $job = $this->makeJobWithGeohubImportService(ImportAppJob::class, $appGeohubId, app(GeohubImportService::class));

        $method = new \ReflectionMethod($job, 'queueEntityImport');
        $method->setAccessible(true);
        $method->invoke($job, 'taxonomy_theme', $appUserId, 'user_id', 1);

        Bus::assertBatched(function ($batch) {
            $queuedEntityIds = collect($batch->jobs)->map(function ($queuedJob) {
                $entityIdProperty = new \ReflectionProperty($queuedJob, 'entityId');
                $entityIdProperty->setAccessible(true);

                return $entityIdProperty->getValue($queuedJob);
            })->all();

            return $batch->name === 'app-dependencies-taxonomy_theme-import-batch'
                && $queuedEntityIds === [9201];
        });
    }
}

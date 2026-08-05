<?php

namespace Wm\WmPackage\Tests\Feature\Import;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportUgcPoiJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class ImportUgcPoiJobTest extends TestCase
{
    use DatabaseTransactions;

    private GeohubImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $default = config('database.default');
        config(['database.connections.geohub' => config("database.connections.{$default}")]);
        DB::purge('geohub');

        // DatabaseTransactions wraps only the default connection in a transaction;
        // sharing the same PDO ensures the geohub connection sees uncommitted test data.
        $defaultConn = DB::connection($default);
        $geohubConn = DB::connection('geohub');
        $geohubConn->setPdo($defaultConn->getPdo());
        $geohubConn->setReadPdo($defaultConn->getReadPdo());

        RolesAndPermissionsService::seedDatabase();

        $this->service = app(GeohubImportService::class);
    }

    private function insertFakeGeohubPoi(int $appId, ?int $userId, string $name = 'Fake poi'): int
    {
        return DB::connection('geohub')->table('ugc_pois')->insertGetId([
            'app_id' => $appId,
            'user_id' => $userId,
            'name' => $name,
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('POINT(11 43)'))"),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_author_is_the_real_geohub_author_not_the_app_owner(): void
    {
        $owner = User::factory()->create();
        $localApp = App::factory()->create(['user_id' => $owner->id]);
        $author = User::factory()->create();

        $geohubPoiId = $this->insertFakeGeohubPoi($localApp->id, $author->id);

        (new ImportUgcPoiJob($geohubPoiId, ['app_id' => $localApp->id]))->handle($this->service);

        $imported = UgcPoi::where('properties->geohub_id', $geohubPoiId)->first();

        $this->assertNotNull($imported);
        $this->assertSame($author->id, $imported->user_id);
        $this->assertNotSame($owner->id, $imported->user_id);
        $this->assertSame($localApp->id, $imported->app_id);
    }

    public function test_author_receives_contributor_role_when_none_assigned(): void
    {
        $localApp = App::factory()->create();
        $author = User::factory()->create();

        $geohubPoiId = $this->insertFakeGeohubPoi($localApp->id, $author->id);

        (new ImportUgcPoiJob($geohubPoiId, ['app_id' => $localApp->id]))->handle($this->service);

        $this->assertTrue($author->fresh()->hasRole('Contributor'));
    }

    public function test_author_with_existing_role_keeps_it_and_does_not_get_contributor(): void
    {
        $localApp = App::factory()->create();
        $author = User::factory()->create();
        $author->assignRole('Editor');

        $geohubPoiId = $this->insertFakeGeohubPoi($localApp->id, $author->id);

        (new ImportUgcPoiJob($geohubPoiId, ['app_id' => $localApp->id]))->handle($this->service);

        $author->refresh();
        $this->assertTrue($author->hasRole('Editor'));
        $this->assertFalse($author->hasRole('Contributor'));
    }

    public function test_reimport_does_not_update_the_already_imported_record(): void
    {
        $localApp = App::factory()->create();
        $author = User::factory()->create();

        $geohubPoiId = $this->insertFakeGeohubPoi($localApp->id, $author->id, 'Original name');

        (new ImportUgcPoiJob($geohubPoiId, ['app_id' => $localApp->id]))->handle($this->service);

        $imported = UgcPoi::where('properties->geohub_id', $geohubPoiId)->first();
        $imported->update(['name' => 'Moderated name']);

        // Geohub-side data changes after the fact...
        DB::connection('geohub')->table('ugc_pois')->where('id', $geohubPoiId)->update(['name' => 'Changed on geohub']);

        // ...but a second import run must leave the already-imported local record untouched.
        (new ImportUgcPoiJob($geohubPoiId, ['app_id' => $localApp->id]))->handle($this->service);

        $this->assertSame('Moderated name', $imported->fresh()->name);
        $this->assertCount(1, UgcPoi::where('properties->geohub_id', $geohubPoiId)->get());
    }

    public function test_record_with_null_author_is_skipped_without_exception(): void
    {
        $mockService = \Mockery::mock(GeohubImportService::class)->makePartial();
        $mockService->shouldReceive('fetchData')->once()->andReturn([
            'id' => 999999,
            'user_id' => null,
            'name' => 'Orphan poi',
            'geometry' => null,
            'properties' => [],
        ]);

        (new ImportUgcPoiJob(999999, ['app_id' => App::factory()->create()->id]))->handle($mockService);

        $this->assertNull(UgcPoi::where('properties->geohub_id', 999999)->first());
    }

    public function test_app_owner_keeps_editor_role_untouched_by_ugc_import(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Editor');
        $localApp = App::factory()->create(['user_id' => $owner->id]);
        $author = User::factory()->create();

        $geohubPoiId = $this->insertFakeGeohubPoi($localApp->id, $author->id);

        (new ImportUgcPoiJob($geohubPoiId, ['app_id' => $localApp->id]))->handle($this->service);

        $owner->refresh();
        $this->assertTrue($owner->hasRole('Editor'));
        $this->assertFalse($owner->hasRole('Contributor'));
    }

    public function test_record_with_missing_geometry_is_skipped_without_exception(): void
    {
        $author = User::factory()->create();

        $mockService = \Mockery::mock(GeohubImportService::class)->makePartial();
        $mockService->shouldReceive('fetchData')->once()->andReturn([
            'id' => 999998,
            'user_id' => $author->id,
            'name' => 'Geometry-less poi',
            'geometry' => null,
            'properties' => [],
        ]);

        (new ImportUgcPoiJob(999998, ['app_id' => App::factory()->create()->id]))->handle($mockService);

        $this->assertNull(UgcPoi::where('properties->geohub_id', 999998)->first());
    }
}

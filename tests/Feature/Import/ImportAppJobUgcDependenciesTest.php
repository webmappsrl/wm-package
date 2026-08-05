<?php

namespace Wm\WmPackage\Tests\Feature\Import;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class ImportAppJobUgcDependenciesTest extends TestCase
{
    use DatabaseTransactions;

    private GeohubImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $default = config('database.default');
        config(['database.connections.geohub' => config("database.connections.{$default}")]);
        DB::purge('geohub');

        $defaultConn = DB::connection($default);
        $geohubConn = DB::connection('geohub');
        $geohubConn->setPdo($defaultConn->getPdo());
        $geohubConn->setReadPdo($defaultConn->getReadPdo());

        RolesAndPermissionsService::seedDatabase();

        $this->service = app(GeohubImportService::class);
    }

    /**
     * Regression test for a bug found in code review: the Bus::batch()->then() callback
     * used to close over $this (dragging in $this->geohubImportService, which holds a live
     * DB connection). Batches persist their options — including then() callbacks — to
     * job_batches at dispatch time regardless of queue driver, and PHP cannot serialize a
     * live PDO handle. Before the fix, this crashed on every dispatch with at least one
     * poi/track job — i.e. the ticket's own primary use case.
     */
    public function test_dispatching_ugc_dependencies_with_poi_and_track_does_not_throw(): void
    {
        $localApp = App::factory()->create();
        $author = User::factory()->create();

        $geohubPoiId = DB::connection('geohub')->table('ugc_pois')->insertGetId([
            'app_id' => $localApp->id,
            'user_id' => $author->id,
            'name' => 'Fake poi',
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('POINT(11 43)'))"),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $geohubTrackId = DB::connection('geohub')->table('ugc_tracks')->insertGetId([
            'app_id' => $localApp->id,
            'user_id' => $author->id,
            'name' => 'Fake track',
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('LINESTRING(11 43, 11.01 43.01)'))"),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new ImportAppJob($localApp->id, ['app_id' => $localApp->id]);
        $reflection = new \ReflectionClass($job);

        $geohubImportServiceProperty = $reflection->getProperty('geohubImportService');
        $geohubImportServiceProperty->setAccessible(true);
        $geohubImportServiceProperty->setValue($job, $this->service);

        $entityIdProperty = $reflection->getProperty('entityId');
        $entityIdProperty->setAccessible(true);
        $entityIdProperty->setValue($job, $localApp->id);

        $method = $reflection->getMethod('queueUgcDependencies');
        $method->setAccessible(true);

        // The bug threw during dispatch() itself (serialization happens synchronously,
        // even on the sync queue) — reaching this assertion at all is the real assertion.
        $method->invoke($job, ['ugc_poi', 'ugc_track', 'ugc_media'], $localApp->id);

        $this->assertNotNull(UgcPoi::where('properties->geohub_id', $geohubPoiId)->first());
        $this->assertNotNull(UgcTrack::where('properties->geohub_id', $geohubTrackId)->first());
    }
}

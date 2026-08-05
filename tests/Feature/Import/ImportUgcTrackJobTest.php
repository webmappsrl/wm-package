<?php

namespace Wm\WmPackage\Tests\Feature\Import;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportUgcTrackJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class ImportUgcTrackJobTest extends TestCase
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

    public function test_single_linestring_from_geohub_is_stored_as_multilinestring(): void
    {
        $localApp = App::factory()->create();
        $author = User::factory()->create();

        // Geohub stores ugc_tracks.geometry as a single LineStringZ (verified against the
        // real Geohub schema), while the local column is MultiLineStringZ.
        $geohubTrackId = DB::connection('geohub')->table('ugc_tracks')->insertGetId([
            'app_id' => $localApp->id,
            'user_id' => $author->id,
            'name' => 'Fake track',
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('LINESTRING(11 43, 11.01 43.01)'))"),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ImportUgcTrackJob($geohubTrackId, ['app_id' => $localApp->id]))->handle($this->service);

        $imported = UgcTrack::where('properties->geohub_id', $geohubTrackId)->first();

        $this->assertNotNull($imported);
        $this->assertSame($author->id, $imported->user_id);

        $type = DB::selectOne('select geometrytype(geometry::geometry) as type from ugc_tracks where id = ?', [$imported->id])->type;
        $this->assertSame('MULTILINESTRING', $type);
    }
}

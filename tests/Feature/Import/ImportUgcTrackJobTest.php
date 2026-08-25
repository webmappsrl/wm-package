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

class ImportUgcTrackJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Share the same PDO between the default and geohub connections, so inserting test
        // data through the default connection is visible to GeohubImportService (which reads
        // via the geohub connection) — same pattern as ImportUgcPoiJobTest.
        $default = config('database.default');
        config(['database.connections.geohub' => config("database.connections.{$default}")]);
        DB::purge('geohub');

        $defaultConn = DB::connection($default);
        $geohubConn = DB::connection('geohub');
        $geohubConn->setPdo($defaultConn->getPdo());
        $geohubConn->setReadPdo($defaultConn->getReadPdo());
    }

    private function lineStringGeometry(): \Illuminate\Database\Query\Expression
    {
        return DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[11.0,44.0,100],[11.1,44.1,110]]]}')");
    }

    private function insertGeohubUgcTrackRow(int $authorUserId, int $geohubAppId, ?string $updatedAt = null, ?string $name = 'Test UGC Track'): int
    {
        return DB::table('ugc_tracks')->insertGetId([
            'user_id' => $authorUserId,
            'app_id' => $geohubAppId,
            'name' => $name,
            'geometry' => $this->lineStringGeometry(),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => $updatedAt ?? now()->toDateTimeString(),
        ]);
    }

    public function test_ugc_track_author_is_the_real_geohub_author_not_the_app_owner(): void
    {
        $owner = User::factory()->create();
        $author = User::factory()->create();
        $app = App::factory()->create(['user_id' => $owner->id]);

        $geohubTrackId = $this->insertGeohubUgcTrackRow($author->id, $app->id);

        (new ImportUgcTrackJob($geohubTrackId, ['app_id' => $app->id]))->handle(app(GeohubImportService::class));

        $imported = UgcTrack::where('properties->geohub_id', $geohubTrackId)->first();

        $this->assertNotNull($imported);
        $this->assertSame($author->id, $imported->user_id);
        $this->assertNotSame($owner->id, $imported->user_id);
    }

    public function test_existing_ugc_track_is_not_updated_when_geohub_updated_at_is_not_newer(): void
    {
        $author = User::factory()->create();
        $app = App::factory()->create();

        $syncedAt = now();
        $geohubTrackId = $this->insertGeohubUgcTrackRow($author->id, $app->id, $syncedAt->toDateTimeString());

        $existing = UgcTrack::factory()->createQuietly([
            'user_id' => $author->id,
            'app_id' => $app->id,
            'name' => 'Original name',
            'properties' => ['geohub_id' => $geohubTrackId, 'geohub_synced_at' => $syncedAt->toIso8601String()],
        ]);

        DB::table('ugc_tracks')->where('id', $geohubTrackId)->update(['name' => 'Changed on Geohub but not newer']);

        (new ImportUgcTrackJob($geohubTrackId, ['app_id' => $app->id]))->handle(app(GeohubImportService::class));

        $this->assertSame('Original name', $existing->fresh()->name);
    }

    public function test_existing_ugc_track_is_updated_when_geohub_updated_at_is_newer(): void
    {
        $author = User::factory()->create();
        $app = App::factory()->create();

        $syncedAt = now()->subDay();
        $geohubUpdatedAt = now();

        $geohubTrackId = $this->insertGeohubUgcTrackRow($author->id, $app->id, $geohubUpdatedAt->toDateTimeString());

        $existing = UgcTrack::factory()->createQuietly([
            'user_id' => $author->id,
            'app_id' => $app->id,
            'name' => 'Original name',
            'properties' => ['geohub_id' => $geohubTrackId, 'geohub_synced_at' => $syncedAt->toIso8601String()],
        ]);

        DB::table('ugc_tracks')->where('id', $geohubTrackId)->update(['name' => 'Updated on Geohub, newer']);

        (new ImportUgcTrackJob($geohubTrackId, ['app_id' => $app->id]))->handle(app(GeohubImportService::class));

        $this->assertSame('Updated on Geohub, newer', $existing->fresh()->name);
    }

    public function test_ugc_track_with_null_author_is_skipped_without_exception(): void
    {
        // ugc_tracks.user_id is NOT NULL on the local schema, so a null-author row can't be
        // faked through the shared geohub connection (would violate the local constraint) —
        // mock fetchData() directly instead, matching what Geohub's own nullable user_id allows.
        $service = \Mockery::mock(GeohubImportService::class)->makePartial();
        $service->shouldReceive('fetchData')
            ->once()
            ->andReturn(['id' => 54321, 'user_id' => null, 'app_id' => 1, 'name' => 'Orphan', 'geometry' => null, 'updated_at' => now()->toDateTimeString()]);

        (new ImportUgcTrackJob(54321, ['app_id' => 1]))->handle($service);

        $this->assertNull(UgcTrack::where('properties->geohub_id', 54321)->first());
    }

    // No automated test for the checkUgcUserExistence() concurrent-duplicate-email race fix:
    // this test class (like ImportUgcPoiJobTest) points the "geohub" connection at the same
    // physical database/table as "local" to keep the harness simple. That collapses the two
    // lookups checkUgcUserExistence() performs (by id on the geohub side, by email on the
    // local side) onto the very same row, so the "user doesn't exist locally yet" branch —
    // and therefore the race on it — can never be reached from this harness. See notes.md.
}

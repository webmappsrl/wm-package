<?php

namespace Wm\WmPackage\Tests\Feature\Import;

// Wm\WmPackage\Tests\ isn't in the consumer's (maphub) autoload-dev map (only wm-package's
// own composer.json declares it, for the package's standalone suite) — require it directly
// so `use SharesGeohubConnectionWithLocal` below resolves regardless of which suite runs this.
require_once __DIR__.'/../../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Database\Query\Expression;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportUgcTrackJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class ImportUgcTrackJobTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();
    }

    private function lineStringGeometry(): Expression
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

    public function test_ugc_track_properties_merge_geohub_properties_flat(): void
    {
        // Geohub's own `description` column is empty on most real records — the activity
        // type and form answers the app user filled in live in `properties.form` instead,
        // which the mapping used to drop entirely. Merged flat (not nested) so it reads the
        // same as on Geohub's own Nova detail page.
        $author = User::factory()->create();
        $app = App::factory()->create();

        $geohubTrackId = DB::table('ugc_tracks')->insertGetId([
            'user_id' => $author->id,
            'app_id' => $app->id,
            'name' => 'Track with rich properties',
            'geometry' => $this->lineStringGeometry(),
            'properties' => json_encode(['form' => ['activity' => 'skitouring', 'description' => 'Bella gita']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ImportUgcTrackJob($geohubTrackId, ['app_id' => $app->id]))->handle(app(GeohubImportService::class));

        $imported = UgcTrack::where('properties->geohub_id', $geohubTrackId)->first();

        $this->assertSame('skitouring', $imported->properties['form']['activity']);
        $this->assertSame('Bella gita', $imported->properties['form']['description']);
        // Our own bookkeeping keys must survive the merge untouched.
        $this->assertSame($geohubTrackId, $imported->properties['geohub_id']);
    }

    /**
     * Builds a raw (E)WKB hex string for a plain 2D LineString — the exact wire format
     * fetchData() returns from Geohub's geometry column, and the only format
     * hasValidTrackGeometry() actually parses (see BaseUgcImportJob).
     */
    private function lineStringWkbHex(array $points): string
    {
        $binary = pack('C', 1); // little-endian
        $binary .= pack('V', 2); // WKB type 2 = LineString, no SRID/Z/M flags
        $binary .= pack('V', count($points));

        foreach ($points as [$x, $y]) {
            $binary .= pack('d', $x).pack('d', $y);
        }

        return bin2hex($binary);
    }

    public function test_ugc_track_with_single_point_geometry_is_skipped_without_exception(): void
    {
        // Found running the real e2e import (see notes.md): a single-point LineString (user
        // started and immediately stopped recording) makes even ST_GeomFromWKB itself reject
        // the geometry, which is exactly why hasValidTrackGeometry() checks this in PHP before
        // any insert is attempted — never exercised by lineStringGeometry() above, which
        // builds a MultiLineString the check explicitly does not validate.
        $author = User::factory()->create();

        $service = \Mockery::mock(GeohubImportService::class)->makePartial();
        $service->shouldReceive('fetchData')
            ->once()
            ->andReturn([
                'id' => 99999,
                'user_id' => $author->id,
                'app_id' => 1,
                'name' => 'Degenerate track',
                'geometry' => $this->lineStringWkbHex([[11.0, 44.0]]),
                'updated_at' => now()->toDateTimeString(),
            ]);

        (new ImportUgcTrackJob(99999, ['app_id' => 1]))->handle($service);

        $this->assertNull(UgcTrack::where('properties->geohub_id', 99999)->first());
    }

    public function test_ugc_track_with_two_point_linestring_geometry_is_not_skipped(): void
    {
        // Counterpart to the test above: confirms hasValidTrackGeometry()'s point-count math
        // (byte offsets, endianness) is correct for a genuinely valid LineString, not just
        // that degenerate ones are rejected.
        $author = User::factory()->create();
        $app = App::factory()->create();

        $service = \Mockery::mock(GeohubImportService::class)->makePartial();
        $service->shouldReceive('fetchData')
            ->once()
            ->andReturn([
                'id' => 88888,
                'user_id' => $author->id,
                'app_id' => $app->id,
                'name' => 'Valid track',
                'geometry' => $this->lineStringWkbHex([[11.0, 44.0], [11.1, 44.1]]),
                'updated_at' => now()->toDateTimeString(),
            ]);
        $service->shouldReceive('transformFields')->once()->andReturn(['name' => 'Valid track']);
        $service->shouldReceive('transformProperties')->once()->andReturn([]);
        $service->shouldReceive('checkUgcUserExistence')->once()->with($author->id)->andReturn($author);
        $service->shouldReceive('importUgcData')->once()->andReturnUsing(
            fn ($data, $modelKey, $modelName, $entityId) => UgcTrack::create(array_merge($data, [
                'properties' => array_merge($data['properties'] ?? [], ['geohub_id' => $entityId]),
            ]))
        );

        (new ImportUgcTrackJob(88888, ['app_id' => $app->id]))->handle($service);

        $this->assertNotNull(UgcTrack::where('properties->geohub_id', 88888)->first());
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

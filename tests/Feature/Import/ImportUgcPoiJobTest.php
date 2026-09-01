<?php

namespace Wm\WmPackage\Tests\Feature\Import;

// Wm\WmPackage\Tests\ isn't in the consumer's (maphub) autoload-dev map (only wm-package's
// own composer.json declares it, for the package's standalone suite) — require it directly
// so `use SharesGeohubConnectionWithLocal` below resolves regardless of which suite runs this.
require_once __DIR__.'/../../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportUgcPoiJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class ImportUgcPoiJobTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();
    }

    private function pointGeometry(): \Illuminate\Database\Query\Expression
    {
        return DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[11.0,44.0,100]}')");
    }

    private function insertGeohubUgcPoiRow(int $authorUserId, int $geohubAppId, ?string $updatedAt = null, ?string $name = 'Test UGC Poi'): int
    {
        return DB::table('ugc_pois')->insertGetId([
            'user_id' => $authorUserId,
            'app_id' => $geohubAppId,
            'name' => $name,
            'geometry' => $this->pointGeometry(),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => $updatedAt ?? now()->toDateTimeString(),
        ]);
    }

    public function test_ugc_poi_author_is_the_real_geohub_author_not_the_app_owner(): void
    {
        $owner = User::factory()->create();
        $author = User::factory()->create();
        $app = App::factory()->create(['user_id' => $owner->id]);

        $geohubPoiId = $this->insertGeohubUgcPoiRow($author->id, $app->id);

        (new ImportUgcPoiJob($geohubPoiId, ['app_id' => $app->id]))->handle(app(GeohubImportService::class));

        $imported = UgcPoi::where('properties->geohub_id', $geohubPoiId)->first();

        $this->assertNotNull($imported);
        $this->assertSame($author->id, $imported->user_id);
        $this->assertNotSame($owner->id, $imported->user_id);
    }

    public function test_existing_ugc_poi_is_not_updated_when_geohub_updated_at_is_not_newer(): void
    {
        $author = User::factory()->create();
        $app = App::factory()->create();

        $syncedAt = now();
        $geohubPoiId = $this->insertGeohubUgcPoiRow($author->id, $app->id, $syncedAt->toDateTimeString());

        $existing = UgcPoi::factory()->createQuietly([
            'user_id' => $author->id,
            'app_id' => $app->id,
            'name' => 'Original name',
            'properties' => ['geohub_id' => $geohubPoiId, 'geohub_synced_at' => $syncedAt->toIso8601String()],
        ]);

        DB::table('ugc_pois')->where('id', $geohubPoiId)->update(['name' => 'Changed on Geohub but not newer']);

        (new ImportUgcPoiJob($geohubPoiId, ['app_id' => $app->id]))->handle(app(GeohubImportService::class));

        $this->assertSame('Original name', $existing->fresh()->name);
    }

    public function test_existing_ugc_poi_is_updated_when_geohub_updated_at_is_newer(): void
    {
        $author = User::factory()->create();
        $app = App::factory()->create();

        $syncedAt = now()->subDay();
        $geohubUpdatedAt = now();

        $geohubPoiId = $this->insertGeohubUgcPoiRow($author->id, $app->id, $geohubUpdatedAt->toDateTimeString());

        $existing = UgcPoi::factory()->createQuietly([
            'user_id' => $author->id,
            'app_id' => $app->id,
            'name' => 'Original name',
            'properties' => ['geohub_id' => $geohubPoiId, 'geohub_synced_at' => $syncedAt->toIso8601String()],
        ]);

        DB::table('ugc_pois')->where('id', $geohubPoiId)->update(['name' => 'Updated on Geohub, newer']);

        (new ImportUgcPoiJob($geohubPoiId, ['app_id' => $app->id]))->handle(app(GeohubImportService::class));

        $this->assertSame('Updated on Geohub, newer', $existing->fresh()->name);
    }

    public function test_ugc_poi_properties_merge_geohub_properties_flat(): void
    {
        // Geohub's own `description` column is empty on most real records — the data a
        // Nova admin actually expects (waypoint type, per-app custom form answers) lives
        // in the `properties` jsonb column instead, which the mapping used to drop entirely.
        // Merged flat (not nested) so it reads the same as on Geohub's own Nova detail page.
        $author = User::factory()->create();
        $app = App::factory()->create();

        $geohubPoiId = DB::table('ugc_pois')->insertGetId([
            'user_id' => $author->id,
            'app_id' => $app->id,
            'name' => 'Poi with rich properties',
            'geometry' => $this->pointGeometry(),
            'properties' => json_encode(['waypoint_type' => 'landslide', 'city' => 'Rome']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ImportUgcPoiJob($geohubPoiId, ['app_id' => $app->id]))->handle(app(GeohubImportService::class));

        $imported = UgcPoi::where('properties->geohub_id', $geohubPoiId)->first();

        $this->assertSame('landslide', $imported->properties['waypoint_type']);
        $this->assertSame('Rome', $imported->properties['city']);
        // Our own bookkeeping keys must survive the merge untouched.
        $this->assertSame($geohubPoiId, $imported->properties['geohub_id']);
    }

    public function test_ugc_poi_with_null_author_is_skipped_without_exception(): void
    {
        // ugc_pois.user_id is NOT NULL on the local schema, so a null-author row can't be
        // faked through the shared geohub connection (would violate the local constraint) —
        // mock fetchData() directly instead, matching what Geohub's own nullable user_id allows.
        $service = \Mockery::mock(GeohubImportService::class)->makePartial();
        $service->shouldReceive('fetchData')
            ->once()
            ->andReturn(['id' => 12345, 'user_id' => null, 'app_id' => 1, 'name' => 'Orphan', 'geometry' => null, 'updated_at' => now()->toDateTimeString()]);

        (new ImportUgcPoiJob(12345, ['app_id' => 1]))->handle($service);

        $this->assertNull(UgcPoi::where('properties->geohub_id', 12345)->first());
    }
}

<?php

namespace Wm\WmPackage\Tests\Feature\Import;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportUgcMediaJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcMedia;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class ImportUgcMediaJobTest extends TestCase
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
        Storage::fake();

        $this->service = app(GeohubImportService::class);
    }

    private function insertFakeGeohubMedia(int $appId, int $userId, string $relativeUrl): int
    {
        return DB::connection('geohub')->table('ugc_media')->insertGetId([
            'app_id' => $appId,
            'user_id' => $userId,
            'name' => 'Fake media',
            'relative_url' => $relativeUrl,
            'geometry' => DB::raw("ST_Force2D(ST_GeomFromText('POINT(11 43)'))"),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_successful_download_creates_media_and_saves_file_locally(): void
    {
        $localApp = App::factory()->create();
        $author = User::factory()->create();
        $relativeUrl = 'media/images/ugc/image_1.jpg';

        Http::fake([
            '*/storage/'.$relativeUrl => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $geohubMediaId = $this->insertFakeGeohubMedia($localApp->id, $author->id, $relativeUrl);

        (new ImportUgcMediaJob($geohubMediaId, ['app_id' => $localApp->id]))->handle($this->service);

        $imported = UgcMedia::where('properties->geohub_id', $geohubMediaId)->first();

        $this->assertNotNull($imported);
        $this->assertSame($relativeUrl, $imported->relative_url);
        $this->assertSame($author->id, $imported->user_id);
        Storage::assertExists($relativeUrl);
    }

    public function test_failed_download_skips_media_without_exception(): void
    {
        $localApp = App::factory()->create();
        $author = User::factory()->create();
        $relativeUrl = 'media/images/ugc/missing.jpg';

        Http::fake([
            '*/storage/'.$relativeUrl => Http::response(null, 404),
        ]);

        $geohubMediaId = $this->insertFakeGeohubMedia($localApp->id, $author->id, $relativeUrl);

        (new ImportUgcMediaJob($geohubMediaId, ['app_id' => $localApp->id]))->handle($this->service);

        $this->assertNull(UgcMedia::where('properties->geohub_id', $geohubMediaId)->first());
        Storage::assertMissing($relativeUrl);
    }

    public function test_media_is_attached_to_local_ugc_poi_via_pivot(): void
    {
        $localApp = App::factory()->create();
        $author = User::factory()->create();
        $relativeUrl = 'media/images/ugc/image_2.jpg';

        Http::fake([
            '*/storage/'.$relativeUrl => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $localPoi = UgcPoi::factory()->createQuietly([
            'app_id' => $localApp->id,
            'user_id' => $author->id,
        ]);
        // The pivot's ugc_poi_id has a real FK to ugc_pois(id) — since this test fakes the
        // "geohub" connection as the same local DB, the fake pivot row must reference a real
        // local id. We make properties->geohub_id equal to that same local id so the job's
        // geohub_id -> local id lookup (a no-op translation here) still exercises the real path.
        $localPoi->update(['properties' => ['geohub_id' => $localPoi->id]]);

        $geohubMediaId = $this->insertFakeGeohubMedia($localApp->id, $author->id, $relativeUrl);

        DB::connection('geohub')->table('ugc_media_ugc_poi')->insert([
            'ugc_media_id' => $geohubMediaId,
            'ugc_poi_id' => $localPoi->id,
        ]);

        (new ImportUgcMediaJob($geohubMediaId, ['app_id' => $localApp->id]))->handle($this->service);

        $imported = UgcMedia::where('properties->geohub_id', $geohubMediaId)->first();

        $this->assertTrue($imported->ugcPois()->where('ugc_pois.id', $localPoi->id)->exists());
    }

    public function test_media_is_attached_to_local_ugc_track_via_pivot(): void
    {
        $localApp = App::factory()->create();
        $author = User::factory()->create();
        $relativeUrl = 'media/images/ugc/image_3.jpg';

        Http::fake([
            '*/storage/'.$relativeUrl => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $localTrack = UgcTrack::factory()->createQuietly([
            'app_id' => $localApp->id,
            'user_id' => $author->id,
        ]);
        // Same shared-connection caveat as the ugc_poi pivot test above: the fake pivot row
        // needs a real local id, so geohub_id is set equal to that id for the lookup to work.
        $localTrack->update(['properties' => ['geohub_id' => $localTrack->id]]);

        $geohubMediaId = $this->insertFakeGeohubMedia($localApp->id, $author->id, $relativeUrl);

        DB::connection('geohub')->table('ugc_media_ugc_track')->insert([
            'ugc_media_id' => $geohubMediaId,
            'ugc_track_id' => $localTrack->id,
        ]);

        (new ImportUgcMediaJob($geohubMediaId, ['app_id' => $localApp->id]))->handle($this->service);

        $imported = UgcMedia::where('properties->geohub_id', $geohubMediaId)->first();

        $this->assertTrue($imported->ugcTracks()->where('ugc_tracks.id', $localTrack->id)->exists());
    }

    public function test_reimport_does_not_redownload_or_overwrite_existing_media(): void
    {
        $localApp = App::factory()->create();
        $author = User::factory()->create();
        $relativeUrl = 'media/images/ugc/image_4.jpg';

        Http::fake([
            '*/storage/'.$relativeUrl => Http::response('original-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $geohubMediaId = $this->insertFakeGeohubMedia($localApp->id, $author->id, $relativeUrl);

        (new ImportUgcMediaJob($geohubMediaId, ['app_id' => $localApp->id]))->handle($this->service);

        // Simulate local moderation replacing the file after the first import.
        Storage::put($relativeUrl, 'moderated-bytes');

        // A second run must not re-download (Http::fake would still allow it — the point is
        // the create-only check happens before beforePersist() so the download never runs).
        Http::fake([
            '*/storage/'.$relativeUrl => function () {
                $this->fail('Geohub should not be contacted again for an already-imported media.');
            },
        ]);

        (new ImportUgcMediaJob($geohubMediaId, ['app_id' => $localApp->id]))->handle($this->service);

        $this->assertSame('moderated-bytes', Storage::get($relativeUrl));
        $this->assertCount(1, UgcMedia::where('properties->geohub_id', $geohubMediaId)->get());
    }
}

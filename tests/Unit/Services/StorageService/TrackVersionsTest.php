<?php

declare(strict_types=1);

namespace Tests\Unit\Services\StorageService;

use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Enums\WmfeTrackVersion;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Tests\TestCase;

/**
 * oc:8997 — dove il JSON di una traccia viene scritto, letto e cancellato, versione per versione.
 */
class TrackVersionsTest extends TestCase
{
    private StorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wm-package.shard_name' => 'webmapp']);
        Storage::fake('wmfe');
        $this->service = new StorageService;
    }

    public function test_store_track_writes_legacy_at_the_unversioned_path(): void
    {
        $this->service->storeTrack(769, '{}', WmfeTrackVersion::Legacy);

        Storage::disk('wmfe')->assertExists('/webmapp/tracks/769.json');
    }

    public function test_store_track_writes_v1_under_its_own_prefix(): void
    {
        $this->service->storeTrack(769, '{}', WmfeTrackVersion::V1);

        Storage::disk('wmfe')->assertExists('/webmapp/tracks/v1/769.json');
    }

    public function test_delete_track_removes_every_known_version(): void
    {
        // anche con la sola `legacy` dichiarata attiva: i file di una versione uscita dalla
        // scrittura restano comunque su S3, e vanno via tutti
        config(['wm-package.wmfe_track_versions' => 'legacy']);
        Storage::disk('wmfe')->put('/webmapp/tracks/769.json', '{}');
        Storage::disk('wmfe')->put('/webmapp/tracks/v1/769.json', '{}');

        $this->assertTrue($this->service->deleteTrack(769));

        Storage::disk('wmfe')->assertMissing('/webmapp/tracks/769.json');
        Storage::disk('wmfe')->assertMissing('/webmapp/tracks/v1/769.json');
    }

    public function test_delete_track_is_quiet_when_no_file_exists(): void
    {
        $this->assertTrue($this->service->deleteTrack(769));
    }

    public function test_get_track_geojson_reads_the_legacy_file(): void
    {
        Storage::disk('wmfe')->put('/webmapp/tracks/769.json', '{"legacy":true}');
        Storage::disk('wmfe')->put('/webmapp/tracks/v1/769.json', '{"legacy":false}');

        $this->assertSame('{"legacy":true}', $this->service->getTrackGeojson(769, 1));
    }
}

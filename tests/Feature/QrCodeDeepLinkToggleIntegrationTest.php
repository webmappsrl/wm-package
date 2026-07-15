<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Tests\TestCase;

/**
 * End-to-end test of the full chain App::save() -> AppObserver -> WellKnownRegistryService
 * -> well-known registry disk, without mocking the service (unlike AppObserverWellKnownSyncTest,
 * which only asserts the service is called).
 */
class QrCodeDeepLinkToggleIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wm-package.shard_name' => 'test_shard',
            'wm-package.deep_link.apple_team_id' => 'TEAM1234X',
            // The sync itself now runs in SyncWellKnownRegistryJob (queued, see AppObserver);
            // force the sync queue driver so this end-to-end test can assert on the disk
            // content immediately instead of needing a running queue worker.
            'queue.default' => 'sync',
        ]);

        Storage::fake('well_known_registry');
        Storage::fake('pois');
        Storage::fake('wmfe');
        Storage::fake('conf');
    }

    /** @test */
    public function enabling_the_toggle_writes_both_well_known_files_with_the_apps_entry()
    {
        $app = App::factory()->create([
            'sku' => 'it.webmapp.integrationtest',
            'properties' => ['native_app_deep_link_enabled' => false],
        ]);

        $app->properties = [
            'native_app_deep_link_enabled' => true,
            'android_cert_sha256' => str_repeat('AB:', 31).'CD',
        ];
        $app->save();

        $apple = json_decode(Storage::disk('well_known_registry')->get('apple-app-site-association'), true);
        $this->assertSame('TEAM1234X.it.webmapp.integrationtest', $apple['applinks']['details'][0]['appID']);

        $android = json_decode(Storage::disk('well_known_registry')->get('assetlinks.json'), true);
        $this->assertSame('it.webmapp.integrationtest', $android[0]['target']['package_name']);
        $this->assertSame([str_repeat('AB:', 31).'CD'], $android[0]['target']['sha256_cert_fingerprints']);
    }

    /** @test */
    public function disabling_the_toggle_removes_the_apps_entry_from_both_files()
    {
        $app = App::factory()->create([
            'sku' => 'it.webmapp.integrationtest',
            'properties' => [
                'native_app_deep_link_enabled' => true,
                'android_cert_sha256' => 'AA:BB',
            ],
        ]);

        $app->properties = ['native_app_deep_link_enabled' => false];
        $app->save();

        $apple = json_decode(Storage::disk('well_known_registry')->get('apple-app-site-association'), true);
        $this->assertCount(0, $apple['applinks']['details']);

        $android = json_decode(Storage::disk('well_known_registry')->get('assetlinks.json'), true);
        $this->assertCount(0, $android);
    }

    /** @test */
    public function deleting_an_app_with_active_toggle_removes_its_entry()
    {
        $app = App::factory()->create([
            'sku' => 'it.webmapp.integrationtest',
            'properties' => ['native_app_deep_link_enabled' => true],
        ]);

        $app->delete();

        $apple = json_decode(Storage::disk('well_known_registry')->get('apple-app-site-association'), true);
        $this->assertCount(0, $apple['applinks']['details']);
    }
}

<?php

namespace Tests\Unit\Observers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\SyncWellKnownRegistryJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Tests\TestCase;

class AppObserverWellKnownSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wm-package.shard_name' => 'test_shard']);

        Storage::fake('pois');
        Storage::fake('wmfe');
        Storage::fake('conf');
        Bus::fake();
    }

    /** @test */
    public function creating_app_with_toggle_already_true_dispatches_add_job()
    {
        // Regression: wasChanged('properties') is always false on a just-created model
        // (same Eloquent trap already known for LayerObserver, oc:8080) — without the
        // wasRecentlyCreated check, an App created with the toggle already active would
        // never get its well-known entry synced.
        $app = App::factory()->create(['sku' => 'it.webmapp.testapp', 'properties' => ['native_app_deep_link_enabled' => true]]);

        Bus::assertDispatched(SyncWellKnownRegistryJob::class, fn ($job) => $job->action === 'add' && $job->sku === $app->sku);
    }

    /** @test */
    public function toggling_the_flag_on_dispatches_add_job()
    {
        $app = App::factory()->create(['sku' => 'it.webmapp.testapp', 'properties' => ['native_app_deep_link_enabled' => false]]);

        $app->properties = ['native_app_deep_link_enabled' => true];
        $app->save();

        Bus::assertDispatched(SyncWellKnownRegistryJob::class, fn ($job) => $job->action === 'add' && $job->sku === 'it.webmapp.testapp');
    }

    /** @test */
    public function toggling_the_flag_off_dispatches_remove_job()
    {
        $app = App::factory()->create(['sku' => 'it.webmapp.testapp', 'properties' => ['native_app_deep_link_enabled' => true]]);

        $app->properties = ['native_app_deep_link_enabled' => false];
        $app->save();

        Bus::assertDispatched(SyncWellKnownRegistryJob::class, fn ($job) => $job->action === 'remove' && $job->sku === 'it.webmapp.testapp');
    }

    /** @test */
    public function changing_fingerprint_while_enabled_dispatches_add_job_again()
    {
        $app = App::factory()->create(['properties' => [
            'native_app_deep_link_enabled' => true,
            'android_cert_sha256' => 'AA:BB',
        ]]);

        $app->properties = [
            'native_app_deep_link_enabled' => true,
            'android_cert_sha256' => 'CC:DD',
        ];
        $app->save();

        Bus::assertDispatched(SyncWellKnownRegistryJob::class, fn ($job) => $job->action === 'add' && $job->androidCertSha256 === 'CC:DD');
    }

    /** @test */
    public function unrelated_property_change_does_not_dispatch_any_job()
    {
        $app = App::factory()->create(['properties' => [
            'native_app_deep_link_enabled' => true,
            'android_cert_sha256' => 'AA:BB',
            'geohub_id' => 1,
        ]]);

        // Creation itself legitimately dispatches a job (toggle already true from
        // scratch, see creating_app_with_toggle_already_true_dispatches_add_job) —
        // reset the fake's dispatch log so this assertion only covers the save below.
        Bus::fake();

        $app->properties = [
            'native_app_deep_link_enabled' => true,
            'android_cert_sha256' => 'AA:BB',
            'geohub_id' => 2,
        ];
        $app->save();

        Bus::assertNotDispatched(SyncWellKnownRegistryJob::class);
    }

    /** @test */
    public function deleting_an_app_with_toggle_active_dispatches_remove_job()
    {
        $app = App::factory()->create(['sku' => 'it.webmapp.testapp', 'properties' => ['native_app_deep_link_enabled' => true]]);

        $app->delete();

        Bus::assertDispatched(SyncWellKnownRegistryJob::class, fn ($job) => $job->action === 'remove' && $job->sku === 'it.webmapp.testapp');
    }

    /** @test */
    public function deleting_an_app_with_toggle_inactive_does_not_dispatch_any_job()
    {
        $app = App::factory()->create(['properties' => ['native_app_deep_link_enabled' => false]]);

        $app->delete();

        Bus::assertNotDispatched(SyncWellKnownRegistryJob::class);
    }

    /** @test */
    public function a_well_known_job_dispatch_failure_does_not_prevent_the_app_from_being_saved()
    {
        $app = App::factory()->create(['properties' => ['native_app_deep_link_enabled' => false]]);

        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue connection refused'));

        $app->properties = ['native_app_deep_link_enabled' => true];
        $app->save();

        $this->assertTrue($app->fresh()->isNativeAppDeepLinkEnabled());
    }

    /** @test */
    public function a_well_known_job_dispatch_failure_on_delete_does_not_prevent_the_app_from_being_deleted()
    {
        $app = App::factory()->create(['properties' => ['native_app_deep_link_enabled' => true]]);

        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue connection refused'));

        $app->delete();

        $this->assertNull(App::find($app->id));
    }
}

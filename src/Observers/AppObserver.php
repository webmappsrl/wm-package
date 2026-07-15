<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Jobs\SyncWellKnownRegistryJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

class AppObserver extends AbstractObserver
{
    /**
     * Handle the App "saving" event.
     *
     * @return void
     */
    public function saving($app)
    {
        parent::saving($app);
        $json = json_encode(json_decode($app->external_overlays));

        $app->external_overlays = $json;
    }

    public function saved(App $app)
    {
        (new AppConfigService($app))->writeAppConfigOnAws();

        try {
            $this->syncWellKnownRegistry($app);
        } catch (Throwable $e) {
            // Best-effort side effect: a misconfigured/unreachable well-known registry
            // (e.g. missing SFTP credentials in local/dev environments) must never
            // prevent the App itself from being saved.
            Log::error("AppObserver: failed to sync well-known registry for app {$app->id}: ".$e->getMessage());
        }
    }

    /**
     * Handle the App "deleting" event: an App with the QR code deep link toggle
     * still active must have its entry removed from the shared well-known registry
     * before it disappears, otherwise the entry would be orphaned forever.
     *
     * Captures `sku`/`apple_team_id` now (not the App itself) because the row will
     * already be gone from the database by the time the queued job actually runs.
     */
    public function deleting(App $app)
    {
        if (! $app->isNativeAppDeepLinkEnabled()) {
            return;
        }

        try {
            SyncWellKnownRegistryJob::dispatch('remove', $app->sku, appleTeamId: $app->properties['apple_team_id'] ?? null);
        } catch (Throwable $e) {
            Log::error("AppObserver: failed to queue well-known removal for app {$app->id}: ".$e->getMessage());
        }
    }

    /**
     * Dispatches a well-known registry sync when the QR code deep link toggle changes
     * state, or when the Android certificate fingerprint or the Apple Team ID change
     * while the toggle is already active (see docs/features/8251-toggle-qr-code-deep-link/overview.md).
     *
     * The sync itself runs in SyncWellKnownRegistryJob (queued, with retries) rather than
     * synchronously here: it involves SFTP I/O to a third-party server, which must never
     * hold up the Nova request that saved this App.
     */
    private function syncWellKnownRegistry(App $app): void
    {
        // wasChanged() returns false on a just-created model (same trap already known
        // for LayerObserver, oc:8080) — without wasRecentlyCreated, an App created with
        // the toggle already active would never get its well-known entry written.
        if (! $app->wasRecentlyCreated && ! $app->wasChanged('properties')) {
            return;
        }

        $originalProperties = $app->getOriginal('properties');
        $originalProperties = is_array($originalProperties)
            ? $originalProperties
            : (json_decode($originalProperties ?? '{}', true) ?: []);

        $wasEnabled = (bool) ($originalProperties['native_app_deep_link_enabled'] ?? false);
        $isEnabled = $app->isNativeAppDeepLinkEnabled();
        $appleTeamId = $app->properties['apple_team_id'] ?? null;

        if (! $wasEnabled && $isEnabled) {
            SyncWellKnownRegistryJob::dispatch('add', $app->sku, $app->properties['android_cert_sha256'] ?? null, $appleTeamId);

            return;
        }

        if ($wasEnabled && ! $isEnabled) {
            SyncWellKnownRegistryJob::dispatch('remove', $app->sku, appleTeamId: $appleTeamId);

            return;
        }

        if ($isEnabled) {
            $oldFingerprint = $originalProperties['android_cert_sha256'] ?? null;
            $newFingerprint = $app->properties['android_cert_sha256'] ?? null;
            $oldTeamId = $originalProperties['apple_team_id'] ?? null;

            if ($oldFingerprint !== $newFingerprint || $oldTeamId !== $appleTeamId) {
                SyncWellKnownRegistryJob::dispatch('add', $app->sku, $newFingerprint, $appleTeamId);
            }
        }
    }
}

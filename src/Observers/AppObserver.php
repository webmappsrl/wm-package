<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Jobs\SyncWellKnownRegistryJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;

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

        $this->regenerateTaxonomyWhereOutputsIfChanged($app);
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

    /**
     * Se cambia l'opzione "Località mostrate" (oc:8588), le uscite già generate (json statici,
     * Elasticsearch, pois.geojson) vanno rigenerate: altrimenti il cambio in Nova non si vede.
     *
     * Niente controllo su `wasRecentlyCreated` qui (a differenza di `syncWellKnownRegistry`
     * sopra): `wasChanged('properties')` è già `false` su un modello appena creato, pure con
     * `properties` valorizzato in `create()` (stesso trap noto per LayerObserver, oc:8080) — il
     * controllo sarebbe ridondante per saltare la creazione, e `wasRecentlyCreated` **non torna
     * mai a `false`** dopo un secondo `save()` sulla stessa istanza PHP (mai resettato da
     * Eloquent fuori da `performInsert()`): un secondo salvataggio con proprietà davvero
     * cambiate verrebbe saltato per errore. Verificato empiricamente in tinker.
     */
    private function regenerateTaxonomyWhereOutputsIfChanged(App $app): void
    {
        if (! $app->wasChanged('properties')) {
            return;
        }

        $original = $app->getOriginal('properties');
        $original = is_array($original) ? $original : (json_decode($original ?? '{}', true) ?: []);

        // normalizeOptionValue() (TaxonomyWhereDisplayService, oc:8588, review): il campo Nova
        // Outl1ne salva l'opzione come array nativo quando dichiara ->saveAsJSON() (vedi
        // Wm\WmPackage\Nova\App::app_tab()), ma un dato storico o un futuro campo che dimentica
        // quella opzione salverebbe una stringa JSON serializzata; un cast diretto `(array)
        // $stringaJson` produrrebbe un solo elemento con l'intera stringa, facendo scattare la
        // rigenerazione ad ogni salvataggio anche a parità di selezione.
        $service = app(TaxonomyWhereDisplayService::class);
        $before = $service->normalizeOptionValue($original[TaxonomyWhereDisplayService::APP_OPTION] ?? []);
        $after = $service->normalizeOptionValue($app->properties[TaxonomyWhereDisplayService::APP_OPTION] ?? []);
        sort($before);
        sort($after);

        if ($before !== $after) {
            RegenerateTaxonomyWhereOutputsJob::dispatch($app->id);
        }
    }
}

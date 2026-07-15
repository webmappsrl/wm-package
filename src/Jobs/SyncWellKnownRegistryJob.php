<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\WellKnownRegistryService;

/**
 * Syncs a single App's entry in the shared well-known registry (apple-app-site-association
 * + assetlinks.json) asynchronously, so a slow/unreachable SFTP server never blocks the
 * Nova request that saved/deleted the App (see AppObserver).
 *
 * Takes primitives, not an App model: for the 'remove' action (dispatched from the App
 * "deleting" event) the App row no longer exists by the time this job runs, so it cannot
 * re-fetch the model from the database.
 */
class SyncWellKnownRegistryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 60;

    public function __construct(
        public readonly string $action, // 'add' or 'remove'
        public readonly string $sku,
        public readonly ?string $androidCertSha256 = null,
        public readonly ?string $appleTeamId = null,
    ) {}

    /**
     * Exponential-ish backoff between retries, in seconds.
     */
    public function backoff(): array
    {
        return [30, 90, 300];
    }

    public function handle(): void
    {
        $service = WellKnownRegistryService::make();

        if ($this->action === 'remove') {
            $service->removeAppEntry($this->sku, $this->appleTeamId);
        } else {
            $service->addAppEntry($this->sku, $this->androidCertSha256, $this->appleTeamId);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SyncWellKnownRegistryJob: failed to sync app {$this->sku} (action: {$this->action}) after {$this->tries} attempts: ".$exception->getMessage());
    }
}

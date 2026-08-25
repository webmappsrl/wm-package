<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\Import\GeohubImportService;

/**
 * Imports a single Geohub ugc_media row as Spatie media attached to the matching local
 * UgcPoi/UgcTrack. No dedicated UgcMedia model — see UgcMediaImportService::attachUgcMedia().
 *
 * The target ugc_poi/ugc_track may not exist locally yet, since the ugc_poi/ugc_track and
 * ugc_media batches dispatched by ImportAppJob run in parallel on Horizon with no guaranteed
 * order (see overview.md, Rischio 3): retries a few times with a short delay before giving up.
 */
class ImportUgcMediaJob extends BaseImportJob
{
    private const MAX_ATTEMPTS_FOR_TARGET = 3;

    private const RETRY_DELAY_SECONDS = 15;

    public $tries = self::MAX_ATTEMPTS_FOR_TARGET;

    protected function getModelKey(): string
    {
        return 'ugc_media';
    }

    public function handle(GeohubImportService $importService): void
    {
        $logger = Log::channel(config('wm-geohub-import.import_log_channel', 'wm-package-failed-jobs'));

        try {
            $data = $importService->fetchData($this->entityId, $this->getTableName());

            $targets = $importService->findUgcMediaTargets($data);

            if (empty($targets)) {
                if ($this->attempts() < self::MAX_ATTEMPTS_FOR_TARGET) {
                    $logger->info("UgcMedia with geohub ID {$this->entityId}: no matching ugc_poi/ugc_track found yet, retrying (attempt {$this->attempts()}).");
                    $this->release(self::RETRY_DELAY_SECONDS);

                    return;
                }

                $logger->warning("Skipped UgcMedia with geohub ID {$this->entityId}: no matching ugc_poi/ugc_track found after {$this->attempts()} attempts.");

                return;
            }

            $importService->attachUgcMedia($targets, $data);

            $logger->info("Completed import of UgcMedia with geohub ID {$this->entityId}");
        } catch (\Exception $e) {
            $logger->error("Failed to import UgcMedia with geohub ID {$this->entityId}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    protected function transformData(array $data): array
    {
        return $data;
    }

    protected function processDependencies(array $data, Model $model): void
    {
        //
    }
}

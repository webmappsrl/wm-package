<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Services\Import\EcMediaImportService;

class ImportEcMediaJob extends BaseEcImportJob
{
    protected function getModelKey(): string
    {
        return parent::getModelKey() . 'media';
    }

    protected function getGeometryType(): string
    {
        return 'POINT Z';
    }

    /**
     * Override the handle method to use Spatie Media Library directly
     */
    public function handle(GeohubImportService $importService): void
    {
        $this->geohubImportService = $importService;
        $logger = Log::channel(config('wm-geohub-import.import_log_channel', 'wm-package-failed-jobs'));

        try {
            // Fetch the data from Geohub
            $data = $this->geohubImportService->fetchData($this->entityId, $this->getTableName());

            $transformedData = parent::transformData($data);
            // Process the media import
            $this->geohubImportService->processEcMediaImport($data, $transformedData['geometry']);

            $logger->info("Completed import of media with ID {$this->entityId}");
        } catch (\Exception $e) {
            $logger->error("Failed to import media with ID {$this->entityId}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Override the parent method since we don't need to process dependencies
     */
    protected function processDependencies(array $data, Model $model): void
    {
        // No need to process dependencies as everything is handled in processEcMediaImport
    }
}

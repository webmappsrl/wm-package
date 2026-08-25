<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Import\GeohubImportService;

/**
 * Base for UGC import jobs (ugc_poi, ugc_track). Differs from BaseImportJob/BaseEcImportJob
 * on the two points that matter for end-user content: the author is the real Geohub author
 * (transformData() is rewritten from scratch here, never calling parent::transformData(),
 * which would force user_id = app owner), and an existing record is updated on reimport only
 * if Geohub's updated_at is more recent than the geohub_synced_at we stored at the last sync
 * (see GeohubImportService::importUgcData()).
 */
abstract class BaseUgcImportJob extends BaseImportJob
{
    public function handle(GeohubImportService $importService): void
    {
        $this->geohubImportService = $importService;
        $logger = Log::channel(config('wm-geohub-import.import_log_channel', 'wm-package-failed-jobs'));
        $modelName = $this->getModelName();

        try {
            $data = $this->geohubImportService->fetchData($this->entityId, $this->getTableName());

            if (empty($data['user_id'])) {
                $logger->warning("Skipped {$modelName} with geohub ID {$this->entityId}: no author (user_id is null on Geohub).");

                return;
            }

            $transformedData = $this->transformData($data);

            $model = $this->geohubImportService->importUgcData(
                $transformedData,
                $this->getModelKey(),
                $modelName,
                $this->entityId,
                $data['updated_at'] ?? null
            );

            $this->processDependencies($data, $model);

            $logger->info("Completed import of {$modelName} with geohub ID {$this->entityId}");
        } catch (\Exception $e) {
            $logger->error("Failed to import {$modelName} with geohub ID {$this->entityId}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    protected function transformData(array $data): array
    {
        $transformedData = $this->geohubImportService->transformFields($data, $this->getModelKey());

        $propertiesColumnName = config('wm-geohub-import.import_mapping.'.$this->getModelKey().'.properties.column_name', 'properties');
        $transformedData[$propertiesColumnName] = $this->geohubImportService->transformProperties($data, $this->getModelKey());

        $transformedData['app_id'] = $this->data['app_id'] ?? null;
        $transformedData['user_id'] = $this->geohubImportService->checkUgcUserExistence((int) $data['user_id'])->id;
        $transformedData['geometry'] = app(GeometryComputationService::class)->convertTo3DGeometry($data['geometry']);

        return $transformedData;
    }

    protected function processDependencies(array $data, Model $model): void
    {
        //
    }
}

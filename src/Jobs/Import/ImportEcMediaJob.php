<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Jobs\Import\BaseEcImportJob;

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

    protected function transformData(array $data): array
    {
        // First use the parent's basic transformation
        $transformedData = parent::transformData($data);

        // Then apply the media-specific logic
        return $this->geohubImportService->transformEcMediaData($data, $transformedData);
    }

    protected function processDependencies(array $data, Model $model): void
    {
        // Delegate all dependency processing logic to the service
        $this->geohubImportService->processEcMediaDependencies($data, $model);
    }
}

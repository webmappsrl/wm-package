<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Services\GeometryComputationService;

abstract class BaseEcImportJob extends BaseImportJob
{
    const DEFAULT_LON_LAT = ['POINT Z' => '12.4964 41.9028 0', 'LINESTRING Z' => '12.4964 41.9028 0, 12.5033 41.9019 0, 12.5092 41.9101 0, 12.4964 41.9028 0'];

    protected function getModelKey(): string
    {
        return 'ec_';
    }

    protected function transformData(array $data): array
    {
        $transformedData = parent::transformData($data);

        $transformedData['geometry'] = $this->fillGeometry($transformedData);

        return $transformedData;
    }

    protected function processDependencies(array $data, Model $model): void {}

    protected function fillGeometry(array $data)
    {
        if (! isset($data['geometry']) || empty($data['geometry'])) {
            $geometry = $this->setDefaultGeometry();
        } else {
            $geometry = app(GeometryComputationService::class)->convertTo3DGeometry($data['geometry']);
        }

        return $geometry;
    }

    protected function setDefaultGeometry()
    {
        return DB::raw("ST_GeomFromText('{$this->getGeometryType()} ({$this::DEFAULT_LON_LAT[$this->getGeometryType()]})')");
    }

    abstract protected function getGeometryType(): string;
}

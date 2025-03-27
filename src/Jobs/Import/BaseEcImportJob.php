<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

        // if geometry is null set a default 3D geometry
        if (empty($transformedData['geometry'])) {
            $transformedData['geometry'] = DB::raw("ST_GeomFromText('{$this->getGeometryType()} ({$this::DEFAULT_LON_LAT[$this->getGeometryType()]})')");
        } else {
            $transformedData['geometry'] = $this->convertTo3DGeometry($transformedData['geometry']);
        }

        return $transformedData;
    }

    protected function processDependencies(array $data, Model $model): void {}

    /**
     * Convert the geometry to 3D.
     */
    private function convertTo3DGeometry(string $geometry): string
    {
        // force geometry to 3D
        if (is_string($geometry) && preg_match('/^[0-9A-Fa-f]+$/', $geometry)) {
            // Properly format WKB hex string for PostgreSQL
            $sql = "SELECT ST_AsText(ST_Force3D(ST_GeomFromEWKB('\\x{$geometry}'))) as geom";
        } else {
            $sql = "SELECT ST_AsText(ST_Force3D({$geometry})) as geom";
        }

        $result = DB::selectOne($sql);

        return $result->geom;
    }

    abstract protected function getGeometryType(): string;
}

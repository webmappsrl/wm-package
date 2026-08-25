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

            if ($this->getModelKey() === 'ugc_track' && ! $this->hasValidTrackGeometry($data['geometry'] ?? null)) {
                $logger->warning("Skipped {$modelName} with geohub ID {$this->entityId}: degenerate geometry (fewer than 2 points) — found running the real e2e import (see notes.md).");

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

    /**
     * A LineString needs at least 2 points — a single-point track (user started and
     * immediately stopped recording) makes even ST_GeomFromWKB itself reject the geometry
     * ("LineString must have at least two points"), so this can't be checked with a PostGIS
     * round-trip: any query on the malformed WKB fails the same way the real insert does,
     * poisoning the connection for every later query in the same batched/sync run. Parsed
     * directly from the (E)WKB bytes instead, without ever touching the DB.
     */
    private function hasValidTrackGeometry(mixed $geometry): bool
    {
        if (empty($geometry) || ! is_string($geometry)) {
            return false;
        }

        $binary = @hex2bin($geometry);
        if ($binary === false || strlen($binary) < 9) {
            return false;
        }

        $littleEndian = ord($binary[0]) === 1;
        $format = $littleEndian ? 'V' : 'N';

        $typeAndFlags = unpack($format, substr($binary, 1, 4))[1];
        $hasSrid = (bool) ($typeAndFlags & 0x20000000);
        $geomType = $typeAndFlags & 0xFF;

        // Only the plain LineString case is checked here — it's the one confirmed to occur on
        // real Geohub data (see notes.md). Any other geometry type (e.g. MultiLineString) is
        // passed through unchecked rather than risk a wrong byte-offset guess for a case never
        // actually observed.
        if ($geomType !== 2) {
            return true;
        }

        $offset = $hasSrid ? 9 : 5;

        return $this->readUInt32($binary, $offset, $format) >= 2;
    }

    private function readUInt32(string $binary, int $offset, string $format): int
    {
        if (strlen($binary) < $offset + 4) {
            return 0;
        }

        return unpack($format, substr($binary, $offset, 4))[1];
    }
}

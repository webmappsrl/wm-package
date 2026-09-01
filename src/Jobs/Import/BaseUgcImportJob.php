<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
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
        $logger = $this->importLogger();
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
            $this->logImportFailure($logger, "Failed to import {$modelName} with geohub ID {$this->entityId}", $e);
        }
    }

    protected function transformData(array $data): array
    {
        $transformedData = $this->geohubImportService->transformFields($data, $this->getModelKey());

        $propertiesColumnName = config('wm-geohub-import.import_mapping.'.$this->getModelKey().'.properties.column_name', 'properties');
        $transformedData[$propertiesColumnName] = $this->geohubImportService->transformProperties($data, $this->getModelKey());

        $transformedData['app_id'] = $this->data['app_id'] ?? null;

        // The merged-flat properties.app_id above still holds whatever Geohub's own raw
        // payload had (a string, Geohub's own numeric app id — e.g. "49") — same column name
        // as ours, unrelated meaning, exactly like the app_id mismatch already fixed in
        // checkUgcUserExistence()/checkUserExistence(). Overwrite it with the local app_id
        // we just resolved, mirroring what Controller::validateGeojson() does for natively
        // created UGC (forces properties.app_id from the app-id header), so imported UGC
        // exposes the same shape as native UGC via the API.
        $transformedData[$propertiesColumnName]['app_id'] = $transformedData['app_id'];

        $transformedData['user_id'] = $this->geohubImportService->checkUgcUserExistence((int) $data['user_id'])->id;
        $transformedData['geometry'] = app(GeometryComputationService::class)->convertTo3DGeometry($data['geometry']);

        return $transformedData;
    }

    protected function processDependencies(array $data, Model $model): void
    {
        //
    }

    /** Byte order (1 byte) + geometry type (4 bytes), before any optional SRID. */
    private const WKB_HEADER_SIZE = 5;

    /** Size of the optional EWKB SRID field, present only when WKB_EWKB_SRID_FLAG is set. */
    private const WKB_SRID_SIZE = 4;

    /** High bit of the EWKB type field marking an embedded SRID (PostGIS EWKB extension). */
    private const WKB_EWKB_SRID_FLAG = 0x20000000;

    /** Low byte of the EWKB type field holds the plain WKB geometry type code. */
    private const WKB_GEOMETRY_TYPE_MASK = 0xFF;

    /** WKB type code for LineString. */
    private const WKB_TYPE_LINESTRING = 2;

    private const MIN_LINESTRING_POINTS = 2;

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
        if ($binary === false || strlen($binary) < self::WKB_HEADER_SIZE + self::WKB_SRID_SIZE) {
            return false;
        }

        $littleEndian = ord($binary[0]) === 1;
        $format = $littleEndian ? 'V' : 'N';

        $typeAndFlags = unpack($format, substr($binary, 1, 4))[1];
        $hasSrid = (bool) ($typeAndFlags & self::WKB_EWKB_SRID_FLAG);
        $geomType = $typeAndFlags & self::WKB_GEOMETRY_TYPE_MASK;

        // Only the plain LineString case is checked here — it's the one confirmed to occur on
        // real Geohub data (see notes.md). Any other geometry type (e.g. MultiLineString) is
        // passed through unchecked rather than risk a wrong byte-offset guess for a case never
        // actually observed.
        if ($geomType !== self::WKB_TYPE_LINESTRING) {
            return true;
        }

        $offset = self::WKB_HEADER_SIZE + ($hasSrid ? self::WKB_SRID_SIZE : 0);

        return $this->readUInt32($binary, $offset, $format) >= self::MIN_LINESTRING_POINTS;
    }

    private function readUInt32(string $binary, int $offset, string $format): int
    {
        if (strlen($binary) < $offset + 4) {
            return 0;
        }

        return unpack($format, substr($binary, $offset, 4))[1];
    }
}

<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\Import\GeohubImportService;

/**
 * Base for UGC import jobs (ugc_poi, ugc_track, ugc_media).
 *
 * Differs from BaseImportJob/BaseEcImportJob on two points that matter for
 * end-user content: the author is the real Geohub author (never the app
 * owner), and an existing record is never updated by a re-import (Maphub
 * becomes the source of truth right after the first import, so local
 * moderation isn't silently overwritten).
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

            if ($this->requiresGeometry() && empty($data['geometry'])) {
                $logger->warning("Skipped {$modelName} with geohub ID {$this->entityId}: missing geometry.");

                return;
            }

            // Checked here, before transformData()/beforePersist(), so an already-imported
            // record never pays for side effects it doesn't need — resolving/creating the
            // author user, and for UgcMedia, downloading the photo and writing it to storage.
            if ($this->geohubImportService->existsForIdentifier($this->getModelKey(), $modelName, $this->entityId)) {
                $logger->info("Skipped {$modelName} with geohub ID {$this->entityId}: already imported previously (create-only).");

                return;
            }

            $transformedData = $this->transformData($data);

            if (! $this->beforePersist($transformedData, $data)) {
                return;
            }

            $model = $this->geohubImportService->importDataCreateOnly($transformedData, $this->getModelKey(), $modelName, $this->entityId);

            if ($model === null) {
                // Lost a race with a concurrent import of the same record between the check
                // above and here — already logged as "already imported" would be misleading
                // twice, so this path just means "someone else just created it, nothing to do".
                return;
            }

            $this->processDependencies($data, $model);

            $logger->info("Completed import of {$modelName} with geohub ID {$this->entityId}");
        } catch (\Exception $e) {
            $logger->error("Failed to import {$modelName} with geohub ID {$this->entityId}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Whether a missing geometry should skip the record entirely instead of importing it.
     * True for POI/Track (the geometry is the whole point of the entity); UgcMedia overrides
     * this to false, since a photo without a precise location is still worth importing.
     */
    protected function requiresGeometry(): bool
    {
        return true;
    }

    protected function transformData(array $data): array
    {
        $transformedData = $this->geohubImportService->transformFields($data, $this->getModelKey());

        $propertiesColumnName = config('wm-geohub-import.import_mapping.'.$this->getModelKey().'.properties.column_name', 'properties');
        $transformedData[$propertiesColumnName] = $this->geohubImportService->transformProperties($data, $this->getModelKey());

        $transformedData['app_id'] = $this->data['app_id'] ?? null;
        $transformedData['user_id'] = $this->geohubImportService->checkUgcUserExistence((int) $data['user_id'])->id;

        if (array_key_exists('geometry', $transformedData)) {
            $transformedData['geometry'] = $this->fillGeometry($transformedData['geometry']);
        }

        return $transformedData;
    }

    /**
     * Convert the raw Geohub geometry (hex WKB or WKT) into the SQL expression
     * matching the local column type. Poi/Track/Media each have a different target shape.
     */
    abstract protected function fillGeometry($rawGeometry);

    /**
     * Last chance to mutate $transformedData (by reference) or veto the import (return false).
     * Used by UgcMedia to download the photo and set relative_url, skipping the record on
     * download failure instead of inserting a broken row or failing the whole batch.
     */
    protected function beforePersist(array &$transformedData, array $rawData): bool
    {
        return true;
    }
}

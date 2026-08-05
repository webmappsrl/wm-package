<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;

class ImportUgcMediaJob extends BaseUgcImportJob
{
    protected function getModelKey(): string
    {
        return 'ugc_media';
    }

    protected function requiresGeometry(): bool
    {
        // A photo without a precise GPS location is still worth importing.
        return false;
    }

    protected function fillGeometry($rawGeometry)
    {
        if (empty($rawGeometry)) {
            return null;
        }

        if (preg_match('/^(0x)?[0-9a-fA-F]+$/', $rawGeometry)) {
            return DB::raw("ST_Force2D(ST_GeomFromWKB(decode('{$rawGeometry}', 'hex')))");
        }

        return DB::raw("ST_Force2D(ST_GeomFromText('{$rawGeometry}'))");
    }

    /**
     * Download the photo from Geohub's public storage and save it on the local disk.
     * A download failure skips just this media (logged with its geohub_id) instead of
     * inserting a broken row or failing the whole import batch.
     */
    protected function beforePersist(array &$transformedData, array $rawData): bool
    {
        $logger = Log::channel(config('wm-geohub-import.import_log_channel', 'wm-package-failed-jobs'));

        $relativeUrl = $rawData['relative_url'] ?? null;

        if (empty($relativeUrl)) {
            $logger->warning("Skipped ugc_media with geohub ID {$this->entityId}: no relative_url on Geohub.");

            return false;
        }

        $url = rtrim(config('wm-package.clients.geohub.host'), '/').'/storage/'.ltrim($relativeUrl, '/');

        try {
            $response = Http::timeout(15)->get($url);
        } catch (\Exception $e) {
            $logger->warning("Skipped ugc_media with geohub ID {$this->entityId}: download failed for {$url} ({$e->getMessage()}).");

            return false;
        }

        if (! $response->successful()) {
            $logger->warning("Skipped ugc_media with geohub ID {$this->entityId}: download returned HTTP {$response->status()} for {$url}.");

            return false;
        }

        $contentType = $response->header('Content-Type');
        if (! is_string($contentType) || ! str_starts_with($contentType, 'image')) {
            $logger->warning("Skipped ugc_media with geohub ID {$this->entityId}: {$url} did not return an image (Content-Type: {$contentType}).");

            return false;
        }

        Storage::put($relativeUrl, $response->body());

        $transformedData['relative_url'] = $relativeUrl;

        return true;
    }

    /**
     * Sync this media to the local UgcPoi/UgcTrack it's attached to on Geohub. Idempotent
     * (checked before attach) so a re-run over an already-synced media is a no-op.
     */
    protected function processDependencies(array $data, Model $model): void
    {
        $this->syncPivot($model, 'ugc_media_ugc_poi', 'ugc_poi_id', UgcPoi::class, $model->ugcPois());
        $this->syncPivot($model, 'ugc_media_ugc_track', 'ugc_track_id', UgcTrack::class, $model->ugcTracks());
    }

    private function syncPivot(Model $media, string $geohubPivotTable, string $geohubForeignKey, string $localModelClass, BelongsToMany $relation): void
    {
        $geohubIds = $this->geohubImportService->getDbConnection()
            ->table($geohubPivotTable)
            ->where('ugc_media_id', $this->entityId)
            ->pluck($geohubForeignKey);

        if ($geohubIds->isEmpty()) {
            return;
        }

        $localIds = $localModelClass::whereIn('properties->geohub_id', $geohubIds)->pluck('id');

        $alreadyAttached = $relation->pluck($relation->getRelated()->getTable().'.id');

        $toAttach = $localIds->diff($alreadyAttached);

        if ($toAttach->isNotEmpty()) {
            $relation->attach($toAttach);
        }
    }
}

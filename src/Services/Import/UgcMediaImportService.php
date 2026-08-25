<?php

namespace Wm\WmPackage\Services\Import;

use Illuminate\Database\Eloquent\Model;

/**
 * Imports UGC photos from Geohub as Spatie media attached directly to the matching local
 * UgcPoi/UgcTrack — same mechanism as EcMediaImportService, but with no dedicated UgcMedia
 * model: Geohub's ugc_media_ugc_poi/ugc_media_ugc_track pivots are only used to resolve which
 * local model(s) to attach the photo to, nothing is persisted about the pivot itself.
 */
class UgcMediaImportService extends GeohubImportService
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Resolve the local UgcPoi/UgcTrack models a Geohub ugc_media row is attached to.
     *
     * @return array<int, Model> Empty if none found yet (caller may retry: the poi/track import
     *                           batch may still be running in parallel with the media batch)
     */
    public function findUgcMediaTargets(array $data): array
    {
        $mediaId = $data['id'];
        $relations = $this->importMapping['ugc_media']['relations'];
        $targets = [];

        foreach ($relations as $relation) {
            $pivotRows = $this->dbConnection
                ->table($relation['pivot_table'])
                ->where($relation['foreign_key'], $mediaId)
                ->get();

            foreach ($pivotRows as $pivotRow) {
                $relatedGeohubId = $pivotRow->{$relation['key']};
                $model = $relation['model']::where('properties->geohub_id', $relatedGeohubId)->first();

                if ($model instanceof Model) {
                    $targets[] = $model;
                }
            }
        }

        return $targets;
    }

    /**
     * Download the photo (if not already imported) and attach it as Spatie media to every
     * resolved target, skipping targets that already have this geohub_id in their gallery.
     *
     * @throws \Exception if the URL is unreachable (network) or does not serve an image
     */
    public function attachUgcMedia(array $targets, array $data): void
    {
        $url = $this->buildUgcMediaUrl($data['relative_url']);
        $this->assertUrlServesAnImage($url);

        $fileName = $this->buildUgcMediaFileName($data);
        $customProperties = [
            'geohub_id' => $data['id'],
            'geohub_synced_at' => now()->toIso8601String(),
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
        ];

        foreach ($targets as $target) {
            $existingMedia = $target->getMedia('default')
                ->where('custom_properties.geohub_id', $data['id'])
                ->first();

            if ($existingMedia) {
                continue;
            }

            $target->addMediaFromUrl($url)
                ->usingName($fileName)
                ->usingFileName($fileName)
                ->withCustomProperties($customProperties)
                ->toMediaCollection('default', config('wm-media-library.disk_name'));
        }
    }

    private function buildUgcMediaUrl(string $relativeUrl): string
    {
        if (filter_var($relativeUrl, FILTER_VALIDATE_URL)) {
            return $relativeUrl;
        }

        return config('wm-package.clients.geohub.host').'/storage/'.ltrim($relativeUrl, '/');
    }

    private function buildUgcMediaFileName(array $data): string
    {
        $fileName = $data['name'] ?: 'ugc_media_'.$data['id'];

        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $fileName);
    }

    /**
     * Distinguishes a network failure (URL unreachable) from a non-image content type — unlike
     * EcMediaImportService::processEcMediaImport(), which treats get_headers() returning false
     * the same as "not an image", producing a misleading log message for a network problem.
     *
     * @throws \Exception
     */
    private function assertUrlServesAnImage(string $url): void
    {
        $headers = @get_headers($url, 1);

        if ($headers === false) {
            throw new \Exception("Network error: unable to reach URL {$url} for UgcMedia import.");
        }

        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? null;
        if (is_array($contentType)) {
            $contentType = end($contentType);
        }

        if (! $contentType || strpos($contentType, 'image') === false) {
            throw new \Exception("The URL {$url} does not return an image content type. Skipping media import.");
        }
    }
}

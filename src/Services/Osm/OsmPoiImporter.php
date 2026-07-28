<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\Osm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Dto\OsmNodePoiData;
use Wm\WmPackage\Exceptions\OsmClientException;
use Wm\WmPackage\Exceptions\OsmClientExceptionNoTags;
use Wm\WmPackage\Http\Clients\OsmClient;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyPoiType;

/**
 * High-level service: given a set of OSM node IDs, fetches data from OpenStreetMap
 * (via {@see OsmClient}), normalizes it into {@see OsmNodePoiData}, and creates/updates
 * {@see EcPoi} records with the correct {@see TaxonomyPoiType}.
 * Long-form text (description, excerpt from inscription) stays in the {@see EcPoi::$properties}
 * JSON because most consumer DB schemas have no dedicated columns for those keys.
 *
 * TODO: Resolve OSM image tags (`image`, `wikimedia_commons`, …) into Spatie Media Library on
 * {@see EcPoi} (`default` collection: first file = feature image, following = gallery per
 * {@see \Wm\WmPackage\Http\Resources\EcPoiResource}), e.g. Wikimedia Commons API + `addMediaFromUrl`.
 *
 * Dry-run mode: no persistence; returns the expected outcome for interactive validation (Nova/CLI).
 */
class OsmPoiImporter
{
    public function __construct(
        private readonly OsmClient $osmClient,
        private readonly OsmTaxonomyPoiTypeResolver $taxonomyResolver,
    ) {}

    /**
     * Imports a list of OSM node IDs.
     *
     * @param  list<int|string>  $osmIds  Numeric OSM node IDs (cast to int).
     * @param  int  $appId  Destination app ID (required on `ec_pois` schema; also used to scope
     *                       existing-POI lookup so different apps never overwrite each other's data).
     * @param  int|null  $userId  Owning user ID (optional).
     * @param  bool  $dryRun  When true, nothing is persisted.
     * @param  bool  $global  When `ec_pois.global` exists: value to set (true = included in {@see \Wm\WmPackage\Models\App::getAllPoisGeojson()} / pois.geojson).
     */
    public function importNodes(array $osmIds, int $appId, ?int $userId = null, bool $dryRun = false, bool $global = true): ImportReport
    {
        $report = new ImportReport($dryRun);
        $hasGlobalColumn = Schema::hasColumn((new EcPoi)->getTable(), 'global');

        $ids = $this->normalizeIds($osmIds);
        $maxPerRun = (int) config('wm-osm-import.max_ids_per_run', 0);
        if ($maxPerRun > 0 && count($ids) > $maxPerRun) {
            $report->setTruncatedBeyondLimit(count($ids) - $maxPerRun);
            $ids = array_slice($ids, 0, $maxPerRun);
        }

        $delayMs = max(0, (int) config('wm-osm-import.request_delay_ms', 0));
        $total = count($ids);

        foreach ($ids as $index => $osmid) {
            try {
                $report->addOutcome($this->importSingleNode($osmid, $appId, $userId, $dryRun, $global, $hasGlobalColumn));
            } catch (\Throwable $e) {
                [$category, $message] = $this->classifyFailure($osmid, $e);
                Log::warning('OsmPoiImporter: failure on node '.$osmid, [
                    'category' => $category,
                    'message' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                $report->addFailure($osmid, $message, $category);
            }

            if ($delayMs > 0 && $index < $total - 1) {
                usleep($delayMs * 1000);
            }
        }

        return $report;
    }

    /**
     * Maps an exception (including TypeError from non-JSON OSM responses) to a
     * (category, human-readable message) pair for the report.
     *
     * @return array{0: string, 1: string}
     */
    private function classifyFailure(int $osmid, \Throwable $e): array
    {
        if ($e instanceof OsmClientExceptionNoTags) {
            return ['no_tags', "node/{$osmid}: no tags on OpenStreetMap; nothing to import."];
        }
        $message = $e->getMessage();
        if ($this->looksLikeStorageMisconfiguration($message)) {
            return [
                'storage',
                "node/{$osmid}: after save, the observer tried to update pois.geojson on S3/MinIO (wmfe disk) but configuration is incomplete. Set at least AWS_DEFAULT_REGION (e.g. us-east-1 or eu-central-1), AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, and for local dev AWS_ENDPOINT for MinIO. See .env-example.",
            ];
        }
        if ($e instanceof \InvalidArgumentException) {
            if (str_contains($message, 'Point') || str_contains($message, 'geometry')) {
                return ['geometry', "node/{$osmid}: invalid OSM geometry."];
            }

            return ['other', "node/{$osmid}: {$message}"];
        }
        if ($e instanceof OsmClientException) {
            return ['not_found_or_invalid_osm', "node/{$osmid}: ".$e->getMessage()];
        }
        if ($e instanceof \TypeError) {
            // Typical: api.openstreetmap.org returns a non-JSON body (404/410 or intermittent errors).
            return ['not_found_or_invalid_osm', "node/{$osmid}: not found on OpenStreetMap or invalid response."];
        }

        return ['other', "node/{$osmid}: ".$message];
    }

    /**
     * After a real import, EcPoiObserver runs jobs/side effects using the `wmfe` (S3) disk.
     * Without region/credentials the AWS SDK throws InvalidArgumentException — do not confuse with OSM geometry.
     */
    private function looksLikeStorageMisconfiguration(string $message): bool
    {
        $lower = mb_strtolower($message);

        return (str_contains($lower, 'region') && str_contains($lower, 's3'))
            || str_contains($lower, 'wmfe')
            || str_contains($lower, 'failed to get disk')
            || str_contains($lower, 'filesystemmanager')
            || (str_contains($lower, 'aws') && str_contains($lower, 'configuration'));
    }

    /**
     * @return array{action: string, osmid: int, ec_poi_id: ?int, taxonomy_identifier: ?string, taxonomy_created: bool}
     */
    private function importSingleNode(int $osmid, int $appId, ?int $userId, bool $dryRun, bool $global, bool $hasGlobalColumn): array
    {
        [$properties, $geometry] = $this->fetchOsmNode($osmid);
        $dto = OsmNodePoiData::fromOsmNode($osmid, $properties, $geometry);

        $resolution = $this->taxonomyResolver->resolve($dto, $dryRun);
        $taxonomy = $resolution['taxonomy'];

        $existing = $this->findExistingEcPoiByOsmid($osmid, $appId);
        $action = $existing ? 'updated' : 'created';

        if ($dryRun) {
            return [
                'action' => $action,
                'osmid' => $osmid,
                'ec_poi_id' => $existing?->id,
                'taxonomy_identifier' => $taxonomy->identifier,
                'taxonomy_created' => $resolution['created'],
            ];
        }

        $ecPoi = DB::transaction(function () use ($dto, $existing, $appId, $userId, $taxonomy, $global, $hasGlobalColumn) {
            $attrs = $dto->toEcPoiAttributes($appId, $userId);
            unset($attrs['name']);

            // Only pass fillable attributes (exclude osmid, name, properties).
            $fillable = array_diff_key($attrs, ['osmid' => true, 'name' => true, 'properties' => true]);

            if ($existing) {
                $existing->fill($fillable);
                $existing->properties = $this->mergeImportedEcPoiProperties(
                    $existing->properties ?? [],
                    $attrs['properties'],
                );
                $existing->setAttribute('osmid', $dto->osmid);
                $this->applyNameTranslations($existing, $dto, $taxonomy);
                if ($hasGlobalColumn) {
                    $existing->global = $global;
                }
                $existing->save();
                $poi = $existing;
            } else {
                $poi = new EcPoi;
                $poi->fill($fillable);
                $poi->properties = $attrs['properties'];
                $poi->setAttribute('osmid', $dto->osmid);
                if ($userId !== null) {
                    $poi->setAttribute('user_id', $userId);
                }
                if ($hasGlobalColumn) {
                    $poi->global = $global;
                }
                $this->applyNameTranslations($poi, $dto, $taxonomy);
                $poi->save();
            }

            $poi->taxonomyPoiTypes()->syncWithoutDetaching([$taxonomy->id]);

            return $poi;
        });

        // TODO: Sync POI media from OSM (`image`, `wikimedia_commons`, multi-value refs): Commons API,
        // then attach to EcPoi `default` collection (featured + gallery order).

        return [
            'action' => $action,
            'osmid' => $osmid,
            'ec_poi_id' => $ecPoi->id,
            'taxonomy_identifier' => $taxonomy->identifier,
            'taxonomy_created' => $resolution['created'],
        ];
    }

    /**
     * Finds a POI already imported from the same OSM node **within the same app**: first
     * `properties->osmid` (JSON, useful when the `osmid` column is unset), then `ec_pois.osmid`.
     *
     * Scoped by `app_id` so two different apps sharing the same database never resolve to each
     * other's POI for the same OSM node (fix applied when this service was centralized in
     * wm-package — see Fase: challenge, oc:8239).
     */
    protected function findExistingEcPoiByOsmid(int $osmid, int $appId): ?EcPoi
    {
        $byProperties = EcPoi::query()
            ->where('app_id', $appId)
            ->where(function ($query) use ($osmid) {
                $query->where('properties->osmid', $osmid)
                    ->orWhere('properties->osmid', (string) $osmid);
            })
            ->first();

        if ($byProperties !== null) {
            return $byProperties;
        }

        return EcPoi::query()->where('app_id', $appId)->where('osmid', $osmid)->first();
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     *
     * @throws OsmClientException When the OSM endpoint does not return valid JSON or the node does not exist.
     */
    private function fetchOsmNode(int $osmid): array
    {
        try {
            $payload = $this->osmClient->getPropertiesAndGeometry("node/{$osmid}");
        } catch (OsmClientException $e) {
            // Already described by the client (e.g. OsmClientExceptionNoTags).
            throw $e;
        } catch (\TypeError $e) {
            // OsmClient throws TypeError when Http::get(...)->json() returns null
            // (typically HTTP 404/410 with a non-JSON body for missing OSM IDs).
            throw new OsmClientException('not found on OpenStreetMap or invalid response.');
        }

        if (! isset($payload[0], $payload[1]) || ! is_array($payload[0]) || ! is_array($payload[1])) {
            throw new OsmClientException('unexpected OSM response.');
        }

        return [$payload[0], $payload[1]];
    }

    /**
     * Sets name translations on the model.
     *
     * Strategy, in order:
     *  1. OSM tags (`name`, `name:it`, `name:en`, …) collected in {@see OsmNodePoiData::$nameTranslations}.
     *  2. If OSM provides no `name*`, use translated names from the matched/created {@see TaxonomyPoiType}
     *     (e.g. "Viewpoint") so the POI inherits a category-consistent name instead of a titlecased OSM label.
     *  3. Final fallback: {@see OsmNodePoiData::primaryName()} (e.g. "OSM node 123") to avoid an empty name.
     */
    private function applyNameTranslations(EcPoi $poi, OsmNodePoiData $dto, TaxonomyPoiType $taxonomy): void
    {
        if ($dto->nameTranslations !== []) {
            foreach ($dto->nameTranslations as $locale => $value) {
                if ($value === '') {
                    continue;
                }
                $poi->setTranslation('name', $locale, $value);
            }

            return;
        }

        $taxonomyNames = array_filter(
            $taxonomy->getTranslations('name'),
            static fn ($value) => is_string($value) && $value !== '',
        );

        if ($taxonomyNames !== []) {
            foreach ($taxonomyNames as $locale => $value) {
                $poi->setTranslation('name', (string) $locale, $value);
            }

            return;
        }

        $poi->setTranslation('name', 'it', $dto->primaryName());
    }

    /**
     * @param  list<int|string>  $osmIds
     * @return list<int>
     */
    private function normalizeIds(array $osmIds): array
    {
        $ids = [];
        foreach ($osmIds as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed === '' || ! ctype_digit($trimmed)) {
                continue;
            }
            $ids[] = (int) $trimmed;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Merges existing properties with OSM import properties and drops legacy keys that would
     * otherwise survive {@see array_merge} (e.g. `related_url_assoc` before DTO alignment, or the
     * `osm` block replaced by `osm_data` + top-level `osmid`).
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $import
     * @return array<string, mixed>
     */
    private function mergeImportedEcPoiProperties(array $existing, array $import): array
    {
        $merged = array_merge($existing, $import);
        unset($merged['related_url_assoc'], $merged['osm']);

        return $merged;
    }
}

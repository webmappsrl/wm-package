<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\Osm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wm\WmPackage\Dto\OsmNodePoiData;
use Wm\WmPackage\Models\TaxonomyPoiType;

/**
 * Resolves a {@see TaxonomyPoiType} from the (OSM key, OSM value) pair derived from {@see OsmNodePoiData}.
 *
 * Strategy (in order):
 *  0. No qualifying tag among known keys → fallback to {@see TaxonomyPoiType} with identifier `poi`
 *     (existing row, or created on first real import if missing).
 *  1. Exact match on `identifier` using normalized OSM value (e.g. "viewpoint").
 *  2. Exact match on composite identifier "key-value" (e.g. "tourism-viewpoint").
 *  3. Case-insensitive match with trim (covers legacy identifiers with different casing).
 *  4. Create a new TaxonomyPoiType with identifier "key-value" and titlecased {it,en} names.
 *
 * All queries use the indexed/unique `identifier` column (see `create_taxonomy_poi_types_table` migration).
 */
class OsmTaxonomyPoiTypeResolver
{
    /**
     * In-memory cache to avoid repeated queries during a single import batch.
     *
     * @var array<string, TaxonomyPoiType|null>
     */
    private array $cache = [];

    /**
     * Returns an existing TaxonomyPoiType or creates a new one.
     * In dry-run mode, returns the existing instance or a non-persisted instance so the caller
     * can see which identifier would be created.
     *
     * @return array{taxonomy: TaxonomyPoiType, created: bool}
     */
    public function resolve(OsmNodePoiData $data, bool $dryRun = false): array
    {
        if ($data->poiTypeOsmKey === null || $data->poiTypeOsmValue === null) {
            $fallback = $this->fallbackGenericPoi($dryRun);

            return [
                'taxonomy' => $fallback['taxonomy'],
                'created' => $fallback['created'],
            ];
        }

        $valueIdentifier = OsmNodePoiData::normalizeIdentifier($data->poiTypeOsmValue);
        $compositeIdentifier = $data->poiTypeCompositeIdentifier() ?? $valueIdentifier;

        if ($existing = $this->findByIdentifier($valueIdentifier)) {
            return ['taxonomy' => $existing, 'created' => false];
        }

        if ($existing = $this->findByIdentifier($compositeIdentifier)) {
            return ['taxonomy' => $existing, 'created' => false];
        }

        if ($dryRun) {
            return [
                'taxonomy' => $this->buildUnsaved($compositeIdentifier, $data->poiTypeOsmValue, $data->nameTranslations),
                'created' => true,
            ];
        }

        return [
            'taxonomy' => $this->createTaxonomy($compositeIdentifier, $data->poiTypeOsmValue, $data->nameTranslations),
            'created' => true,
        ];
    }

    /**
     * Lookup by identifier with legacy support: exact match first, then case-insensitive trim.
     */
    private function findByIdentifier(string $identifier): ?TaxonomyPoiType
    {
        if ($identifier === '') {
            return null;
        }
        if (array_key_exists($identifier, $this->cache)) {
            return $this->cache[$identifier];
        }

        $match = TaxonomyPoiType::query()
            ->whereRaw('LOWER(BTRIM(identifier)) = ?', [$identifier])
            ->orderBy('id')
            ->first();

        return $this->cache[$identifier] = $match;
    }

    private function createTaxonomy(string $identifier, string $osmValue, array $nameTranslations): TaxonomyPoiType
    {
        $taxonomy = new TaxonomyPoiType;
        $this->applyNewTaxonomyAttributes($taxonomy, $identifier, $osmValue, $nameTranslations);
        $taxonomy->save();

        $this->cache[$identifier] = $taxonomy;

        return $taxonomy;
    }

    private function buildUnsaved(string $identifier, string $osmValue, array $nameTranslations): TaxonomyPoiType
    {
        $taxonomy = new TaxonomyPoiType;
        $this->applyNewTaxonomyAttributes($taxonomy, $identifier, $osmValue, $nameTranslations);

        return $taxonomy;
    }

    /**
     * @param  array<string, string>  $nameTranslations
     */
    private function applyNewTaxonomyAttributes(
        TaxonomyPoiType $taxonomy,
        string $identifier,
        string $osmValue,
        array $nameTranslations,
    ): void {
        $humanName = Str::title(str_replace(['_', '-'], ' ', $osmValue));

        $taxonomy->identifier = $identifier;

        $taxonomy->setTranslation('name', 'it', $nameTranslations['it'] ?? $humanName);
        if (isset($nameTranslations['en']) && $nameTranslations['en'] !== '') {
            $taxonomy->setTranslation('name', 'en', $nameTranslations['en']);
        } else {
            $taxonomy->setTranslation('name', 'en', $humanName);
        }
    }

    /**
     * When the OSM node has no qualifying classifying tag, always use {@see TaxonomyPoiType} with identifier `poi`.
     * If missing: created on real import; in dry-run returns a non-persisted instance with created=true.
     *
     * @return array{taxonomy: TaxonomyPoiType, created: bool}
     */
    private function fallbackGenericPoi(bool $dryRun): array
    {
        if ($existing = $this->findByIdentifier('poi')) {
            return ['taxonomy' => $existing, 'created' => false];
        }

        $taxonomy = new TaxonomyPoiType;
        $taxonomy->identifier = 'poi';
        $taxonomy->setTranslation('name', 'it', 'POI');
        $taxonomy->setTranslation('name', 'en', 'POI');

        if ($dryRun) {
            return ['taxonomy' => $taxonomy, 'created' => true];
        }

        try {
            DB::transaction(function () use ($taxonomy) {
                $taxonomy->save();
            });
            $this->cache['poi'] = $taxonomy;

            return ['taxonomy' => $taxonomy, 'created' => true];
        } catch (\Throwable) {
            // Race: another process created `poi` in the meantime.
            $existing = TaxonomyPoiType::query()->where('identifier', 'poi')->firstOrFail();
            $this->cache['poi'] = $existing;

            return ['taxonomy' => $existing, 'created' => false];
        }
    }
}

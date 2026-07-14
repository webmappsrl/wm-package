<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\Osm;

/**
 * Aggregates outcomes of a single {@see OsmPoiImporter::importNodes()} run.
 *
 * Not a readonly DTO: it is filled incrementally during iteration; state is
 * owned by the importer (mutations only through public methods).
 */
class ImportReport
{
    /** @var list<array{action: string, osmid: int, ec_poi_id: ?int, taxonomy_identifier: ?string, taxonomy_created: bool}> */
    private array $outcomes = [];

    /** @var list<array{osmid: int, error: string, category: string}> */
    private array $failures = [];

    /**
     * Human-readable error category labels (English strings passed to {@see __()}).
     * Translations live in the package `resources/lang/{en,it}.json` files.
     *
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        'not_found_or_invalid_osm' => 'Node not found or invalid OSM response',
        'no_tags' => 'OSM node has no tags (nothing useful to import)',
        'geometry' => 'Invalid OSM geometry',
        'storage' => 'S3/MinIO storage (wmfe disk) not configured or error after save',
        'other' => 'Other error',
    ];

    /** Count of OSM IDs not processed because {@see config('wm-osm-import.max_ids_per_run')} was exceeded. */
    private int $truncatedBeyondLimit = 0;

    public function __construct(public readonly bool $dryRun) {}

    public function setTruncatedBeyondLimit(int $count): void
    {
        $this->truncatedBeyondLimit = max(0, $count);
    }

    public function truncatedBeyondLimit(): int
    {
        return $this->truncatedBeyondLimit;
    }

    /**
     * @param  array{action: string, osmid: int, ec_poi_id: ?int, taxonomy_identifier: ?string, taxonomy_created: bool}  $outcome
     */
    public function addOutcome(array $outcome): void
    {
        $this->outcomes[] = $outcome;
    }

    public function addFailure(int $osmid, string $error, string $category = 'other'): void
    {
        $this->failures[] = [
            'osmid' => $osmid,
            'error' => $error,
            'category' => $category,
        ];
    }

    /** @return list<array{action: string, osmid: int, ec_poi_id: ?int, taxonomy_identifier: ?string, taxonomy_created: bool}> */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    /** @return list<array{osmid: int, error: string, category: string}> */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Failure counts grouped by category (e.g. ['no_tags' => 12, 'not_found_or_invalid_osm' => 9]).
     *
     * @return array<string, int>
     */
    public function failuresByCategory(): array
    {
        $out = [];
        foreach ($this->failures as $f) {
            $out[$f['category']] = ($out[$f['category']] ?? 0) + 1;
        }

        return $out;
    }

    public function createdCount(): int
    {
        return count(array_filter($this->outcomes, static fn ($o) => $o['action'] === 'created'));
    }

    public function updatedCount(): int
    {
        return count(array_filter($this->outcomes, static fn ($o) => $o['action'] === 'updated'));
    }

    public function failuresCount(): int
    {
        return count($this->failures);
    }

    public function newTaxonomiesCount(): int
    {
        return count(array_filter($this->outcomes, static fn ($o) => $o['taxonomy_created'] === true));
    }
}

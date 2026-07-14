<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\Osm;

/**
 * Serializes {@see ImportReport} for the post-import HTML report view.
 *
 * @phpstan-type CategoryRow array{label: string, count: int}
 * @phpstan-type FailureRow array{node: string, osm_url: string, message: string, category: string}
 */
final class OsmImportReportPresenter
{
    private const FAILURE_SAMPLE_LIMIT = 40;

    /**
     * @return array{
     *     dry_run: bool,
     *     requested: int,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     new_taxonomies: int,
     *     categories: list<CategoryRow>,
     *     failure_samples: list<FailureRow>,
     *     failure_more: int,
     *     status: 'success'|'warning'|'danger',
     *     truncated_beyond_limit: int,
     * }
     */
    public static function payload(ImportReport $report, int $requested): array
    {
        $categories = [];
        foreach ($report->failuresByCategory() as $category => $count) {
            $categories[] = [
                'label' => __(ImportReport::CATEGORY_LABELS[$category] ?? $category),
                'count' => $count,
            ];
        }

        $failures = $report->failures();
        $samples = array_slice($failures, 0, self::FAILURE_SAMPLE_LIMIT);
        $failureSamples = [];
        foreach ($samples as $f) {
            $osmid = (int) $f['osmid'];
            $failureSamples[] = [
                'node' => 'node/' . $osmid,
                'osm_url' => 'https://www.openstreetmap.org/node/' . $osmid,
                'message' => $f['error'],
                'category' => __(ImportReport::CATEGORY_LABELS[$f['category']] ?? $f['category']),
            ];
        }

        $processedOk = $report->createdCount() + $report->updatedCount();
        $truncated = $report->truncatedBeyondLimit();
        $status = self::resolveStatus($requested, $report->failuresCount(), $processedOk);

        return [
            'dry_run' => $report->dryRun,
            'requested' => $requested,
            'created' => $report->createdCount(),
            'updated' => $report->updatedCount(),
            'skipped' => $report->failuresCount(),
            'new_taxonomies' => $report->newTaxonomiesCount(),
            'categories' => $categories,
            'failure_samples' => $failureSamples,
            'failure_more' => max(0, $report->failuresCount() - count($samples)),
            'status' => $status,
            'truncated_beyond_limit' => $truncated,
        ];
    }

    private static function resolveStatus(int $requested, int $failures, int $processedOk): string
    {
        if ($requested === 0) {
            return 'warning';
        }
        if ($failures > 0 && $processedOk === 0) {
            return 'danger';
        }
        if ($failures > 0) {
            return 'warning';
        }

        return 'success';
    }
}

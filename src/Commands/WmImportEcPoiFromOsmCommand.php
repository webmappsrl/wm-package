<?php

declare(strict_types=1);

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Osm\ImportReport;
use Wm\WmPackage\Services\Osm\OsmPoiImporter;

/**
 * Imports POIs from OSM node IDs (CLI).
 *
 * Examples:
 *   php artisan wm-package:import-ec-pois-from-osm "12345,67890,11223" --app=1
 *   php artisan wm-package:import-ec-pois-from-osm 12345 --app=1 --dry-run
 *   php artisan wm-package:import-ec-pois-from-osm @osmids.txt --app=1
 *   php artisan wm-package:import-ec-pois-from-osm 12345 --app=2 --no-global
 *
 * POI owner user_id comes from {@see App::$user_id} on the selected app (same as the Nova action).
 */
class WmImportEcPoiFromOsmCommand extends Command
{
    protected $signature = 'wm-package:import-ec-pois-from-osm
        {osmids : Comma-separated OSM node IDs, or "@/path/file.txt" to read IDs from a file}
        {--app= : Destination app ID (auto-picked when only one app exists)}
        {--dry-run : Run without persisting; print what would happen}
        {--no-global : Set EcPoi.global=false (excluded from pois.geojson; default: global true)}';

    protected $description = 'Import EcPoi records from OpenStreetMap (nodes only), mapping OSM tags to TaxonomyPoiType.';

    public function handle(OsmPoiImporter $importer): int
    {
        $osmIds = $this->readOsmIds((string) $this->argument('osmids'));
        if ($osmIds === []) {
            $this->error('No valid OSM IDs. Enter numeric IDs separated by commas.');

            return self::INVALID;
        }

        $appOption = $this->option('app');
        $app = $this->resolveApp();
        if ($app === null) {
            if ($appOption !== null && $appOption !== '') {
                $this->error("No app found with ID {$appOption}.");

                return self::INVALID;
            }
            $this->error('Pass --app=ID: more than one app exists in the database.');

            return self::INVALID;
        }

        $appId = (int) $app->id;
        $userId = $app->user_id !== null ? (int) $app->user_id : null;
        $dryRun = (bool) $this->option('dry-run');
        $global = ! (bool) $this->option('no-global');

        $this->info(sprintf(
            '%sImporting %d OSM ID(s) into app %d%s (global=%s) ...',
            $dryRun ? '[DRY-RUN] ' : '',
            count($osmIds),
            $appId,
            $userId !== null ? " (user_id={$userId})" : '',
            $global ? 'true' : 'false',
        ));

        $report = $importer->importNodes($osmIds, $appId, $userId, $dryRun, $global);

        $this->renderReport($report);

        // SUCCESS when at least one POI was created/updated. Skipped IDs are bad input data,
        // not a failed operation — do not force a non-zero exit for that alone.
        $imported = $report->createdCount() + $report->updatedCount();

        return $imported > 0 || $report->failuresCount() === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<int>
     */
    private function readOsmIds(string $raw): array
    {
        if (str_starts_with($raw, '@')) {
            $path = substr($raw, 1);
            if (! is_readable($path)) {
                $this->error("File not readable: {$path}");

                return [];
            }
            $raw = (string) file_get_contents($path);
        }

        $ids = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || ! ctype_digit($token)) {
                continue;
            }
            $ids[] = (int) $token;
        }

        return array_values(array_unique($ids));
    }

    private function resolveApp(): ?App
    {
        $option = $this->option('app');
        if ($option !== null && $option !== '') {
            return App::query()->find((int) $option);
        }

        $apps = App::query()->orderBy('id')->limit(2)->get();
        if ($apps->count() === 1) {
            return $apps->first();
        }

        return null;
    }

    private function renderReport(ImportReport $report): void
    {
        $rows = array_map(
            static fn ($o) => [
                $o['osmid'],
                $o['action'],
                $o['ec_poi_id'] ?? '-',
                $o['taxonomy_identifier'] ?? '-',
                $o['taxonomy_created'] ? 'yes' : 'no',
            ],
            $report->outcomes(),
        );

        if ($rows !== []) {
            $this->table(['OSMID', 'Action', 'EcPoi ID', 'Taxonomy', 'New Taxonomy?'], $rows);
        }

        if ($report->truncatedBeyondLimit() > 0) {
            $this->warn(sprintf(
                'Warning: %d OSM ID(s) were not processed (WM_OSM_IMPORT_MAX_IDS_PER_RUN limit). Run another import with the remaining IDs.',
                $report->truncatedBeyondLimit(),
            ));
        }

        if ($report->failuresCount() > 0) {
            $this->warn("Skipped ({$report->failuresCount()}):");
            foreach ($report->failures() as $failure) {
                $label = ImportReport::CATEGORY_LABELS[$failure['category']] ?? $failure['category'];
                $this->warn(" - node/{$failure['osmid']} [{$label}]: {$failure['error']}");
            }

            $byCategory = $report->failuresByCategory();
            if ($byCategory !== []) {
                $this->line('Skip summary by reason:');
                foreach ($byCategory as $category => $count) {
                    $label = ImportReport::CATEGORY_LABELS[$category] ?? $category;
                    $this->line(" - {$label}: {$count}");
                }
            }
        }

        $this->line(sprintf(
            '%sCreated: %d | Updated: %d | New taxonomies: %d | Skipped: %d',
            $report->dryRun ? '[DRY-RUN] ' : '',
            $report->createdCount(),
            $report->updatedCount(),
            $report->newTaxonomiesCount(),
            $report->failuresCount(),
        ));
    }
}

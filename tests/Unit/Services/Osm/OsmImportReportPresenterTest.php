<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Osm\ImportReport;
use Wm\WmPackage\Services\Osm\OsmImportReportPresenter;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('builds a success payload when there are no failures', function () {
    $report = new ImportReport(false);
    $report->addOutcome(['action' => 'created', 'osmid' => 1, 'ec_poi_id' => 10, 'taxonomy_identifier' => 'amenity-bench', 'taxonomy_created' => true]);

    $payload = OsmImportReportPresenter::payload($report, 1);

    expect($payload['status'])->toBe('success')
        ->and($payload['created'])->toBe(1)
        ->and($payload['new_taxonomies'])->toBe(1);
});

it('builds a danger payload when every node fails', function () {
    $report = new ImportReport(false);
    $report->addFailure(1, 'node not found', 'not_found_or_invalid_osm');

    $payload = OsmImportReportPresenter::payload($report, 1);

    expect($payload['status'])->toBe('danger')
        ->and($payload['failure_samples'])->toHaveCount(1)
        ->and($payload['failure_samples'][0]['osm_url'])->toBe('https://www.openstreetmap.org/node/1');
});

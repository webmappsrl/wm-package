<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Osm\ImportReport;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('aggregates outcomes and failures', function () {
    $report = new ImportReport(false);
    $report->addOutcome([
        'action' => 'created',
        'osmid' => 1,
        'ec_poi_id' => 10,
        'taxonomy_identifier' => 'amenity-bench',
        'taxonomy_created' => false,
    ]);
    $report->addFailure(2, 'skipped', 'no_tags');
    $report->setTruncatedBeyondLimit(3);

    expect($report->createdCount())->toBe(1)
        ->and($report->failuresCount())->toBe(1)
        ->and($report->truncatedBeyondLimit())->toBe(3)
        ->and($report->failuresByCategory())->toHaveKey('no_tags');
});

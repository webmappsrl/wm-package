<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Osm\OsmImportReportStore;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('stores and retrieves a report only for the owning user', function () {
    $token = OsmImportReportStore::put(['requested' => 1], 42);

    expect(OsmImportReportStore::get($token, 42))->toBe(['requested' => 1])
        ->and(OsmImportReportStore::get($token, 43))->toBeNull()
        ->and(OsmImportReportStore::get('non-existent-token', 42))->toBeNull();
});

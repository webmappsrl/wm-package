<?php

declare(strict_types=1);

use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('exposes wm-osm-import config with defaults', function () {
    expect(config('wm-osm-import.request_delay_ms'))->toBe(350)
        ->and(config('wm-osm-import.max_ids_per_run'))->toBe(500);
});

it('reads request_delay_ms and max_ids_per_run from env', function () {
    config(['wm-osm-import.request_delay_ms' => 0, 'wm-osm-import.max_ids_per_run' => 10]);

    expect(config('wm-osm-import.request_delay_ms'))->toBe(0)
        ->and(config('wm-osm-import.max_ids_per_run'))->toBe(10);
});

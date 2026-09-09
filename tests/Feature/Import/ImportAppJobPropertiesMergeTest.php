<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Models\App;

uses(DatabaseTransactions::class);

function mergePropertiesForTest(App $app, array $incoming): array
{
    $job = new ImportAppJob(999, []);
    $method = new ReflectionMethod($job, 'mergeProperties');
    $method->setAccessible(true);

    return $method->invoke($job, $app, $incoming);
}

it('preserves Nova-configured properties keys across a re-import', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'geohub_id' => 999,
            'min_app_version' => '3.2.1',
            'analytics_app_enabled' => true,
        ],
    ]);

    $merged = mergePropertiesForTest($app, ['geohub_id' => 999, 'geohub_synced_at' => 'now']);

    expect($merged)->toHaveKeys(['min_app_version', 'analytics_app_enabled'])
        ->and($merged['min_app_version'])->toBe('3.2.1');
});

it('lets a non-null Geohub value overwrite an explicit local null under theme', function () {
    $app = App::factory()->createQuietly([
        'properties' => ['theme' => ['primary_color' => null, 'font_family_header' => null]],
    ]);

    $merged = mergePropertiesForTest($app, [
        'theme' => ['primary_color' => '#0055aa', 'font_family_header' => 'Montserrat'],
    ]);

    expect($merged['theme']['primary_color'])->toBe('#0055aa')
        ->and($merged['theme']['font_family_header'])->toBe('Montserrat');
});

it('keeps a local value when Geohub has nothing to say about that key', function () {
    $app = App::factory()->createQuietly([
        'properties' => ['theme' => ['secondary_color' => '#abcdef']],
    ]);

    $merged = mergePropertiesForTest($app, ['theme' => ['primary_color' => '#0055aa']]);

    expect($merged['theme']['secondary_color'])->toBe('#abcdef')
        ->and($merged['theme']['primary_color'])->toBe('#0055aa');
});

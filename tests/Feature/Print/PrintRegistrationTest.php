<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Services\FeaturesService;

/*
 * La stampa non è un dominio opzionale (scrum del 24/11): rotte, comando e pulizia notturna ci sono su
 * ogni shard, e la stampa vale solo per gli elementi di un'App con `properties.ec_pdf_enabled` acceso.
 */
it('is not an optional domain and has no switch on the shard', function () {
    expect(FeaturesService::declaredDomains())->not->toContain('print')
        ->and(config('wm-package.print'))->not->toHaveKey('enabled');
});

it('registers routes, cleanup command and nightly cleanup at boot', function () {
    expect(Route::has('print.show'))->toBeTrue()
        ->and(Route::has('print.zip'))->toBeTrue()
        ->and(Route::has('print.overview-map'))->toBeTrue()
        ->and(Artisan::all())->toHaveKey('wm:print-cleanup')
        ->and(collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains((string) $e->command, 'wm:print-cleanup')))->toBeTrue();
});

it('exposes the expected defaults', function () {
    $print = config('wm-package.print');

    expect($print['queue'])->toBe('print')
        ->and($print['map_service']['driver'])->toBe('fake')
        ->and($print['map_service']['timeout'])->toBe(60)
        ->and($print['compile']['timeout'])->toBe(120)
        ->and($print['compile']['memory_mb'])->toBe(1024)
        ->and($print['zip']['max_tracks'])->toBe(50)
        ->and($print['overview_map']['max_scale'])->toBe(50000)
        ->and($print)->toHaveKey('deps_version');
});

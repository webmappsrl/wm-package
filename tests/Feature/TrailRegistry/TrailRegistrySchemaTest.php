<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * runTrailRegistryStubs() e' condivisa in tests/Pest.php (applica tutti e
 * quattro gli stub del dominio, non raccolti da `migrate` perche' hanno
 * estensione .php.stub).
 *
 * Non e' in un beforeEach globale qui: il test del gate di pubblicazione (in
 * fondo al file) deve girare su un DB che NON ha ancora lo schema del
 * dominio, altrimenti gli stub risulterebbero gia' allineati e non "da
 * pubblicare".
 */
it('crea le tre tabelle del dominio', function () {
    runTrailRegistryStubs();

    expect(Schema::hasTable('trail_applications'))->toBeTrue();
    expect(Schema::hasTable('trail_registry_codes'))->toBeTrue();
    expect(Schema::hasTable('trail_registry_code_events'))->toBeTrue();
});

it('rifiuta un numero fuori dalle due cifre', function () {
    runTrailRegistryStubs();

    expect(fn () => DB::statement("
        INSERT INTO trail_registry_codes
            (region, province, area, sector, number, variant, status, created_at, updated_at)
        VALUES ('Z', 'NU', 'B', '5', 150, '0', 'released', now(), now())
    "))->toThrow(QueryException::class);
});

it('rifiuta una variante non ammessa dallo schema', function () {
    runTrailRegistryStubs();

    expect(fn () => DB::statement("
        INSERT INTO trail_registry_codes
            (region, province, area, sector, number, variant, status, created_at, updated_at)
        VALUES ('Z', 'NU', 'B', '5', 35, '-', 'released', now(), now())
    "))->toThrow(QueryException::class);
});

it('rifiuta due codici attivi identici', function () {
    runTrailRegistryStubs();

    makeCode(['region' => 'Z', 'province' => 'NU', 'area' => 'B', 'sector' => '5', 'number' => 35, 'variant' => '0', 'status' => 'reserved']);

    expect(fn () => makeCode(['region' => 'Z', 'province' => 'NU', 'area' => 'B', 'sector' => '5', 'number' => 35, 'variant' => '0', 'status' => 'reserved']))
        ->toThrow(QueryException::class);
});

it('ammette piu codici identici se sono liberati', function () {
    runTrailRegistryStubs();

    // Un numero puo' essere stato liberato e riassegnato piu' volte: la
    // storia resta in tabella, e nessuna di quelle righe occupa la posizione.
    foreach (range(1, 3) as $ignored) {
        makeCode(['region' => 'Z', 'province' => 'NU', 'area' => 'B', 'sector' => '5', 'number' => 35, 'variant' => '0', 'status' => 'released']);
    }

    expect(DB::table('trail_registry_codes')->count())->toBe(3);
});

it('rifiuta lo stesso codice attivo su due stati diversi (reserved + assigned)', function () {
    runTrailRegistryStubs();

    makeCode(['region' => 'Z', 'province' => 'NU', 'area' => 'B', 'sector' => '5', 'number' => 35, 'variant' => '0', 'status' => 'reserved']);

    // Caso peggiore per la clausola WHERE dell'indice unico parziale: uno
    // stesso codice occupato da due righe con stati attivi DIVERSI (non lo
    // stesso stato ripetuto) deve comunque essere rifiutato. E' il caso in
    // cui una WHERE scritta male (es. WHERE status = 'reserved' invece di
    // status IN (...)) passerebbe inosservato.
    expect(fn () => makeCode(['region' => 'Z', 'province' => 'NU', 'area' => 'B', 'sector' => '5', 'number' => 35, 'variant' => '0', 'status' => 'assigned']))
        ->toThrow(QueryException::class);
});

it('espone gli stub del dominio trail_registry al gate di pubblicazione', function () {
    // Nessun runTrailRegistryStubs() qui: lo schema del dominio non deve
    // esistere ancora sul DB, altrimenti il comando li considererebbe gia'
    // allineati e non li elencherebbe come "da pubblicare".
    config(['wm-package.features' => ['trail_registry' => ['enabled' => true]]]);

    $exitCode = Artisan::call('wm-package:publish-missing-migrations', [
        '--with' => ['trail_registry'],
        '--dry-run' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1);

    foreach ([
        'trail_registry/zz_2026_09_09_000001_create_trail_applications_table',
        'trail_registry/zz_2026_09_09_000002_create_trail_registry_codes_table',
        'trail_registry/zz_2026_09_09_000003_create_trail_registry_code_events_table',
        'trail_registry/zz_2026_09_09_000004_add_gist_index_to_taxonomy_wheres',
        'trail_registry/zz_2026_09_10_000001_create_trail_registry_anomalies_table',
    ] as $stub) {
        expect($output)->toContain($stub);
    }
});

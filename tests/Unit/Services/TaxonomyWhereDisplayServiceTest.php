<?php

declare(strict_types=1);

use Tests\TestCase;
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;

uses(TestCase::class);

function displayService(): TaxonomyWhereDisplayService
{
    return app(TaxonomyWhereDisplayService::class);
}

it('computes the same category for int and string levels', function () {
    expect(displayService()->categoryOf(['it' => 'Lazio', '_admin_level' => 4]))->toBe('4');
    expect(displayService()->categoryOf(['it' => 'Lazio', '_admin_level' => '4']))->toBe('4');
    expect(displayService()->categoryOf(['name' => ['it' => 'Lazio'], 'admin_level' => 4]))->toBe('4');
});

it('falls back to the source when the level is missing, null or empty', function () {
    expect(displayService()->categoryOf(['it' => 'X', '_admin_level' => null, '_source' => 'geohub']))->toBe('source:geohub');
    expect(displayService()->categoryOf(['it' => 'X', '_admin_level' => '', '_source' => 'geohub']))->toBe('source:geohub');
    expect(displayService()->categoryOf(['name' => ['it' => 'X'], 'admin_level' => null, 'source' => 'geohub']))->toBe('source:geohub');
});

it('returns a null category for the oldest shape', function () {
    expect(displayService()->categoryOf(['it' => 'Esperia', 'en' => 'Esperia']))->toBeNull();
    expect(displayService()->categoryOf(['it' => 'X', '_admin_level' => 'abc']))->toBeNull();
});

it('normalizes an oc:8487 entry to the legacy shape keeping only language keys', function () {
    $entry = ['name' => ['it' => 'Toscana', 'en' => 'Tuscany', 'wikidata' => 'Q1273'], 'admin_level' => 4, 'source' => 'osmfeatures'];

    expect(displayService()->toLegacyEntry($entry))
        ->toBe(['it' => 'Toscana', 'en' => 'Tuscany', '_admin_level' => 4, '_source' => 'osmfeatures']);
});

it('accepts a plain or json string name', function () {
    expect(displayService()->toLegacyEntry(['name' => 'Corsica', 'admin_level' => 4]))
        ->toBe(['it' => 'Corsica', 'en' => 'Corsica', '_admin_level' => 4]);
    expect(displayService()->toLegacyEntry(['name' => '{"it":"Corsica"}']))->toBe(['it' => 'Corsica']);
});

it('keeps a legacy entry as it is', function () {
    $entry = ['it' => 'Lazio', 'en' => 'Lazio', '_admin_level' => 4];

    expect(displayService()->toLegacyEntry($entry))->toBe($entry);
});

it('drops entries without any name when normalizing', function () {
    $normalized = displayService()->normalize([
        'R1' => ['_admin_level' => 8],
        'R2' => ['it' => 'Lazio', '_admin_level' => 4],
    ]);

    expect(array_keys($normalized))->toBe(['R2']);
});

it('filters by one or more categories and keeps everything when none is selected', function () {
    $where = [
        'R40784' => ['it' => 'Lazio', '_admin_level' => 4],
        'R41241' => ['it' => 'Esperia', '_admin_level' => 8],
        'G1' => ['it' => 'Parco', '_source' => 'geohub'],
        'OLD' => ['it' => 'Ausonia'],
    ];

    expect(array_keys(displayService()->filter($where, ['4'])))->toBe(['R40784']);
    expect(array_keys(displayService()->filter($where, ['4', 'source:geohub'])))->toBe(['R40784', 'G1']);
    expect(array_keys(displayService()->filter($where, [])))->toBe(['R40784', 'R41241', 'G1', 'OLD']);
    expect(displayService()->filter($where, ['6']))->toBe([]);
});

it('orders names by ascending level with nulls first', function () {
    $names = displayService()->orderedNames([
        'R41241' => ['it' => 'Esperia', '_admin_level' => 8],
        'R40784' => ['it' => 'Lazio', '_admin_level' => 4],
        'OLD' => ['it' => 'Ausonia'],
    ]);

    expect($names)->toBe(['Ausonia', 'Lazio', 'Esperia']);
});

it('maps osmfeatures output to the legacy shape with the osmfeatures source', function () {
    $mapped = displayService()->fromOsmfeatures([
        'R617447' => ['it' => 'Toscana', 'en' => 'Tuscany', '_admin_level' => 4],
        'R1' => ['_admin_level' => 8],
    ]);

    expect($mapped)->toBe(['R617447' => ['it' => 'Toscana', 'en' => 'Tuscany', '_admin_level' => 4, '_source' => 'osmfeatures']]);
});

it('classifies the stored format', function () {
    expect(displayService()->classifyFormat(null))->toBe('empty');
    expect(displayService()->classifyFormat([]))->toBe('empty');
    expect(displayService()->classifyFormat(['R1' => ['it' => 'Esperia']]))->toBe('oldest');
    expect(displayService()->classifyFormat(['R1' => ['name' => ['it' => 'X'], 'admin_level' => 4]]))->toBe('oc8487');
    expect(displayService()->classifyFormat(['R1' => ['it' => 'Lazio', '_admin_level' => 4]]))->toBe('legacy');
});

it('reads the option again after the scoped instance is flushed', function () {
    $app = \Wm\WmPackage\Models\App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    expect(displayService()->selectedCategoriesForApp($app->id))->toBe(['4']);

    \Illuminate\Support\Facades\DB::table('apps')->where('id', $app->id)
        ->update(['properties' => json_encode(['taxonomy_where_display' => ['8']])]);
    app()->forgetScopedInstances();

    expect(displayService()->selectedCategoriesForApp($app->id))->toBe(['8']);
})->uses(\Illuminate\Foundation\Testing\DatabaseTransactions::class);

it('reads the selected categories when the MultiSelect field saved them as a JSON string', function () {
    // Fallback per un eventuale campo Nova MultiSelect su properties->taxonomy_where_display
    // che, invece di un array nativo, salva la lista come stringa JSON serializzata
    // (verifica non eseguibile senza Nova UI diretta, coperta invece da normalizeOptionValue()
    // sotto — regressione emersa in review, oc:8588).
    $app = \Wm\WmPackage\Models\App::factory()->create();

    \Illuminate\Support\Facades\DB::table('apps')->where('id', $app->id)
        ->update(['properties' => json_encode(['taxonomy_where_display' => '["4","8"]'])]);
    app()->forgetScopedInstances();

    expect(displayService()->selectedCategoriesForApp($app->id))->toBe(['4', '8']);
})->uses(\Illuminate\Foundation\Testing\DatabaseTransactions::class);

it('normalizes a JSON-string option value to a string array (oc:8588)', function () {
    expect(displayService()->normalizeOptionValue('["4","8"]'))->toBe(['4', '8']);
});

it('normalizes a native array option value to a string array, stringifying each entry (oc:8588)', function () {
    expect(displayService()->normalizeOptionValue([4, '8']))->toBe(['4', '8']);
});

it('normalizes anything that is not a string or array to an empty array (oc:8588)', function () {
    expect(displayService()->normalizeOptionValue(null))->toBe([]);
    expect(displayService()->normalizeOptionValue(42))->toBe([]);
    expect(displayService()->normalizeOptionValue(false))->toBe([]);
});

it('normalizes an unparseable JSON string to an empty array (oc:8588)', function () {
    expect(displayService()->normalizeOptionValue('not json'))->toBe([]);
});

it('skips non-scalar elements when normalizing an option value, instead of stringifying them (oc:8588, fix round 1)', function () {
    expect(displayService()->normalizeOptionValue([4, '8', ['nested' => 'x'], null]))->toBe(['4', '8']);
});

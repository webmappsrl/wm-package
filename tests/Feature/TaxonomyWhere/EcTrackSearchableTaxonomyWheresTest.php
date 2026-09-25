<?php

declare(strict_types=1);

// Wm\WmPackage\Tests\ non e' in autoload-dev del consumer — require diretto cosi' `use
// InsertsGeometryFixtures` sotto risolve indipendentemente da quale suite lancia questo file
// (stesso pattern di ImportEcPoiJobTaxonomySyncTest.php, oc:8588).
require_once __DIR__.'/../../Concerns/InsertsGeometryFixtures.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Tests\Concerns\InsertsGeometryFixtures;

uses(TestCase::class, DatabaseTransactions::class, InsertsGeometryFixtures::class);

function trackForSearch(App $app): EcTrack
{
    $id = test()->insertEcTrackWithGeometry($app, ['taxonomy_where' => [
        'R40784' => ['it' => 'Lazio', '_admin_level' => 4],
        'R41241' => ['it' => 'Esperia', '_admin_level' => 8],
    ]]);

    return EcTrack::find($id);
}

it('indexes only the selected categories in taxonomyWheres', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);

    expect(trackForSearch($app)->toSearchableArray()['taxonomyWheres'])->toBe(['Lazio']);
});

it('indexes every entry when no category is selected', function () {
    $app = App::factory()->create();

    expect(trackForSearch($app)->toSearchableArray()['taxonomyWheres'])->toBe(['Lazio', 'Esperia']);
});

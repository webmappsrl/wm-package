<?php

declare(strict_types=1);

// Wm\WmPackage\Tests\ non e' in autoload-dev del consumer — require diretto cosi' `use
// InsertsGeometryFixtures` sotto risolve indipendentemente da quale suite lancia questo file
// (stesso pattern di ImportEcPoiJobTaxonomySyncTest.php, oc:8588).
require_once __DIR__.'/../../Concerns/InsertsGeometryFixtures.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Nova\Filters\EcPoiRegionFilter;
use Wm\WmPackage\Tests\Concerns\InsertsGeometryFixtures;

uses(TestCase::class, DatabaseTransactions::class, InsertsGeometryFixtures::class);

// Regressione oc:8588: gli scrittori sono tornati alla forma legacy
// {<lingue>, _admin_level, _source} per properties.taxonomy_where. Questo test verifica
// che i lettori esistenti (filtro Nova e scope Eloquent) continuino a intendere quella forma.
function legacyPoi(App $app): int
{
    return test()->insertEcPoiWithGeometry($app, ['taxonomy_where' => [
        'R40784' => ['it' => 'Lazio', '_admin_level' => 4, '_source' => 'osmfeatures'],
    ]]);
}

it('EcPoiRegionFilter still finds a POI stored in the legacy shape', function () {
    $id = legacyPoi(App::factory()->create());

    $query = (new EcPoiRegionFilter)->apply(NovaRequest::create('/'), EcPoi::query(), 'R40784');

    expect($query->pluck('id')->all())->toContain($id);
});

it('scopeByWhereProperty still matches a POI stored in the legacy shape', function () {
    $id = legacyPoi(App::factory()->create());

    $ids = EcPoi::query()->byWhereProperty(['taxonomy_where' => ['R40784' => []]])->pluck('id')->all();

    expect($ids)->toContain($id);
});

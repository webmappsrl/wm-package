<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Jobs\UpdateEcPoiDemJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\Models\EcPoiService;

// wm-package/tests/Feature è fuori dal path bound da tests/Pest.php del consumer, quindi il
// TestCase va dichiarato esplicitamente altrimenti Bus::fake() non risolve il QueueingDispatcher
// (vedi wm-package/.claude/rules/test.md).
uses(TestCase::class, DatabaseTransactions::class);

// Trovato mancante in review finale (oc:8487): EcTrackService ha un test dedicato per
// updateDataChain() (Task 3), EcPoiService no (Task 2 richiedeva solo un grep). Mirror dello
// stesso schema, verifica che il nuovo SyncModelTaxonomyWhereJob sia davvero nella chain e non
// solo importato.
it('dispatches SyncModelTaxonomyWhereJob chained with UpdateEcPoiDemJob when elevation and taxonomy_where are unset', function () {
    Bus::fake();

    $app = App::factory()->create();
    $poi = EcPoi::create([
        'name' => ['it' => 'Poi di test'],
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => [],
    ]);

    app(EcPoiService::class)->updateDataChain($poi);

    Bus::assertChained([
        SyncModelTaxonomyWhereJob::class,
        UpdateEcPoiDemJob::class,
    ]);
});

it('does not dispatch the sync chain when geometry is unchanged and elevation/taxonomy_where are already populated', function () {
    Bus::fake();

    $app = App::factory()->create();
    $poi = EcPoi::create([
        'name' => ['it' => 'Poi di test'],
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => ['ele' => 120, 'taxonomy_where' => ['R123' => ['name' => ['it' => 'Test']]]],
    ]);
    $poi = $poi->fresh();

    app(EcPoiService::class)->updateDataChain($poi);

    Bus::assertNotDispatched(SyncModelTaxonomyWhereJob::class);
    Bus::assertNotDispatched(UpdateEcPoiDemJob::class);
});

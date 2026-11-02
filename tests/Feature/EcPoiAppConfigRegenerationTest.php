<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Services\AppIconsService;
use Wm\WmPackage\Services\StorageService;

uses(DatabaseTransactions::class);

// pois.geojson e il filtro dei tipi di POI della config dipendono dagli stessi dati: si
// rigenerano insieme (AppPoiOutputsService), dopo il commit (oc:8911).

beforeEach(function () {
    // Tutti i job finti: niente data chain, geojson o config veri. Bus::fake() non onora
    // afterCommit(), quindi si verifica il flag sul job (vedi .claude/rules/test.md).
    Bus::fake();
    // Niente scritture su S3 dai servizi chiamati in modo sincrono dagli observer.
    $this->mock(AppIconsService::class)->shouldIgnoreMissing();
    $this->mock(StorageService::class)->shouldIgnoreMissing();

    $this->poiApp = App::factory()->createQuietly();
    $this->poi = EcPoi::factory()->createQuietly([
        'app_id' => $this->poiApp->id,
        'osmid' => null,
        'properties' => [],
    ]);
    $this->type = TaxonomyPoiType::create([
        'identifier' => 'oc8911-'.uniqid(),
        'name' => ['it' => 'Area tende'],
    ]);

    $this->assertBothQueuedAfterCommit = function (): void {
        $appId = $this->poiApp->id;
        Bus::assertDispatched(
            UpdateAppConfigJob::class,
            fn (UpdateAppConfigJob $job) => $job->appId === $appId && $job->afterCommit === true
        );
        Bus::assertDispatched(
            BuildAppPoisGeojsonJob::class,
            fn (BuildAppPoisGeojsonJob $job) => $job->uniqueId() === "build_pois_geojson_{$appId}" && $job->afterCommit === true
        );
    };
});

it('queues pois geojson and app config after commit when a poi type is attached', function () {
    $this->poi->taxonomyPoiTypes()->attach($this->type->id);

    ($this->assertBothQueuedAfterCommit)();
});

it('queues pois geojson and app config after commit when a poi type is detached', function () {
    $this->poi->taxonomyPoiTypes()->attach($this->type->id);
    // L'attach ha preso i lock ShouldBeUnique dei job: senza liberarli i dispatch del detach
    // verrebbero scartati. Su ArrayStore flush() non libera i lock (.claude/rules/test.md).
    app('cache')->forgetDriver('redis');
    Bus::fake();

    $this->poi->taxonomyPoiTypes()->detach($this->type->id);

    ($this->assertBothQueuedAfterCommit)();
});

it('queues pois geojson and app config after commit when global changes', function () {
    $poi = $this->poi->fresh();
    $poi->global = ! $poi->global;
    $poi->save();

    ($this->assertBothQueuedAfterCommit)();
});

it('queues pois geojson and app config after commit when an ec poi is created', function () {
    EcPoi::factory()->create(['app_id' => $this->poiApp->id, 'osmid' => null, 'properties' => []]);

    ($this->assertBothQueuedAfterCommit)();
});

it('queues only pois geojson when a saved ec poi keeps global and app', function () {
    // Istanza riletta come in Nova: su quella creata nel test wasRecentlyCreated resta true.
    $poi = $this->poi->fresh();
    $poi->name = ['it' => 'Nuovo nome '.uniqid()];
    $poi->save();

    Bus::assertDispatched(BuildAppPoisGeojsonJob::class);
    Bus::assertNotDispatched(UpdateAppConfigJob::class);
});

it('queues pois geojson and app config after commit when an ec poi is deleted', function () {
    $this->poi->delete();

    ($this->assertBothQueuedAfterCommit)();
});

// Durante un import Geohub la config si scrive solo a layer batch integro (oc:8488):
// il collegamento dei tipi fatto dall'import non deve aggirare quel controllo.
it('does not queue app config update while the app layer import is pending', function () {
    Cache::put("wm-package:import-layer-batch:{$this->poiApp->id}", 'pending');

    $this->poi->taxonomyPoiTypes()->attach($this->type->id);
    $this->poi->global = ! $this->poi->global;
    $this->poi->save();

    Bus::assertNotDispatched(UpdateAppConfigJob::class);
    Bus::assertDispatched(BuildAppPoisGeojsonJob::class);
});

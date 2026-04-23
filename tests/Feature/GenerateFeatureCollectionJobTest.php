<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Models\Layer;

uses(TestCase::class, DatabaseTransactions::class);

it('dispatches GenerateFeatureCollectionJob when layer is deleted', function () {
    Queue::fake();

    $app = App::factory()->createQuietly(['map_bbox' => '[10.39637,43.71683,10.52729,43.84512]']);
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);
    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'mode' => 'generated',
        'enabled' => true,
    ]);
    $fc->layers()->attach($layer->id);

    $layer->delete();

    Queue::assertPushed(GenerateFeatureCollectionJob::class, function ($job) use ($fc) {
        return $job->featureCollectionId === $fc->id;
    });
});

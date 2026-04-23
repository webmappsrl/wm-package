<?php

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Models\Layer;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(Tests\TestCase::class, DatabaseTransactions::class);

it('belongs to an app', function () {
    $app = App::factory()->createQuietly();
    $fc = FeatureCollection::factory()->createQuietly(['app_id' => $app->id]);

    expect($fc->app)->toBeInstanceOf(App::class);
    expect($fc->app->id)->toBe($app->id);
});

it('can have many layers', function () {
    $app = App::factory()->createQuietly();
    $fc = FeatureCollection::factory()->createQuietly(['app_id' => $app->id, 'mode' => 'generated']);
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);

    $fc->layers()->attach($layer->id);

    expect($fc->layers)->toHaveCount(1);
    expect($fc->layers->first()->id)->toBe($layer->id);
});

it('enforces only one default per app', function () {
    $app = App::factory()->createQuietly();
    $fc1 = FeatureCollection::factory()->createQuietly(['app_id' => $app->id, 'default' => true]);
    $fc2 = FeatureCollection::factory()->createQuietly(['app_id' => $app->id, 'default' => false]);

    $fc2->default = true;
    $fc2->save();

    expect($fc1->fresh()->default)->toBeFalse();
    expect($fc2->fresh()->default)->toBeTrue();
});

<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;

// wm-package/tests/Feature is outside the `tests/Feature` path bound by the consumer's
// tests/Pest.php (`pest()->extend(Tests\TestCase::class)->in('Feature')`), so the TestCase
// must be declared explicitly here, otherwise the app is never booted (see wm-package
// .claude/rules/test.md).
uses(TestCase::class, DatabaseTransactions::class);

it('dispatches SyncModelTaxonomyWhereJob from the Regenerate Taxonomy Where inline action', function () {
    Bus::fake();

    // This consumer's config('wm-package.ec_track_model') is 'App\Models\EcTrack' (a subclass
    // of Wm\WmPackage\Models\EcTrack), while Nova\EcTrack::$model (unmodified by the consumer)
    // resolves resources to Wm\WmPackage\Models\EcTrack directly. ExecuteEcTrackDataChainAction
    // ::getTracksFromModel() does `$model instanceof $ecTrackModelClass`, which is false for a
    // parent-class instance against its own subclass — a pre-existing mismatch unrelated to this
    // task (out of scope: it lives in ExecuteEcTrackDataChainAction.php, not a file this task
    // touches). Aligning the config to the model actually used here isolates the assertion to
    // the chain wiring this task is responsible for.
    config(['wm-package.ec_track_model' => EcTrack::class]);

    $app = App::factory()->create();
    $track = EcTrack::factory()->create(['app_id' => $app->id, 'user_id' => $app->user_id]);

    $resource = new EcTrackResource($track);
    $actions = $resource->actions(app(NovaRequest::class));

    $regenerateAction = collect($actions)->first(
        fn ($action) => $action->name() === __('Regenerate Taxonomy Where')
    );

    expect($regenerateAction)->not->toBeNull();

    $regenerateAction->handle(new ActionFields(collect(), collect()), collect([$track]));

    Bus::assertChained([
        SyncModelTaxonomyWhereJob::class,
        UpdateEcTrackAwsJob::class,
    ]);
});

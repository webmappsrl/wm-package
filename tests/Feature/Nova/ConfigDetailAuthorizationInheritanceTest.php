<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();

    // EcPoiObserver::saved() dispatches EcPoiService::updateDataChain(), which
    // chains DEM/Osmfeatures jobs on the real (sync) queue — same pattern as
    // LayerEcPoiSyncObserverTest.php — so a real Nova update doesn't get its
    // `properties` column clobbered by unrelated background jobs while this
    // test is only exercising the authorization gate.
    Queue::fake();
    Http::fake();
});

it('blocks a Validator from persisting a config_detail box on EcPoi via the real Nova update endpoint, same as any other field', function () {
    App::factory()->createQuietly();

    $owner = User::factory()->create();
    $poi = EcPoi::factory()->create(['properties' => [], 'user_id' => $owner->id]);

    $validator = User::factory()->create();
    $validator->assignRole('Validator');

    $groups = [[
        'layout' => 'info',
        'key' => 'new-group-1',
        'attributes' => ['items' => [
            // FlexibleTranslatable::simple()'s real Vue component submits each locale as its
            // own flat attribute ("translations_{attr}_{locale}"), not an aggregated JSON
            // string — see LayerConfigDetailInfoBoxTest.php's infoBoxRepeaterBlock().
            ['type' => 'info-box-item', 'fields' => ['translations_title_it' => 'Non autorizzato', 'content_it' => '<p>x</p>']],
        ]],
    ]];

    // Payload must be identical to the Administrator test below (including the
    // required `app`/`user` fields) — Nova authorizes BEFORE validating the
    // request body, so an incomplete payload could 422 even if authorization
    // were broken, masking a real regression. The only difference between the
    // two tests must be the acting user's role.
    $response = $this->actingAs($validator)
        ->put("/nova-api/ec-pois/{$poi->id}", [
            'app' => $poi->app_id,
            'user' => $owner->id,
            'properties->config_detail' => $groups,
        ]);

    expect($response->status())->toBe(403);
    expect($poi->fresh()->properties['config_detail'] ?? null)->toBeNull();
});

it('allows an Administrator to persist a config_detail box on EcPoi via the real Nova update endpoint', function () {
    App::factory()->createQuietly();

    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $poi = EcPoi::factory()->create(['properties' => [], 'user_id' => $admin->id]);

    $groups = [[
        'layout' => 'info',
        'key' => 'new-group-1',
        'attributes' => ['items' => [
            ['type' => 'info-box-item', 'fields' => ['translations_title_it' => 'Autorizzato', 'content_it' => '<p>x</p>']],
        ]],
    ]];

    // Nova's PUT update endpoint validates the whole resource form, not just
    // the field being changed — the resource's App/User BelongsTo fields are
    // required (no ->nullable()), unrelated to this feature, so they must be
    // resent with their existing values alongside config_detail.
    //
    // ->put() (form-encoded), not ->putJson(): Nova's real frontend submits
    // updates as regular form data, never as a raw JSON body. Using putJson()
    // here made whitecube/nova-flexible-content's Repeater-rule validation
    // (triggered by the "info" layout's `Repeater::make('items')->rules('required','array')`)
    // clone the request into a ScopedRequest whose ParameterBag ends up shared
    // with the original (a Symfony Request shallow-clone quirk), overwriting
    // the outer request's data before the Flexible field's own fill ran — so
    // config_detail silently failed to persist even though the response was
    // still 200. This isn't a real bug in production usage (the browser never
    // sends JSON here); it was purely an artifact of the wrong test helper.
    $response = $this->actingAs($admin)
        ->put("/nova-api/ec-pois/{$poi->id}", [
            'app' => $poi->app_id,
            'user' => $admin->id,
            'properties->config_detail' => $groups,
        ]);

    $response->assertOk();
    expect($poi->fresh()->properties['config_detail'][0]['items'][0]['title'])->toBe(['it' => 'Autorizzato']);
});

it('blocks a Validator from persisting a config_detail box on an EcTrack they do not own, via the real Nova update endpoint', function () {
    // Regression test for the review-flagged blocker: EcTrack had no policy registered,
    // so Nova granted update to ANY non-Guest user regardless of ownership — a Validator
    // could inject a cross-origin iframe/img into config_detail on any EcTrack in the
    // system, not just their own. Now that Wm\WmPackage\Policies\EcTrackPolicy is
    // registered (App\Providers\AppServiceProvider), ownership is enforced.
    App::factory()->createQuietly();

    $owner = User::factory()->create();
    $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $owner->id]);

    $validator = User::factory()->create();
    $validator->assignRole('Validator');

    $groups = [[
        'layout' => 'info',
        'key' => 'new-group-1',
        'attributes' => ['items' => [
            ['type' => 'info-box-item', 'fields' => ['translations_title_it' => 'Non autorizzato', 'content_it' => '<p>x</p>']],
        ]],
    ]];

    // No 'app' key here (unlike the EcPoi tests above): App\Nova\EcTrack::fields()
    // unconditionally filters out the App BelongsTo field for every role, so it's never
    // part of updateRules() — sending it would be a dead no-op, not a required field.
    $response = $this->actingAs($validator)
        ->put("/nova-api/ec-tracks/{$track->id}", [
            'properties->config_detail' => $groups,
        ]);

    expect($response->status())->toBe(403);
    expect($track->fresh()->properties['config_detail'] ?? null)->toBeNull();
});

it('allows a Validator to persist a config_detail box on their own EcTrack, via the real Nova update endpoint', function () {
    App::factory()->createQuietly();

    $validator = User::factory()->create();
    $validator->assignRole('Validator');

    $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $validator->id]);

    $groups = [[
        'layout' => 'info',
        'key' => 'new-group-1',
        'attributes' => ['items' => [
            ['type' => 'info-box-item', 'fields' => ['translations_title_it' => 'Autorizzato', 'content_it' => '<p>x</p>']],
        ]],
    ]];

    $response = $this->actingAs($validator)
        ->put("/nova-api/ec-tracks/{$track->id}", [
            'properties->config_detail' => $groups,
        ]);

    $response->assertOk();
    expect($track->fresh()->properties['config_detail'][0]['items'][0]['title'])->toBe(['it' => 'Autorizzato']);
});

<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\MultiSelect;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Print\GeneratePrintPdfJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Actions\GeneratePrintPdfAction;
use Wm\WmPackage\Nova\EcPoi as EcPoiResource;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    config(['wm-package.ec_track_model' => EcTrack::class]);
});

it('offre le lingue dell\'app e di default solo l\'italiano', function () {
    $app = App::factory()->createQuietly(['available_languages' => json_encode(['it', 'en'])]);
    $track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);

    $action = new GeneratePrintPdfAction;
    $options = $action->languageOptions(collect([$track]));

    expect(array_keys($options))->toBe(['en', 'it']);

    $field = $action->fields(NovaRequest::create('/'))[0];
    $field->resolveForAction(NovaRequest::create('/'));
    expect($field)->toBeInstanceOf(MultiSelect::class)
        ->and($field->value)->toBe(['it']);
});

it('con più app offre l\'elenco di configurazione', function () {
    $a = App::factory()->createQuietly(['available_languages' => json_encode(['it'])]);
    $b = App::factory()->createQuietly(['available_languages' => json_encode(['en'])]);
    $tracks = collect([
        EcTrack::factory()->createQuietly(['app_id' => $a->id, 'osmid' => null]),
        EcTrack::factory()->createQuietly(['app_id' => $b->id, 'osmid' => null]),
    ]);

    $options = (new GeneratePrintPdfAction)->languageOptions($tracks);

    expect(array_keys($options))->toBe(array_keys(config('wm-app-languages.languages')));
});

it('su 3 elementi con it ed en accoda 6 job', function () {
    Queue::fake();
    $app = App::factory()->createQuietly();
    $models = collect([
        EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]),
        EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]),
        EcPoi::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null, 'properties' => []]),
    ]);

    $result = (new GeneratePrintPdfAction)->handle(new ActionFields(collect(['languages' => ['it', 'en']]), collect()), $models);

    Queue::assertPushed(GeneratePrintPdfJob::class, 6);
    expect($result)->toBeInstanceOf(ActionResponse::class);
});

it('l\'action è assente a dominio spento e presente a dominio acceso', function () {
    $request = NovaRequest::create('/');
    $find = fn (string $resource, $model) => collect((new $resource($model))->actions($request))
        ->contains(fn ($a) => $a instanceof GeneratePrintPdfAction);

    config(['wm-package.features.print.enabled' => false]);
    expect($find(EcTrackResource::class, new EcTrack))->toBeFalse()
        ->and($find(EcPoiResource::class, new EcPoi))->toBeFalse();

    config(['wm-package.features.print.enabled' => true]);
    expect($find(EcTrackResource::class, new EcTrack))->toBeTrue()
        ->and($find(EcPoiResource::class, new EcPoi))->toBeTrue();
});

it('ignora le lingue non ammesse per l\'app del modello', function () {
    Queue::fake();
    $app = App::factory()->createQuietly(['available_languages' => json_encode(['it', 'en'])]);
    $track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
    $action = new GeneratePrintPdfAction;

    $action->handle(new ActionFields(collect(['languages' => ['xx']]), collect()), collect([$track]));
    Queue::assertNothingPushed();

    // `fr` è in config ma non tra le lingue dell'app.
    $action->handle(new ActionFields(collect(['languages' => ['fr', 'en']]), collect()), collect([$track]));
    Queue::assertPushed(GeneratePrintPdfJob::class, 1);
});

it('il default è la prima opzione se manca l\'italiano e l\'intersezione vuota ricade sulla config', function () {
    $app = App::factory()->createQuietly(['available_languages' => json_encode(['en', 'fr'])]);
    $track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
    $action = new GeneratePrintPdfAction;

    $request = NovaRequest::create('/', 'GET', ['resources' => (string) $track->id]);
    $request->setRouteResolver(fn () => null);
    $field = $action->fields($request)[0];
    $field->resolveForAction($request);
    expect($field->value)->toBeArray()->toHaveCount(1);

    $empty = App::factory()->createQuietly(['available_languages' => json_encode(['xx'])]);
    $t2 = EcTrack::factory()->createQuietly(['app_id' => $empty->id, 'osmid' => null]);
    expect(array_keys($action->languageOptions(collect([$t2]))))->toBe(array_keys(config('wm-app-languages.languages')));
});

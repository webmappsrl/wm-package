<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\ActionRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Actions\GeneratePrintPdfAction;
use Wm\WmPackage\Nova\EcPoi as EcPoiResource;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;

uses(DatabaseTransactions::class);

/*
 * L'action scarica: con un elemento apre la pagina della scheda, con più elementi quella dello ZIP.
 * Le due pagine generano ciò che manca e poi portano al file; l'action non accoda nulla da sé.
 */
beforeEach(function () {
    config(['wm-package.ec_track_model' => EcTrack::class, 'wm-package.ec_poi_model' => EcPoi::class]);
    Queue::fake();
});

/** L'URL che l'action apre in una nuova scheda, o null. */
function printActionTab(mixed $result): ?string
{
    $redirect = $result instanceof ActionResponse ? ($result->jsonSerialize()['redirect'] ?? null) : null;

    return $redirect !== null && $redirect->openInNewTab ? $redirect->url : null;
}

function printActionRun(array $models, string $language = 'it'): mixed
{
    return (new GeneratePrintPdfAction)->handle(new ActionFields(collect(['language' => $language]), collect()), collect($models));
}

it('apre la scheda PDF di un solo elemento, nella lingua scelta', function () {
    $app = printApp(['available_languages' => json_encode(['it', 'en'])]);
    $track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
    $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null, 'properties' => []]);

    expect(printActionTab(printActionRun([$track], 'en')))->toBe(route('print.show', ['type' => 'ec-track', 'id' => $track->id, 'lang' => 'en']))
        ->and(printActionTab(printActionRun([$poi])))->toBe(route('print.show', ['type' => 'ec-poi', 'id' => $poi->id, 'lang' => 'it']));
    Queue::assertNothingPushed();
});

it('apre lo ZIP di più tracce o di più POI', function () {
    $app = printApp();
    $tracks = EcTrack::factory()->count(2)->createQuietly(['app_id' => $app->id, 'osmid' => null]);
    $pois = EcPoi::factory()->count(2)->createQuietly(['app_id' => $app->id, 'osmid' => null, 'properties' => []]);

    $trackIds = $tracks->pluck('id')->sort()->values()->all();
    $poiIds = $pois->pluck('id')->sort()->values()->all();

    expect(printActionTab(printActionRun($tracks->reverse()->all())))->toBe(route('print.zip', ['ids' => $trackIds, 'lang' => 'it', 'type' => 'ec-track']))
        ->and(printActionTab(printActionRun($pois->all())))->toBe(route('print.zip', ['ids' => $poiIds, 'lang' => 'it', 'type' => 'ec-poi']));
});

it('si ferma con un messaggio oltre il massimo dello ZIP', function () {
    config(['wm-package.print.zip.max_tracks' => 2]);
    $app = printApp();
    $tracks = EcTrack::factory()->count(3)->createQuietly(['app_id' => $app->id, 'osmid' => null]);

    $result = printActionRun($tracks->all());

    expect(printActionTab($result))->toBeNull()
        ->and((string) ($result->jsonSerialize()['danger'] ?? ''))->toContain('2');
});

it('scarta gli elementi di un\'App spenta o senza la lingua, e si ferma se non ne resta nessuno', function () {
    $on = printApp(['available_languages' => json_encode(['it', 'en'])]);
    $off = App::factory()->createQuietly();
    $printable = EcTrack::factory()->createQuietly(['app_id' => $on->id, 'osmid' => null]);
    $hidden = EcTrack::factory()->createQuietly(['app_id' => $off->id, 'osmid' => null]);

    // Uno solo resta stampabile: si apre la sua scheda, non uno ZIP.
    expect(printActionTab(printActionRun([$printable, $hidden])))->toBe(route('print.show', ['type' => 'ec-track', 'id' => $printable->id, 'lang' => 'it']));

    // `fr` è in config ma non fra le lingue dell'App.
    $result = printActionRun([$printable], 'fr');
    expect(printActionTab($result))->toBeNull()->and($result->jsonSerialize())->toHaveKey('danger');
});

it('offre una sola lingua fra quelle dell\'app, con la lingua di default dell\'app proposta', function () {
    $app = printApp(['available_languages' => json_encode(['it', 'en']), 'default_language' => 'en']);
    $track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
    $action = new GeneratePrintPdfAction;

    expect(array_keys($action->languageOptions(collect([$track]))))->toBe(['en', 'it']);

    $request = NovaRequest::create('/', 'GET', ['resources' => (string) $track->id]);
    $request->setRouteResolver(fn () => null);
    $field = $action->fields($request)[0];
    $field->resolveForAction($request);
    expect($field)->toBeInstanceOf(Select::class);
});

it('con più app offre l\'elenco di configurazione, e così con un\'intersezione vuota', function () {
    $a = printApp(['available_languages' => json_encode(['it'])]);
    $b = printApp(['available_languages' => json_encode(['en'])]);
    $empty = printApp(['available_languages' => json_encode(['xx'])]);
    $all = array_keys(config('wm-app-languages.languages'));
    $action = new GeneratePrintPdfAction;

    expect(array_keys($action->languageOptions(collect([
        EcTrack::factory()->createQuietly(['app_id' => $a->id, 'osmid' => null]),
        EcTrack::factory()->createQuietly(['app_id' => $b->id, 'osmid' => null]),
    ]))))->toBe($all)
        ->and(array_keys($action->languageOptions(collect([EcTrack::factory()->createQuietly(['app_id' => $empty->id, 'osmid' => null])]))))->toBe($all);
});

it('valida la lingua come la manda Nova: obbligatoria e fra le opzioni', function () {
    $action = new GeneratePrintPdfAction;
    $validate = fn (array $payload) => $action->validateFields(ActionRequest::create('/', 'POST', $payload));

    expect($validate(['language' => 'it']))->toHaveKey('language');
    foreach ([[], ['language' => ''], ['language' => 'xx']] as $payload) {
        expect(fn () => $validate($payload))->toThrow(ValidationException::class);
    }
});

it('l\'action c\'è sempre su EcTrack ed EcPoi: chi può eseguirla lo decide canRun()', function () {
    $request = NovaRequest::create('/');
    $find = fn (string $resource, $model) => collect((new $resource($model))->actions($request))
        ->contains(fn ($a) => $a instanceof GeneratePrintPdfAction);

    expect($find(EcTrackResource::class, new EcTrack))->toBeTrue()
        ->and($find(EcPoiResource::class, new EcPoi))->toBeTrue();
});

/*
 * canRun() va preso dall'istanza della Resource: un'action istanziata a mano risulta sempre
 * autorizzata (regola Nova del package).
 */
it('runs only on elements of an app with print turned on', function () {
    $printable = EcTrack::factory()->createQuietly(['app_id' => printApp()->id, 'osmid' => null]);
    $hidden = EcTrack::factory()->createQuietly(['app_id' => App::factory()->createQuietly()->id, 'osmid' => null]);
    $request = NovaRequest::create('/');
    $action = collect((new EcTrackResource($printable))->actions($request))
        ->first(fn ($a) => $a instanceof GeneratePrintPdfAction);

    expect($action->authorizedToRun($request, $printable))->toBeTrue()
        ->and($action->authorizedToRun($request, $hidden))->toBeFalse();
});

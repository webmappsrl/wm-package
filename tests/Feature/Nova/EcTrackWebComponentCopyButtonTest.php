<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\ResourceDetailRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;

uses(DatabaseTransactions::class);

/*
 * Bottone «Copia codice web component» sulla detail view della EcTrack (oc:8944): una option per
 * ogni layer della traccia la cui App ha il flag acceso, ordinate per id; lo snippet di ogni
 * option porta `track-id`.
 */

beforeEach(function () {
    // Salvare EcTrack/Layer fa partire observer e job con chiamate HTTP reali.
    Queue::fake();
    Http::fake();
    config(['wm-package.shard_name' => 'camminiditalia']);
});

function webComponentApp(bool $enabled): App
{
    return App::factory()->createQuietly([
        'properties' => ['layer_web_component_enabled' => $enabled],
    ]);
}

function webComponentLayer(App $app, string $name = 'Cammino'): Layer
{
    return Layer::factory()->createQuietly(['app_id' => $app->id, 'name' => ['it' => $name]]);
}

function webComponentTrack(App $app, array $layers): EcTrack
{
    $track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
    foreach ($layers as $layer) {
        $track->layers()->attach($layer->id);
    }

    return $track->fresh();
}

function webComponentLayers(EcTrack $track): Collection
{
    $resource = new EcTrackResource($track);
    $method = new ReflectionMethod($resource, 'webComponentLayers');

    return $method->invoke($resource, NovaRequest::create('/'));
}

function webComponentField(EcTrack $track, NovaRequest $request): ?Text
{
    return collect((new EcTrackResource($track))->fields($request))
        ->first(fn ($field) => $field instanceof Text && $field->name === __('Web Component'));
}

function webComponentFieldHtml(EcTrack $track): ?string
{
    $request = ResourceDetailRequest::create('/');
    $field = collect((new EcTrackResource($track))->fields($request))
        ->first(fn ($field) => $field instanceof Text && $field->name === __('Web Component'));

    if ($field === null || ! $field->authorizedToSee($request)) {
        return null;
    }

    $field->resolveForDisplay($track);

    return $field->value;
}

it('offers only layers whose app has the flag on, ordered by id', function () {
    $enabledApp = webComponentApp(true);
    $disabledApp = webComponentApp(false);
    $second = webComponentLayer($enabledApp);
    $first = webComponentLayer($enabledApp);
    $disabled = webComponentLayer($disabledApp);
    $track = webComponentTrack($enabledApp, [$disabled, $first, $second]);

    $expected = collect([$first->id, $second->id])->sort()->values()->all();

    expect(webComponentLayers($track)->pluck('id')->all())->toBe($expected);
});

it('hides the field for a track without layers', function () {
    $app = webComponentApp(true);
    $track = webComponentTrack($app, []);

    expect(webComponentLayers($track))->toBeEmpty()
        ->and(webComponentFieldHtml($track))->toBeNull();
});

it('renders a snippet with layer-id and track-id for each option, the lowest id selected', function () {
    $app = webComponentApp(true);
    $a = webComponentLayer($app);
    $b = webComponentLayer($app);
    $track = webComponentTrack($app, [$b, $a]);
    [$low, $high] = $a->id < $b->id ? [$a, $b] : [$b, $a];

    $html = webComponentFieldHtml($track);

    expect($html)
        ->toContain('<select')
        ->toMatch('/<option data-snippet="[^"]*layer-id=&quot;'.$low->id.'&quot;\n  track-id=&quot;'.$track->id.'&quot;[^"]*" selected>/')
        ->toMatch('/<option data-snippet="[^"]*layer-id=&quot;'.$high->id.'&quot;\n  track-id=&quot;'.$track->id.'&quot;[^"]*">/');
});

it('renders no select when only one layer is offered', function () {
    $app = webComponentApp(true);
    $layer = webComponentLayer($app);
    $track = webComponentTrack($app, [$layer]);

    expect(webComponentFieldHtml($track))
        ->not->toContain('<select')
        ->toContain('layer-id=&quot;'.$layer->id.'&quot;')
        ->toContain('track-id=&quot;'.$track->id.'&quot;');
});

it('escapes the layer name in the option text', function () {
    $app = webComponentApp(true);
    $risky = webComponentLayer($app, "Cammino dell'Alta Via <b>");
    $other = webComponentLayer($app, 'Altro');
    $track = webComponentTrack($app, [$risky, $other]);

    expect(webComponentFieldHtml($track))
        ->toContain('Cammino dell&#039;Alta Via &lt;b&gt; (#'.$risky->id.')')
        ->not->toContain('<b>');
});

it('does not query the layers outside the detail view', function () {
    $app = webComponentApp(true);
    $track = webComponentTrack($app, [webComponentLayer($app)]);

    // Su edit e update Nova risolve e autorizza i Field prima di scartare quelli onlyOnDetail.
    $request = NovaRequest::create('/');
    expect(webComponentField($track, $request)->authorizedToSee($request))->toBeFalse();
});

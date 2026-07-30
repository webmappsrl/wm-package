<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Tests\TestCase;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\EcPoi as EcPoiResource;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    // Saving EcTrack/EcPoi triggers observer chains (EcPoiService::updateDataChain(),
    // DEM/Osmfeatures jobs) that make real HTTP calls / dispatch jobs on the real
    // (sync) queue — fake both so these tests only exercise config_detail persistence.
    Queue::fake();
    Http::fake();
});

function findConfigDetailField(object $resource, NovaRequest $request): Flexible
{
    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof Flexible && $f->attribute === 'properties->config_detail');
        if ($found) {
            return $found;
        }
    }

    throw new RuntimeException('config_detail field not found');
}

function fillOneInfoGroup(Flexible $field, string $uriKey, $model): void
{
    $groups = [[
        'layout' => 'info',
        'key' => 'new-group-1',
        'attributes' => ['items' => [
            // KeyValue::fillAttributeFromRequest() expects the front-end's
            // JSON.stringify-serialized value, not a raw PHP array (see
            // Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable's
            // translatable `title` field, and LayerConfigDetailInfoBoxTest.php
            // where the same correction was already established).
            ['type' => 'info-box-item', 'fields' => ['title' => json_encode(['it' => 'Nota']), 'content_it' => '<p>Testo</p>']],
        ]],
    ]];

    $symfonyRequest = HttpRequest::create("/nova-api/{$uriKey}/{$model->id}", 'PUT', ['properties->config_detail' => $groups]);
    $request = NovaRequest::createFrom($symfonyRequest, new NovaRequest);

    $callback = $field->fill($request, $model);
    if (is_callable($callback)) {
        $callback();
    }
    $model->save();
}

it('stores an info group on EcTrack', function () {
    App::factory()->createQuietly();
    $track = EcTrack::factory()->createQuietly(['properties' => []]);

    $field = findConfigDetailField(new EcTrackResource($track), NovaRequest::create('/'));
    fillOneInfoGroup($field, 'ec-tracks', $track);

    expect($track->fresh()->properties['config_detail'][0]['items'][0]['title'])->toBe(['it' => 'Nota']);
});

it('stores an info group on EcPoi', function () {
    App::factory()->createQuietly();
    $poi = EcPoi::factory()->create(['properties' => []]);

    $field = findConfigDetailField(new EcPoiResource($poi), NovaRequest::create('/'));
    fillOneInfoGroup($field, 'ec-pois', $poi);

    expect($poi->fresh()->properties['config_detail'][0]['items'][0]['title'])->toBe(['it' => 'Nota']);
});

it('sanitizes malicious content through the full Flexible/Repeater/resolver/save chain on EcPoi', function () {
    // InfoBoxItemRepeatableTest.php already proves sanitization at the isolated
    // field level (Trix::fillUsing() alone). This proves it still holds when
    // driven through the real chain: Flexible field fill -> Repeater ->
    // ConfigDetailResolver::set() -> $model->save() -> persisted JSON.
    App::factory()->createQuietly();
    $poi = EcPoi::factory()->create(['properties' => []]);

    $field = findConfigDetailField(new EcPoiResource($poi), NovaRequest::create('/'));

    $groups = [[
        'layout' => 'info',
        'key' => 'new-group-1',
        'attributes' => ['items' => [
            [
                'type' => 'info-box-item',
                'fields' => [
                    'title' => json_encode(['it' => 'Nota']),
                    'content_it' => '<p onclick="alert(1)">Testo <script>alert(2)</script><b>sicuro</b></p>',
                ],
            ],
        ]],
    ]];

    $symfonyRequest = HttpRequest::create("/nova-api/ec-pois/{$poi->id}", 'PUT', ['properties->config_detail' => $groups]);
    $request = NovaRequest::createFrom($symfonyRequest, new NovaRequest);

    $callback = $field->fill($request, $poi);
    if (is_callable($callback)) {
        $callback();
    }
    $poi->save();

    $content = $poi->fresh()->properties['config_detail'][0]['items'][0]['content']['it'];

    expect($content)->toContain('<b>sicuro</b>');
    expect($content)->not->toContain('<script>');
    expect($content)->not->toContain('onclick');
});

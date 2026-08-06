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
            // FlexibleTranslatable::simple()'s real Vue component (kongulov/nova-tab-translatable's
            // FormField.vue) submits each locale as its own flat attribute ("translations_{attr}_{locale}"),
            // not an aggregated JSON string — see LayerConfigDetailInfoBoxTest.php's infoBoxRepeaterBlock().
            ['type' => 'info-box-item', 'fields' => ['translations_title_it' => 'Nota', 'content_it' => '<p>Testo</p>']],
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
                    'translations_title_it' => 'Nota',
                    'content_it' => '<p onclick="alert(1)">Testo <script>alert(2)</script><b>sicuro</b></p><iframe width="560" height="315" src="https://www.youtube.com/embed/RBNY26gkdzM" title="YouTube video player" frameborder="0" allowfullscreen></iframe>',
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
    expect($content)->toContain('<iframe');
    expect($content)->toContain('https://www.youtube.com/embed/');
});

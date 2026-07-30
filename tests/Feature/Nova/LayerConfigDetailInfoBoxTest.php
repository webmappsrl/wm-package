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
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Layer as LayerResource;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    // LayerObserver::saved() dispatches UpdateAppConfigJob when properties change
    // (real disk write) with QUEUE_CONNECTION=sync — fake the queue/HTTP so a
    // real Nova/model save in these tests doesn't trigger unrelated side effects.
    Queue::fake();
    Http::fake();
});

function layerConfigDetailField(Layer $model): Flexible
{
    $request = NovaRequest::create('/');
    $resource = new LayerResource($model);

    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof Flexible && $f->attribute === 'properties->config_detail');
        if ($found) {
            return $found;
        }
    }

    throw new RuntimeException('config_detail field not found on Layer resource');
}

function infoBoxRepeaterBlock(string $titleIt, string $contentIt): array
{
    return [
        'type' => 'info-box-item',
        // KeyValue::fillAttributeFromRequest() expects the front-end's
        // JSON.stringify-serialized value, not a raw PHP array — see
        // Laravel\Nova\Fields\KeyValue::fillAttributeFromRequest().
        'fields' => ['title' => json_encode(['it' => $titleIt]), 'content_it' => "<p>{$contentIt}</p>"],
    ];
}

it('stores two info groups with 9 and 5 items each, matching the mockup', function () {
    App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['properties' => []]);

    $generalTitles = ['Storia', 'Acqua', 'Servizi', 'Segnaletica', 'Logistica e trasporti', 'In tenda', 'Credenziale e testimonium', 'Fondo stradale', 'Contatti'];
    $stageTitles = ['Tappa 01: Iglesias – Nebida', 'Tappa 02: Nebida – Masua', 'Tappa 03: Masua – Buggerru', 'Tappa 04: Buggerru – Portixeddu', 'Tappa 05: Portixeddu – Piscinas'];

    $groups = [
        [
            'layout' => 'info',
            'key' => 'new-group-1',
            'attributes' => ['items' => array_map(fn ($t) => infoBoxRepeaterBlock($t, "Contenuto {$t}"), $generalTitles)],
        ],
        [
            'layout' => 'info',
            'key' => 'new-group-2',
            'attributes' => ['items' => array_map(fn ($t) => infoBoxRepeaterBlock($t, "Contenuto {$t}"), $stageTitles)],
        ],
    ];

    $field = layerConfigDetailField($layer);
    $symfonyRequest = HttpRequest::create("/nova-api/layers/{$layer->id}", 'PUT', ['properties->config_detail' => $groups]);
    $request = NovaRequest::createFrom($symfonyRequest, new NovaRequest);

    $callback = $field->fill($request, $layer);
    if (is_callable($callback)) {
        $callback();
    }
    $layer->save();

    $stored = $layer->fresh()->properties['config_detail'];

    expect($stored)->toHaveCount(2);
    expect($stored[0]['box_type'])->toBe('info');
    expect($stored[0]['items'])->toHaveCount(9);
    expect($stored[1]['items'])->toHaveCount(5);
    expect($stored[0]['items'][0]['title'])->toBe(['it' => 'Storia']);
    expect($stored[0]['items'][0]['content'])->toBe(['it' => '<p>Contenuto Storia</p>']);
    expect($stored[1]['items'][4]['title'])->toBe(['it' => 'Tappa 05: Portixeddu – Piscinas']);
    // Nova's transient {type, fields} block shape must not leak into storage.
    expect($stored[0]['items'][0])->not->toHaveKey('type');
    expect($stored[0]['items'][0])->not->toHaveKey('fields');
});

it('resolves stored info groups back through the Repeater without a custom preset', function () {
    App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly([
        'properties' => [
            'config_detail' => [
                ['box_type' => 'info', 'items' => [['title' => ['it' => 'Storia'], 'content' => ['it' => '<p>Testo storia</p>']]]],
            ],
        ],
    ]);

    $field = layerConfigDetailField($layer);
    $field->resolve($layer);

    $group = collect($field->value)->first();
    // Layout::getResolvedValue() serializes fields via FieldCollection::jsonSerialize()
    // (mirrors the array-access pattern already used in ConfigDetailResolverTest.php),
    // so resolved attributes are plain arrays (Field::jsonSerialize() output), not live
    // Field/Repeater instances — match on the serialized 'component' key instead of
    // `instanceof Repeater`.
    $itemsField = collect($group['attributes'])->first(fn ($f) => is_array($f) && ($f['component'] ?? null) === 'repeater-field');

    expect($itemsField)->not->toBeNull();

    // Strengthen beyond "some repeater field exists": inspect the resolved row
    // itself to prove hydrateInfoAttributes() actually reshaped the stored
    // {title, content} item back into the Repeater's {title, content_it,...}
    // field values, not just that SOME repeater-field was found.
    // Repeater's resolved 'value' is a RepeatableCollection of Repeatable
    // instances whose internal $data is private (no getter) — jsonSerialize()
    // resolves each configured field against that data, exposing the real
    // per-field resolved value under Field::$value.
    $repeatable = collect($itemsField['value'])->first();
    $resolvedFields = $repeatable->jsonSerialize()['fields'];

    $titleField = $resolvedFields->first(fn ($f) => $f->attribute === 'title');
    $contentItField = $resolvedFields->first(fn ($f) => $f->attribute === 'content_it');

    $locales = config('wm-tab-translatable.locales', []);
    $expectedTitle = array_merge(array_fill_keys($locales, ''), ['it' => 'Storia']);

    expect($titleField->value)->toBe($expectedTitle);
    expect($contentItField->value)->toBe('<p>Testo storia</p>');
});

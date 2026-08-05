<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Tests\TestCase;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Nova\App as AppResource;

uses(TestCase::class, DatabaseTransactions::class);

/**
 * Regression coverage for App::overlays_title_layout() — found missing in review: it's
 * one of only 4 real FlexibleTranslatable consumption points, but was the only one
 * without a dedicated test exercising the ACTUAL Nova resource (as opposed to a
 * standalone Layout built directly in FlexibleTranslatableTest.php). Layout-direct,
 * same fillInto()/resolve() code path already covered for config_home_title_layout()
 * (AppConfigHomeHorizontalScrollTest.php's group-level title) — this closes the gap for
 * the second, previously-untested Layout-direct consumer.
 */
function appConfigOverlaysField(App $model): Flexible
{
    $request = NovaRequest::create('/');
    $resource = new AppResource($model);

    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof Flexible && $f->attribute === 'config_overlays');
        if ($found) {
            return $found;
        }
    }

    throw new RuntimeException('config_overlays field not found on App resource');
}

it('stores the overlays Titolo layout label translated, in the shape ConfigOverlaysResolver expects', function () {
    $app = App::factory()->createQuietly(['config_overlays' => null]);

    $groups = [
        [
            'layout' => 'title',
            'key' => 'new-group-1',
            // Same real wire format as config_home_title_layout() (see
            // AppConfigHomeHorizontalScrollTest.php): each locale submitted under its
            // own flat "translations_label_{locale}" key, not an aggregated map.
            'attributes' => [
                'translations_label_it' => 'Percorsi disponibili',
            ],
        ],
    ];

    $field = appConfigOverlaysField($app);
    $symfonyRequest = HttpRequest::create("/nova-api/apps/{$app->id}", 'PUT', ['config_overlays' => $groups]);
    $request = NovaRequest::createFrom($symfonyRequest, new NovaRequest);

    $callback = $field->fill($request, $app);
    if (is_callable($callback)) {
        $callback();
    }
    $app->saveQuietly();

    $stored = json_decode($app->fresh()->getRawOriginal('config_overlays'), true);

    expect($stored['OVERLAYS'])->toHaveCount(1);
    $element = $stored['OVERLAYS'][0];

    expect($element['box_type'])->toBe('title');
    expect($element['label'])->toBe(['it' => 'Percorsi disponibili']);
});

it('resolves a stored overlays Titolo label back through the Layout with the right per-locale value', function () {
    $app = App::factory()->createQuietly([
        'config_overlays' => json_encode([
            'OVERLAYS' => [
                ['box_type' => 'title', 'label' => ['it' => 'Percorsi disponibili']],
            ],
        ]),
    ]);

    $field = appConfigOverlaysField($app);
    $field->resolve($app);

    // Unlike a Repeater row's items (see AppConfigHomeHorizontalScrollTest.php), a
    // Layout-direct field's resolved 'attributes' entry is ALREADY the outer
    // FlexibleTranslatable field's own jsonSerialize() output (verified live via
    // print_r) — 'value' is exactly the merged per-locale map our resolve() override
    // (simple() branch) produces, no need to drill into 'fields'/sub-fields.
    $layout = collect($field->value)->first();
    $labelField = $layout['attributes'][0];

    expect($labelField['attribute'])->toBe('label');

    $locales = config('wm-tab-translatable.locales', []);
    $expected = array_merge(array_fill_keys($locales, ''), ['it' => 'Percorsi disponibili']);

    expect($labelField['value'])->toBe($expected);
});

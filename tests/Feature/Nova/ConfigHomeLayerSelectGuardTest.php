<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigHomeResolver;

uses(DatabaseTransactions::class);

/**
 * Invokes the private ConfigHomeResolver::buildLayerElement() directly via reflection,
 * bypassing the full Nova fill/request cycle. The 'layer' box_type layout is built from a
 * raw attributes bag (Layout::duplicateAndHydrate()) rather than through App::layer_layout()'s
 * Select field + a NovaRequest, because buildLayerElement() only ever reads
 * $layout->getAttributes() — which returns whatever was hydrated onto the Layout, independent
 * of which Nova fields are actually declared for it. This mirrors the real-world case this task
 * fixes: a 'layer' group whose in-memory attributes still carry a stale/unresolvable id (and a
 * title carried over from a previous state), regardless of how it got there client-side.
 */
function buildLayerElementForTest(App $app, array $attributes): array
{
    $layout = new Layout('Layer', 'layer', [], 'test-key-0000001', $attributes);
    $layout->setModel($app);

    $resolver = new ConfigHomeResolver;
    $method = new ReflectionMethod($resolver, 'buildLayerElement');
    $method->setAccessible(true);

    return $method->invoke($resolver, $layout);
}

it('preserves a layer id that is not among the options instead of reassigning it', function () {
    $app = App::factory()->createQuietly();
    Layer::factory()->createQuietly(['app_id' => $app->id]);

    // 133 è un id Geohub residuo: non è tra le options
    $element = buildLayerElementForTest($app, ['layer' => 133, 'title' => ['it' => 'Toscana']]);

    expect($element['layer'])->toBe(133)
        ->and($element['title'])->toBe(['it' => 'Toscana']);
});

it('still resolves the title from the layer when the id is valid', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);

    $element = buildLayerElementForTest($app, ['layer' => $layer->id]);

    expect($element['layer'])->toBe($layer->id)
        ->and($element['title'])->not->toBeEmpty();
});

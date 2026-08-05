<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Tests\TestCase;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyActivity as TaxonomyActivityModel;
use Wm\WmPackage\Nova\App as AppResource;
use Wm\WmPackage\Nova\Flexible\ConfigHome\HorizontalScrollItemRepeatable;

uses(TestCase::class, DatabaseTransactions::class);

/**
 * Finds the `config_home` Flexible field on the App Nova resource, mirroring
 * layerConfigDetailField() in LayerConfigDetailInfoBoxTest.php.
 */
function appConfigHomeField(App $model): Flexible
{
    $request = NovaRequest::create('/');
    $resource = new AppResource($model);

    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof Flexible && $f->attribute === 'config_home');
        if ($found) {
            return $found;
        }
    }

    throw new RuntimeException('config_home field not found on App resource');
}

/**
 * Field::fill() returns a callback to invoke (or nothing) rather than mutating $model
 * directly — found duplicated inline in review. Named per-file, not shared, to avoid a
 * "Cannot redeclare" fatal when Pest loads every test file's global functions into the
 * same process — FlexibleTranslatableTest.php already declares its own same-shaped
 * fillField() helper.
 */
function fillHomeField($field, $request, $model): void
{
    $callback = $field->fill($request, $model);
    if (is_callable($callback)) {
        $callback();
    }
}

/**
 * Builds the Nova Repeater's transient `{type, fields}` block for a single
 * horizontal-scroll item, as submitted by the real frontend for the `items`
 * Repeater on horizontal_scroll_activities/horizontal_scroll_poi_types layouts.
 */
function horizontalScrollItemBlock(string $res, ?string $titleIt, string $imageUrl): array
{
    $fields = [
        'res' => $res,
        'image_url' => $imageUrl,
    ];

    // FlexibleTranslatable::simple()'s actual Vue component (kongulov/nova-tab-translatable's
    // FormField.vue) submits EACH locale sub-field independently under its OWN flat attribute
    // ("translations_title_{locale}") — there is no single aggregated "title" key in the real
    // payload (see FlexibleTranslatable::fillInto()'s simple-mode branch). Only include the
    // locale(s) actually being set here, mirroring an admin who fills in just the 'it' tab and
    // leaves the others untouched (Nova still submits every configured locale in the real UI,
    // but the fill logic only needs one present key to take this path — the untouched-locale
    // case below omits the key entirely, matching how an admin leaving the title empty behaves).
    if ($titleIt !== null) {
        $fields['translations_title_it'] = $titleIt;
    }

    return [
        'type' => HorizontalScrollItemRepeatable::key(),
        'fields' => $fields,
    ];
}

/**
 * Builds the `config_home` group submitted for a horizontal_scroll_activities layout:
 * the group-level title (FlexibleTranslatable::simple(), migrated from the old KeyValue-based
 * mechanism in an earlier task of this same ticket — see App::config_home_title_layout())
 * plus the `items` Repeater carrying HorizontalScrollItemRepeatable rows.
 *
 * The group-level title is used directly on a Whitecube Layout (not inside a Repeater row),
 * so FlexibleTranslatable::fillInto() takes its "Layout-direct" branch: it defers to
 * NovaTabTranslatable's own loop over the per-locale sub-fields, each reading its own flat
 * "translations_title_{locale}" attribute off the request. Whitecube's ScopedRequest flattens
 * a group's `attributes` map so each of its top-level keys becomes a top-level request key —
 * so, exactly like the Repeater-row case, the real wire format is a flat
 * "translations_title_{locale}" key directly inside `attributes`, not a nested/aggregated
 * "title" key.
 */
function horizontalScrollActivitiesGroup(string $groupKey, string $groupTitleIt, array $itemBlocks): array
{
    return [
        'layout' => 'horizontal_scroll_activities',
        'key' => $groupKey,
        'attributes' => [
            'translations_title_it' => $groupTitleIt,
            'items' => $itemBlocks,
        ],
    ];
}

it('stores a horizontal scroll activities item with a translated title in the exact shape ConfigHomeResolver expects', function () {
    $app = App::factory()->createQuietly(['config_home' => null]);

    // Same taxonomy label for both locales: ConfigHomeResolver::resolveTaxonomyItem()
    // reads $activity->name (Spatie HasTranslations, current-locale string), not
    // getTranslations() — using an identical label for 'it'/'en' keeps the expected
    // merged title deterministic regardless of the app's current locale during the
    // test run, instead of coupling this test to that unrelated resolver behavior.
    TaxonomyActivityModel::create([
        'identifier' => 'hiking',
        'name' => ['it' => 'Escursionismo', 'en' => 'Escursionismo'],
    ]);

    $groups = [
        horizontalScrollActivitiesGroup('new-group-1', 'Attività', [
            horizontalScrollItemBlock('hiking', 'Escursionismo personalizzato', 'https://example.com/hiking.jpg'),
        ]),
    ];

    $field = appConfigHomeField($app);
    $symfonyRequest = HttpRequest::create("/nova-api/apps/{$app->id}", 'PUT', ['config_home' => $groups]);
    $request = NovaRequest::createFrom($symfonyRequest, new NovaRequest);

    fillHomeField($field, $request, $app);
    $app->saveQuietly();

    $stored = json_decode($app->fresh()->getRawOriginal('config_home'), true);

    expect($stored['HOME'])->toHaveCount(1);
    $group = $stored['HOME'][0];

    expect($group['box_type'])->toBe('horizontal_scroll');
    expect($group['item_type'])->toBe('activities');
    // Group-level title round-trips as a locale-keyed map, same FlexibleTranslatable::simple()
    // mechanism as the per-item title below (see horizontalScrollActivitiesGroup() docblock) —
    // only the locale actually filled ('it') is present, no empty entries for the other
    // configured locales (ConfigHomeResolver::finalizeHorizontalScrollElement() filters those).
    expect($group['title'])->toBe(['it' => 'Attività']);
    expect($group['items'])->toHaveCount(1);

    $item = $group['items'][0];

    // The title round-trips as a locale-keyed map: the custom value overrides the
    // taxonomy default for 'it' (the locale we actually filled), while 'en' falls
    // back to the taxonomy default — not a raw string, not corrupted/double-encoded.
    expect($item['title'])->toBe(['it' => 'Escursionismo personalizzato', 'en' => 'Escursionismo']);
    expect($item['res'])->toBe('hiking');
    expect($item['image_url'])->toBe('https://example.com/hiking.jpg');

    // Nova's transient Repeater block shape ({type, fields}) must not leak into storage.
    expect($item)->not->toHaveKey('type');
    expect($item)->not->toHaveKey('fields');
});

it('falls back to the taxonomy default title when the item title is left empty', function () {
    $app = App::factory()->createQuietly(['config_home' => null]);

    TaxonomyActivityModel::create([
        'identifier' => 'climbing',
        'name' => ['it' => 'Arrampicata', 'en' => 'Arrampicata'],
    ]);

    $groups = [
        horizontalScrollActivitiesGroup('new-group-1', 'Attività', [
            horizontalScrollItemBlock('climbing', null, ''),
        ]),
    ];

    $field = appConfigHomeField($app);
    $symfonyRequest = HttpRequest::create("/nova-api/apps/{$app->id}", 'PUT', ['config_home' => $groups]);
    $request = NovaRequest::createFrom($symfonyRequest, new NovaRequest);

    fillHomeField($field, $request, $app);
    $app->saveQuietly();

    $stored = json_decode($app->fresh()->getRawOriginal('config_home'), true);
    $item = $stored['HOME'][0]['items'][0];

    expect($item['title'])->toBe(['it' => 'Arrampicata', 'en' => 'Arrampicata']);
    expect($item['res'])->toBe('climbing');
});

it('resolves stored horizontal scroll activities items back through the Repeater with the right per-locale title', function () {
    $app = App::factory()->createQuietly([
        'config_home' => json_encode([
            'HOME' => [
                [
                    'box_type' => 'horizontal_scroll',
                    'item_type' => 'activities',
                    'title' => ['it' => 'Attività'],
                    'items' => [
                        ['title' => ['it' => 'Escursionismo personalizzato'], 'res' => 'hiking', 'image_url' => 'https://example.com/hiking.jpg'],
                    ],
                ],
            ],
        ]),
    ]);

    $field = appConfigHomeField($app);
    $field->resolve($app);

    $group = collect($field->value)->first();

    // Layout::getResolvedValue() serializes fields via FieldCollection::jsonSerialize()
    // (same pattern as LayerConfigDetailInfoBoxTest.php's resolve test), so resolved
    // attributes are plain arrays (Field::jsonSerialize() output), not live
    // Field/Repeater instances — match on the serialized 'component' key instead of
    // `instanceof Repeater`.
    $itemsField = collect($group['attributes'])->first(fn ($f) => is_array($f) && ($f['component'] ?? null) === 'repeater-field');

    expect($itemsField)->not->toBeNull();

    // Strengthen beyond "some repeater field exists": inspect the resolved row itself
    // to prove ConfigHomeResolver::toRepeaterItems() + FlexibleTranslatable::resolve()
    // actually reshaped the stored {title: {it: ...}} item back into the field's
    // per-locale value, not just that SOME repeater-field was found.
    $repeatable = collect($itemsField['value'])->first();
    $resolvedFields = $repeatable->jsonSerialize()['fields'];

    $titleField = $resolvedFields->first(fn ($f) => $f->attribute === 'title');
    $resField = $resolvedFields->first(fn ($f) => $f->attribute === 'res');

    $locales = config('wm-tab-translatable.locales', []);
    $expectedTitle = array_merge(array_fill_keys($locales, ''), ['it' => 'Escursionismo personalizzato']);

    expect($titleField->value)->toBe($expectedTitle);
    expect($resField->value)->toBe('hiking');
});

<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Wm\WmPackage\Nova\App;
use Wm\WmPackage\Support\ImportedAppProperties;

uses(DatabaseTransactions::class);

it('exposes a Nova field for every key that needs one', function () {
    $attributes = novaAppFieldAttributes();

    foreach (ImportedAppProperties::novaKeys() as $key) {
        expect($attributes)->toContain("properties->{$key}");
    }
});

it('does not duplicate the group A fields that already existed', function () {
    $attributes = novaAppFieldAttributes();

    expect(array_count_values($attributes)['properties->show_travel_mode'] ?? 0)->toBe(1);
});

it('has an it and en translation for every generated label and help', function () {
    $it = json_decode(file_get_contents(__DIR__.'/../../../resources/lang/it.json'), true);
    $en = json_decode(file_get_contents(__DIR__.'/../../../resources/lang/en.json'), true);

    foreach (ImportedAppProperties::novaKeys() as $key) {
        expect($it)->toHaveKey("app.prop.{$key}")->and($it)->toHaveKey("app.prop.{$key}.help")
            ->and($en)->toHaveKey("app.prop.{$key}")->and($en)->toHaveKey("app.prop.{$key}.help");
    }
});

/**
 * TabsGroup (Nova) already flattens every Tab's fields into its own $data array at
 * construction time (see TabsGroup::addFields()) — each entry there is a leaf Field,
 * never a nested Tab. Pattern verified against the existing
 * AppConfigOverlaysTitleLayoutTest.php::appConfigOverlaysField() helper, which relies on
 * the same "collect($item->data) when $item instanceof Panel" shape (TabsGroup extends
 * Panel). No recursion needed beyond one level.
 */
function novaAppFieldAttributes(): array
{
    $resource = new App(new Wm\WmPackage\Models\App);
    $request = NovaRequest::create('/', 'GET');

    $out = [];

    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);

        foreach ($fields as $field) {
            if (property_exists($field, 'attribute')) {
                $out[] = $field->attribute;
            }
        }
    }

    return $out;
}

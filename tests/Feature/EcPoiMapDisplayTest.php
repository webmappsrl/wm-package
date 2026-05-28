<?php

declare(strict_types=1);

use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\EcPoi;

it('returns use_image_as_icon from properties when set on taxonomy', function () {
    $taxonomy = TaxonomyPoiType::factory()->create([
        'properties' => ['use_image_as_icon' => true],
    ]);

    expect($taxonomy->getUseImageAsIcon())->toBeTrue();
});

it('returns false as default when use_image_as_icon is not set on taxonomy', function () {
    $taxonomy = TaxonomyPoiType::factory()->create([
        'properties' => [],
    ]);

    expect($taxonomy->getUseImageAsIcon())->toBeFalse();
});

it('returns use_image_as_icon from properties when set on ec poi', function () {
    $poi = EcPoi::factory()->create([
        'properties' => ['use_image_as_icon' => true],
    ]);

    expect($poi->getUseImageAsIcon())->toBeTrue();
});

it('returns null when use_image_as_icon is not set on ec poi', function () {
    $poi = EcPoi::factory()->create([
        'properties' => [],
    ]);

    expect($poi->getUseImageAsIcon())->toBeNull();
});

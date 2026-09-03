<?php

declare(strict_types=1);

use Wm\WmPackage\Models\TaxonomyActivity;

it('derives the identifier from the name by default', function () {
    $activity = TaxonomyActivity::create(['name' => ['it' => 'Escursionismo']]);

    expect($activity->identifier)->toBe('escursionismo');
});

it('leaves the identifier null when the name produces an empty slug', function () {
    $activity = TaxonomyActivity::create(['name' => ['it' => '올리아스트라']]);

    expect($activity->identifier)->toBeNull();
});

it('does not skip the uniqueness check for a name with an empty slug', function () {
    TaxonomyActivity::create(['name' => ['it' => '올리아스트라']]);
    TaxonomyActivity::create(['name' => ['it' => '세컨드']]);

    expect(TaxonomyActivity::whereNull('identifier')->count())->toBe(2);
});

it('keeps an explicitly provided identifier instead of deriving one', function () {
    $activity = TaxonomyActivity::create([
        'name' => ['it' => 'Escursionismo'],
        'identifier' => 'custom-id',
    ]);

    expect($activity->identifier)->toBe('custom-id');
});

it('does not recompute the identifier when the name changes', function () {
    $activity = TaxonomyActivity::create(['name' => ['it' => 'Escursionismo']]);

    $activity->update(['name' => ['it' => 'Trekking']]);

    expect($activity->fresh()->identifier)->toBe('escursionismo');
});

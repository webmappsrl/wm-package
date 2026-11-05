<?php

use Illuminate\Database\QueryException;
use Wm\WmPackage\Models\TaxonomyTerm;

it('distingue una tassonomia da un termine', function () {
    $taxonomy = TaxonomyTerm::create(['identifier' => 'hiking-difficulty', 'name' => ['it' => 'Difficoltà escursionistica']]);
    $term = TaxonomyTerm::create(['identifier' => 'hiking-difficulty-e', 'parent_id' => $taxonomy->id, 'name' => ['it' => 'E']]);

    expect($taxonomy->isTaxonomy())->toBeTrue()
        ->and($term->isTaxonomy())->toBeFalse()
        ->and($term->parent->id)->toBe($taxonomy->id)
        ->and($taxonomy->children)->toHaveCount(1)
        ->and(TaxonomyTerm::taxonomies()->pluck('identifier')->all())->toBe(['hiking-difficulty']);
});

it('traduce name, description ed excerpt', function () {
    $term = TaxonomyTerm::create([
        'identifier' => 'accepted-disabilities',
        'name' => ['it' => 'Disabilità accolte', 'en' => 'Accepted disabilities'],
    ]);

    app()->setLocale('en');
    expect($term->refresh()->name)->toBe('Accepted disabilities');
});

it('rifiuta un termine senza identifier', function () {
    expect(fn () => TaxonomyTerm::create(['name' => ['it' => 'Senza']]))
        ->toThrow(QueryException::class);
});

it('ordina i termini per sort_order e non per id', function () {
    $taxonomy = TaxonomyTerm::create(['identifier' => 'mtb-difficulty', 'name' => ['it' => 'MTB']]);
    TaxonomyTerm::create(['identifier' => 'mtb-ec', 'parent_id' => $taxonomy->id, 'sort_order' => 4, 'name' => ['it' => 'EC']]);
    TaxonomyTerm::create(['identifier' => 'mtb-tc', 'parent_id' => $taxonomy->id, 'sort_order' => 0, 'name' => ['it' => 'TC']]);

    expect($taxonomy->children->pluck('identifier')->all())->toBe(['mtb-tc', 'mtb-ec']);
});

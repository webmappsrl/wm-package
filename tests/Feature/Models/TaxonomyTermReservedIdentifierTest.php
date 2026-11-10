<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\DetailTaxonomiesService;

/**
 * Gli identifier delle tassonomie storiche sono le chiavi di `properties.taxonomies` nel dettaglio
 * delle tracce: una tassonomia di `taxonomy_terms` con lo stesso identifier ne prenderebbe il posto
 * (oc:8959).
 */
uses(DatabaseTransactions::class);

it('rifiuta una tassonomia con un identifier riservato', function (string $identifier) {
    expect(fn () => TaxonomyTerm::create(['identifier' => $identifier, 'name' => ['it' => 'x']]))
        ->toThrow(InvalidArgumentException::class, 'riservato');
})->with(TaxonomyTerm::RESERVED_TAXONOMY_IDENTIFIERS);

it('accetta un termine figlio con un identifier uguale a un nome riservato', function () {
    $taxonomy = TaxonomyTerm::create(['identifier' => 'reserved-'.uniqid(), 'name' => ['it' => 'x']]);

    $term = TaxonomyTerm::create(['identifier' => 'activity', 'parent_id' => $taxonomy->id, 'name' => ['it' => 'x']]);

    expect($term->exists)->toBeTrue();
});

it('rifiuta di promuovere a tassonomia un termine con un identifier riservato', function () {
    $taxonomy = TaxonomyTerm::create(['identifier' => 'reserved-'.uniqid(), 'name' => ['it' => 'x']]);
    $term = TaxonomyTerm::create(['identifier' => 'where', 'parent_id' => $taxonomy->id, 'name' => ['it' => 'x']]);

    $term = $term->fresh();
    $term->parent_id = null;

    expect(fn () => $term->save())->toThrow(InvalidArgumentException::class, 'riservato');
});

it('lascia salvare una tassonomia riservata già esistente se non cambia identifier', function () {
    $taxonomy = TaxonomyTerm::create(['identifier' => 'reserved-'.uniqid(), 'name' => ['it' => 'x']]);
    $taxonomy->name = ['it' => 'y'];

    expect($taxonomy->save())->toBeTrue();
});

it('riserva esattamente le chiavi delle tassonomie storiche, compresa poi-type', function () {
    expect(TaxonomyTerm::RESERVED_TAXONOMY_IDENTIFIERS)
        ->toEqualCanonicalizing(array_keys(DetailTaxonomiesService::LEGACY_TAXONOMIES))
        ->toContain('poi-type');
});

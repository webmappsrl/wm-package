<?php

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;

it('indicizza i termini raggruppati per tassonomia con il properties della relazione', function () {
    [, $physical, $track] = seedTaxonomyTermAndTrack('accepted-disabilities', 'disability-physical');
    [, $sensory] = seedTaxonomyTermAndTrack('accepted-disabilities', 'disability-sensory', $track);
    [, $hiking] = seedTaxonomyTermAndTrack('hiking-difficulty', 'hiking-difficulty-e', $track);

    $track->taxonomyTerms()->attach([
        $physical->id,
        $sensory->id,
        $hiking->id => ['properties' => ['duration_forward' => 7200]],
    ]);

    $array = EcTrack::find($track->id)->toSearchableArray();

    expect($array['terms']['accepted-disabilities'])->toHaveCount(2)
        ->and($array['terms']['hiking-difficulty'])->toHaveCount(1)
        ->and($array['terms']['hiking-difficulty'][0]['identifier'])->toBe('hiking-difficulty-e')
        ->and($array['terms']['hiking-difficulty'][0]['name'])->toBe(['it' => 'hiking-difficulty-e'])
        ->and($array['terms']['hiking-difficulty'][0]['pivot_properties'])->toBe(['duration_forward' => 7200])
        ->and($array['terms']['accepted-disabilities'][0]['pivot_properties'])->toBe([]);
});

it('indicizza un termine spostato sotto la tassonomia nuova, non quella copiata nel pivot', function () {
    [, $term, $track] = seedTaxonomyTermAndTrack('hiking-difficulty', 'term-moved');
    $track->taxonomyTerms()->attach($term->id);

    $newFamily = TaxonomyTerm::create(['identifier' => 'route-type', 'name' => ['it' => 'route-type']]);
    // Accesa sull'app come la prima: l'indice porta solo le tassonomie accese.
    $newFamily->apps()->syncWithoutDetaching([$track->app_id]);
    $term->update(['parent_id' => $newFamily->id]);

    $array = EcTrack::find($track->id)->toSearchableArray();

    expect($array['terms'])->toHaveKey('route-type')
        ->and($array['terms'])->not->toHaveKey('hiking-difficulty')
        ->and($array['terms']['route-type'][0]['identifier'])->toBe('term-moved');
});

it('indicizza terms vuoto senza termini e salta le tassonomie attaccate direttamente', function () {
    [$taxonomy, , $track] = seedTaxonomyTermAndTrack();
    expect(EcTrack::find($track->id)->toSearchableArray()['terms'])->toBe([]);

    $track->taxonomyTerms()->attach($taxonomy->id);
    expect(EcTrack::find($track->id)->toSearchableArray()['terms'])->toBe([]);
});

<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Traccia con due termini di accepted-disabilities, uno di hiking-difficulty e uno di
 * accessibility-difficulty; nessuno di route-type.
 */
function trackWithTermsInThreeFamilies(): EcTrack
{
    [, $physical, $track] = seedTaxonomyTermAndTrack('accepted-disabilities', 'disability-physical');
    [, $sensory] = seedTaxonomyTermAndTrack('accepted-disabilities', 'disability-sensory', $track);
    [, $hiking] = seedTaxonomyTermAndTrack('hiking-difficulty', 'hiking-difficulty-e', $track);
    [, $access] = seedTaxonomyTermAndTrack('accessibility-difficulty', 'accessibility-difficulty-low', $track);

    $track->taxonomyTerms()->attach([$physical->id, $sensory->id, $hiking->id, $access->id]);

    return $track;
}

it('restituisce i termini di una tassonomia', function () {
    $track = trackWithTermsInThreeFamilies()->fresh();

    expect($track->terms('accepted-disabilities')->pluck('identifier')->all())
        ->toEqualCanonicalizing(['disability-physical', 'disability-sensory'])
        ->and($track->term('hiking-difficulty')->identifier)->toBe('hiking-difficulty-e')
        ->and($track->term('route-type'))->toBeNull()
        ->and($track->terms('route-type'))->toBeEmpty();
});

it('non fa una query per tassonomia', function () {
    $track = trackWithTermsInThreeFamilies()->fresh();
    $track->load('taxonomyTerms');

    DB::enableQueryLog();
    $track->terms('accepted-disabilities');
    $track->terms('hiking-difficulty');
    $track->term('accessibility-difficulty');
    $track->term('route-type');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBeEmpty();
});

it('carica la relazione una volta sola anche se non era caricata', function () {
    $track = trackWithTermsInThreeFamilies()->fresh();

    DB::enableQueryLog();
    $track->terms('accepted-disabilities');
    $afterFirst = count(DB::getQueryLog());
    $track->terms('hiking-difficulty');
    $track->term('accessibility-difficulty');
    $afterAll = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($afterAll)->toBe($afterFirst);
});

it('non fa query su una lista di tracce caricata insieme', function () {
    $tracks = collect(range(1, 4))->map(function ($i) {
        [, $term, $track] = seedTaxonomyTermAndTrack('hiking-difficulty', "hiking-difficulty-$i");
        [, $disability] = seedTaxonomyTermAndTrack('accepted-disabilities', "disability-$i", $track);
        $track->taxonomyTerms()->attach([$term->id, $disability->id]);

        return $track->id;
    });

    $loaded = EcTrack::with('taxonomyTerms')->whereIn('id', $tracks)->get();

    DB::enableQueryLog();
    $found = $loaded->map(fn ($t) => [
        $t->term('hiking-difficulty')?->identifier,
        $t->terms('accepted-disabilities')->count(),
        $t->term('route-type'),
    ]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($loaded)->toHaveCount(4)
        ->and($queries)->toBeEmpty()
        ->and($found->pluck(0)->filter()->unique())->toHaveCount(4)
        ->and($found->pluck(1)->unique()->all())->toBe([1]);
});

it('non restituisce una tassonomia attaccata direttamente come termine di sé stessa', function () {
    [$taxonomy, $term, $track] = seedTaxonomyTermAndTrack();
    $track->taxonomyTerms()->attach([$taxonomy->id, $term->id]);
    $track = $track->fresh();

    expect($track->terms('hiking-difficulty')->pluck('identifier')->all())->toBe(['hiking-difficulty-e']);
});

it('segue la tassonomia attuale del termine se viene spostato dopo l\'attach', function () {
    [, $term, $track] = seedTaxonomyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);
    $other = TaxonomyTerm::create(['identifier' => 'altra', 'name' => ['it' => 'Altra']]);
    $term->update(['parent_id' => $other->id]);
    $track = $track->fresh();

    expect($track->terms('hiking-difficulty'))->toBeEmpty()
        ->and($track->term('altra')->identifier)->toBe('hiking-difficulty-e');
});

it('restituisce i termini per sort_order e poi per id, non nell\'ordine di inserimento', function () {
    [$taxonomy, , $track] = seedTaxonomyTermAndTrack('hiking-difficulty', 'hiking-difficulty-ee');
    $make = fn (string $identifier, int $sortOrder) => TaxonomyTerm::create([
        'identifier' => $identifier,
        'parent_id' => $taxonomy->id,
        'name' => ['it' => $identifier],
        'sort_order' => $sortOrder,
    ]);
    // Creati e attaccati in ordine diverso dalla scala T < E < EE < EEA; due con lo stesso sort_order.
    $ee = TaxonomyTerm::where('identifier', 'hiking-difficulty-ee')->first();
    $ee->update(['sort_order' => 3]);
    $eea = $make('hiking-difficulty-eea', 4);
    $t = $make('hiking-difficulty-t', 1);
    $e1 = $make('hiking-difficulty-e1', 2);
    $e2 = $make('hiking-difficulty-e2', 2);

    $track->taxonomyTerms()->attach([$eea->id, $e2->id, $ee->id, $t->id, $e1->id]);
    $track = $track->fresh();

    expect($track->terms('hiking-difficulty')->pluck('identifier')->all())->toBe([
        'hiking-difficulty-t',
        'hiking-difficulty-e1',
        'hiking-difficulty-e2',
        'hiking-difficulty-ee',
        'hiking-difficulty-eea',
    ])->and($track->term('hiking-difficulty')->identifier)->toBe('hiking-difficulty-t');
});

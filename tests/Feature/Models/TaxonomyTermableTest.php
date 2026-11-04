<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;

it('copia la tassonomia sul legame al salvataggio', function () {
    [$taxonomy, $term, $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);

    expect(DB::table('taxonomy_termables')->where('taxonomy_term_id', $term->id)->value('parent_id'))
        ->toBe($taxonomy->id);
});

it('lascia parent_id nullo quando si associa una tassonomia direttamente', function () {
    [$taxonomy, , $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($taxonomy->id);

    expect(DB::table('taxonomy_termables')->where('taxonomy_term_id', $taxonomy->id)->value('parent_id'))
        ->toBeNull();
});

it('ignora un parent_id passato a mano sul legame', function () {
    [$taxonomy, $term, $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id, ['parent_id' => 999999]);

    expect(DB::table('taxonomy_termables')->where('taxonomy_term_id', $term->id)->value('parent_id'))
        ->toBe($taxonomy->id);
});

it('rifiuta un termine il cui padre non è una tassonomia', function () {
    [$taxonomy, $term] = seedVocabularyTermAndTrack();
    expect(fn () => TaxonomyTerm::create([
        'identifier' => 'nipote', 'parent_id' => $term->id, 'name' => ['it' => 'Nipote'],
    ]))->toThrow(InvalidArgumentException::class);
});

it('legge i modelli associati da ecTracks() ed ecPois()', function () {
    config(['wm-package.ec_track_model' => EcTrack::class]);
    [, $term, $track] = seedVocabularyTermAndTrack();
    $poi = EcPoi::factory()->createQuietly();

    DB::table('taxonomy_termables')->insert([
        ['taxonomy_term_id' => $term->id, 'termable_type' => $track->getMorphClass(), 'termable_id' => $track->id],
        ['taxonomy_term_id' => $term->id, 'termable_type' => $poi->getMorphClass(), 'termable_id' => $poi->id],
    ]);

    expect($term->ecTracks()->count())->toBe(1)
        ->and($term->ecTracks()->first()->id)->toBe($track->id)
        ->and($term->ecPois()->count())->toBe(1)
        ->and($term->ecPois()->first()->id)->toBe($poi->id);
});

it('rifiuta di assegnare un padre a una tassonomia che ha già figli', function () {
    [$taxonomy, $term] = seedVocabularyTermAndTrack();
    $other = TaxonomyTerm::create(['identifier' => 'altra', 'name' => ['it' => 'Altra']]);

    $taxonomy->parent_id = $other->id;

    expect(fn () => $taxonomy->save())->toThrow(InvalidArgumentException::class);
});

it('rifiuta un termine che è padre di sé stesso', function () {
    [, $term] = seedVocabularyTermAndTrack();
    $term->parent_id = $term->id;

    expect(fn () => $term->save())->toThrow(InvalidArgumentException::class);
});

it('accetta di spostare un termine sotto un\'altro tassonomia', function () {
    [, $term] = seedVocabularyTermAndTrack();
    $other = TaxonomyTerm::create(['identifier' => 'altra', 'name' => ['it' => 'Altra']]);

    $term->update(['parent_id' => $other->id]);

    expect($term->fresh()->parent_id)->toBe($other->id);
});

it('rilegge con ecTracks() ciò che taxonomyTerms() ha scritto, senza righe a mano', function () {
    config(['wm-package.ec_track_model' => EcTrack::class]);
    [, $term, $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);

    $found = $term->ecTracks()->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->id)->toBe($track->id)
        ->and($track->taxonomyTerms()->pluck('taxonomy_terms.id')->all())->toBe([$term->id]);
});

it('riscrive la copia della tassonomia sui legami quando il termine cambia tassonomia', function () {
    [$taxonomy, $term, $track] = seedVocabularyTermAndTrack();
    [, , $track2] = seedVocabularyTermAndTrack('hiking-difficulty', 'altro-termine');
    $track->taxonomyTerms()->attach($term->id);
    $track2->taxonomyTerms()->attach($term->id);
    $untouched = TaxonomyTerm::create(['identifier' => 'resta', 'parent_id' => $taxonomy->id, 'name' => ['it' => 'Resta']]);
    $track->taxonomyTerms()->attach($untouched->id);
    $other = TaxonomyTerm::create(['identifier' => 'altra', 'name' => ['it' => 'Altra']]);

    $term->update(['parent_id' => $other->id]);

    expect(DB::table('taxonomy_termables')->where('taxonomy_term_id', $term->id)->pluck('parent_id')->all())
        ->toBe([$other->id, $other->id])
        ->and(DB::table('taxonomy_termables')->where('taxonomy_term_id', $untouched->id)->value('parent_id'))
        ->toBe($taxonomy->id)
        ->and(DB::table('taxonomy_termables')->where('parent_id', $taxonomy->id)->count())->toBe(1);
});

it('non riscrive i legami se il termine cambia solo altri campi', function () {
    [$taxonomy, $term, $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);
    DB::table('taxonomy_termables')->where('taxonomy_term_id', $term->id)->update(['parent_id' => 424242]);

    $term->update(['sort_order' => 9]);

    expect(DB::table('taxonomy_termables')->where('taxonomy_term_id', $term->id)->value('parent_id'))->toBe(424242);
});

it('non reindicizza i contenuti quando il termine cambia tassonomia', function () {
    config(['scout.queue' => true]);
    [, $term, $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);
    $other = TaxonomyTerm::create(['identifier' => 'altra', 'name' => ['it' => 'Altra']]);
    Queue::fake();

    $term->update(['parent_id' => $other->id]);

    Queue::assertNothingPushed();
});

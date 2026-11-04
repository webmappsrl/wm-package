<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Laravel\Scout\Jobs\MakeSearchable;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;

/**
 * Engine finto che registra i modelli passati a update() (percorso sincrono, quello reale del package).
 */
function fakeScoutEngine(bool $failing = false): object
{
    $engine = new class extends NullEngine
    {
        public array $updated = [];

        public bool $failing = false;

        public function update($models)
        {
            if ($this->failing) {
                throw new RuntimeException('Elasticsearch non raggiungibile');
            }
            foreach ($models as $model) {
                $this->updated[] = $model;
            }
        }
    };
    $engine->failing = $failing;

    config(['scout.driver' => 'fake']);
    // Senza singleton app(EngineManager::class) e' un'istanza nuova a ogni risoluzione (come in ReverseTest).
    app()->singleton(EngineManager::class, fn ($app) => new EngineManager($app));
    app(EngineManager::class)->extend('fake', fn () => $engine);

    return $engine;
}

it('reindicizza il contenuto giusto quando nasce un legame (coda)', function () {
    config(['scout.queue' => true]);
    [, $term, $track] = seedVocabularyTermAndTrack();
    $other = EcTrack::factory()->createQuietly();
    Queue::fake();

    $track->taxonomyTerms()->attach($term->id);

    Queue::assertPushed(MakeSearchable::class, fn ($job) => $job->models->count() === 1 && $job->models->first()->is($track));
    Queue::assertNotPushed(MakeSearchable::class, fn ($job) => $job->models->first()->is($other));
});

it('reindicizza anche quando cambia solo il properties del legame', function () {
    config(['scout.queue' => true]);
    [, $term, $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);
    Queue::fake();

    $track->taxonomyTerms()->updateExistingPivot($term->id, ['properties' => ['duration_forward' => 7200]]);

    Queue::assertPushed(MakeSearchable::class, fn ($job) => $job->models->count() === 1 && $job->models->first()->is($track));
});

it('reindicizza quando un legame viene tolto', function () {
    config(['scout.queue' => true]);
    [, $term, $track] = seedVocabularyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);
    Queue::fake();

    $track->taxonomyTerms()->detach($term->id);

    Queue::assertPushed(MakeSearchable::class, fn ($job) => $job->models->count() === 1 && $job->models->first()->is($track));
});

it('non spinge niente per un modello non Searchable', function () {
    config(['scout.queue' => true]);
    [, $term] = seedVocabularyTermAndTrack();
    $layer = Layer::factory()->createQuietly();
    Queue::fake();

    $layer->taxonomyTerms()->attach($term->id);

    Queue::assertNothingPushed();
});

it('passa il contenuto giusto all\'engine nel percorso sincrono', function () {
    config(['scout.queue' => false]);
    [, $term, $track] = seedVocabularyTermAndTrack();
    $engine = fakeScoutEngine();

    $track->taxonomyTerms()->attach($term->id);

    expect($engine->updated)->toHaveCount(1)
        ->and($engine->updated[0]->is($track))->toBeTrue();
});

it('non interrompe attach se l\'indicizzazione fallisce, e registra l\'errore', function () {
    config(['scout.queue' => false]);
    [$taxonomy, $term, $track] = seedVocabularyTermAndTrack();
    fakeScoutEngine(failing: true);
    Log::spy();

    $track->taxonomyTerms()->attach([$term->id, $taxonomy->id]);

    expect($track->taxonomyTerms()->count())->toBe(2);
    Log::shouldHaveReceived('error')->atLeast()->once();
});

it('rispetta withoutSyncingToSearch: niente reindex mentre il sync e\' disabilitato', function () {
    config(['scout.queue' => false]);
    [, $term, $track] = seedVocabularyTermAndTrack();
    $engine = fakeScoutEngine();

    EcTrack::withoutSyncingToSearch(fn () => $track->taxonomyTerms()->attach($term->id));

    expect($engine->updated)->toBeEmpty()
        ->and($track->taxonomyTerms()->count())->toBe(1);

    // Finita la closure il sync torna attivo.
    $track->taxonomyTerms()->detach($term->id);
    $track->taxonomyTerms()->attach($term->id);

    expect($engine->updated)->not->toBeEmpty();
});

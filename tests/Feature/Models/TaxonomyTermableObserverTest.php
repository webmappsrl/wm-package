<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Laravel\Scout\Jobs\MakeSearchable;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyTerm;

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

it('reindicizza il contenuto giusto quando nasce una relazione (coda)', function () {
    config(['scout.queue' => true]);
    [, $term, $track] = seedTaxonomyTermAndTrack();
    $other = EcTrack::factory()->createQuietly();
    Queue::fake();

    $track->taxonomyTerms()->attach($term->id);

    Queue::assertPushed(MakeSearchable::class, fn ($job) => $job->models->count() === 1 && $job->models->first()->is($track));
    Queue::assertNotPushed(MakeSearchable::class, fn ($job) => $job->models->first()->is($other));
});

it('reindicizza anche quando cambia solo il properties della relazione', function () {
    config(['scout.queue' => true]);
    [, $term, $track] = seedTaxonomyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);
    Queue::fake();

    $track->taxonomyTerms()->updateExistingPivot($term->id, ['properties' => ['duration_forward' => 7200]]);

    Queue::assertPushed(MakeSearchable::class, fn ($job) => $job->models->count() === 1 && $job->models->first()->is($track));
});

it('reindicizza quando una relazione viene tolta', function () {
    config(['scout.queue' => true]);
    [, $term, $track] = seedTaxonomyTermAndTrack();
    $track->taxonomyTerms()->attach($term->id);
    Queue::fake();

    $track->taxonomyTerms()->detach($term->id);

    Queue::assertPushed(MakeSearchable::class, fn ($job) => $job->models->count() === 1 && $job->models->first()->is($track));
});

it('non spinge niente per un modello non Searchable', function () {
    config(['scout.queue' => true]);
    [, $term] = seedTaxonomyTermAndTrack();
    $layer = Layer::factory()->createQuietly();
    Queue::fake();

    $layer->taxonomyTerms()->attach($term->id);

    Queue::assertNothingPushed();
});

it('passa il contenuto giusto all\'engine nel percorso sincrono', function () {
    config(['scout.queue' => false]);
    [, $term, $track] = seedTaxonomyTermAndTrack();
    $engine = fakeScoutEngine();

    $track->taxonomyTerms()->attach($term->id);

    expect($engine->updated)->toHaveCount(1)
        ->and($engine->updated[0]->is($track))->toBeTrue();
});

it('non interrompe attach se l\'indicizzazione fallisce, e registra l\'errore', function () {
    config(['scout.queue' => false]);
    [$taxonomy, $term, $track] = seedTaxonomyTermAndTrack();
    fakeScoutEngine(failing: true);
    Log::spy();

    $track->taxonomyTerms()->attach([$term->id, $taxonomy->id]);

    expect($track->taxonomyTerms()->count())->toBe(2);
    Log::shouldHaveReceived('error')->atLeast()->once();
});

it('rispetta withoutSyncingToSearch: niente reindex mentre il sync e\' disabilitato', function () {
    config(['scout.queue' => false]);
    [, $term, $track] = seedTaxonomyTermAndTrack();
    $engine = fakeScoutEngine();

    EcTrack::withoutSyncingToSearch(fn () => $track->taxonomyTerms()->attach($term->id));

    expect($engine->updated)->toBeEmpty()
        ->and($track->taxonomyTerms()->count())->toBe(1);

    // Finita la closure il sync torna attivo.
    $track->taxonomyTerms()->detach($term->id);
    $track->taxonomyTerms()->attach($term->id);

    expect($engine->updated)->not->toBeEmpty();
});

/**
 * Il dettaglio della traccia su AWS porta `properties.taxonomies`: un legame cambiato in Nova lo
 * deve rigenerare, altrimenti la lista (Elastic) e il dettaglio divergono (oc:8959).
 */
function awsJobFor(EcTrack $track): Closure
{
    return fn (UpdateEcTrackAwsJob $job) => (fn () => $this->ecTrack)->call($job)->is($track);
}

it('accoda UpdateEcTrackAwsJob per la traccia quando nasce o sparisce un legame', function () {
    config(['scout.queue' => false]);
    [, $term, $track] = seedTaxonomyTermAndTrack();
    fakeScoutEngine();
    Bus::fake([UpdateEcTrackAwsJob::class, UpdateUgcTrackDemStatsJob::class]);

    $track->taxonomyTerms()->attach($term->id);
    Bus::assertDispatchedTimes(UpdateEcTrackAwsJob::class, 1);
    Bus::assertDispatched(UpdateEcTrackAwsJob::class, awsJobFor($track));

    $track->taxonomyTerms()->detach($term->id);
    Bus::assertDispatchedTimes(UpdateEcTrackAwsJob::class, 2);
});

it('accoda UpdateEcTrackAwsJob anche se l\'indicizzazione fallisce', function () {
    config(['scout.queue' => false]);
    [, $term, $track] = seedTaxonomyTermAndTrack();
    fakeScoutEngine(failing: true);
    Bus::fake([UpdateEcTrackAwsJob::class, UpdateUgcTrackDemStatsJob::class]);

    $track->taxonomyTerms()->attach($term->id);

    Bus::assertDispatched(UpdateEcTrackAwsJob::class, awsJobFor($track));
});

it('non accoda UpdateEcTrackAwsJob mentre il sync a Scout è disattivato', function () {
    config(['scout.queue' => false]);
    [, $term, $track] = seedTaxonomyTermAndTrack();
    fakeScoutEngine();
    Bus::fake([UpdateEcTrackAwsJob::class, UpdateUgcTrackDemStatsJob::class]);

    EcTrack::withoutSyncingToSearch(fn () => $track->taxonomyTerms()->attach($term->id));

    Bus::assertNotDispatched(UpdateEcTrackAwsJob::class);
});

it('non accoda UpdateEcTrackAwsJob per un contenuto che non è una traccia', function () {
    config(['scout.queue' => false]);
    [, $term] = seedTaxonomyTermAndTrack();
    $layer = Layer::factory()->createQuietly();
    Bus::fake([UpdateEcTrackAwsJob::class, UpdateUgcTrackDemStatsJob::class]);

    $layer->taxonomyTerms()->attach($term->id);

    Bus::assertNotDispatched(UpdateEcTrackAwsJob::class);
});

it('accoda UpdateEcTrackAwsJob dopo il commit, una volta sola per traccia', function () {
    config(['scout.queue' => false]);
    [$taxonomy, $term, $track] = seedTaxonomyTermAndTrack();
    $other = TaxonomyTerm::create(['identifier' => 'other-'.uniqid(), 'parent_id' => $taxonomy->id, 'name' => ['it' => 'x']]);
    fakeScoutEngine();
    Bus::fake([UpdateEcTrackAwsJob::class, UpdateUgcTrackDemStatsJob::class]);

    // Come un salvataggio Nova: più legami cambiati nella stessa transazione.
    DB::transaction(function () use ($track, $term, $other) {
        $track->taxonomyTerms()->attach($term->id);
        $track->taxonomyTerms()->attach($other->id);

        Bus::assertNotDispatched(UpdateEcTrackAwsJob::class);
    });

    Bus::assertDispatchedTimes(UpdateEcTrackAwsJob::class, 1);
    Bus::assertDispatched(UpdateEcTrackAwsJob::class, awsJobFor($track));
});

it('dopo un rollback la traccia si rigenera di nuovo al legame successivo', function () {
    config(['scout.queue' => false]);
    [, $term, $track] = seedTaxonomyTermAndTrack();
    fakeScoutEngine();
    Bus::fake([UpdateEcTrackAwsJob::class, UpdateUgcTrackDemStatsJob::class]);

    try {
        DB::transaction(function () use ($track, $term) {
            $track->taxonomyTerms()->attach($term->id);
            throw new RuntimeException('annullato');
        });
    } catch (RuntimeException) {
    }
    Bus::assertNotDispatched(UpdateEcTrackAwsJob::class);

    DB::transaction(fn () => $track->taxonomyTerms()->attach($term->id));

    Bus::assertDispatchedTimes(UpdateEcTrackAwsJob::class, 1);
});

<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\Models\App\AppConfigService;

/**
 * Le etichette delle tassonomie e dei loro tab stanno una volta sola nel config.json dell'app:
 * il dettaglio della traccia le richiama per chiave (oc:8959).
 */
uses(DatabaseTransactions::class);

function labelledTaxonomy(App $app, array $attributes = [], ?array $models = ['EcTrack']): TaxonomyTerm
{
    $taxonomy = TaxonomyTerm::create([
        'identifier' => 'tx-'.uniqid(),
        'name' => ['it' => 'Tipo percorso', 'en' => 'Route type'],
        ...$attributes,
    ]);
    $taxonomy->apps()->attach($app->id, ['models' => $models === null ? null : json_encode($models)]);

    return $taxonomy;
}

function configOf(App $app): array
{
    return (new AppConfigService($app->fresh()))->config();
}

it('etichetta le storiche e le tassonomie accese per le EcTrack, nell\'ordine di Nova', function () {
    $app = App::factory()->createQuietly();
    $second = labelledTaxonomy($app, ['sort_order' => 2]);
    $first = labelledTaxonomy($app, ['sort_order' => 1]);

    $labels = configOf($app)['TAXONOMY_LABELS'];

    expect(array_keys($labels))->toBe(['activity', 'theme', 'poi-type', 'where', $first->identifier, $second->identifier])
        ->and($labels[$first->identifier])->toEqual(['it' => 'Tipo percorso', 'en' => 'Route type'])
        ->and($labels['activity']['it'])->toBe('Attività')
        ->and($labels['where']['it'])->toBe('Zone');
});

it('etichetta anche le tassonomie accese solo per i POI, non quelle spente', function () {
    $app = App::factory()->createQuietly();
    $poiOnly = labelledTaxonomy($app, [], ['EcPoi']);
    $off = labelledTaxonomy($app);
    $off->apps()->detach();

    expect(configOf($app)['TAXONOMY_LABELS'])->toHaveKey($poiOnly->identifier)
        ->not->toHaveKey($off->identifier);
});

it('etichetta i tab usati, con General per primo', function () {
    $app = App::factory()->createQuietly();
    labelledTaxonomy($app, ['properties' => ['tab' => 'Inclusivity']]);

    $tabs = configOf($app)['TAXONOMY_TAB_LABELS'];

    expect(array_key_first($tabs))->toBe('General')
        ->and(array_keys($tabs))->toEqualCanonicalizing(['General', 'Activities', 'Inclusivity'])
        ->and($tabs['General']['it'])->toBe('Generale')
        ->and($tabs['Activities']['it'])->toBe('Attività')
        ->and($tabs['General']['en'])->toBe('General');
});

it('rigenera il config.json delle app accese quando cambia il nome di una tassonomia, dopo il commit', function () {
    $app = App::factory()->createQuietly();
    $taxonomy = labelledTaxonomy($app);
    Bus::fake([UpdateAppConfigJob::class, UpdateEcTrackAwsJob::class, UpdateUgcTrackDemStatsJob::class]);

    $taxonomy->fresh()->update(['name' => ['it' => 'Tipologia']]);

    // Bus::fake() non onora afterCommit(): si verifica il flag, non lo scarto (regola dei test).
    Bus::assertDispatched(UpdateAppConfigJob::class, fn (UpdateAppConfigJob $job) => $job->appId === $app->id && $job->afterCommit === true);
});

it('non rigenera il config.json quando cambia un termine', function () {
    $app = App::factory()->createQuietly();
    $taxonomy = labelledTaxonomy($app);
    $term = TaxonomyTerm::create(['identifier' => 'term-'.uniqid(), 'parent_id' => $taxonomy->id, 'name' => ['it' => 'x']]);
    Bus::fake([UpdateAppConfigJob::class, UpdateEcTrackAwsJob::class, UpdateUgcTrackDemStatsJob::class]);

    $term->fresh()->update(['name' => ['it' => 'y']]);

    Bus::assertNotDispatched(UpdateAppConfigJob::class);
});

it('rigenera il config.json delle app accese quando si cancella una tassonomia', function () {
    $app = App::factory()->createQuietly();
    $taxonomy = labelledTaxonomy($app);
    Bus::fake([UpdateAppConfigJob::class, UpdateEcTrackAwsJob::class, UpdateUgcTrackDemStatsJob::class]);

    $taxonomy->fresh()->delete();

    Bus::assertDispatched(UpdateAppConfigJob::class, fn (UpdateAppConfigJob $job) => $job->appId === $app->id && $job->afterCommit === true);
});

it('scrive come oggetto JSON il nome di una tassonomia senza traduzioni', function () {
    $app = App::factory()->createQuietly();
    $taxonomy = labelledTaxonomy($app);
    DB::table('taxonomy_terms')->where('id', $taxonomy->id)->update(['name' => '{}']);

    expect(json_encode(configOf($app)['TAXONOMY_LABELS']))->toContain('"'.$taxonomy->identifier.'":{}');
});

it('accoda la riscrittura del config.json anche mentre un\'altra è in corso, e non la fa girare in parallelo', function () {
    $job = new UpdateAppConfigJob(1);

    // Unico finché non parte: un dispatch arrivato durante l'esecuzione viene accettato e
    // rilegge lo stato nuovo (con ShouldBeUnique veniva scartato).
    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and(collect($job->middleware())->contains(fn ($m) => $m instanceof WithoutOverlapping))->toBeTrue();
});

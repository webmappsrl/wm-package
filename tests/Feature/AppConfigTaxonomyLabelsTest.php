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

    $taxonomies = configOf($app)['LABELS']['taxonomies'];

    expect(array_keys($taxonomies))->toBe(['activity', 'theme', 'poi-type', 'where', $first->identifier, $second->identifier])
        ->and($taxonomies[$first->identifier])->toEqual(['labels' => ['it' => 'Tipo percorso', 'en' => 'Route type'], 'tab' => 'General'])
        ->and($taxonomies['activity'])->toEqual(['labels' => ['it' => 'Attività', 'en' => 'Activities'], 'tab' => 'Activities'])
        ->and($taxonomies['where']['labels']['it'])->toBe('Zone')
        ->and($taxonomies['where']['tab'])->toBe('General');
});

it('etichetta anche le tassonomie accese solo per i POI, non quelle spente', function () {
    $app = App::factory()->createQuietly();
    $poiOnly = labelledTaxonomy($app, [], ['EcPoi']);
    $off = labelledTaxonomy($app);
    $off->apps()->detach();

    expect(configOf($app)['LABELS']['taxonomies'])->toHaveKey($poiOnly->identifier)
        ->not->toHaveKey($off->identifier);
});

it('dà a ogni tassonomia la chiave del suo tab, e le etichette dei tab una volta sola', function () {
    $app = App::factory()->createQuietly();
    $taxonomy = labelledTaxonomy($app, ['properties' => ['tab' => 'Inclusivity']]);

    $labels = configOf($app)['LABELS'];

    expect($labels['taxonomies'][$taxonomy->identifier]['tab'])->toBe('Inclusivity')
        ->and(array_key_first($labels['tabs']))->toBe('General')
        ->and(array_keys($labels['tabs']))->toEqualCanonicalizing(['General', 'Activities', 'Inclusivity'])
        ->and($labels['tabs']['General']['labels']['it'])->toBe('Generale')
        ->and($labels['tabs']['Activities']['labels']['it'])->toBe('Attività')
        ->and($labels['tabs']['General']['labels']['en'])->toBe('General');
});

it('usa General per una tassonomia che non dichiara un tab', function () {
    $app = App::factory()->createQuietly();
    $taxonomy = labelledTaxonomy($app);

    expect(configOf($app)['LABELS']['taxonomies'][$taxonomy->identifier]['tab'])->toBe('General');
});

it('non scrive più TAXONOMY_LABELS né TAXONOMY_TAB_LABELS', function () {
    $config = configOf(App::factory()->createQuietly());

    expect($config)->not->toHaveKey('TAXONOMY_LABELS')->not->toHaveKey('TAXONOMY_TAB_LABELS');
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

    expect(json_encode(configOf($app)['LABELS']['taxonomies'][$taxonomy->identifier]))->toContain('"labels":{}');
});

it('accoda la riscrittura del config.json anche mentre un\'altra è in corso, e non la fa girare in parallelo', function () {
    $job = new UpdateAppConfigJob(1);

    // Unico finché non parte: un dispatch arrivato durante l'esecuzione viene accettato e
    // rilegge lo stato nuovo (con ShouldBeUnique veniva scartato).
    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and(collect($job->middleware())->contains(fn ($m) => $m instanceof WithoutOverlapping))->toBeTrue();
});

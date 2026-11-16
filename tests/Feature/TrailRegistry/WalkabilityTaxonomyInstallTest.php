<?php

use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\ReindexAppContentsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityTaxonomyInstaller;

/**
 * La famiglia della percorribilita' nasce con il dominio (oc:8989): chi accende
 * l'interruttore del Catasto e migra se la ritrova pronta, accesa per le app
 * esistenti e marcata perche' le app future la ricevano.
 */
beforeEach(function () {
    // Accendere una tassonomia per un'app accoda il reindex dei suoi contenuti,
    // che con la coda sincrona dei test uscirebbe verso lo storage. Il Bus::fake
    // qui sostituisce quello globale della cartella: i job di quello vanno
    // ripetuti.
    Bus::fake([
        ReindexAppContentsJob::class,
        UpdateTrailApplicationDemJob::class,
        UpdateUgcTrackDemStatsJob::class,
        UpdateEcTrackAwsJob::class,
    ]);

    runTaxonomyStubs();

    // Creare un'App costruisce i dischi S3 dello shard: senza una region l'SDK
    // di AWS solleva un'eccezione prima ancora che il test cominci. I test del
    // dominio finora non creavano App, quindi non era mai emerso.
    config([
        'filesystems.disks.s3.region' => 'eu-central-1',
        'wm-package.features.trail_registry.walkability_taxonomy' => 'walkability-status',
    ]);

    $this->app_under_test = App::factory()->createQuietly();
});

it('crea la famiglia e i suoi quattro termini', function () {
    app(WalkabilityTaxonomyInstaller::class)->install();

    $family = TaxonomyTerm::query()->where('identifier', 'walkability-status')->first();

    expect($family)->not->toBeNull()
        ->and($family->single_choice)->toBeTrue()
        ->and($family->children()->orderBy('sort_order')->pluck('identifier')->all())->toBe([
            'walkability-status-unknown',
            'walkability-status-not-walkable',
            'walkability-status-partially-walkable',
            'walkability-status-walkable',
        ]);
});

it('è idempotente: due giri non creano doppioni', function () {
    app(WalkabilityTaxonomyInstaller::class)->install();
    app(WalkabilityTaxonomyInstaller::class)->install();

    expect(TaxonomyTerm::query()->where('identifier', 'walkability-status')->count())->toBe(1)
        ->and(TaxonomyTerm::query()->where('identifier', 'like', 'walkability-status-%')->count())->toBe(4);
});

it('accende la famiglia sulle app esistenti, solo per le tracce', function () {
    app(WalkabilityTaxonomyInstaller::class)->install();

    $family = TaxonomyTerm::query()->where('identifier', 'walkability-status')->first();
    $pivot = $this->app_under_test->taxonomies()
        ->where('taxonomy_terms.id', $family->id)
        ->first()?->pivot;

    // Il pivot non ha un cast su `models`: torna la stringa JSON, come la
    // tratta `App::syncTaxonomiesForModel()`.
    $models = is_string($pivot?->models) ? json_decode($pivot->models, true) : $pivot?->models;

    expect($pivot)->not->toBeNull()
        ->and($models)->toBe(['EcTrack']);
});

it('marca la famiglia di sistema, così le app create dopo la ricevono', function () {
    app(WalkabilityTaxonomyInstaller::class)->install();

    $family = TaxonomyTerm::query()->where('identifier', 'walkability-status')->first();
    expect($family->is_system)->toBeTrue();

    // Qui `create()` e non `createQuietly()`: e' `AppObserver::created` ad
    // accendere le famiglie di sistema, e con gli observer silenziati il test
    // verificherebbe il contrario di quello che vuole.
    $newApp = App::factory()->create();

    expect($newApp->taxonomies()->where('taxonomy_terms.id', $family->id)->exists())->toBeTrue();
});

it('usa il nome di famiglia che lo shard ha configurato', function () {
    config(['wm-package.features.trail_registry.walkability_taxonomy' => 'percorribilita']);

    app(WalkabilityTaxonomyInstaller::class)->install();

    expect(TaxonomyTerm::query()->where('identifier', 'percorribilita')->exists())->toBeTrue()
        ->and(TaxonomyTerm::query()->where('identifier', 'percorribilita-unknown')->exists())->toBeTrue()
        ->and(TaxonomyTerm::query()->where('identifier', 'walkability-status')->exists())->toBeFalse();
});

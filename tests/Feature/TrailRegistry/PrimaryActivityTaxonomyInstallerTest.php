<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\ReindexAppContentsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
use Wm\WmPackage\TrailRegistry\Services\PrimaryActivityTaxonomyInstaller;

/**
 * La famiglia dell'attivita' primaria nasce con il dominio (oc:9038).
 */
beforeEach(function () {
    // Nessun test deve scrivere fuori (regola in cima al CLAUDE.md del package):
    // il disco di wmfe e' finto e i job di reindex non partono.
    Storage::fake('wmfe');
    Bus::fake([
        ReindexAppContentsJob::class,
        UpdateTrailApplicationDemJob::class,
        UpdateUgcTrackDemStatsJob::class,
        UpdateEcTrackAwsJob::class,
    ]);

    runTaxonomyStubs();

    config([
        'filesystems.disks.s3.region' => 'eu-central-1',
        'wm-package.features.trail_registry.primary_activity_taxonomy' => 'primary-activity',
    ]);
});

it('crea la famiglia a scelta singola nel tab delle attività', function () {
    app(PrimaryActivityTaxonomyInstaller::class)->install();

    $family = TaxonomyTerm::where('identifier', 'primary-activity')->whereNull('parent_id')->first();

    // `properties` e' un cast ad array su TaxonomyTerm.
    expect($family)->not->toBeNull()
        ->and($family->single_choice)->toBeTrue()
        ->and($family->is_system)->toBeTrue()
        ->and($family->properties['tab'])->toBe('Activities');
});

it('copia i nomi dalle attività esistenti invece di scriverli fissi', function () {
    TaxonomyActivity::create(['identifier' => 'hiking', 'name' => ['it' => 'Alte vie']]);

    app(PrimaryActivityTaxonomyInstaller::class)->install();

    $term = TaxonomyTerm::where('identifier', 'primary-activity-hiking')->first();

    // `name` e' traducibile: l'accessor da' la lingua corrente, le traduzioni stanno in `getTranslations()`.
    expect($term->getTranslations('name'))->toBe(['it' => 'Alte vie']);
});

it('usa l\'identifier come nome quando l\'attività non esiste a database', function () {
    app(PrimaryActivityTaxonomyInstaller::class)->install();

    $term = TaxonomyTerm::where('identifier', 'primary-activity-nordic-walking')->first();

    expect($term->getTranslations('name'))->toEqual(['it' => 'nordic-walking', 'en' => 'nordic-walking']);
});

it('crea un termine per ciascuna delle sette attività dello schema', function () {
    app(PrimaryActivityTaxonomyInstaller::class)->install();

    expect(TaxonomyTerm::where('identifier', 'like', 'primary-activity-%')->count())->toBe(7);
});

it('non crea un termine per le attività che attività non sono', function () {
    TaxonomyActivity::create(['identifier' => 'trail', 'name' => ['it' => 'Sentiero']]);

    app(PrimaryActivityTaxonomyInstaller::class)->install();

    expect(TaxonomyTerm::where('identifier', 'primary-activity-trail')->exists())->toBeFalse();
});

it('è idempotente: due installazioni non duplicano nulla', function () {
    app(PrimaryActivityTaxonomyInstaller::class)->install();
    app(PrimaryActivityTaxonomyInstaller::class)->install();

    expect(TaxonomyTerm::where('identifier', 'like', 'primary-activity%')->count())->toBe(8);
});

it('accende la famiglia sulle app esistenti solo per le tracce', function () {
    $app = App::factory()->createQuietly();

    app(PrimaryActivityTaxonomyInstaller::class)->install();

    $family = TaxonomyTerm::where('identifier', 'primary-activity')->whereNull('parent_id')->first();
    $models = DB::table('app_taxonomy_term')
        ->where('app_id', $app->id)->where('taxonomy_term_id', $family->id)->value('models');

    expect(json_decode((string) $models, true))->toBe(['EcTrack']);
});

it('lascia com\'è una famiglia accesa solo per i POI', function () {
    $app = App::factory()->createQuietly();
    $poiOnly = TaxonomyTerm::create(['identifier' => 'only-poi-family', 'name' => ['it' => 'Solo POI']]);
    $app->syncTaxonomiesForModel('EcPoi', [$poiOnly->id]);

    app(PrimaryActivityTaxonomyInstaller::class)->install();

    $models = DB::table('app_taxonomy_term')
        ->where('app_id', $app->id)->where('taxonomy_term_id', $poiOnly->id)->value('models');

    expect(json_decode((string) $models, true))->toBe(['EcPoi']);
});

it('conserva le altre famiglie già accese per le tracce', function () {
    $app = App::factory()->createQuietly();
    $other = TaxonomyTerm::create(['identifier' => 'other-track-family', 'name' => ['it' => 'Altra']]);
    $app->syncTaxonomiesForModel('EcTrack', [$other->id]);

    app(PrimaryActivityTaxonomyInstaller::class)->install();

    $models = DB::table('app_taxonomy_term')
        ->where('app_id', $app->id)->where('taxonomy_term_id', $other->id)->value('models');

    expect(json_decode((string) $models, true))->toBe(['EcTrack']);
});

it('conserva una famiglia accesa per tutti i contenuti (models nullo)', function () {
    $app = App::factory()->createQuietly();
    $all = TaxonomyTerm::create(['identifier' => 'all-contents-family', 'name' => ['it' => 'Tutti']]);
    $app->syncTaxonomiesForModel('EcTrack', [$all->id]);
    $app->syncTaxonomiesForModel('EcPoi', [$all->id]);

    app(PrimaryActivityTaxonomyInstaller::class)->install();

    $row = DB::table('app_taxonomy_term')
        ->where('app_id', $app->id)->where('taxonomy_term_id', $all->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->models)->toBeNull();
});

it('mette la primaria prima delle scale di difficolta nel tab', function () {
    // Nel tab le famiglie seguono `sort_order` e poi `id`: con lo stesso
    // `sort_order` delle difficolta' la primaria finirebbe ultima, perche'
    // creata dopo (trovato guardando il tab su Nova, oc:9038).
    app(PrimaryActivityTaxonomyInstaller::class)->install();

    $family = TaxonomyTerm::query()->where('identifier', 'primary-activity')->firstOrFail();

    expect($family->sort_order)->toBeLessThan(0);
});

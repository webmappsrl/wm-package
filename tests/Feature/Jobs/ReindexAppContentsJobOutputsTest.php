<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\App\ReindexAppContentsJob;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Accendere o spegnere una tassonomia dalla scheda App cambia `TAXONOMY_LABELS` del config.json
 * e `taxonomies` nel dettaglio di ogni traccia dell'app, senza che nessun contenuto venga
 * salvato: il job che già reindicizza deve rigenerare anche quelle uscite (oc:8959).
 */
uses(DatabaseTransactions::class);

beforeEach(fn () => Bus::fake([UpdateAppConfigJob::class, UpdateEcTrackAwsJob::class, BuildAppPoisGeojsonJob::class, RegenerateTaxonomyWhereOutputsJob::class, UpdateUgcTrackDemStatsJob::class]));

it('rigenera config.json e, con il job delle uscite dell\'app, dettagli e pois.geojson quando le tassonomie accese cambiano', function () {
    $app = App::factory()->createQuietly();
    EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
    $before = ReindexAppContentsJob::taxonomiesSignature($app);
    $taxonomy = TaxonomyTerm::create(['identifier' => 'tx-'.uniqid(), 'name' => ['it' => 'x']]);
    $taxonomy->apps()->attach($app->id);

    (new ReindexAppContentsJob($app->id, $before))->handle();

    Bus::assertDispatched(UpdateAppConfigJob::class, fn ($job) => $job->appId === $app->id);
    // Il giro sulle tracce lo fa il job che già rigenera le uscite pubbliche dell'app: unico
    // finché non parte, così due salvataggi ravvicinati non raddoppiano i job per traccia.
    Bus::assertDispatched(RegenerateTaxonomyWhereOutputsJob::class, fn ($job) => $job->appId === $app->id);
    Bus::assertNotDispatched(UpdateEcTrackAwsJob::class);
});

it('non rigenera niente se le tassonomie accese non sono cambiate', function () {
    $app = App::factory()->createQuietly();
    EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);

    (new ReindexAppContentsJob($app->id, ReindexAppContentsJob::taxonomiesSignature($app)))->handle();

    Bus::assertNotDispatched(UpdateAppConfigJob::class);
    Bus::assertNotDispatched(RegenerateTaxonomyWhereOutputsJob::class);
});

<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\Pbf\GenerateEcTrackPBFBatch;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrack3DDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAppRelationsInfoJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackCurrentDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackGenerateElevationChartImage;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackManualDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackOrderRelatedPoi;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackSlopeValues;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/*
 * Il DEM mancante si ricalcola all'apertura del dettaglio (oc:8660). La logica
 * sta nel padre MultiLineString: qui i casi valgono per ogni figlio che la
 * usa, TrailApplication ed EcTrack.
 */

beforeEach(function () {
    runTrailRegistryStubs();
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    Bus::fake([UpdateTrailApplicationDemJob::class]);
});

/**
 * Un'istanza con la geometria data e il lock della creazione gia' rilasciato,
 * cosi' il test osserva solo la chiamata che fa lui.
 */
function demTrailApplication(array $properties = [], string $wkt = 'MULTILINESTRING Z ((1 1 0, 2 2 0))'): TrailApplication
{
    $application = TrailApplication::factory()->create(['properties' => $properties]);
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        [$wkt, $application->id]
    );
    // La creazione ha gia' preso sia il lock del modello sia quello
    // ShouldBeUnique del job (oc:8564): quest'ultimo vive in $locks di
    // ArrayStore, un array separato da quello che flush() svuota. Serve un
    // driver nuovo di zecca, non solo uno store vuoto.
    app('cache')->forgetDriver('redis');
    Bus::fake([UpdateTrailApplicationDemJob::class]);

    return $application->fresh();
}

describe('TrailApplication', function () {
    it('accoda il job DEM alla creazione, dopo il commit', function () {
        // Sotto Bus::fake() il rinvio al commit non e' osservabile: si verifica
        // che il job sia marcato afterCommit, lo scarto al rollback lo fa il
        // dispatcher vero di Laravel.
        $application = TrailApplication::factory()->create();

        Bus::assertDispatched(
            UpdateTrailApplicationDemJob::class,
            fn ($job) => $job->applicationId === $application->id && $job->afterCommit === true
        );
    });

    it('serve il DEM con dem_data vuoto', function () {
        expect(demTrailApplication()->needsDem())->toBeTrue()
            ->and(demTrailApplication(['dem_data' => []])->needsDem())->toBeTrue();
    });

    it('serve il DEM con dem_data pieno e quote tutte a zero', function () {
        expect(demTrailApplication(['dem_data' => ['ascent' => 10]])->needsDem())->toBeTrue();
    });

    it('non serve il DEM con dem_data pieno e quote calcolate', function () {
        $application = demTrailApplication(['dem_data' => ['ascent' => 10]], 'MULTILINESTRING Z ((1 1 120, 2 2 140))');

        expect($application->needsDem())->toBeFalse();
    });

    it('un solo punto sopra zero basta: un tratto sul mare ha quote vere a zero', function () {
        $application = demTrailApplication(['dem_data' => ['ascent' => 10]], 'MULTILINESTRING Z ((1 1 0, 2 2 35))');

        expect($application->needsDem())->toBeFalse();
    });

    it('non serve il DEM con geometria non valida', function () {
        // Una linea con un solo punto distinto: ST_IsValid la rifiuta.
        $application = demTrailApplication([], 'MULTILINESTRING Z ((1 1 0, 1 1 0))');

        expect($application->needsDem())->toBeFalse();
    });

    it('non serve il DEM senza geometria', function () {
        $application = demTrailApplication();
        DB::statement('UPDATE trail_applications SET geometry = NULL WHERE id = ?', [$application->id]);

        expect($application->fresh()->needsDem())->toBeFalse();
    });

    it('rilancia il job dove il DEM manca', function () {
        demTrailApplication()->dispatchDemIfMissing();

        Bus::assertDispatched(UpdateTrailApplicationDemJob::class);
    });

    it('non rilancia il job dove il DEM c e', function () {
        demTrailApplication(['dem_data' => ['ascent' => 10]], 'MULTILINESTRING Z ((1 1 120, 2 2 140))')
            ->dispatchDemIfMissing();

        Bus::assertNotDispatched(UpdateTrailApplicationDemJob::class);
    });

    it('due aperture di fila accodano un solo job', function () {
        $application = demTrailApplication();

        $application->dispatchDemIfMissing();
        $application->dispatchDemIfMissing();

        Bus::assertDispatchedTimes(UpdateTrailApplicationDemJob::class, 1);
    });

    it('il dettaglio aperto subito dopo la creazione non accoda un secondo job', function () {
        $application = TrailApplication::factory()->create();
        DB::statement(
            'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
            ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]
        );

        $application->fresh()->dispatchDemIfMissing();

        Bus::assertDispatchedTimes(UpdateTrailApplicationDemJob::class, 1);
    });
});

/**
 * Una EcTrack con la geometria data e il lock della creazione gia' rilasciato.
 * Bus::fake() prima della creazione: senza, l'observer eseguirebbe la catena
 * vera, che chiama il servizio DEM.
 */
function demEcTrack(array $properties = [], string $wkt = 'MULTILINESTRING Z ((1 1 0, 2 2 0))'): EcTrack
{
    Bus::fake();
    $track = EcTrack::factory()->create(['osmid' => null, 'properties' => $properties]);
    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        [$wkt, $track->id]
    );
    app('cache')->forgetDriver('redis');
    Bus::fake();

    return $track->fresh();
}

describe('EcTrack', function () {
    beforeEach(function () {
        // La catena della creazione costruisce anche i job di pubblicazione
        // (es. UpdateEcTrackAwsJob), che indicizzano su Scout leggendo i
        // media dal disco `wmfe`: senza uno shard e un disco S3 finti,
        // StorageService risolve lo shard a null e poi tenta un client S3
        // vero (pattern esistente in GetUpdatedAtTrackTest ed
        // ExecuteEcTrackDataChainActionTest).
        config(['wm-package.shard_name' => 'test_shard']);
        Storage::fake('s3');
        Storage::fake('wmfe');
        config([
            'filesystems.disks.s3.key' => 'dummy_key',
            'filesystems.disks.s3.secret' => 'dummy_secret',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'dummy_bucket',
            'filesystems.disks.s3.url' => '',
            'filesystems.disks.wmfe.driver' => 'local',
            'medialibrary.disk_name' => 'public',
        ]);
    });

    it('serve il DEM con dem_data vuoto', function () {
        expect(demEcTrack()->needsDem())->toBeTrue();
    });

    it('serve il DEM con dem_data pieno e quote tutte a zero', function () {
        expect(demEcTrack(['dem_data' => ['ascent' => 10]])->needsDem())->toBeTrue();
    });

    it('non serve il DEM con dem_data pieno e quote calcolate', function () {
        expect(demEcTrack(['dem_data' => ['ascent' => 10]], 'MULTILINESTRING Z ((1 1 120, 2 2 140))')->needsDem())
            ->toBeFalse();
    });

    it('Z assente conta come zero', function () {
        // ST_AsGeoJSON di una geometria 2D emette due sole coordinate.
        $track = demEcTrack(['dem_data' => ['ascent' => 10]]);

        expect((fn () => $this->hasOnlyZeroElevations([
            'type' => 'MultiLineString',
            'coordinates' => [[[1, 1], [2, 2]]],
        ]))->call($track))->toBeTrue();
    });

    it('non serve il DEM con geometria non valida', function () {
        expect(demEcTrack([], 'MULTILINESTRING Z ((1 1 0, 1 1 0))')->needsDem())->toBeFalse();
    });

    it('accoda la catena del DEM, col PBF e senza la taxonomy where', function () {
        demEcTrack()->dispatchDemIfMissing();

        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackManualDataJob::class,
            UpdateEcTrackCurrentDataJob::class,
            UpdateEcTrack3DDemJob::class,
            UpdateEcTrackSlopeValues::class,
            UpdateEcTrackGenerateElevationChartImage::class,
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
        Bus::assertNotDispatched(SyncModelTaxonomyWhereJob::class);
    });

    it('non accoda nulla dove il DEM c e', function () {
        demEcTrack(['dem_data' => ['ascent' => 10]], 'MULTILINESTRING Z ((1 1 120, 2 2 140))')
            ->dispatchDemIfMissing();

        Bus::assertNothingDispatched();
    });

    it('due aperture di fila accodano una sola catena', function () {
        $track = demEcTrack();

        $track->dispatchDemIfMissing();
        $track->dispatchDemIfMissing();

        Bus::assertDispatchedTimes(UpdateEcTrackDemJob::class, 1);
    });

    it('il dettaglio aperto subito dopo la creazione non accoda una seconda catena', function () {
        Bus::fake();
        $track = EcTrack::factory()->create(['osmid' => null, 'properties' => []]);

        $track->fresh()->dispatchDemIfMissing();

        // Solo la catena della creazione: createDataChain() ha preso il lock.
        Bus::assertDispatchedTimes(UpdateEcTrackDemJob::class, 1);
    });

    it('il dettaglio aperto subito dopo una modifica della geometria non accoda una seconda catena', function () {
        // updateDataChain() prende il lock quando ricalcola per una geometria
        // modificata (oc:8660): l'observer updated() la chiama gia' con
        // Bus::fake() attivo, come createDataChain() alla creazione.
        $track = demEcTrack();

        $track->geometry = DB::raw("ST_GeomFromText('MULTILINESTRING Z ((3 3 0, 4 4 0))', 4326)");
        $track->save();

        $track->fresh()->dispatchDemIfMissing();

        // Solo la catena dell'aggiornamento: updateDataChain() ha preso il lock.
        Bus::assertDispatchedTimes(UpdateEcTrackDemJob::class, 1);
    });
});

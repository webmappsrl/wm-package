<?php

namespace Tests\Unit\Services\EcTrackService;

use Mockery;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackFromOsmJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackManualDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackOrderRelatedPoi;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackCurrentDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrack3DDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackSlopeValues;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackGenerateElevationChartImage;
use Wm\WmPackage\Jobs\Pbf\GenerateEcTrackPBFBatch;

class UpdateDataChainTest extends AbstractEcTrackServiceTest
{
    /** @var EcTrack|Mockery\MockInterface */
    private $track;

    private array $mockedTrackProperties;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->mockedTrackProperties = [
            // Popola questo array con le proprietà che ti aspetti siano lette,
            // es. osmid sarà impostato per test specifici.
            // Per il test 'at_least_one_job', osmid non dovrebbe essere presente qui
            // se vuoi che UpdateEcTrackFromOsmJob non venga aggiunto in quel caso.
            // Le tue precedenti 'dem_data' e 'manual_data' non sono più usate da updateDataChain.
        ];

        // Usa un mock PURO, non makePartial()
        $this->track = Mockery::mock(EcTrack::class);

        // --- Mock per l'accesso alle proprietà dal servizio ---
        $this->track->shouldReceive('getAttribute')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });
        // Mock per l'accesso diretto a ->properties
        $this->track->shouldReceive('__get')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });

        // --- Mock per la serializzazione della coda di Laravel ---
        $this->track->shouldReceive('getKey')->andReturn(1); // ID del Modello
        $this->track->shouldReceive('getQueueableClass')->andReturn(EcTrack::class); // Classe REALE per la serializzazione
        $this->track->shouldReceive('getQueueableRelations')->andReturn([]); // Relazioni da serializzare (nessuna qui)
        $this->track->shouldReceive('getQueueableConnection')->andReturn('test_connection_name'); // Nome connessione di test o null

        // Mock per getAttribute('id') (comune)
        $this->track->shouldReceive('getAttribute')->with('id')->andReturn(1);

        // wasChanged sarà mockato per ogni test specifico
        // Non è necessario shouldAllowMockingMethod con mock puri se le aspettative sono chiare per test.
    }

    public function test_update_data_chain_dispatches_at_least_one_job()
    {
        // Mock wasChanged('geometry') only here
        $this->track->shouldReceive('wasChanged')->with('geometry')->once()->andReturn(true);

        $this->ecTrackService->updateDataChain($this->track);

        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackManualDataJob::class,
            UpdateEcTrackCurrentDataJob::class,
            UpdateEcTrack3DDemJob::class,
            UpdateEcTrackSlopeValues::class,
            UpdateModelWithGeometryTaxonomyWhere::class,
            UpdateEcTrackGenerateElevationChartImage::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
            GenerateEcTrackPBFBatch::class,
        ]);
    }

    public function test_update_data_chain_dispatches_job_if_track_has_osm_data()
    {
        $this->mockedTrackProperties = ['osmid' => 123];

        $this->track->shouldReceive('wasChanged')->with('geometry')->andReturn(false);

        $this->ecTrackService->updateDataChain($this->track);

        Bus::assertDispatched(UpdateEcTrackFromOsmJob::class);
    }
}

<?php

namespace Wm\WmPackage\Jobs\Pbf;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\PBFGeneratorService;

class RegeneratePBFForTrackJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Numero massimo di tentativi
    public $tries = 3;

    // Tempo massimo di esecuzione in secondi
    public $timeout = 600; // 10 minuti

    private $trackId;

    private $trackModelClass;

    private $startZoom;

    private $minZoom;

    private $app_id;

    private $zoomTreshold;

    /**
     * Create a new job instance.
     *
     * @param  int  $trackId  ID della traccia modificata
     * @param  string  $trackModelClass  Classe del modello della traccia (es. 'App\Models\EcTrack')
     * @param  int  $startZoom  Zoom di partenza (es. 13)
     * @param  int  $minZoom  Zoom minimo da rigenerare (es. 5)
     * @param  int  $app_id  ID dell'app
     * @return void
     */
    public function __construct(int $trackId, string $trackModelClass, int $startZoom, int $minZoom, int $app_id)
    {
        $this->trackId = $trackId;
        $this->trackModelClass = $trackModelClass;
        $this->startZoom = $startZoom;
        $this->minZoom = $minZoom;
        $this->app_id = $app_id;
        $this->zoomTreshold = PBFGeneratorService::make()->getzoomTreshold();
    }

    public function handle(GeometryComputationService $geometryService)
    {
        try {
            Log::info("Rigenerazione PBF per traccia {$this->trackId}: ".$this->trackModelClass);
            // Carica il modello della traccia
            $trackModel = $this->trackModelClass::find($this->trackId);

            if (! $trackModel) {
                Log::warning("Track {$this->trackId} non trovata, impossibile rigenerare i tile PBF");

                return;
            }

            // Verifica che il modello sia un GeometryModel
            if (! ($trackModel instanceof GeometryModel)) {
                Log::error("Il modello {$this->trackModelClass} non estende GeometryModel");

                return;
            }

            // Verifica che la traccia abbia una geometria valida
            if (empty($trackModel->geometry) ||
                ! DB::selectOne('SELECT ST_IsValid(?) as is_valid', [$trackModel->geometry])->is_valid ||
                DB::selectOne('SELECT ST_Dimension(?) as dim', [$trackModel->geometry])->dim !== 1) {
                Log::warning("Track {$this->trackId} ha una geometria non valida o vuota, saltando la rigenerazione PBF");

                return;
            }

            // Verifica che sia LINESTRING o MULTILINESTRING
            $geometryType = DB::selectOne('SELECT ST_GeometryType(?) as type', [$trackModel->geometry])->type;
            if (! in_array($geometryType, ['ST_LineString', 'ST_MultiLineString'])) {
                Log::warning("Track {$this->trackId} ha un tipo di geometria non supportato: {$geometryType}, saltando la rigenerazione PBF");

                return;
            }

            // Genera solo i tile impattati dalla modifica della traccia
            $impactedTiles = $geometryService->generateImpactedTilesForTrack(
                $trackModel,
                $this->startZoom,
                $this->minZoom
            );

            if (empty($impactedTiles)) {
                Log::info("Nessun tile da rigenerare per la traccia {$this->trackId}");

                return;
            }

            Log::info("Rigenerazione PBF per traccia {$this->trackId}: ".count($impactedTiles).' tile impattati');

            // Raggruppa i tile per livello di zoom
            $tilesByZoom = [];
            foreach ($impactedTiles as $tile) {
                [$x, $y, $zoom] = $tile;
                if (! isset($tilesByZoom[$zoom])) {
                    $tilesByZoom[$zoom] = [];
                }
                $tilesByZoom[$zoom][] = [$x, $y, $zoom];
            }

            // Crea i job per ogni livello di zoom
            $jobs = [];
            foreach ($tilesByZoom as $zoom => $tiles) {
                // Pulisci le chiavi cache per questo zoom
                $geometryService->clearEmptyTileKeys($this->app_id, $zoom);

                foreach ($tiles as $tile) {
                    [$x, $y, $z] = $tile;

                    // Scegli il tipo di job in base al livello di zoom
                    if ($z <= $this->zoomTreshold) {
                        $jobs[] = new GenerateLayerPBFJob($z, $x, $y, $this->app_id);
                    } else {
                        $jobs[] = new GeneratePBFJob($z, $x, $y, $this->app_id);
                    }
                }
            }

            // Dispatch del batch se ci sono job da eseguire
            if (! empty($jobs)) {
                $batch = Bus::batch($jobs)
                    ->name("PBF Regeneration for Track {$this->trackId}: {$this->app_id}")
                    ->onConnection('redis')
                    ->onQueue('pbf')
                    ->dispatch();

                Log::info("Batch di rigenerazione PBF avviato per traccia {$this->trackId}: ".count($jobs).' job');
            }

        } catch (Throwable $e) {
            Log::error("Errore nella rigenerazione PBF per traccia {$this->trackId}: ".$e->getMessage(), [
                'track_id' => $this->trackId,
                'start_zoom' => $this->startZoom,
                'min_zoom' => $this->minZoom,
                'app_id' => $this->app_id,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Metodo statico per facilitare l'uso del job
     *
     * @param  GeometryModel  $track  Il modello della traccia modificata
     * @param  int  $startZoom  Zoom di partenza
     * @param  int  $minZoom  Zoom minimo
     * @param  int  $app_id  ID dell'app
     */
    public static function dispatchForTrack(GeometryModel $track, int $startZoom, int $minZoom, int $app_id): void
    {
        self::dispatch(
            $track->id,
            get_class($track),
            $startZoom,
            $minZoom,
            $app_id
        );
    }
}

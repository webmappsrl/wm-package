<?php

namespace Wm\WmPackage\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class NodeJsService extends BaseService
{
    public function __construct(protected StorageService $storageService) {}

    /**
     * Generate the elevation chart image for the ec track
     * Imported from geomixer
     *
     *
     * @return string with the generated image path
     *
     * @throws Exception when the generation fail
     */
    public function generateElevationChartImage(array $geojson): string
    {
        if (! isset($geojson['properties']['id'])) {
            throw new Exception('The geojson id is not defined');
        }

        // TODO: mv all to the storage service
        $localDisk = $this->storageService->getLocalDisk();
        $mediaDisk = $this->storageService->getMediaDisk();

        if (! $localDisk->exists('elevation_charts')) {
            $localDisk->makeDirectory('elevation_charts');
        }
        if (! $localDisk->exists('geojson')) {
            $localDisk->makeDirectory('geojson');
        }

        $id = $geojson['properties']['id'];

        $localDisk->put("geojson/$id.geojson", json_encode($geojson));

        $src = $localDisk->path("geojson/$id.geojson");
        $dest = $localDisk->path("elevation_charts/$id.svg");

        $cmd = config('wm-package.nodejs.node_executable')." node/jobs/build-elevation-chart.js --geojson=$src --dest=$dest --type=svg";

        // Log::info("Running node command: {$cmd}");

        $this->runNodeJsCommand($cmd);

        // TODO: mv all to the storage service

        $localDisk->delete("geojson/$id.geojson");

        if ($mediaDisk->exists("ectrack/elevation_charts/$id.svg")) {
            if ($mediaDisk->exists("ectrack/elevation_charts/{$id}_old.svg")) {
                $mediaDisk->delete("ectrack/elevation_charts/{$id}_old.svg");
            }
            $mediaDisk->move("ectrack/elevation_charts/$id.svg", "ectrack/elevation_charts/{$id}_old.svg");
        }
        try {
            $mediaDisk->writeStream("ectrack/elevation_charts/$id.svg", $localDisk->readStream("elevation_charts/$id.svg"));
        } catch (Exception $e) {
            Log::warning('The elevation chart image could not be written');
            if ($mediaDisk->exists("ectrack/elevation_charts/{$id}_old.svg")) {
                $mediaDisk->move("ectrack/elevation_charts/{$id}_old.svg", "ectrack/elevation_charts/$id.svg");
            }
        }

        if ($mediaDisk->exists("ectrack/elevation_charts/{$id}_old.svg")) {
            $mediaDisk->delete("ectrack/elevation_charts/{$id}_old.svg");
        }

        return $mediaDisk->path("ectrack/elevation_charts/{$id}.svg");
    }

    /**
     * Run the effective image generation
     * Imported from geomixer
     *
     *
     * @throws Exception
     */
    private function runNodeJsCommand(string $cmd): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],   // stdin is a pipe that the child will read from
            1 => ['pipe', 'w'],   // stdout is a pipe that the child will write to
            2 => ['pipe', 'w'],    // stderr is a pipe that the child will write to
        ];
        flush();

        $process = proc_open($cmd, $descriptorSpec, $pipes, realpath('./'), []);
        if (is_resource($process)) {
            while ($s = fgets($pipes[1])) {
                Log::info($s);
                flush();
            }

            if ($s = fgets($pipes[2])) {
                throw new Exception($s);
            }
        }
    }
}

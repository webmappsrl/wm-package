<?php

namespace Wm\WmPackage\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\WmPackageServiceProvider;

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

        $id = $geojson['properties']['id'];

        $paths = $this->storageService->storeLocalElevationChartImage($id, $geojson);

        $src = $paths['src'];
        $dest = $paths['dest'];

        $packageServiceProvider = WmPackageServiceProvider::getBasePath();

        $cmd = config('wm-package.services.nodejs.executable')." {$packageServiceProvider}/node/jobs/build-elevation-chart.js --geojson=$src --dest=$dest --type=svg";

        // Log::info("Running node command: {$cmd}");

        $this->runNodeJsCommand($cmd);

        $this->storageService->deleteLocalTempGeojsonForElavationChartImageGeneration($id);

        return $this->storageService->storeRemoteElevationChartImage($id);
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
                throw new Exception("Exception running command: {$cmd}.\n{$s}");
            }
        }
    }
}

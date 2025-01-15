<?php

namespace Wm\WmPackage\Jobs\Track;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\GeometryComputationService;

class UpdateEcTrack3DDemJob extends BaseEcTrackJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(GeometryComputationService $geometryComputationService)
    {
        $data = $this->ecTrack->getTrackGeometryGeojson();
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(
            rtrim(config('wm-package.services.dem.host'), '/').rtrim(config('wm-package.services.dem.3d_data_api'), '/'),
            $data
        );

        // Check the response
        if ($response->successful()) {
            // Request was successful, handle the response data here
            $responseData = $response->json();

            if (isset($responseData['geometry']) && ! empty($responseData['geometry'])) {
                // TODO: here we can set in the geometry a raw expression to execute only 1 query
                $this->ecTrack->geometry = $geometryComputationService->getWktFromGeojson(json_encode($responseData['geometry']));
                $this->ecTrack->saveQuietly();
                // Log::info($this->ecTrack->id . ' UpdateEcTrack3DDemJob: SUCCESS');
            }
        } else {
            // Request failed, handle the error here
            $errorCode = $response->status();
            $errorBody = $response->body();
            throw new Exception($this->ecTrack->id."UpdateEcTrack3DDemJob: FAILED: Error {$errorCode}: {$errorBody}");
        }
    }
}

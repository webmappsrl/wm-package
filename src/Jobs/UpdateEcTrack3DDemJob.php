<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\GeometryComputationService;

class UpdateEcTrack3DDemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $ecTrack;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($ecTrack)
    {
        $this->ecTrack = $ecTrack;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $data = $this->ecTrack->getTrackGeometryGeojson();
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(
            rtrim(config('wm-package.services.dem.host'), '/') . rtrim(config('wm-package.services.dem.3d_data_api'), '/'),
            $data
        );

        // Check the response
        if ($response->successful()) {
            // Request was successful, handle the response data here
            $responseData = $response->json();
            try {
                if (isset($responseData['geometry']) && ! empty($responseData['geometry'])) {
                    //TODO: here we can set in the geometry a raw expression to execute only 1 query
                    $this->ecTrack->geometry = GeometryComputationService::make()->getWktFromGeojson(json_encode($responseData['geometry']));
                    $this->ecTrack->saveQuietly();
                    // Log::info($this->ecTrack->id . ' UpdateEcTrack3DDemJob: SUCCESS');
                }
            } catch (\Exception $e) {
                Log::error($this->ecTrack->id . 'UpdateEcTrack3DDemJob: FAILED: ' . $e->getMessage());
            }
        } else {
            // Request failed, handle the error here
            $errorCode = $response->status();
            $errorBody = $response->body();
            Log::error($this->ecTrack->id . "UpdateEcTrack3DDemJob: FAILED: Error {$errorCode}: {$errorBody}");
        }
    }
}

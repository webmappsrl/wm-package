<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;

class UpdateLayeredFeaturesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected Layer $layer, protected string $ecModelClass) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'update_layered_'.$this->ecModelClass.'_'.$this->layer->id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(LayerService $layerService)
    {
        $savedModels = $layerService->updateLayersPropertyOnLayeredFeature($this->layer, $this->ecModelClass);

        $savedModelsString = print_r($savedModels, true);
        Log::info("SUCCESS: Saved {$savedModelsString} {$this->ecModelClass} on layer with ID {$this->layer->id} ");

        return $savedModels;
    }
}

<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;

class UpdateLayeredFeaturesJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        return 'update_layered_' . class_basename($this->ecModelClass) . '_' . $this->layer->id;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping($this->uniqueId())->dontRelease()];
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(LayerService $layerService)
    {
        $savedModels = $layerService->updateLayersPropertyOnLayeredFeature($this->layer, $this->ecModelClass);

        // $savedModelsString = print_r($savedModels, true);
        // Log::info("SUCCESS: Saved {$savedModelsString} {$this->ecModelClass} on layer with ID {$this->layer->id} ");

        return $savedModels;
    }
}

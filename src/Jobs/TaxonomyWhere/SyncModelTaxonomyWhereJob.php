<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\GeometryComputationService;

class SyncModelTaxonomyWhereJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(protected GeometryModel $model) {}

    public function handle(GeometryComputationService $service): void
    {
        $service->syncTaxonomyWhere(get_class($this->model), $this->model->id);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncModelTaxonomyWhereJob failed after all retries', [
            'model' => get_class($this->model),
            'model_id' => $this->model->id,
            'error' => $e->getMessage(),
        ]);
    }
}


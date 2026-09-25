<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\TaxonomyWhereResyncService;

/**
 * Job per record del riallineamento conservativo (oc:8588): non azzera mai, scrive solo se trova
 * un risultato non vuoto. Fase 1 del batch dispatchato da TaxonomyWhereResyncService::dispatch();
 * la fase 2 (RegenerateTaxonomyWhereOutputsJob) parte da `finally()` sul batch, non da qui.
 */
class ConservativeSyncTaxonomyWhereJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public string $modelClass, public int $modelId) {}

    public function handle(TaxonomyWhereResyncService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $service->resyncRecord($this->modelClass, $this->modelId);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('oc:8588 ConservativeSyncTaxonomyWhereJob fallito dopo tutti i tentativi: valore lasciato invariato', [
            'model' => $this->modelClass,
            'model_id' => $this->modelId,
            'error' => $e->getMessage(),
        ]);
    }
}

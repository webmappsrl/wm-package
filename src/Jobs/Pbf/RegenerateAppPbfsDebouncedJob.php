<?php

namespace Wm\WmPackage\Jobs\Pbf;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\PBFGeneratorService;

/**
 * Job "debounced" per rigenerare i PBF dell'intera app dopo una modifica al rank di un Layer.
 *
 * Il primo dispatch imposta un lock (uniqueId per app_id) e accoda l'esecuzione
 * a +5 minuti (delay impostato da chi dispatcha). I dispatch successivi entro
 * la finestra vengono scartati, cosi' la rigenerazione parte una sola volta.
 */
class RegenerateAppPbfsDebouncedJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    // Safety net del lock oltre la finestra di debounce (5 min).
    public int $uniqueFor = 900;

    public function __construct(public int $appId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'regenerate-app-pbfs-'.$this->appId;
    }

    public function handle(PBFGeneratorService $pbfGeneratorService): void
    {
        $app = App::find($this->appId);
        if (! $app) {
            Log::warning('RegenerateAppPbfsDebouncedJob: app non trovata', [
                'app_id' => $this->appId,
            ]);

            return;
        }

        try {
            $pbfGeneratorService->generateWholeAppPbfsOptimized($app);
        } catch (Throwable $e) {
            Log::error('RegenerateAppPbfsDebouncedJob: errore nella rigenerazione PBF', [
                'app_id' => $this->appId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

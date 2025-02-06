<?php

namespace Wm\WmPackage\Jobs\Pbf;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Services\PBFGeneratorService;

class GeneratePBFJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Numero massimo di tentativi
    public $tries = 5;

    // Tempo massimo di esecuzione in secondi
    public $timeout = 900; // 10 minuti

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected $z, protected $x, protected $y, protected $app_id) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(PBFGeneratorService $PBFGeneratorService)
    {
        $PBFGeneratorService->generate($this->app_id, $this->z, $this->x, $this->y);
    }
}

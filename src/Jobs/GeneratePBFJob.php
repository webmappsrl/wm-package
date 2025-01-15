<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\PBFGenerator;

class GeneratePBFJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Numero massimo di tentativi
    public $tries = 5;

    // Tempo massimo di esecuzione in secondi
    public $timeout = 900; // 10 minuti

    protected $z;

    protected $x;

    protected $y;

    protected $app_id;

    protected $author_id;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($z, $x, $y, $app_id, $author_id)
    {
        $this->z = $z;
        $this->x = $x;
        $this->y = $y;
        $this->app_id = $app_id;
        $this->author_id = $author_id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        try {
            $generator = new PBFGenerator($this->app_id, $this->author_id);
            $generator->generate($this->z, $this->x, $this->y);
        } catch (\Exception $e) {
            // Log dell'errore
            Log::error('Errore durante la generazione del PBF: '.$e->getMessage());
            // Opzionalmente, puoi reintrodurre l'eccezione per far fallire il job
            throw $e;
        }
    }
}

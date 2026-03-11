<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;

class UpdateLayerGeometryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int debounce in secondi (default 5 minuti) */
    protected int $debounceSec;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected Layer $layer, int $debounceSec = 300)
    {
        $this->afterCommit(true);
        $this->debounceSec = $debounceSec;
    }

    /**
     * Facade "debounced": salva il last-update e mette in coda il job (una sola istanza grazie a ShouldBeUnique)
     */
    public static function dispatchDebounced(Layer $layer, int $debounceSec = 300): void
    {
        $key = "layer:{$layer->id}:update:last";
        $lastUpdate = Cache::get($key);
        $now = now();

        // Se c'è un update recente e non è ancora passato il periodo di debounce, non dispatchare
        if ($lastUpdate) {
            $elapsed = $now->diffInSeconds($lastUpdate);
            if ($elapsed < $debounceSec) {
                // Aggiorna solo il timestamp, ma non dispatchare (il job esistente si occuperà del resto)
                Cache::put($key, $now, $debounceSec * 4);

                return;
            }
        }

        // Salva l'ultimo update; TTL ampio per sicurezza
        Cache::put($key, $now, $debounceSec * 4);

        // Delay iniziale per permettere al job di rimanere visibile in "Delayed" per un po'
        // Questo delay è più breve del debounce, così il job può essere processato e rilasciato se necessario
        $initialDelay = app()->isLocal() ? 10 : 60; // 10 secondi in locale, 1 minuto in produzione

        // Con ShouldBeUnique, se il job è già in coda o in esecuzione, questo dispatch verrà ignorato
        // (ed è proprio ciò che vogliamo: una sola istanza che si "autoriprogramma").
        try {
            dispatch(new self($layer, $debounceSec))->delay($initialDelay);
        } catch (QueryException $e) {
            // Gestisce silenziosamente l'errore di transazione SQL fallita
            if ($e->getCode() === '25P02') {
                return;
            }
            throw $e;
        }
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'update_layer_geometry_'.$this->layer->id;
    }

    /**
     * (Opzionale, ma utile) durata massima del lock di unicità in caso di crash
     */
    public function uniqueFor(): int
    {
        // 10 minuti: abbastanza per più cicli di release consecutivi
        return 600;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(LayerService $layerService)
    {
        // --- BLOCCO DI DEBOUNCE ---
        $key = "layer:{$this->layer->id}:update:last";
        $last = Cache::get($key);

        if ($last) {
            $elapsed = now()->diffInSeconds($last);
            $remaining = $this->debounceSec - $elapsed;

            if ($remaining > 0) {
                // Non è ancora trascorso il periodo di quiete: rimetti in coda per i secondi rimanenti
                $this->release($remaining);

                return;
            }
        }
        // --- FINE BLOCCO DI DEBOUNCE ---

        $saved = $layerService->updateLayerGeometry($this->layer);

        return ['saved' => $saved];
    }
}

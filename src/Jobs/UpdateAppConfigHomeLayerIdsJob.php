<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\App;

class UpdateAppConfigHomeLayerIdsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $geohubAppId;

    protected int $maxAttempts = 5; // Massimo 5 tentativi (10 minuti)

    public function __construct(int $geohubAppId)
    {
        $this->geohubAppId = $geohubAppId;
        // $this->afterCommit(true);
    }

    public function handle(): void
    {
        $app = App::where('properties->geohub_id', $this->geohubAppId)->first();
        if (! $app) {
            Log::warning("App non trovata per geohub_id {$this->geohubAppId}");

            return;
        }

        // Verifica se l'app ha layer importati, se non li ha aspetta
        $layers = $app->layers()->get()->concat($app->associatedLayers()->get());
        if ($layers->isEmpty()) {
            $currentAttempt = $this->attempts();
            if ($currentAttempt >= $this->maxAttempts) {
                Log::warning("App ID {$app->id} (geohub_id: {$this->geohubAppId}) non ha layer dopo {$this->maxAttempts} tentativi, abbandono");

                return;
            }
            Log::info("App ID {$app->id} (geohub_id: {$this->geohubAppId}) non ha layer importati, riprovo tra 2 minuti (tentativo {$currentAttempt}/{$this->maxAttempts})");
            $this->release(120);

            return;
        }

        // Usa getRawOriginal per evitare il cast FlexibleCast
        $configHome = $app->getRawOriginal('config_home');

        if (empty($configHome)) {
            return;
        }

        if (! empty($configHome)) {
            if (is_string($configHome)) {
                $configHome = json_decode($configHome, true);
            } elseif (is_array($configHome)) {
                // già array
            } elseif (is_object($configHome) && method_exists($configHome, 'toArray')) {
                $configHome = $configHome->toArray();
            }
        }

        if (! is_array($configHome) || ! isset($configHome['HOME'])) {
            return;
        }

        $homeElements = $configHome['HOME'] ?? [];
        $updated = false;

        // Recupera tutti i layer dell'app (diretti e associati)
        $allLayers = $app->layers()->get()->concat($app->associatedLayers()->get());

        foreach ($homeElements as $index => $element) {
            if (isset($element['box_type']) && $element['box_type'] === 'layer') {
                $layerId = $element['layer'];
                $layer = $allLayers->firstWhere('properties.geohub_id', $layerId);
                if ($layer) {
                    $element['layer'] = $layer->id;
                    $homeElements[$index] = $element;
                    $updated = true;
                }
            }
        }

        $homeElements = array_values($homeElements);

        if ($updated) {
            $configHome['HOME'] = $homeElements;

            DB::table('apps')
                ->where('id', $app->id)
                ->update(['config_home' => json_encode($configHome)]);

            Log::info("Config home aggiornata per App ID {$app->id}");
        }
    }
}

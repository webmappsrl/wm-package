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

    protected int $appId;

    public function __construct(int $appId)
    {
        $this->appId = $appId;
    }

    public function handle(): void
    {
        $app = App::find($this->appId);

        if (! $app) {
            Log::warning("App non trovata per app id {$this->appId}");

            return;
        }

        $configHome = $app->getRawOriginal('config_home');

        if (empty($configHome)) {
            return;
        }

        if (is_string($configHome)) {
            $configHome = json_decode($configHome, true);
        } elseif (is_object($configHome) && method_exists($configHome, 'toArray')) {
            $configHome = $configHome->toArray();
        }

        if (! is_array($configHome) || ! isset($configHome['HOME'])) {
            return;
        }

        $allLayers = $app->layers()->get()->concat($app->associatedLayers()->get());
        $localIds = $allLayers->pluck('id')->all();

        $homeElements = $configHome['HOME'] ?? [];
        $updated = false;

        foreach ($homeElements as $index => $element) {
            if (($element['box_type'] ?? null) !== 'layer') {
                continue;
            }

            $layerId = $element['layer'] ?? null;

            if (is_null($layerId)) {
                continue;
            }

            // Idempotenza: un valore già id locale di questa app non va rimappato.
            // Limite noto: id Geohub e id locali condividono lo spazio di interi, quindi un
            // id Geohub che sia ANCHE un id locale valido è indistinguibile da un valore già
            // rimappato. Oggi non collide (id locali 1-9, geohub_id 131-137) ma gli id locali
            // crescono. Servirebbe un marker persistito. Vedi overview → Rischi.
            if (in_array((int) $layerId, $localIds, true)) {
                continue;
            }

            $layer = $allLayers->firstWhere('properties.geohub_id', $layerId);

            if (! $layer) {
                continue;
            }

            $homeElements[$index]['layer'] = $layer->id;
            $updated = true;
        }

        if (! $updated) {
            return;
        }

        $configHome['HOME'] = array_values($homeElements);

        DB::table('apps')->where('id', $app->id)->update(['config_home' => json_encode($configHome)]);

        Log::info("Config home: id layer rimappati per App ID {$app->id}");
    }
}

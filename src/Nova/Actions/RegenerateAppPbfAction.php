<?php

namespace Wm\WmPackage\Nova\Actions;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\PBFGeneratorService;

/**
 * Azione Nova per rigenerare tutti i PBF ottimizzati per un'App
 *
 * Utilizza l'approccio bottom-up ottimizzato con clustering geografico
 * per rigenerare tutti i tile PBF dell'applicazione.
 */
class RegenerateAppPbfAction extends BasePbfAction
{
    public function name()
    {
        return __('Regenerate Optimized PBFs');
    }

    /**
     * Esegue la rigenerazione dei PBF ottimizzati per le app selezionate
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $pbfService = PBFGeneratorService::make();
        $processedCount = 0;
        $errors = [];

        foreach ($models as $app) {
            if (! $app instanceof App) {
                continue;
            }

            try {
                // Verifica che l'app abbia tracce
                $trackCount = $app->ecTracks()->count();
                if ($trackCount === 0) {
                    $errors[] = "App '{$app->name}' (ID: {$app->id}) non ha tracce associate";
                    Log::warning('Tentativo di rigenerare PBF per app senza tracce', [
                        'app_id' => $app->id,
                        'app_name' => $app->name,
                    ]);

                    continue;
                }

                // Rigenera tutti i PBF ottimizzati per l'app
                $pbfService->generateWholeAppPbfsOptimized($app);

                $processedCount++;

                Log::info('Rigenerazione PBF ottimizzati avviata per app', [
                    'app_id' => $app->id,
                    'app_name' => $app->name,
                    'track_count' => $trackCount,
                ]);
            } catch (Exception $e) {
                $errors[] = "Errore per app '{$app->name}' (ID: {$app->id}): {$e->getMessage()}";
                Log::error('Errore nella rigenerazione PBF ottimizzati per app', [
                    'app_id' => $app->id,
                    'app_name' => $app->name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Costruisce il messaggio di risposta
        $message = "Rigenerazione PBF ottimizzati avviata per {$processedCount} app";
        if (! empty($errors)) {
            $message .= '. Errori: '.implode('; ', $errors);
        }

        return Action::message($message);
    }
}

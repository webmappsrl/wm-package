<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

/**
 * Azione Nova per reindicizzare tutte le EcTrack di un'App in Scout/Elasticsearch
 * 
 * Reindicizza tutte le tracce associate all'applicazione utilizzando
 * il metodo searchable() di Laravel Scout.
 */
class ReindexAppScoutAction extends Action
{
    use InteractsWithQueue, Queueable;

    public function name()
    {
        return __('Reindex Scout');
    }

    /**
     * Esegue la reindicizzazione Scout per le app selezionate
     *
     * @param  ActionFields  $fields
     * @param  Collection  $models
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $processedCount = 0;
        $totalTracksIndexed = 0;
        $errors = [];

        // Verifica se l'indice Scout esiste
        try {
            $ecTrackModel = new EcTrack();
            $indexName = $ecTrackModel->searchableAs();
            $client = app(\Elastic\Elasticsearch\Client::class);
            $indexExists = $client->indices()->exists(['index' => $indexName])->asBool();
            
            if (!$indexExists) {
                // L'indice non esiste, mostra errore con comando da eseguire
                $containerName = $this->getContainerName();
                $ecTrackModelClass = config('wm-package.ec_track_model', 'App\\Models\\EcTrack');
                $command = sprintf('docker exec %s php artisan scout:import "%s"', $containerName, $ecTrackModelClass);
                
                $errorMessage = __('The Scout index ":index" does not exist.<br><br>If the index is missing, create it this way:<br><br><code>:command</code>', [
                    'index' => $indexName,
                    'command' => htmlspecialchars($command),
                ]);
                
                return Action::danger($errorMessage);
            }
        } catch (\Exception $e) {
            Log::error('Error checking Scout index', [
                'error' => $e->getMessage(),
            ]);
            return Action::danger(__('Error checking Scout index: :error', ['error' => $e->getMessage()]));
        }

        foreach ($models as $app) {
            if (! $app instanceof App) {
                continue;
            }

            try {
                // Recupera tutte le EcTrack dell'app
                $ecTracks = $app->ecTracks()->get();
                $trackCount = $ecTracks->count();

                if ($trackCount === 0) {
                    Log::info('No tracks to reindex for app', [
                        'app_id' => $app->id,
                        'app_name' => $app->name,
                    ]);
                    continue;
                }

                // Reindicizza tutte le tracce usando Scout
                $ecTracks->searchable();

                $processedCount++;
                $totalTracksIndexed += $trackCount;

                Log::info('Scout reindexing completed for app', [
                    'app_id' => $app->id,
                    'app_name' => $app->name,
                    'track_count' => $trackCount,
                ]);
            } catch (\Exception $e) {
                // Se l'errore è relativo all'indice mancante, mostra il comando
                if (str_contains($e->getMessage(), 'index') || str_contains($e->getMessage(), 'Index')) {
                    $containerName = $this->getContainerName();
                    $ecTrackModelClass = config('wm-package.ec_track_model', 'App\\Models\\EcTrack');
                    $command = sprintf('docker exec %s php artisan scout:import "%s"', $containerName, $ecTrackModelClass);
                    
                    $errors[] = __('Error for app \':name\' (ID: :id): The Scout index does not exist. Execute: :command', [
                        'name' => $app->name,
                        'id' => $app->id,
                        'command' => $command,
                    ]);
                } else {
                    $errors[] = __('Error for app \':name\' (ID: :id): :error', [
                        'name' => $app->name,
                        'id' => $app->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::error('Error reindexing Scout for app', [
                    'app_id' => $app->id,
                    'app_name' => $app->name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Costruisce il messaggio di risposta
        $message = __('Scout reindexing completed for :count app', ['count' => $processedCount]);
        if ($totalTracksIndexed > 0) {
            $message .= ' ' . __('(:count tracks reindexed)', ['count' => $totalTracksIndexed]);
        }
        if (! empty($errors)) {
            $message .= '. ' . __('Errors') . ': ' . implode('; ', $errors);
            return Action::danger($message);
        }

        return Action::message($message);
    }

    /**
     * Ottiene il nome del container PHP
     *
     * @return string
     */
    protected function getContainerName(): string
    {
        $appName = env('APP_NAME', 'camminiditalia');
        return "php-{$appName}";
    }
}


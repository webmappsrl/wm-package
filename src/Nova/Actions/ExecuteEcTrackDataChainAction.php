<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\EcTrackService;

class ExecuteEcTrackDataChainAction extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * Chain di Job da eseguire
     */
    protected array $chain;

    /**
     * Nome personalizzabile dell'action
     */
    protected string $actionName;

    /**
     * Dimensione del batch per il processamento delle EcTrack
     */
    protected int $chunkSize;

    /**
     * Crea una nuova istanza dell'action
     *
     * @param  array  $chain  Array di istanze Job
     * @param  string|null  $name  Nome personalizzabile dell'action
     */
    public function __construct(array $chain = [], ?string $name = null, int $chunkSize = 100)
    {
        $this->chain = $chain;
        $this->actionName = $name ?? __('Process Track Data');
        $this->chunkSize = $chunkSize;
    }

    public function name()
    {
        return $this->actionName;
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $ecTrackService = app(EcTrackService::class);
        $processedCount = 0;

        $executeChain = empty($this->chain)
            ? fn ($ecTrack) => $ecTrackService->createDataChain($ecTrack)
            : fn ($ecTrack) => $this->executeCustomChain($ecTrack, $this->chain);

        foreach ($models as $model) {
            $ecTracks = $this->getTracksFromModel($model);

            foreach ($ecTracks->chunk($this->chunkSize) as $batch) {
                foreach ($batch as $ecTrack) {
                    try {
                        $executeChain($ecTrack);
                        $processedCount++;
                    } catch (\Exception $e) {
                        Log::error('Failed to process EcTrack', [
                            'track_id' => $ecTrack->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                // Small delay between batches to prevent overwhelming the queue
                usleep(100000); // 0.1 second delay
            }
        }

        return Action::message(__(':count EcTrack processed!', ['count' => $processedCount]));
    }

    /**
     * Ottiene le EcTrack collegate al modello
     *
     * @param  App|Layer|$ecTrackModelClass  $model
     */
    protected function getTracksFromModel($model): Collection
    {
        $ecTrackModelClass = config('wm-package.ec_track_model');

        if ($model instanceof $ecTrackModelClass) {
            return collect([$model]);
        }

        if ($model instanceof App || $model instanceof Layer) {
            return $model->ecTracks;
        }

        return collect();
    }

    /**
     * Esegue la chain di Job per una EcTrack
     *
     * @param  array  $jobs  Array di istanze Job o closure che restituiscono Job
     * @return void
     */
    protected function executeCustomChain(EcTrack $ecTrack, array $jobs)
    {
        $chain = [];

        foreach ($jobs as $job) {
            // Se è una closure, chiamala con $ecTrack per ottenere il Job
            if ($job instanceof \Closure) {
                $job = $job($ecTrack);
            }

            // Controlla che sia un Job valido
            if ($job instanceof ShouldQueue) {
                $chain[] = $job;
            }
        }

        if (! empty($chain)) {
            Bus::chain($chain)->dispatch();
        }
    }
}

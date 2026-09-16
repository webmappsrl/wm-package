<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Media;

/**
 * Nessuno dei trigger esistenti su Layer (wasChanged('properties') nel
 * package, calculatedValuesAreUnchanged() nei consumer) reagisce a un
 * cambio isolato di un qualsiasi media collegato a un Layer — vedi oc:8564.
 * Observer dedicato (non dentro MediaObserver, già globale su ogni media
 * del sistema) per tenere questo rischio isolato e testabile
 * separatamente. Delay di 10s coerente con LayerObserver::updateAppConf(),
 * per lasciare il tempo alle conversion (thumbnail, registrate per
 * l'intero modello da GeometryModel::registerMediaConversions()) di
 * essere generate prima di rigenerare la config pubblica.
 *
 * Registrato globalmente su Media (ogni media del sistema, non solo quelli
 * di un Layer) — try/catch obbligatorio: un'eccezione qui non deve mai far
 * fallire il salvataggio di un media estraneo ai Layer, stesso principio
 * già applicato da MediaObserver::setAppIdAndGeometry() sullo stesso
 * identico accesso a $media->model (oc:8564, review).
 */
class LayerMediaObserver
{
    private const DISPATCH_DELAY_SECONDS = 10;

    public function created(Media $media): void
    {
        $this->dispatchIfLayerMedia($media);
    }

    public function deleted(Media $media): void
    {
        $this->dispatchIfLayerMedia($media);
    }

    private function dispatchIfLayerMedia(Media $media): void
    {
        try {
            $model = $media->model;

            if (! $model instanceof Layer) {
                return;
            }

            // Layer::creating() assegna app_id automaticamente solo se
            // App::count() === 1: su un consumer multi-app un Layer può
            // restare senza app_id fino a un salvataggio esplicito
            // successivo. UpdateAppConfigJob richiede un int non nullable —
            // dispatchare con null farebbe TypeError sincrono dentro questo
            // observer, durante il save Nova (oc:8564, review).
            if ($model->app_id === null) {
                return;
            }

            UpdateAppConfigJob::dispatch($model->app_id)
                ->delay(now()->addSeconds(self::DISPATCH_DELAY_SECONDS))
                ->onQueue('default');
        } catch (\Exception $e) {
            Log::error('Error dispatching UpdateAppConfigJob from LayerMediaObserver', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
